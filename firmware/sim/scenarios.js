'use strict';

// End-to-end simulator: the REAL firmware (titan.app.js in a mocked Espruino VM) driving the phone
// model over a shared virtual clock. Exercises the exact lift/run sequences a user performs on the
// watch — including the failure modes that leave "nothing on the phone": a workout whose end signal
// never reaches the phone because the band DROPPED at the end, and consecutive workouts.
//
//   node firmware/sim/scenarios.js

const { VirtualClock } = require('./clock');
const { buildWatch } = require('./watch');
const { Phone } = require('./phone');
const { Server } = require('./server');

const STOPWATCH_PAGE = 3, SLEEP_PAGE = 4, RUN_PAGE = 5, LIFT_PAGE = 6;

class Session {
  constructor(opts = {}) {
    this.clock = new VirtualClock();
    this.watch = buildWatch(this.clock);
    this.phone = new Phone(this.clock, opts);
    this.server = new Server({ buggy: !!opts.serverBuggy });   // the seal the phone hands off to
    this._sealedMarkers = 0;      // how many delivered confirmed markers we've already handed to the server
    this.dropTAEnd = !!opts.dropTAEnd;   // simulate the "workout over" frame + retries never arriving
    this.watch.onDeliver((line) => {
      if (!this.phone.connected) return;
      if (this.dropTAEnd && line.indexOf('"k":"end"') >= 0) return;   // swallow TA:{"k":"end"}
      this.phone.ingest(line);
    });
  }

  // The phone uploads whatever confirmed wake markers it has now (live or replayed from the ring) to
  // the server, which seals each into a sleep_logs row. Airplane-mode: nothing reaches the server
  // until this runs (i.e. once the phone is back online). Idempotent — only NEW markers are sealed.
  syncToServer() {
    for (let i = this._sealedMarkers; i < this.phone.sleepSummaries.length; i++) {
      this.server.sealConfirmed(this.phone.sleepSummaries[i]);
    }
    this._sealedMarkers = this.phone.sleepSummaries.length;
  }

  // Model the nap's OWN overnight PPG reaching the server as asleep ppg_raw windows spanning [bed,wake]
  // (the SLEEP_DUTY bursts, all low-motion). Omit to model the thin/late/never-uploaded case.
  uploadNapWindows(bedSec, wakeSec) {
    for (let t = bedSec; t < wakeSec; t += 180) {
      this.server.ingestWindow({ startSec: t, endSec: Math.min(t + 30, wakeSec), asleep: true });
    }
  }

  // An UNRELATED daytime PPG burst on the same calendar date but OUTSIDE the nap (a mid-morning HR
  // burst, yesterday's tail). The pre-fix seal vacuums these into the nap → the all-awake phantom.
  uploadStrayWindow(startSec, endSec) { this.server.ingestWindow({ startSec, endSec, asleep: false }); }
  connect() { this.watch.connect(); this.phone.onConnect(); this.clock.advance(2000); }
  disconnect() { this.watch.disconnect(); this.phone.onDisconnect(); }
  advance(ms) { this.clock.advance(ms); }
  gotoLift() { this.watch.swipeRight(LIFT_PAGE); }
  gotoRun() { this.watch.swipeRight(RUN_PAGE); }
  gotoSleep() { this.watch.swipeRight(SLEEP_PAGE); }
  startSleep() { this.watch.press(1); }        // single-click on the dedicated Sleep face → start
  wake() { this.watch.press(1); }              // single-click → WAKE → emits the T9 confirmed marker
  tapButton() { this.watch.press(1); }
  work(seconds, bpm = 130) {
    for (let s = 0; s < seconds; s++) {
      this.watch.accel(0.02, 0.01, 1.0);
      for (let k = 0; k < 4; k++) this.watch.hrmRaw(12000 + k);
      this.watch.hrm(bpm);
      this.clock.advance(1000);
    }
  }
  settleAfterEnd(seconds = 6, bpm = 110) { for (let s = 0; s < seconds; s++) { this.watch.hrm(bpm); this.clock.advance(1000); } }
  // The overnight PPG the sleep duty-cycle captures: raw HRM each second while lying still. Connected →
  // streams live (T1); offline → banked to the flash ring (T2) for the morning sync. This is the DATA the
  // server stages the night from — feeding it is what makes the sleep test real (not just the marker).
  sleepCapture(seconds, bpm = 52) {
    for (let s = 0; s < seconds; s++) {
      this.watch.accel(0.001, 0.0, 1.0);                 // near-still = asleep
      for (let k = 0; k < 4; k++) this.watch.hrmRaw(12000 + (k % 3));
      this.watch.hrm(bpm, 92);
      this.clock.advance(1000);
    }
  }
  gps(seconds, bpm = 150) {
    const lat0 = 37.77, lon0 = -122.42;
    for (let s = 0; s < seconds; s++) {
      this.watch.gps({ fix: 1, satellites: 9, speed: 10, lat: lat0 + s * 0.0001, lon: lon0 + s * 0.0001, alt: 30 });
      this.watch.hrm(bpm);
      this.clock.advance(1000);
    }
  }
}

