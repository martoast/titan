'use strict';

// A faithful JS mirror of the iOS phone's LIVE workout path — the decode + state machine that turns
// the band's NUS frames into a live workout, a summary, and a sealed `ended` window. It mirrors:
//   • TitanCore/FrameDecoder.swift   (T1/T4/T5/TA decode + the frame-liveness gate)
//   • TitanCore/WorkoutAssembler.swift (open / periodic / flush / build, incl. the ended min-skip)
//   • BandSync/FrameRouter.swift     (route live vs replayed backlog to wa / waLog; TA end/kind)
//   • App/AppModel.swift             (ingestLiveHr sport-edge start/end, endRun, summary gate)
// KEEP IN SYNC with those files. `simulateBug` intentionally reproduces the pre-fix Swift so a
// scenario can prove the sim catches the real regression.

class Reader {
  constructor(b64) { this.b = Buffer.from(b64, 'base64'); }
  get count() { return this.b.length; }
  u8(o) { return this.b[o]; }
  u16(o) { return this.b.readUInt16LE(o); }
  i16(o) { return this.b.readInt16LE(o); }
  u32(o) { return this.b.readUInt32LE(o); }
  u64(o) { return this.b.readUInt32LE(o) + this.b.readUInt32LE(o + 4) * 4294967296; }
}
const decode = {
  T5(p) { const r = new Reader(p); if (r.count < 12) return null; return { t: r.u64(4), bpm: r.u8(1), conf: r.u8(2), sport: r.u8(3) }; },
  T1(p) { const r = new Reader(p); if (r.count < 16) return { samples: [] }; const n = r.u16(2), e = r.u64(4); const s = []; for (let i = 0; i < n; i++) { const o = 16 + i * 12; if (o + 12 > r.count) break; s.push({ t: e + r.u32(o), ax: r.i16(o + 6), ay: r.i16(o + 8), az: r.i16(o + 10) }); } return { samples: s }; },
  T4(p) { const r = new Reader(p); if (r.count < 20) return null; const NONE = -2147483648; let lat = null, lon = null; if (r.count >= 24) { const la = r.i32(16), lo = r.i32(20); if (la !== NONE) lat = la / 1e7; if (lo !== NONE) lon = lo / 1e7; } return { t: r.u64(4), sats: r.u8(1), speedKmh: r.i16(2) / 100, lat, lon }; },
};

const END_GAP_MS = 120_000, FLUSH_MS = 180_000, MIN_MS = 60_000, MAX_WINDOW_MS = 24 * 3600 * 1000;

class WorkoutAssembler {
  constructor() { this.alwaysEnded = false; this.reset(); }
  reset() { this.active = false; this.accel = []; this.hr = []; this.gps = []; this.lastT = 0; this.winStart = 0; this.activityKind = null; }
  _open(t) { if (!this.active) { this.active = true; this.winStart = t; } }
  _gapFinalize(t) { if (this.active && this.lastT !== 0 && t > this.lastT && t - this.lastT > END_GAP_MS) { const w = this._build(this.lastT); this.reset(); return w; } return null; }
  _periodic(t) { if (!this.active || t < this.winStart || t - this.winStart < FLUSH_MS) return null; const w = this._build(t); this.accel = this.accel.filter(a => a.t >= t); this.hr = this.hr.filter(h => h.t >= t); this.gps = this.gps.filter(g => g.t >= t); this.winStart = (this.gps[0] && this.gps[0].t) || t; return w; }
  addWorkoutHr(h) { if (h.sport <= 0) return null; const e = this._gapFinalize(h.t); this._open(h.t); this.hr.push(h); this.lastT = Math.max(this.lastT, h.t); return e || this._periodic(h.t); }
  addGps(f) { const e = this._gapFinalize(f.t); this._open(f.t); this.gps.push(f); this.lastT = Math.max(this.lastT, f.t); return e || this._periodic(f.t); }
  addAccel(samples) { if (!this.active) return; for (const s of samples) this.accel.push(s); }
  tick(now) { return this._gapFinalize(now); }
  flush(ended = false) { if (!this.active) return null; const w = this._build(this.lastT !== 0 ? this.lastT : this.winStart, ended); this.reset(); return w; }
  _build(endT, ended = false) {
    const isEnded = ended || this.alwaysEnded;   // backlog assembler tags every window ended
    if (endT < this.winStart) return null;
    if (!isEnded && (endT - this.winStart < MIN_MS || this.accel.length < 25)) return null; // ended ALWAYS emits (the fix)
    if (endT - this.winStart > MAX_WINDOW_MS) return null;
    const secs = Math.max(1, Math.round((endT - this.winStart) / 1000));
    return { kind: 'workout', startT: this.winStart, endT, durSec: secs, ended: isEnded || null,
      activity_kind: this.activityKind, n_accel: this.accel.length, n_hr: this.hr.length };
  }
}

