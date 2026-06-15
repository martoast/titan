/**
 * Titan bridge — frame decoders + workout assembler.
 *
 * The Bangle firmware (firmware/banglejs/titan.app.js) streams newline-delimited base64 frames
 * over the Nordic UART Service. This module turns the raw bytes into samples and assembles a
 * WORKOUT window (the shape SealActivityJob ingests) from the live workout frames:
 *
 *   T1 — live raw: 16-B header + 12-B samples [relT u32, ppg i16, ax i16, ay i16, az i16].
 *        Accel is milli-g. (Used for both PPG/HRV and, during a workout, 3-axis classification.)
 *   T2 — overnight compact log: PPG + a scalar actigraphy count (handled in the bridge, not here).
 *   T4 — GPS fix: [ver u8, sats u8, speed×100 i16, tsLo u32, tsHi u32, alt×10 i32, rsvd u32] (20 B).
 *        GPS only powers on once the watch detects a workout, so a T4 frame == "in a workout".
 *   T5 — workout HR: [ver u8, bpm u8, conf u8, rsvd u8, tsLo u32, tsHi u32] (12 B). On-chip bpm.
 *
 * Pure + side-effect free so it runs under `node --test`; the browser build attaches it to
 * window.TitanBridge (see the footer + resources/js/app.js).
 */

const ALT_NONE = -2147483648; // firmware sentinel for "no altitude"

function b64ToBytes(b64) {
  // atob in the browser; Buffer under node.
  if (typeof atob === 'function') {
    const bin = atob(b64);
    const a = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) a[i] = bin.charCodeAt(i);
    return a;
  }
  return new Uint8Array(Buffer.from(b64, 'base64'));
}

function u64(dv, lo) {
  return dv.getUint32(lo + 4, true) * 4294967296 + dv.getUint32(lo, true);
}

/** T1 → { epoch, samples: [{t, ppg, ax, ay, az}] } with accel in milli-g. */
export function decodeT1(b64) {
  const bytes = b64ToBytes(b64);
  if (bytes.length < 16) return { epoch: 0, samples: [] };
  const dv = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
  const count = dv.getUint16(2, true);
  const epoch = u64(dv, 4);
  const samples = [];
  for (let i = 0; i < count; i++) {
    const off = 16 + i * 12;
    if (off + 12 > bytes.length) break;
    samples.push({
      t: epoch + dv.getUint32(off, true),
      ppg: dv.getInt16(off + 4, true),
      ax: dv.getInt16(off + 6, true),
      ay: dv.getInt16(off + 8, true),
      az: dv.getInt16(off + 10, true),
    });
  }
  return { epoch, samples };
}

/** T4 → { t, speedKmh, alt (m | null), sats } for one GPS fix. */
export function decodeT4(b64) {
  const bytes = b64ToBytes(b64);
  if (bytes.length < 20) return null;
  const dv = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
  const altRaw = dv.getInt32(12, true);
  return {
    sats: dv.getUint8(1),
    speedKmh: dv.getInt16(2, true) / 100,
    t: u64(dv, 4),
    alt: altRaw === ALT_NONE ? null : altRaw / 10,
  };
}

/** T5 → { t, bpm, conf } for one HR reading. */
export function decodeT5(b64) {
  const bytes = b64ToBytes(b64);
  if (bytes.length < 12) return null;
  const dv = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
  return { bpm: dv.getUint8(1), conf: dv.getUint8(2), t: u64(dv, 4) };
}

/**
 * Accumulates the live frames of a workout and emits `kind=workout` windows (the shape
 * SealActivityJob ingests). A workout is "active" while GPS fixes (T4) keep arriving; it ends
 * after END_GAP_MS without one (GPS stood down) or on an explicit flush (disconnect). Long
 * workouts emit periodic windows (FLUSH_MS) so a drop never loses the whole session — the seal
 * job re-groups them by time.
 */
export class WorkoutAssembler {
  constructor(opts = {}) {
    this.END_GAP_MS = opts.endGapMs ?? 120000;  // ~GPS_OFF_SEC + margin
    this.FLUSH_MS = opts.flushMs ?? 180000;     // cap one window at ~3 min of data
    this.MIN_MS = opts.minMs ?? 60000;          // don't emit windows shorter than this
    this.reset();
  }

  reset() {
    this.active = false;
    this.accel = [];   // {t, ax, ay, az} milli-g
    this.hr = [];      // {t, bpm}
    this.gps = [];     // {t, speedKmh, alt}
    this.lastGpsT = 0;
    this.winStart = 0;
  }

  addAccel(samples) {
    if (!this.active) return; // accel only matters once a workout is under way
    for (const s of samples) this.accel.push({ t: s.t, ax: s.ax, ay: s.ay, az: s.az });
  }

  addHr(hr) {
    if (hr) this.hr.push(hr);
  }