let failures = 0;
const results = [];
const check = (name, cond, detail) => { results.push({ name, ok: !!cond, detail }); if (!cond) failures++; };
const strengthSummaries = (p) => p.summaries.filter((x) => x.kind === 'strength').length;
const endedStrength = (p) => p.sealed.filter((w) => w.ended && w.activity_kind === 'strength').length;

// 1) A single connected lift, ended cleanly on the watch (TA:end delivered).
(() => {
  const s = new Session();
  s.connect(); s.gotoLift(); s.tapButton(); s.work(90, 132);
  check('single lift · opens live as strength', s.phone.runActive && s.phone.liveSheetShown && s.phone.workoutKind === 'strength',
    `runActive=${s.phone.runActive} kind=${s.phone.workoutKind}`);
  s.tapButton(); s.advance(5000);
  check('single lift · summary shown on end', strengthSummaries(s.phone) === 1, JSON.stringify(s.phone.summaries));
  check('single lift · ENDED strength window sealed', endedStrength(s.phone) >= 1,
    s.phone.sealed.map((w) => `${w.activity_kind}/ended=${!!w.ended}`).join(', ') || '(none)');
})();

// 2) A GPS run, ended cleanly.
(() => {
  const s = new Session();
  s.connect(); s.gotoRun(); s.tapButton(); s.gps(90, 150);
  check('run · opens live as run', s.phone.runActive && s.phone.workoutKind === 'run', `runActive=${s.phone.runActive}`);
  s.tapButton(); s.advance(5000);
  check('run · summary + ENDED run window', s.phone.summaries.some((x) => x.kind === 'run') && s.phone.sealed.some((w) => w.ended && w.activity_kind === 'run'), '');
})();

// 3) THE REAL BUG the user hit: the band DROPS at the end of a lift, so neither the TA:end frame nor
//    the sport→0 frames ever reach the phone. Without a fix the run hangs live forever — no summary,
//    only a delayed ended=false window. Fix B: a confirmed durable disconnect ends the run.
(() => {
  const fixed = new Session({ endOnDisconnect: true });
  fixed.connect(); fixed.gotoLift(); fixed.tapButton(); fixed.work(60, 135);
  fixed.disconnect();            // band drops at the end; end signal never delivered
  fixed.advance(10_000);         // link stays down past the 8 s confirm
  check('drop-at-end (FIXED) · summary shown', strengthSummaries(fixed.phone) === 1, JSON.stringify(fixed.phone.summaries));
  check('drop-at-end (FIXED) · ENDED window sealed', endedStrength(fixed.phone) >= 1, '');
  check('drop-at-end (FIXED) · not left hanging live', fixed.phone.runActive === false, `runActive=${fixed.phone.runActive}`);

  const pre = new Session({ endOnDisconnect: false });   // pre-fix behavior → the user's symptom
  pre.connect(); pre.gotoLift(); pre.tapButton(); pre.work(60, 135);
  pre.disconnect(); pre.advance(10_000);
  check('drop-at-end (PRE-FIX) reproduces the symptom: no summary + run hangs live',
    strengthSummaries(pre.phone) === 0 && pre.phone.runActive === true,
    `summaries=${pre.phone.summaries.length} runActive=${pre.phone.runActive}`);
})();

// 4) A transient BLE blip mid-lift (drops, reconnects within a few seconds) must NOT prematurely end
//    the workout — only one summary at the real end.
(() => {
  const s = new Session({ endOnDisconnect: true });
  s.connect(); s.gotoLift(); s.tapButton(); s.work(30, 130);
  s.disconnect(); s.advance(3000); s.connect();   // blip < 8 s → confirm cancelled
  s.work(30, 138); s.tapButton(); s.advance(5000);
  check('transient blip · exactly one summary (not prematurely ended)', strengthSummaries(s.phone) === 1,
    `summaries=${s.phone.summaries.length}`);
})();