class Phone {
  constructor(clock, opts = {}) {
    this.clock = clock;
    this.simulateBug = !!opts.simulateBug;   // reproduce pre-fix Swift (endRun leaves lastSportWas1 stuck)
    this.wa = new WorkoutAssembler();
    this.waLog = new WorkoutAssembler();
    this.waLog.alwaysEnded = true;   // recovered offline workout is already finished → seal it `ended`
    this.maxDeviceT = 0;
    // live-run state (AppModel)
    this.runActive = false;
    this.workoutKind = 'run';
    this.lastSportWas1 = false;
    this.runSawSport1 = false;
    this.runSportLostAt = null;
    this.suppressUntil = null;
    this.runStartedAt = null;
    this.runLiveBpm = null;
    this.runMaxBpm = 0;
    // observable outcomes
    this.events = [];              // ordered log
    this.summaries = [];           // each shown post-workout summary
    this.sealed = [];              // windows submitted (from sealWorkout + disconnect flush + periodic)
    this.liveSheetShown = false;
    this.connected = false;
    this._discTimer = null;        // pending disconnect-confirm timer
    this.endOnDisconnect = opts.endOnDisconnect !== false;  // Fix B (on by default; off = pre-fix behavior)
  }
  _now() { return this.clock.nowMs(); }
  _log(e) { this.events.push(e); }
  _frameIsLive(t) { if (!t) return true; const n = this._now(); return n <= t || n - t <= 60_000; }
  _submit(w, why) { if (w) { this.sealed.push({ ...w, why }); this._log(`seal(${why}) kind=${w.activity_kind} ended=${!!w.ended} dur=${w.durSec}s`); } }

  // FrameRouter.ingest — one NUS line.
  ingest(line) {
    const tag = line.slice(0, 3);
    const payload = line.slice(3);
    if (tag === 'T5:') {
      const hr = decode.T5(payload); if (!hr) return;
      this._ingestLiveHr(hr);
      if (this._frameIsLive(hr.t)) this._submit(this.wa.addWorkoutHr(hr), 'periodic');
      else this._submit(this.waLog.addWorkoutHr(hr), 'periodic-log');
    } else if (tag === 'T1:') {
      const f = decode.T1(payload);
      this.wa.addAccel(f.samples);
      const last = f.samples.length ? f.samples[f.samples.length - 1].t : null;
      if (last) { this.maxDeviceT = Math.max(this.maxDeviceT, last); this._submit(this.wa.tick(this.maxDeviceT), 'gap'); this._submit(this.waLog.tick(this.maxDeviceT), 'gap-log'); }
    } else if (tag === 'T4:') {
      const fix = decode.T4(payload); if (!fix) return;
      if (this._frameIsLive(fix.t)) { this._ingestLiveGps(fix); this._submit(this.wa.addGps(fix), 'periodic'); }
      else this._submit(this.waLog.addGps(fix), 'periodic-log');
    } else if (tag === 'TA:') {
      let obj; try { obj = JSON.parse(payload); } catch (e) { return; }
      const k = obj && obj.k;
      if (!k) return;
      if (k === 'end') this._onWorkoutEnd();
      else { this.wa.activityKind = k; this.waLog.activityKind = k; this._setWorkoutKind(k); }
    } else if (tag === 'TS:') {
      // Offline ring fully drained → seal whatever workout we recovered from the backlog now.
      this._submit(this.waLog.flush(), 'backlog-synced');
      this.backlogSynced = (this.backlogSynced || 0) + 1;   // AppModel.checkForSyncedWorkout() fires here
    }
  }