  /** A GPS fix means we're in a workout. Returns a window if a flush boundary was crossed. */
  addGps(fix) {
    if (!fix) return null;
    if (!this.active) { this.active = true; this.winStart = fix.t; }
    this.lastGpsT = fix.t;
    this.gps.push(fix);
    if (fix.t - this.winStart >= this.FLUSH_MS) return this._build(fix.t);
    return null;
  }

  /** Call on each tick / new frame with the latest timestamp → a window when the workout ends.
   * The window spans the actual DATA (up to the last GPS fix), not the wall-clock `nowT`. */
  tick(nowT) {
    if (this.active && this.lastGpsT && nowT - this.lastGpsT >= this.END_GAP_MS) {
      return this._build(this.lastGpsT);
    }
    return null;
  }

  /** Force-emit whatever is buffered (e.g. on disconnect). */
  flush() {
    return this.active ? this._build(this.lastGpsT || this.winStart) : null;
  }

  _build(endT) {
    const startT = this.winStart;
    const win = buildWorkoutWindow(this.accel, this.hr, this.gps, startT, endT, this.MIN_MS);
    // Keep only data after this window for the next one (periodic flush continuity).
    this.accel = this.accel.filter((s) => s.t >= endT);
    this.hr = this.hr.filter((s) => s.t >= endT);
    this.gps = this.gps.filter((s) => s.t >= endT);
    if (this.gps.length) { this.winStart = this.gps[0].t; } else { this.active = false; }
    return win;
  }
}

/**
 * Build the `kind=workout` window: 3-axis accel at its native rate (milli-g) + per-SECOND HR,
 * GPS speed and baro-derived grade, plus per-30 s accel counts. Returns null if too short.
 */
export function buildWorkoutWindow(accel, hr, gps, startT, endT, minMs = 60000) {
  if (endT - startT < minMs || accel.length < 25) return null;
  const ax = accel.map((s) => s.ax);
  const ay = accel.map((s) => s.ay);
  const az = accel.map((s) => s.az);
  const accelFs = Math.max(1, Math.round((accel.length * 1000) / Math.max(endT - startT, 1)));

  const secs = Math.max(1, Math.ceil((endT - startT) / 1000));

  // HR → one bpm per second (last reading in the second carries forward).
  const hrBySec = perSecond(hr, startT, secs, (e) => e.bpm);

  // GPS speed per second, and grade = Δaltitude / Δdistance (baro alt over GPS distance).
  const speedBySec = perSecond(gps, startT, secs, (e) => e.speedKmh);
  const altBySec = perSecond(gps, startT, secs, (e) => e.alt);
  const grade = new Array(secs).fill(0);
  for (let i = 1; i < secs; i++) {
    const dAlt = altBySec[i] != null && altBySec[i - 1] != null ? altBySec[i] - altBySec[i - 1] : 0;
    const dDist = (speedBySec[i] || 0) / 3.6; // km/h → m travelled in 1 s
    grade[i] = dDist > 0.5 ? clamp(dAlt / dDist, -0.3, 0.3) : 0;
  }

  // Per-30 s accel activity counts (drives the counts-based session detector / TRIMP).
  const counts = [];
  const epoch = accelFs * 30;
  let acc = 0, prev = null, k = 0;
  for (let i = 0; i < accel.length; i++) {
    const mag = Math.sqrt(ax[i] * ax[i] + ay[i] * ay[i] + az[i] * az[i]) / 1000; // milli-g → g
    if (prev != null) acc += Math.abs(mag - prev);
    prev = mag;
    if (++k >= epoch) { counts.push(Math.round(Math.min(acc * 2, 300))); acc = 0; k = 0; }
  }
  if (k > 0) counts.push(Math.round(Math.min(acc * 2, 300)));

  return {
    kind: 'workout',
    start: new Date(startT).toISOString(),
    end: new Date(endT).toISOString(),
    accel_xyz: { x: ax, y: ay, z: az },
    accel_fs: accelFs,
    accel_unit: 'mg', // milli-g — the server converts to m/s² for the classifier
    hr_bpm: hrBySec,
    accel_counts: counts,
    gps: { speed_kmh: speedBySec, grade },
    src: 'banglejs2',
  };
}

/** Bucket time-stamped events into per-second slots, carrying the last value forward. */
function perSecond(events, startT, secs, pick) {
  const out = new Array(secs).fill(null);
  for (const e of events) {
    const s = Math.floor((e.t - startT) / 1000);
    if (s >= 0 && s < secs) out[s] = pick(e);
  }
  let last = null;
  for (let i = 0; i < secs; i++) {
    if (out[i] == null) out[i] = last; else last = out[i];
  }
  // Back-fill any leading nulls with the first real value.
  const first = out.find((v) => v != null);
  for (let i = 0; i < secs && out[i] == null; i++) out[i] = first ?? 0;
  return out.map((v) => (v == null ? 0 : v));
}

function clamp(v, lo, hi) { return v < lo ? lo : v > hi ? hi : v; }

if (typeof window !== 'undefined') {
  window.TitanBridge = { decodeT1, decodeT4, decodeT5, buildWorkoutWindow, WorkoutAssembler };
}