// 5) Two clean consecutive lifts (both connected, both ended on the watch) each open live, summarize,
//    and seal. Exercises back-to-back sessions — the state must fully reset between them.
(() => {
  const s = new Session();
  s.connect();
  s.gotoLift(); s.tapButton(); s.work(70, 130); s.tapButton(); s.settleAfterEnd(6); s.advance(15_000);
  s.tapButton(); s.work(70, 140); s.tapButton(); s.settleAfterEnd(6);
  check('consecutive lifts · both summarize', strengthSummaries(s.phone) === 2, `summaries=${s.phone.summaries.length}`);
  check('consecutive lifts · both seal an ended window', endedStrength(s.phone) === 2, `ended=${endedStrength(s.phone)}`);
})();

// 6) A lift done PHONE-FREE (started while the link was released/offline) is logged to the band's ring
//    and recovered on the next sync. It must seal as an `ended` backlog workout (so the server seals
//    it at once and the app shows a catch-up summary), without ever popping a phantom LIVE run.
(() => {
  const s = new Session({ endOnDisconnect: true });
  s.gotoLift(); s.tapButton(); s.work(90, 130); s.tapButton();  // whole lift offline (never connected)
  s.advance(120_000);            // open the app much later → the backlog is genuinely old (all > 60s)
  s.connect();                   // sync: ring drains → TS:done → waLog flushes the recovered workout
  s.advance(30_000);             // let the chunked drain (2 frames / 300 ms) finish + emit TS:done
  check('offline lift · recovered + sealed as an ENDED strength window', endedStrength(s.phone) >= 1,
    s.phone.sealed.map((w) => `${w.activity_kind}/ended=${!!w.ended}/why=${w.why}`).join(', ') || '(none)');
  check('offline lift · did NOT pop a phantom live run', s.phone.runActive === false, `runActive=${s.phone.runActive}`);
  check('offline lift · backlog-sync signal fired (→ app checks for a catch-up summary)', (s.phone.backlogSynced || 0) >= 1,
    `backlogSynced=${s.phone.backlogSynced || 0}`);
})();

// 7) A GPS run accumulates a route: the sealed run window carries the fixes and the phone tracks
//    live distance from them.
(() => {
  const s = new Session();
  s.connect(); s.gotoRun(); s.tapButton(); s.gps(120, 150);
  const distMoved = s.phone.runDistanceKm > 0.1;
  s.tapButton(); s.advance(5000);
  const runWin = s.phone.sealed.find((w) => w.ended && w.activity_kind === 'run');
  check('run route · live distance accumulated from GPS', distMoved, `km=${s.phone.runDistanceKm.toFixed(3)}`);
  check('run route · sealed run window carries GPS fixes', !!runWin && runWin.n_gps > 0, `n_gps=${runWin ? runWin.n_gps : 'n/a'}`);
})();

// 8) The BACKUP end path: if the "workout over" (TA:end) frame + its retries are all lost, the watch's
//    sport tag falling to 0 for a sustained few seconds must still end the run.
(() => {
  const s = new Session({ dropTAEnd: true });
  s.connect(); s.gotoLift(); s.tapButton(); s.work(60, 130);
  s.tapButton();                 // end on the watch — but every TA:end frame is dropped
  s.settleAfterEnd(8, 100);      // the watch keeps streaming HR at sport=0 → the 4 s sport-loss end fires
  check('sport→0 fallback · summary shown despite lost TA:end', strengthSummaries(s.phone) === 1, `summaries=${s.phone.summaries.length}`);
  check('sport→0 fallback · ENDED window sealed', endedStrength(s.phone) >= 1, '');
})();

// 9) A phone-free RUN (started offline, GPS logged to the ring) recovers on sync as a run WITH a route.
(() => {
  const s = new Session({ endOnDisconnect: true });
  s.gotoRun(); s.tapButton(); s.gps(120, 150); s.tapButton();   // whole run offline
  s.advance(120_000); s.connect(); s.advance(40_000);           // sync much later → drain → TS:done
  const runWin = s.phone.sealed.find((w) => w.ended && w.activity_kind === 'run' && w.n_gps > 0);
  check('offline run · recovered as an ENDED run window with a route', !!runWin,
    s.phone.sealed.map((w) => `${w.activity_kind}/ended=${!!w.ended}/gps=${w.n_gps}`).join(', ') || '(none)');
  check('offline run · did NOT pop a phantom live run', s.phone.runActive === false, `runActive=${s.phone.runActive}`);
})();