  _setWorkoutKind(k) {
    const norm = (k === 'lift') ? 'strength' : k;
    if (norm === this.workoutKind) return;
    this.workoutKind = norm;
    this._log(`workoutKind=${norm}`);
  }
  get isLift() { return this.workoutKind === 'strength' || this.workoutKind === 'lift'; }
  get _suppressed() { return this.suppressUntil != null && this._now() < this.suppressUntil; }

  _ingestLiveGps(fix) {
    if (!this.runActive) return;
    // (route/distance omitted — not needed for lift/run start-end assertions)
  }

  // AppModel.ingestLiveHr — the sport-edge start/end state machine.
  _ingestLiveHr(hr) {
    const now = this._now();
    if (hr.t !== 0 && now > hr.t && now - hr.t > 60_000) return; // stale backlog frame → ignore
    if (hr.sport === 1) {
      if (!this._suppressed) {
        if (!this.lastSportWas1) this._startRunIfNeeded();
        this.lastSportWas1 = true;
      }
      if (this.runActive) { this.runSawSport1 = true; this.runSportLostAt = null; }
    } else {
      this.lastSportWas1 = false;
      if (this.runActive && this.runSawSport1) {
        if (this.runSportLostAt != null) {
          if (now - this.runSportLostAt > 4000) { this._endRun(false); return; }
        } else {
          this.runSportLostAt = now;
        }
      }
    }
    if (this.runActive) { this.runLiveBpm = hr.bpm; this.runMaxBpm = Math.max(this.runMaxBpm, hr.bpm); }
  }

  _startRunIfNeeded() {
    if (this.runActive) return;
    this.runActive = true;
    this.runStartedAt = this._now();
    this.runMaxBpm = 0; this.runLiveBpm = null;
    this.runSawSport1 = false; this.runSportLostAt = null;
    this.liveSheetShown = true;
    this._log(`startRun kind=${this.workoutKind}`);
  }

  _onWorkoutEnd() { this._endRun(false); }   // TA:{"k":"end"} → the primary end signal

  _endRun() {
    if (!this.runActive) { this._log('endRun IGNORED (runActive=false)'); return; }
    this.runActive = false;
    this.runSawSport1 = false; this.runSportLostAt = null;
    if (!this.simulateBug) this.lastSportWas1 = false;   // THE FIX — reset the rising-edge tracker
    this.suppressUntil = this._now() + 12_000;
    this.liveSheetShown = false;
    this._submit(this.wa.flush(true), 'ended');          // sealWorkout(ended:true)
    const elapsed = this.runStartedAt != null ? (this._now() - this.runStartedAt) / 1000 : 0;
    if (elapsed >= 10) {
      this.summaries.push({ kind: this.workoutKind, elapsedSec: Math.round(elapsed), maxBpm: this.runMaxBpm });
      this._log(`SUMMARY kind=${this.workoutKind} elapsed=${Math.round(elapsed)}s`);
    }
    this.workoutKind = 'run';
  }

  // BandManager.didConnect
  onConnect() {
    this.connected = true;
    if (this._discTimer != null) { this.clock.clear(this._discTimer); this._discTimer = null; }  // cancel a pending confirm — the drop was transient
  }

  // BandManager.didDisconnect → FrameRouter.flush(live:false), then (Fix B) confirm-then-end.
  onDisconnect() {
    this.connected = false;
    // While a run is live we DEFER the workout flush (keep wa open) so a transient blip can resume it
    // and a durable drop can seal it as `ended`. Draining it here (the old unconditional ended=false
    // flush) is what left the confirmed-end with nothing to seal. ppg/hr backlog still flush normally.
    const willConfirmEnd = this.endOnDisconnect && this.runActive;
    if (!willConfirmEnd) this._submit(this.wa.flush(false), 'disconnect');
    this._submit(this.waLog.flush(false), 'disconnect-log');
    // If the link stays down, the watch almost certainly finished (you racked the weight / walked off)
    // and the TA:end / sport→0 frames never arrived — end for real so the summary shows + the window
    // seals with `ended`. A quick reconnect (onConnect) cancels this, so a blip never false-ends.
    if (willConfirmEnd && this._discTimer == null) {
      this._discTimer = this.clock.setTimeout(() => {
        this._discTimer = null;
        if (!this.connected && this.runActive) { this._log('disconnect-confirmed → end run'); this._endRun(false); }
      }, 8000);
    }
  }
}

module.exports = { Phone, WorkoutAssembler };
