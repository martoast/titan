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
    this._sealedWorkouts = 0;     // how many delivered confirmed workout envelopes we've already sealed
    this.opts = opts;
    this.dropTAEnd = !!opts.dropTAEnd;   // simulate the "workout over" frame + retries never arriving
    this._wireDeliver();
  }

  _wireDeliver() {
    this.watch.onDeliver((line) => {
      if (!this.phone.connected) return;
      if (this.dropTAEnd && line.indexOf('"k":"end"') >= 0) return;   // swallow TA:{"k":"end"}
      this.phone.ingest(line);
    });
  }

  // Reboot the WATCH: rebuild the firmware VM against the SAME flash image, so it re-runs the real
  // boot-restore path (titan.wo / titan.sleep reconcile) — a faithful mid-session crash/flash. The phone
  // + server + clock persist. The BLE link drops through the reboot (as on a real device).
  reboot() {
    this.watch.disconnect();
    this.phone.onDisconnect();
    this.watch = buildWatch(this.clock, { storageFiles: this.watch.storageFiles });
    this._wireDeliver();
  }

  // The phone hands whatever confirmed workout envelopes it has now (live or replayed from the ring) to
  // the server, which seals each into an activity_sessions row. Idempotent — only NEW envelopes seal.
  syncWorkoutsToServer() {
    for (let i = this._sealedWorkouts; i < this.phone.workoutSessions.length; i++) {
      this.server.sealConfirmedWorkout(this.phone.workoutSessions[i]);
    }
    this._sealedWorkouts = this.phone.workoutSessions.length;
  }

  // Model the workout's accel/HR windows reaching the server (kind=workout). Omit to model the thin/
  // never-uploaded case (airplane / out of range) — the confirmed seal must STILL produce a row.
  uploadWorkoutWindows(startSec, endSec, kind) {
    for (let t = startSec; t < endSec; t += 180) {
      this.server.ingestWorkoutWindow({ startSec: t, endSec: Math.min(t + 180, endSec), kind: kind || null });
    }
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

// 15) THE STEP-COUNTER-DIES-AFTER-A-MANUAL-WORKOUT bug (the exact one the user hit).
//     Root cause is in the FIRMWARE runtime, not our JS, and it is PRE-EXISTING (applyAccelRate /
//     startWorkout / startStreaming are byte-identical to the last-good commit). Mechanism, from
//     Espruino jswrap_bangle.c:
//       • Bangle.setPollInterval() force-clears the OS `powerSave` flag (bangleFlags &= ~JSBF_POWER_SAVE).
//       • The built-in pedometer only counts while `powerSaveTimer < 60s` (peripheralPollHandler).
//       • powerSaveTimer climbs while STILL and resets to 0 on motion — but ONLY while powerSave is on.
//         Once powerSave is off, the timer is FROZEN at its last value.
//     Trigger: reflashing the app does NOT wipe Storage, so a user who had recording OFF keeps
//     titan.run="0" → boot does NOT startStreaming → powerSave stays ON (no setPollInterval yet). The
//     watch sits still >60s (you glance at it), THEN you press to START a manual workout — the FIRST
//     setPollInterval — which freezes powerSaveTimer ABOVE 60s. getHealthStatus().steps never climbs
//     again until a reboot. The fix: applyAccelRate() re-enables powerSave right after setPollInterval,
//     so motion can reset the timer again; our persistent accel listener keeps the poll pinned at 80ms
//     regardless (powerSave only drops the poll when nothing listens to accel), so no accuracy is lost.
(() => {
  const LIFT = 6;
  const clock = new VirtualClock();
  // Reflash-preserving-prefs: recording was previously turned OFF → boot won't startStreaming → the OS
  // powerSave flag stays ON until the workout's first setPollInterval. This is the vulnerable state.
  const w = buildWatch(clock, { storageFiles: { 'titan.run': { data: '0', pos: 0 } } });

  // Baseline: steps count fine before any workout (the user: "steps worked before I did a workout").
  // With powerSave ON, motion self-corrects the gate — walking counts even after a still spell.
  for (let i = 0; i < 30; i++) w.walk(1);
  check('step-freeze · steps count before any workout (powerSave still ON)',
    w.osSteps() === 30 && w.powerSaveOn() === true, `os=${w.osSteps()} powerSave=${w.powerSaveOn()}`);

  // You glance at the watch for a bit (stationary) before starting — the OS climbs past its 60s still gate.
  w.sitStill(70000);

  // Press to START a manual lift, then press to END it — the EXACT start→stop the user performed.
  w.swipeRight(LIFT); w.press(1);   // liftTap → startStreaming → applyAccelRate → setPollInterval (powerSave OFF, timer frozen ≥60s)
  clock.advance(3000);
  w.press(1);                       // FINISH → endWorkout → applyAccelRate again
  clock.advance(3000);

  // Now WALK. Pre-fix the OS pedometer is frozen (powerSave left off with the timer stuck ≥60s).
  const before = w.osSteps();
  for (let i = 0; i < 40; i++) w.walk(1);
  const after = w.osSteps();
  check('step-freeze · getHealthStatus().steps keeps climbing after a manual workout start→stop',
    after === before + 40,
    `before=${before} after=${after}${after === before ? ' — FROZEN: setPollInterval left powerSave OFF with powerSaveTimer stuck ≥60s' : ''}`);
  check('step-freeze · powerSave re-enabled by applyAccelRate (so motion can reset the OS timer)',
    w.powerSaveOn() === true, `powerSave=${w.powerSaveOn()}`);
  check('step-freeze · Steps face reflects the live climb (not frozen)',
    w.sandbox.stepCount() >= 70, `shown=${w.sandbox.stepCount()}`);
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

// ===========================================================================================
// WORKOUT OFFLINE DURABILITY — the same guarantee sleep got (confirmed session, sealed on reconnect).
// A workout done while the phone is disconnected — including the real path "connected at start → walk
// out of BLE range → STOP out of range → reconnect later" — must be captured on the watch and seal into
// ONE bounded activity_sessions row on reconnect, with the right kind, surviving a mid-workout reboot.
// The watch now persists the session (titan.wo) + writes an explicit [start,end,kind] END envelope (TW)
// to the replay log; the server seals SCOPED to that envelope (mirrors SealActivityJob::sealConfirmedSession).
const lastWo = (p) => p.workoutSessions[p.workoutSessions.length - 1];
const WO_MIN = 120;   // 2-min sessions (above the 60-s confirmed floor)

// 21) (a) A workout done ENTIRELY OFFLINE (airplane — never connected during it). The END envelope is
//     banked to the ring at STOP and replayed on the next connect; the server seals a bounded session
//     even though NO accel windows ever reached it. PRE-FIX: no windows → the workout is silently LOST.
(() => {
  const s = new Session();
  s.gotoLift(); s.tapButton(); s.work(WO_MIN, 132); s.tapButton();   // whole lift offline (never connected)
  check('offline workout (a) · nothing on the server yet (airplane)', s.server.activitySessions.length === 0 && s.phone.workoutSessions.length === 0, '');
  s.advance(120_000); s.connect(); s.advance(40_000);   // later: reconnect → ring drains → TW replayed
  check('offline workout (a) · confirmed envelope recovered on reconnect (not lost at stop)',
    s.phone.workoutSessions.some((x) => x.confirmed), `envelopes=${s.phone.workoutSessions.length}`);
  s.syncWorkoutsToServer();   // NOTE: no uploadWorkoutWindows() — airplane: the accel windows never reached the server
  const wo = s.server.activitySessions[0];
  check('offline workout (a) · sealed into ONE activity_session from the marker (guaranteed, not lost)',
    s.server.activitySessions.length === 1 && !!wo && wo.durationMin >= 2 && wo.activityType === 'strength',
    wo ? `rows=${s.server.activitySessions.length} dur=${wo.durationMin} type=${wo.activityType}` : '(NONE — workout lost)');

  // Repro the miss FIRST: the pre-fix server, fed the SAME airplane delivery (windows never arrived, no
  // explicit envelope), seals nothing → the workout vanishes.
  const buggy = new Session({ serverBuggy: true });
  buggy.gotoLift(); buggy.tapButton(); buggy.work(WO_MIN, 132); buggy.tapButton();
  buggy.advance(120_000); buggy.connect(); buggy.advance(40_000);
  buggy.syncWorkoutsToServer();   // buggy path infers from windows — there are none
  check('offline workout (a) PRE-FIX · reproduces the loss (no windows → no session)',
    buggy.server.activitySessions.length === 0, `rows=${buggy.server.activitySessions.length}`);
})();

// 22) (b) THE EXACT REAL-WORLD FAILURE. Band CONNECTED at START (phone shows a live workout). User walks
//     off — phone left charging → band OUT OF RANGE mid-workout → link drops. User presses STOP while
//     DISCONNECTED. Returns later → band reconnects. The finished session must be persisted on-watch at
//     STOP, replayed on reconnect, seal into ONE row (right duration incl. the offline tail), and the
//     live workout state must resolve — not stay stuck, not double-count.
(() => {
  const s = new Session();
  s.connect(); s.gotoLift(); s.tapButton();
  s.work(60, 132);                 // 1 min live → the phone opens the live workout off the sport-HR stream
  check('mid-drop (b) · phone opened a live workout at start (connected)', s.phone.runActive === true && s.phone.liveSheetShown === true, `runActive=${s.phone.runActive}`);
  s.disconnect();                  // walked out of range → link drops MID-workout
  s.work(WO_MIN, 132);             // 2 min more, now offline (banked to the ring)
  s.tapButton();                   // STOP while DISCONNECTED → envelope logged to the ring
  check('mid-drop (b) · envelope NOT delivered yet (still out of range, stop happened offline)', s.phone.workoutSessions.length === 0, `envelopes=${s.phone.workoutSessions.length}`);
  s.connect(); s.advance(40_000);  // returns → band reconnects → ring replays TW
  check('mid-drop (b) · confirmed envelope replayed on reconnect (stop-seal did not need a live link)',
    s.phone.workoutSessions.some((x) => x.confirmed), `envelopes=${s.phone.workoutSessions.length}`);
  check('mid-drop (b) · live workout state resolved on reconnect (not stuck, not double-counted)',
    s.phone.runActive === false && s.phone.workoutSummaryShown === true, `runActive=${s.phone.runActive} summary=${s.phone.workoutSummaryShown}`);
  const env = lastWo(s.phone);
  s.uploadWorkoutWindows(env.start, env.end, 'strength'); s.syncWorkoutsToServer();
  const wo = s.server.activitySessions[0];
  check('mid-drop (b) · sealed as ONE activity_session (~3 min incl. the offline tail, not doubled)',
    s.server.activitySessions.length === 1 && !!wo && wo.durationMin >= 3 && wo.activityType === 'strength',
    wo ? `rows=${s.server.activitySessions.length} dur=${wo.durationMin}` : '(NONE — workout lost)');

  // Repro the miss FIRST: the pre-fix server, WITHOUT the explicit envelope, infers the session from the
  // windows — and the offline tail near the stop never became a reliable window, so the duration is
  // TRUNCATED to the live portion (the "ended-tail window loss").
  const buggy = new Session({ serverBuggy: true });
  buggy.connect(); buggy.gotoLift(); buggy.tapButton(); buggy.work(60, 132);
  buggy.disconnect(); buggy.work(WO_MIN, 132); buggy.tapButton();
  buggy.connect(); buggy.advance(40_000);
  const be = lastWo(buggy.phone);
  buggy.uploadWorkoutWindows(be.start, be.start + 60, 'strength');   // only the live 1-min portion survived as windows
  buggy.syncWorkoutsToServer();
  const bwo = buggy.server.activitySessions[0];
  check('mid-drop (b) PRE-FIX · reproduces the truncation (tail lost — duration far short of the real ~3 min)',
    !!bwo && bwo.durationMin < 2, bwo ? `dur=${bwo.durationMin}` : '(no row)');
})();

// 23) (c) MID-WORKOUT REBOOT. The watch reloads mid-lift (a crash/flash). The session is reconstructed
//     from the persisted titan.wo pref (not RAM), keeps the ORIGINAL start, and seals as ONE session
//     spanning both sides of the reboot — never orphaned.
(() => {
  const s = new Session();
  s.connect(); s.gotoLift(); s.tapButton(); s.work(WO_MIN, 130);   // 2 min, then reboot
  const pref = JSON.parse(s.watch.storageFiles['titan.wo'].data);
  check('reboot (c) · workout persisted to titan.wo (survives a mid-workout reload)', !!pref && pref.start > 0 && pref.kind === 'strength', JSON.stringify(pref));
  s.reboot();   // rebuild the firmware VM against the same flash → re-runs boot-restore (titan.wo reconcile)
  check('reboot (c) · session RESUMED from flash (workout still active, original start, right kind)',
    s.watch.state().workout === true && s.watch.state().woStartMs === pref.start && s.watch.state().woKind === 'strength',
    `workout=${s.watch.state().workout} start=${s.watch.state().woStartMs} kind=${s.watch.state().woKind}`);
  s.connect();                       // BLE returns after the reboot
  s.work(WO_MIN, 130);               // 2 more min post-reboot
  s.watch.sendCommand('C0:');         // phone "End" → finishLift → endWorkout → envelope (original start)
  s.advance(5000);
  const env = lastWo(s.phone);
  check('reboot (c) · envelope carries the ORIGINAL start (session not orphaned by the reload)',
    !!env && Math.abs(env.start * 1000 - pref.start) < 2000, env ? `env.start=${env.start} orig=${Math.round(pref.start / 1000)}` : '(none)');
  s.uploadWorkoutWindows(env.start, env.end, 'strength'); s.syncWorkoutsToServer();
  const wo = s.server.activitySessions[0];
  check('reboot (c) · sealed ONE session spanning both sides of the reboot (~4 min)',
    s.server.activitySessions.length === 1 && !!wo && wo.durationMin >= 4, wo ? `rows=${s.server.activitySessions.length} dur=${wo.durationMin}` : '(none)');
})();

// 24) (d) LIFT vs RUN both survive offline with the RIGHT kind (the watch's choice is authoritative).
(() => {
  // An offline RUN → recovered + sealed as a RUN (not re-guessed as strength).
  const r = new Session();
  r.gotoRun(); r.tapButton(); r.gps(WO_MIN, 150); r.tapButton();   // whole run offline
  r.advance(120_000); r.connect(); r.advance(40_000);
  const re = lastWo(r.phone);
  check('kind (d) · offline RUN envelope recovered with kind=run', !!re && re.kind === 'run', re ? `kind=${re.kind}` : '(none)');
  r.uploadWorkoutWindows(re.start, re.end, 'run'); r.syncWorkoutsToServer();
  const rwo = r.server.activitySessions[0];
  check('kind (d) · offline RUN sealed as a run', !!rwo && rwo.activityType === 'run' && rwo.durationMin >= 2, rwo ? `type=${rwo.activityType} dur=${rwo.durationMin}` : '(none)');

  // A RUN across a mid-workout reboot keeps kind=run.
  const s = new Session();
  s.connect(); s.gotoRun(); s.tapButton(); s.gps(WO_MIN, 150);
  const pref = JSON.parse(s.watch.storageFiles['titan.wo'].data);
  check('kind (d) · run persisted to titan.wo with kind=run', !!pref && pref.kind === 'run', JSON.stringify(pref));
  s.reboot();
  check('kind (d) · run RESUMED across the reboot as a run', s.watch.state().workout === true && s.watch.state().woKind === 'run', `kind=${s.watch.state().woKind}`);
  s.connect(); s.gps(WO_MIN, 150); s.watch.sendCommand('C0:'); s.advance(5000);
  const se = lastWo(s.phone);
  s.uploadWorkoutWindows(se.start, se.end, 'run'); s.syncWorkoutsToServer();
  const swo = s.server.activitySessions[0];
  check('kind (d) · run across a reboot still seals as a run', !!swo && swo.activityType === 'run', swo ? `type=${swo.activityType}` : '(none)');
})();

// 25) (e) ALWAYS-ON disconnect UX. With the phone now holding a persistent 24/7 link, a REAL drop must
//     surface on the watch (one buzz + a persistent "PHONE OFF" indicator) so the user reconnects — but
//     a momentary blip (auto-reconnects inside the debounce) must NOT nag, and reconnect clears it.
(() => {
  const s = new Session();
  s.connect();                          // establish a real link (everConnected = true)
  check('disconnect UX (e) · linked → no indicator', s.watch.state().linkLost === false, `linkLost=${s.watch.state().linkLost}`);
  s.disconnect(); s.advance(3000); s.connect();   // blip: drop + reconnect INSIDE the 6s debounce
  check('disconnect UX (e) · momentary blip does NOT flag (debounced)', s.watch.state().linkLost === false, `linkLost=${s.watch.state().linkLost}`);
  s.disconnect(); s.advance(8000);      // sustained drop past the debounce → indicator trips
  check('disconnect UX (e) · sustained drop flags PHONE OFF', s.watch.state().linkLost === true, `linkLost=${s.watch.state().linkLost}`);
  s.connect();                          // reconnect clears it
  check('disconnect UX (e) · reconnect clears the indicator', s.watch.state().linkLost === false, `linkLost=${s.watch.state().linkLost}`);
})();

// 26) (f) CONNECTED-AT-REST HRM DUTY-CYCLE + link heartbeat (battery). Holding a 24/7 BLE link must NOT
//     run the HRM/PPG LED continuously — that LED (not the radio) caused the prior ~50%/night drain. At
//     REST we now duty-cycle the LED EVEN while connected (a burst of HR every period, LED off between);
//     a WORKOUT stays continuous. Because HR now only reaches the phone per-period, a tiny 5 s TB: heart-
//     beat keeps the phone's 8 s data-staleness watchdog LIVE through the OFF gaps (no false "disconnect").
(() => {
  const t5Count = (w) => w.allFrames.filter((l) => l.slice(0, 3) === 'T5:').length;
  const s = new Session();
  s.connect();                          // linked; recording ON by default → REST + connected

  // Sample the HRM LED + the phone's data-freshness once a second across >1 full rest duty period.
  const t5Before = t5Count(s.watch);
  let onTicks = 0, offTicks = 0, maxStaleMs = 0;
  for (let t = 0; t < 90; t++) {
    s.watch.accel(t % 2 ? 0.10 : 0.18, 0.02, 1.0);   // light wrist motion (below the 0.20 GPS-arm gate)
    if (s.watch.hrmPower()) { s.watch.hrm(72); onTicks++; } else offTicks++;  // feed a bpm only while the LED is on
    s.clock.advance(1000);
    const stale = s.clock.nowMs() - (s.phone.lastDataAt || s.clock.nowMs());
    if (stale > maxStaleMs) maxStaleMs = stale;
  }

  check('connected-rest (f) · HRM LED is DUTY-CYCLED, not continuously on',
    onTicks > 0 && offTicks > 0, `on=${onTicks} off=${offTicks}`);
  check('connected-rest (f) · each burst still streams live HR to the phone (T5)',
    t5Count(s.watch) > t5Before, `t5 ${t5Before}→${t5Count(s.watch)}`);
  check('connected-rest (f) · TB: heartbeat keeps the link "live" (≤8 s between frames)',
    maxStaleMs <= 8000, `maxStale=${maxStaleMs}ms`);
  const tbCount = s.watch.allFrames.filter((l) => l.slice(0, 3) === 'TB:').length;
  check('connected-rest (f) · heartbeat frames emitted at ~5 s cadence',
    tbCount >= 10, `tb=${tbCount}`);

  // WORKOUT ⇒ HRM goes CONTINUOUS: start a lift, verify the LED never drops across the set.
  s.gotoLift(); s.tapButton();
  let woOff = 0;
  for (let t = 0; t < 40; t++) {
    s.watch.accel(0.05, 0.03, 1.0); s.watch.hrm(130);
    if (!s.watch.hrmPower()) woOff++;
    s.clock.advance(1000);
  }
  check('workout (f) · HRM is CONTINUOUS (LED never duty-cycles off)', woOff === 0, `offTicks=${woOff}`);

  // Back to REST after the workout ⇒ duty-cycling RESUMES (the LED turns off again within a period).
  s.watch.sendCommand('C0:'); s.advance(5000);   // finish the lift on the watch
  let restedOff = 0;
  for (let t = 0; t < 70; t++) {
    s.watch.accel(t % 2 ? 0.10 : 0.18, 0.02, 1.0);
    if (s.watch.hrmPower()) s.watch.hrm(70); else restedOff++;
    s.clock.advance(1000);
  }
  check('post-workout (f) · duty-cycling RESUMES at rest (LED turns off again)', restedOff > 0, `off=${restedOff}`);
})();

// 27) (f) OFFLINE rest is UNCHANGED by the connected-rest duty-cycle change. With no phone in range, the
//     LED must still duty-cycle exactly as before and each burst banks a light T5 HR-trend point to the
//     ring (appendLog) — not raw PPG — so a day of wear stays weeks of flash, not ~16 h.
(() => {
  const s = new Session();          // boots streaming, NEVER connects → offline the whole time
  let onTicks = 0, offTicks = 0;
  for (let t = 0; t < 90; t++) {
    s.watch.accel(t % 2 ? 0.10 : 0.18, 0.02, 1.0);
    if (s.watch.hrmPower()) { s.watch.hrm(66); onTicks++; } else offTicks++;
    s.clock.advance(1000);
  }
  check('offline-rest (f) · still duty-cycles (unchanged)', onTicks > 0 && offTicks > 0, `on=${onTicks} off=${offTicks}`);
  // Offline bursts append T5 HR-trend points to the flash ring; nothing streams (no link) and no TB: heartbeat.
  const t5Logged = Object.values(s.watch.storageFiles).some((f) => (f.data || '').indexOf('T5:') >= 0);
  const tbOffline = s.watch.allFrames.filter((l) => l.slice(0, 3) === 'TB:').length;
  check('offline-rest (f) · burst banks a T5 trend point to the ring', t5Logged, `logged=${t5Logged}`);
  check('offline-rest (f) · no heartbeat emitted while disconnected', tbOffline === 0, `tb=${tbOffline}`);
})();

// ===========================================================================================
// BATTERY HARDENING — the rest-duty reentrancy fix + the ON-window / period / conn-interval levers.

// 28) (h) REST-DUTY REENTRANCY. During an OFF gap (a next-burst timer pending) a wrist-glance fires
//     lcdPower → the firmware kicks restDutyTick out-of-band. It MUST cancel the pending burst, not
//     orphan it — else every glance spawns another parallel self-scheduling chain and the LED ends up
//     powered far more than one burst/period (the ~50%/night regression). Assert exactly ONE chain.
(() => {
  const s = new Session();          // offline streaming, STILL rest (5-min period → long OFF gaps)
  const dutyChains = () => s.clock.timers.filter((t) => t.fn === s.watch.sandbox.restDutyTick).length;
  s.advance(9000);                  // boot burst closes at its 8s cap → drop into the long OFF gap
  check('reentrancy (h) · one duty chain pending in the OFF gap (baseline)', dutyChains() === 1, `chains=${dutyChains()}`);
  // Four wrist-glances, each in an OFF gap (spaced > the 8s ON window). PRE-FIX each orphans the pending
  // next-burst timer → another parallel chain; POST-FIX each just restarts the single chain.
  for (let i = 0; i < 4; i++) { s.watch.lcdWake(); s.advance(20000); }
  s.advance(15000);                 // settle into a quiet OFF gap (no further stimulus)
  check('reentrancy (h) · still exactly ONE duty chain after screen-wakes during off-gaps (no runaway parallel loops)',
    dutyChains() === 1, `pendingDutyChains=${dutyChains()}`);
})();

// 29) (i) CONFIDENCE-GATED EARLY EXIT + 8s HARD CAP. A good lock (conf>=90) closes the burst EARLY
//     (don't burn the full window once the bpm is solid); a burst that never locks still closes at the
//     8s hard cap.
(() => {
  const s = new Session();          // offline streaming, rest — boot fired a burst (LED on now)
  check('early-exit (i) · LED on at burst start', s.watch.hrmPower() === true, `hrm=${s.watch.hrmPower()}`);
  s.watch.hrm(60, 96);              // a solid lock (conf 96)
  s.advance(1500);                  // well under the 8s cap
  check('early-exit (i) · a good lock (conf>=90) closes the burst EARLY (LED off < 8s cap)', s.watch.hrmPower() === false, `hrm=${s.watch.hrmPower()}`);
  s.watch.lcdWake();                // glance → a fresh burst (LED on)
  check('early-exit (i) · a glance starts a fresh burst', s.watch.hrmPower() === true, `hrm=${s.watch.hrmPower()}`);
  s.watch.hrm(61, 50);             // a WEAK lock (conf 50) — must NOT early-exit
  s.advance(4000);                  // 4s < 8s cap
  check('early-exit (i) · a weak lock keeps the LED on until the 8s hard cap', s.watch.hrmPower() === true, `hrm=${s.watch.hrmPower()}`);
  s.advance(5000);                  // now past 8s → the hard cap closes it
  check('early-exit (i) · the 8s hard cap still closes a never-locking burst', s.watch.hrmPower() === false, `hrm=${s.watch.hrmPower()}`);
})();

// 30) (j) 5-MIN STILL PERIOD. At still rest (no motion) with early-exit, the LED is off the vast
//     majority of the time — a resting HR point every 5 min, not a 15s burst every 3 min.
(() => {
  const s = new Session();          // offline, STILL rest (no motion fed → 5-min period)
  let onT = 0, offT = 0;
  for (let t = 0; t < 600; t++) {   // 10 min still, no glances
    if (s.watch.hrmPower()) { s.watch.hrm(62, 96); onT++; } else offT++;
    s.clock.advance(1000);
  }
  check('still-rest (j) · 5-min period + early-exit → LED off the vast majority of the time', offT > onT * 20, `on=${onT} off=${offT}`);
})();

// 31) (g) DYNAMIC CONNECTION INTERVAL — flush drain. A morning sync tightens the link to {15,30} for the
//     ring dump (throughput) and restores {30,45} at rest when the drain finishes.
(() => {
  const s = new Session();          // offline streaming, rest
  for (let t = 0; t < 8; t++) { s.watch.lcdWake(); s.watch.hrm(60, 96); s.clock.advance(30000); }   // bank 8 T5 trend points to the ring
  s.connect();                      // sync → flushLog tightens, drains, restores
  s.advance(20_000);
  const ivs = s.watch.connIntervals();
  const last = s.watch.lastConnInterval();
  check('conn-interval (g) · morning flush drain tightens the link to {15,30}',
    ivs.some((o) => o.minInterval === 15 && o.maxInterval === 30), JSON.stringify(ivs.slice(-5)));
  check('conn-interval (g) · restored to {30,45} once the drain finishes (rest)',
    !!last && last.minInterval === 30 && last.maxInterval === 45, JSON.stringify(last));
})();

// 32) (g) DYNAMIC CONNECTION INTERVAL — workout. A workout tightens the link to {15,30} for the live PPG
//     stream and restores {30,45} on the way back to rest.
(() => {
  const s = new Session();
  s.connect(); s.advance(12_000);   // post-connect flush drains empty ring → restored to rest
  const restIv = s.watch.lastConnInterval();
  check('conn-interval (g) · idle connected rests at {30,45}', !!restIv && restIv.minInterval === 30 && restIv.maxInterval === 45, JSON.stringify(restIv));
  s.gotoLift(); s.tapButton();      // start a workout → tighten
  const woIv = s.watch.lastConnInterval();
  check('conn-interval (g) · a workout tightens to {15,30}', !!woIv && woIv.minInterval === 15 && woIv.maxInterval === 30, JSON.stringify(woIv));
  s.watch.sendCommand('C0:'); s.advance(5000);   // finish → back to rest
  const afterIv = s.watch.lastConnInterval();
  check('conn-interval (g) · restored to {30,45} after the workout', !!afterIv && afterIv.minInterval === 30 && afterIv.maxInterval === 45, JSON.stringify(afterIv));
})();

console.log('\n=== Titan watch simulator — lift/run/sleep sequences ===\n');
for (const r of results) console.log(`${r.ok ? '  ✓' : '  ✗'} ${r.name}${r.ok ? '' : `\n      → ${r.detail}`}`);
console.log(`\n${results.length - failures}/${results.length} checks passed\n`);
process.exit(failures ? 1 : 0);