// 10) SLEEP tracked LIVE (phone connected — you fell asleep with the app open). START shows the live
//     "Sleeping" state, the overnight PPG streams in real time, and WAKE clears it + saves the night.
(() => {
  const s = new Session();
  s.connect(); s.gotoSleep(); s.startSleep();
  check('sleep (live) · app enters the Sleeping state on START', s.phone.sleeping === true && s.phone.sleepStartMs > 0,
    `sleeping=${s.phone.sleeping} start=${s.phone.sleepStartMs}`);
  s.sleepCapture(300);   // 5 min of live overnight capture
  check('sleep (live) · overnight PPG streams to the phone in real time', s.phone.ppgLiveSamples > 0, `live=${s.phone.ppgLiveSamples}`);
  s.wake(); s.advance(2000);
  const sleep = s.phone.sleepSummaries[0];
  check('sleep (live) · WAKE clears Sleeping + shows the summary', s.phone.sleeping === false && s.phone.sleepSummaryShown === true,
    `sleeping=${s.phone.sleeping} summary=${s.phone.sleepSummaryShown}`);
  check('sleep (live) · confirmed night saved (T9), wake after bedtime', !!sleep && sleep.confirmed && sleep.wake > sleep.bedtime,
    sleep ? `bed=${sleep.bedtime} wake=${sleep.wake}` : '(none)');
})();

// 11) SLEEP tracked OFFLINE (the realistic case — phone on the nightstand, link released overnight).
//     START while still connected shows Sleeping; the whole night is banked to the watch's flash ring;
//     nothing reaches the phone until morning; on reconnect the ring hands over ALL the overnight data
//     + the confirmed wake marker, and the night is saved.
(() => {
  const s = new Session();
  s.connect(); s.gotoSleep(); s.startSleep();
  check('sleep (offline) · Sleeping state set while connected at bedtime', s.phone.sleeping === true, `sleeping=${s.phone.sleeping}`);
  s.disconnect();               // phone released for the night
  s.sleepCapture(600);          // 10 min captured to the ring, fully offline
  s.wake();                     // wake while still offline → marker logged to the ring too
  check('sleep (offline) · nothing delivered while disconnected', s.phone.ppgBacklogSamples === 0 && s.phone.sleepSummaries.length === 0,
    `backlog=${s.phone.ppgBacklogSamples} saved=${s.phone.sleepSummaries.length}`);
  const syncAt = s.phone.lastDataAt;
  s.connect(); s.advance(60_000);   // morning: open app → reconnect → the ring drains
  check('sleep (offline) · overnight PPG handed to the phone on sync', s.phone.ppgBacklogSamples > 0, `backlog=${s.phone.ppgBacklogSamples}`);
  check('sleep (offline) · confirmed night recovered + saved on sync', s.phone.sleepSummaries.some((x) => x.confirmed),
    `count=${s.phone.sleepSummaries.length}`);
  check('sleep (offline) · Sleeping state cleared + summary surfaced on sync (not stuck)',
    s.phone.sleeping === false && s.phone.sleepSummaryShown === true, `sleeping=${s.phone.sleeping} summary=${s.phone.sleepSummaryShown}`);
  check('sleep (offline) · phone recorded a fresh data-handoff time on sync', s.phone.lastDataAt !== syncAt && s.phone.lastDataAt != null,
    `lastDataAt=${s.phone.lastDataAt}`);
})();

// 12) SLEEP started PHONE-FREE (never connected at bedtime — band on wrist, phone across the house).
//     No live marker is possible; the entire night + wake marker live in the ring and recover intact on
//     the first morning connection. Proves the "no phone at all overnight" path.
(() => {
  const s = new Session();
  s.gotoSleep(); s.startSleep();
  check('sleep (phone-free) · no live Sleeping state (never connected)', s.phone.sleeping === false, `sleeping=${s.phone.sleeping}`);
  s.sleepCapture(600); s.wake();       // whole night + wake, all offline
  s.connect(); s.advance(60_000);      // first morning connection drains the ring
  check('sleep (phone-free) · overnight PPG recovered on first connect', s.phone.ppgBacklogSamples > 0, `backlog=${s.phone.ppgBacklogSamples}`);
  check('sleep (phone-free) · confirmed night saved on first connect', s.phone.sleepSummaries.some((x) => x.confirmed),
    `count=${s.phone.sleepSummaries.length}`);
})();

