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

const STOPWATCH_PAGE = 3, SLEEP_PAGE = 4, RUN_PAGE = 5, LIFT_PAGE = 6;

class Session {
  constructor(opts = {}) {
    this.clock = new VirtualClock();
    this.watch = buildWatch(this.clock);
    this.phone = new Phone(this.clock, opts);
    this.dropTAEnd = !!opts.dropTAEnd;   // simulate the "workout over" frame + retries never arriving
    this.watch.onDeliver((line) => {
      if (!this.phone.connected) return;
      if (this.dropTAEnd && line.indexOf('"k":"end"') >= 0) return;   // swallow TA:{"k":"end"}
      this.phone.ingest(line);
    });
  }
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

// 10) A SLEEP session tracked live: start on the Stopwatch face, sleep, wake → the confirmed T9 marker
//     reaches the phone (which the server seals into the night + fires the morning summary).
(() => {
  const s = new Session();
  s.connect(); s.gotoSleep(); s.startSleep(); s.advance(120_000); s.wake(); s.advance(2000);
  const sleep = s.phone.sleepSummaries[0];
  check('sleep (live) · confirmed T9 marker received', !!sleep && sleep.confirmed, `count=${s.phone.sleepSummaries.length}`);
  check('sleep (live) · wake is after bedtime', !!sleep && sleep.wake > sleep.bedtime, sleep ? `bed=${sleep.bedtime} wake=${sleep.wake}` : '(none)');
})();

// 11) SLEEP tracked OFFLINE (the realistic case — phone on the nightstand, link released): the wake
//     marker is logged to the ring overnight and delivered on the morning sync.
(() => {
  const s = new Session();
  s.gotoSleep(); s.startSleep(); s.advance(180_000); s.wake();   // whole night offline
  s.connect(); s.advance(10_000);                                    // morning: sync → ring flush → T9
  const sleep = s.phone.sleepSummaries[0];
  check('sleep (offline) · confirmed T9 recovered on morning sync', !!sleep && sleep.confirmed, `count=${s.phone.sleepSummaries.length}`);
})();

console.log('\n=== Titan watch simulator — lift/run/sleep sequences ===\n');
for (const r of results) console.log(`${r.ok ? '  ✓' : '  ✗'} ${r.name}${r.ok ? '' : `\n      → ${r.detail}`}`);
console.log(`\n${results.length - failures}/${results.length} checks passed\n`);
process.exit(failures ? 1 : 0);
