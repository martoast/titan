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

const LIFT_PAGE = 6, RUN_PAGE = 5;

class Session {
  constructor(opts = {}) {
    this.clock = new VirtualClock();
    this.watch = buildWatch(this.clock);
    this.phone = new Phone(this.clock, opts);
    this.watch.onDeliver((line) => { if (this.phone.connected) this.phone.ingest(line); });
  }
  connect() { this.watch.connect(); this.phone.onConnect(); this.clock.advance(2000); }
  disconnect() { this.watch.disconnect(); this.phone.onDisconnect(); }
  advance(ms) { this.clock.advance(ms); }
  gotoLift() { this.watch.swipeRight(LIFT_PAGE); }
  gotoRun() { this.watch.swipeRight(RUN_PAGE); }
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

// 6) DOCUMENTED GAP (not yet fixed): a lift started while the phone link was released/offline logs to
//    the ring; on the next sync it replays as backlog (old timestamps) → no live sheet, no instant
//    summary, only a delayed ended=false seal. Asserted here so the gap is visible + tracked.
(() => {
  const s = new Session({ endOnDisconnect: true });
  s.gotoLift(); s.tapButton(); s.work(60, 130);  // never connected
  s.tapButton(); s.connect(); s.advance(3000);   // now sync → ring flush replays old frames
  check('offline-started lift · KNOWN GAP: no instant summary (backlog only)', strengthSummaries(s.phone) === 0,
    `summaries=${s.phone.summaries.length} (expected 0 until we add a retro-summary for synced workouts)`);
})();

console.log('\n=== Titan watch simulator — lift/run sequences ===\n');
for (const r of results) console.log(`${r.ok ? '  ✓' : '  ✗'} ${r.name}${r.ok ? '' : `\n      → ${r.detail}`}`);
console.log(`\n${results.length - failures}/${results.length} checks passed\n`);
process.exit(failures ? 1 : 0);