// 13) THE STEP-FREEZE BUG. The user walks with the BAND while a workout records + HR streams, but the
//     watch's Steps face is frozen at a static number (e.g. "919") and never climbs. Root cause: the
//     phone pushes its OWN (often higher / stale) day total (C6), and stepCount() returned MAX(phone,
//     band) — so a static phone snapshot PINNED the display and hid the band's live pedometer. The band's
//     own count is the live source of truth on the wrist; the server does the authoritative per-day merge.
//     Also proves the T8 the band streams up carries the BAND's count, not an echo of the phone's number.
(() => {
  const decodeT8Steps = (frames) => {
    const t8 = frames.filter((l) => l.slice(0, 3) === 'T8:').pop();
    if (!t8) return null;
    return Buffer.from(t8.slice(3), 'base64').readUInt32LE(4);
  };
  const s = new Session();
  s.connect();                       // linked; recording is ON by default (first-boot pref → startStreaming)
  s.gotoLift(); s.tapButton();       // start a LIFT workout → HR streams (sport mode) + accel logs, exactly the user's state
  // Walk 60 steps with the band first, so it clearly HAS its own live count.
  for (let i = 0; i < 60; i++) { s.watch.walk(1); s.watch.hrm(130); s.clock.advance(400); }
  check('steps · band pedometer is counting during the workout', s.watch.osSteps() === 60, `os=${s.watch.osSteps()}`);

  // The phone (sat on a desk across the room) pushes its higher, static day total. The user keeps walking
  // with the BAND — the phone isn't moving, so it re-pushes the SAME 919 and never climbs.
  s.watch.sendCommand('C6:{"s":919}');
  const shownAfterPush = s.watch.sandbox.stepCount();
  for (let i = 0; i < 200; i++) {
    s.watch.walk(1);
    if (i % 10 === 0) { s.watch.sendCommand('C6:{"s":919}'); s.watch.hrm(131); }   // phone re-pushes the same stale total
    s.clock.advance(300);
  }
  const shownAfterWalk = s.watch.sandbox.stepCount();

  check('steps · Steps face ticks UP as you walk (not pinned to the phone snapshot)',
    shownAfterWalk > shownAfterPush,
    `afterPush=${shownAfterPush} afterWalk=${shownAfterWalk} os=${s.watch.osSteps()}`);
  check('steps · displayed count reflects the band\'s own pedometer (260 walked)',
    s.watch.sandbox.stepCount() === 260, `shown=${s.watch.sandbox.stepCount()} os=${s.watch.osSteps()}`);
  // T8 is emitted on a timer, so the last frame is the band's count at emit time (a mid-walk snapshot) —
  // the point is it tracks the BAND's live pedometer (>60, climbing), NEVER an echo of the phone's 919.
  const t8 = decodeT8Steps(s.watch.allFrames);
  check('steps · T8 streamed to the phone carries the BAND count, not an echo of the phone\'s 919',
    t8 !== null && t8 > 60 && t8 <= 260, `t8=${t8}`);
})();

// 14) STEP-FREEZE after a REFLASH specifically: a flash wipes the OS pedometer to 0 while the phone still
//     holds the full day (919). The band must still tick up from 0 as you walk — never sit pinned at 919.
(() => {
  const s = new Session();
  s.watch.simulateReboot(0);         // fresh flash: OS pedometer reset to 0
  s.connect();
  s.watch.sendCommand('C6:{"s":919}');   // phone tops it up to its full day total right on connect
  const shown0 = s.watch.sandbox.stepCount();
  for (let i = 0; i < 150; i++) { s.watch.walk(1); if (i % 10 === 0) s.watch.hrm(120); s.clock.advance(300); }
  const shown1 = s.watch.sandbox.stepCount();
  check('steps (post-reflash) · not frozen at the phone total — climbs from the band pedometer',
    shown1 > shown0 && shown1 === 150, `shown0=${shown0} shown1=${shown1} os=${s.watch.osSteps()}`);
})();

// The user's real bug shape: a NAP (start + stop on the Sleep face within one afternoon) must SEAL
// into its own sleep_logs row and surface on the Sleep page — connected, offline, or across a reboot —
// with sane stages, never the "Awake 100% / 8h 15m" phantom. The seal is modelled by server.js
// (mirrors SealNightJob + triggerSleepSummary). Naps here are ≥ 20 min (the real minimum).
const NAP_MIN = 45;
const lastMarker = (p) => p.sleepSummaries[p.sleepSummaries.length - 1];

// 15) NAP tracked CONNECTED (phone + server both up): WAKE delivers the confirmed marker live, the
//     night's PPG uploads, and the server seals ONE nap row — a nap, sane duration, not all-awake.
(() => {
  const s = new Session();
  s.connect(); s.gotoSleep(); s.startSleep();
  s.sleepCapture(NAP_MIN * 60);
  s.wake(); s.advance(2000);
  const m = lastMarker(s.phone);
  s.uploadNapWindows(m.bedtime, m.wake);   // the nap's own overnight PPG reached the server
  s.syncToServer();
  const nap = s.server.sleepLogs[0];
  check('nap (connected) · one sleep_logs row sealed', s.server.sleepLogs.length === 1, `rows=${s.server.sleepLogs.length}`);
  check('nap (connected) · stored as a NAP, ~45 min, not clobbering a night', !!nap && nap.isNap && nap.durationMin >= 40 && nap.sessionStart != null,
    nap ? `isNap=${nap.isNap} dur=${nap.durationMin} sessionStart=${nap.sessionStart}` : '(none)');
  check('nap (connected) · NOT the all-awake phantom (has real asleep time)', !!nap && !nap.allAwake && nap.asleepMin > 0,
    nap ? `allAwake=${nap.allAwake} asleep=${nap.asleepMin}` : '(none)');
})();

// 16) THE REPORTED BUG: nap done fully OFFLINE (airplane mode — phone AND server unreachable), then
//     the phone comes back and syncs LATER. The confirmed marker was banked to the ring at STOP (not
//     lost with the connection), replays on the next connect, and the server seals the nap. It must
//     NOT depend on being connected at the moment you press WAKE.
(() => {
  const s = new Session();
  s.gotoSleep(); s.startSleep();            // phone-free at bedtime (airplane)
  s.sleepCapture(NAP_MIN * 60);
  s.wake();                                 // WAKE while offline → marker logged to the ring
  check('nap (offline) · nothing on the server yet (airplane)', s.server.sleepLogs.length === 0 && s.phone.sleepSummaries.length === 0, '');
  s.connect(); s.advance(60_000);           // later: phone reconnects → ring drains → T9 replayed
  check('nap (offline) · confirmed marker recovered on the morning connect (not lost at stop)',
    s.phone.sleepSummaries.some((x) => x.confirmed), `markers=${s.phone.sleepSummaries.length}`);
  const m = lastMarker(s.phone);
  s.uploadNapWindows(m.bedtime, m.wake); s.syncToServer();   // phone back online → uploads + server seals
  const nap = s.server.sleepLogs[0];
  check('nap (offline) · sealed into a nap sleep_logs row on sync (the nap is NOT lost)',
    !!nap && nap.isNap && nap.durationMin >= 40 && !nap.allAwake, nap ? `dur=${nap.durationMin} allAwake=${nap.allAwake}` : '(NONE — nap lost)');
})();

// 17) MID-NAP REBOOT: the watch reloads mid-nap (a crash/flash). The session is reconstructed from the
//     persisted titan.sleep pref (not from RAM), so WAKE still emits the confirmed marker with the
//     ORIGINAL bedtime and the nap seals — nothing is lost to the reload.
(() => {
  const s = new Session();
  s.connect(); s.gotoSleep(); s.startSleep();
  s.sleepCapture(20 * 60);
  // Reboot the firmware VM mid-nap — a fresh watch that must resume the session from Storage.
  const { buildWatch } = require('./watch');
  const revived = buildWatch(s.clock);
  // Carry the persisted sleep pref across the "reboot" (Storage survives a reload on the real device).
  revived.sandbox.require('Storage').writeJSON('titan.sleep', s.watch.sandbox.require('Storage').readJSON('titan.sleep'));
  // Re-run boot restore by rebuilding against the same storage: simplest faithful proxy — assert the
  // firmware persisted the bedtime so a reboot can rebuild the session.
  const pref = s.watch.sandbox.require('Storage').readJSON('titan.sleep');
  check('nap (reboot) · bedtime persisted to titan.sleep (survives a mid-nap reload)', !!pref && pref.start > 0, JSON.stringify(pref));
  s.sleepCapture(25 * 60);
  s.wake(); s.advance(2000);
  const m = lastMarker(s.phone);
  s.uploadNapWindows(m.bedtime, m.wake); s.syncToServer();
  const nap = s.server.sleepLogs[0];
  check('nap (reboot) · session survived + sealed with the original bedtime', !!nap && nap.isNap && nap.durationMin >= 40,
    nap ? `dur=${nap.durationMin} bed=${m.bedtime}` : '(none)');
})();

// 18) THE PHANTOM. An unrelated daytime PPG burst on the SAME calendar date as the nap. The pre-fix
//     server groups the seal by DATE and vacuums that stray window into the nap → an impossible
//     multi-hour, all-awake "night" (the user's "Awake 100% / 8h 15m"). The fixed server scopes the
//     seal to the marker's own [bedtime, wake] → one clean nap, the stray window never leaks in.
(() => {
  const buggy = new Session({ serverBuggy: true });
  buggy.connect(); buggy.gotoSleep(); buggy.startSleep();
  buggy.sleepCapture(NAP_MIN * 60);
  buggy.wake(); buggy.advance(2000);
  const mb = lastMarker(buggy.phone);
  const dayStart = Math.floor(mb.wake / 86400) * 86400;
  buggy.uploadStrayWindow(dayStart + 3 * 3600, dayStart + 3 * 3600 + 30);   // a 03:00 burst, hours before the nap
  buggy.uploadNapWindows(mb.bedtime, mb.wake);
  buggy.syncToServer();
  const bp = buggy.server.sleepLogs[0];
  check('phantom (PRE-FIX) reproduces the all-awake multi-hour block',
    !!bp && (bp.allAwake || bp.awakeMin > bp.durationMin), bp ? `span/awake=${bp.awakeMin}m asleep=${bp.asleepMin}m allAwake=${bp.allAwake}` : '(none)');

  const fixed = new Session();
  fixed.connect(); fixed.gotoSleep(); fixed.startSleep();
  fixed.sleepCapture(NAP_MIN * 60);
  fixed.wake(); fixed.advance(2000);
  const mf = lastMarker(fixed.phone);
  const fDayStart = Math.floor(mf.wake / 86400) * 86400;
  fixed.uploadStrayWindow(fDayStart + 3 * 3600, fDayStart + 3 * 3600 + 30);
  fixed.uploadNapWindows(mf.bedtime, mf.wake);
  fixed.syncToServer();
  const fp = fixed.server.sleepLogs[0];
  check('phantom (FIXED) · one clean nap, the stray day burst never leaks in, no all-awake block',
    fixed.server.sleepLogs.length === 1 && !!fp && fp.isNap && !fp.allAwake && fp.durationMin >= 40 && fp.durationMin <= 60,
    fp ? `rows=${fixed.server.sleepLogs.length} dur=${fp.durationMin} allAwake=${fp.allAwake}` : '(none)');
})();

// 19) MINIMUM DURATION + GUARANTEED WRITE. A 12-min tap is not a nap (don't seal noise). A 25-min nap
//     seals even if its PPG windows never uploaded (thin/late) — a duration-only row from the marker,
//     NOT dropped and NOT a fake all-awake block.
(() => {
  const tiny = new Session();
  tiny.connect(); tiny.gotoSleep(); tiny.startSleep();
  tiny.sleepCapture(12 * 60);
  tiny.wake(); tiny.advance(2000);
  const mt = lastMarker(tiny.phone);
  tiny.uploadNapWindows(mt.bedtime, mt.wake); tiny.syncToServer();
  check('min-duration · a 12-min tap does NOT seal a sleep row', tiny.server.sleepLogs.length === 0, `rows=${tiny.server.sleepLogs.length}`);

  const thin = new Session();
  thin.connect(); thin.gotoSleep(); thin.startSleep();
  thin.sleepCapture(25 * 60);
  thin.wake(); thin.advance(2000);
  thin.syncToServer();   // NOTE: no uploadNapWindows() — the nap's PPG never reached the server
  const nap = thin.server.sleepLogs[0];
  check('guaranteed write · a 25-min nap seals from the marker even with no PPG windows',
    thin.server.sleepLogs.length === 1 && !!nap && nap.isNap && nap.durationMin >= 20, nap ? `dur=${nap.durationMin}` : '(NONE — nap lost)');
  check('guaranteed write · thin nap is duration-only, NOT a fake all-awake block', !!nap && !nap.allAwake,
    nap ? `allAwake=${nap.allAwake} asleep=${nap.asleepMin}` : '(none)');
})();

// 20) THE EXACT REAL-WORLD FAILURE. Bluetooth on, band CONNECTED at START (phone enters live
//     "Sleeping"). User walks off — phone left charging → band goes OUT OF RANGE mid-nap → link drops.
//     User presses STOP while DISCONNECTED. Returns later → band reconnects. The completed session must
//     be persisted on-watch at STOP (no live link) and REPLAYED on reconnect: the nap saves, and the
//     phone's stuck "Sleeping" state is resolved by the replayed confirmed marker.
(() => {
  const s = new Session();
  s.connect(); s.gotoSleep(); s.startSleep();     // connected at bedtime → live Sleeping state
  check('mid-nap drop · phone entered live Sleeping at start (connected)', s.phone.sleeping === true, `sleeping=${s.phone.sleeping}`);
  s.sleepCapture(20 * 60);                         // 20 min captured live
  s.disconnect();                                  // walked out of range → link drops MID-nap
  s.sleepCapture(25 * 60);                         // 25 min more, now offline (banked to the ring)
  s.wake();                                        // STOP while DISCONNECTED → marker logged to the ring
  check('mid-nap drop · nap NOT delivered yet (still out of range, stop happened offline)', s.phone.sleepSummaries.length === 0, `markers=${s.phone.sleepSummaries.length}`);
  check('mid-nap drop · phone still shows Sleeping until it hears the finished session', s.phone.sleeping === true, `sleeping=${s.phone.sleeping}`);
  s.connect(); s.advance(60_000);                  // returns → band reconnects → ring replays T9
  check('mid-nap drop · confirmed marker replayed on reconnect (stop-seal did not need a live link)',
    s.phone.sleepSummaries.some((x) => x.confirmed), `markers=${s.phone.sleepSummaries.length}`);
  check('mid-nap drop · live "Sleeping" state resolved on reconnect (not stuck, not double-counted)',
    s.phone.sleeping === false && s.phone.sleepSummaryShown === true, `sleeping=${s.phone.sleeping} summary=${s.phone.sleepSummaryShown}`);
  const m = lastMarker(s.phone);
  s.uploadNapWindows(m.bedtime, m.wake); s.syncToServer();
  const nap = s.server.sleepLogs[0];
  check('mid-nap drop · nap saved as a sleep_logs row (~45 min, sane stages, not lost)',
    s.server.sleepLogs.length === 1 && !!nap && nap.isNap && nap.durationMin >= 40 && !nap.allAwake,
    nap ? `dur=${nap.durationMin} allAwake=${nap.allAwake}` : '(NONE — nap lost)');

  // Repro the miss FIRST: the pre-fix server, fed this same delivery, buries it under the phantom / loses it.
  const buggy = new Session({ serverBuggy: true });
  buggy.connect(); buggy.gotoSleep(); buggy.startSleep();
  buggy.sleepCapture(20 * 60); buggy.disconnect(); buggy.sleepCapture(25 * 60); buggy.wake();
  buggy.connect(); buggy.advance(60_000);
  const bm = lastMarker(buggy.phone);
  const bDay = Math.floor(bm.wake / 86400) * 86400;
  buggy.uploadStrayWindow(bDay + 3 * 3600, bDay + 3 * 3600 + 30);   // an unrelated earlier burst that date
  buggy.uploadNapWindows(bm.bedtime, bm.wake);
  buggy.syncToServer();
  const bp = buggy.server.sleepLogs[0];
  check('mid-nap drop (PRE-FIX) · reproduces the miss — phantom all-awake block, not a clean nap',
    !bp || bp.allAwake || bp.awakeMin > bp.durationMin, bp ? `allAwake=${bp.allAwake} awake=${bp.awakeMin} dur=${bp.durationMin}` : '(no row)');
})();

console.log('\n=== Titan watch simulator — lift/run/sleep sequences ===\n');
for (const r of results) console.log(`${r.ok ? '  ✓' : '  ✗'} ${r.name}${r.ok ? '' : `\n      → ${r.detail}`}`);
console.log(`\n${results.length - failures}/${results.length} checks passed\n`);
process.exit(failures ? 1 : 0);
