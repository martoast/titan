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

/** T4 → { t, speedKmh, alt (m | null), sats, lat (deg | null), lon (deg | null) } for one GPS fix.
 *  v5 (24 B) adds lat/lon at bytes 16/20 (deg ×1e7); v4 (20 B) frames decode with lat/lon = null. */
export function decodeT4(b64) {
  const bytes = b64ToBytes(b64);
  if (bytes.length < 20) return null;
  const dv = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
  const altRaw = dv.getInt32(12, true);
  let lat = null, lon = null;
  if (bytes.length >= 24) {
    const latRaw = dv.getInt32(16, true);
    const lonRaw = dv.getInt32(20, true);
    if (latRaw !== ALT_NONE) lat = latRaw / 1e7;
    if (lonRaw !== ALT_NONE) lon = lonRaw / 1e7;
  }
  return {
    sats: dv.getUint8(1),
    speedKmh: dv.getInt16(2, true) / 100,
    t: u64(dv, 4),
    alt: altRaw === ALT_NONE ? null : altRaw / 10,
    lat,
    lon,
  };
}

/**
 * T7 → [{t, alt}] — the AMBIENT barometric altitude batch for all-day floor counting (server counts
 * floors). 16-B header [ver u8, count u8, tsLo u32, tsHi u32, intervalMs u16, base×10 i32] +
 * count × int16 (Δ from base, ×10 = decimetres). Altitudes are reconstructed as (base + Δ)/10 m,
 * timestamped from `ts` at `intervalMs` cadence.
 */
export function decodeT7(b64) {
  const bytes = b64ToBytes(b64);
  if (bytes.length < 16) return [];
  const dv = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
  const count = dv.getUint8(1);
  const t0 = u64(dv, 2);
  const intervalMs = dv.getUint16(10, true);
  const base = dv.getInt32(12, true);
  const out = [];
  for (let i = 0; i < count; i++) {
    const o = 16 + i * 2;
    if (o + 2 > bytes.length) break;
    out.push({ t: t0 + i * intervalMs, alt: (base + dv.getInt16(o, true)) / 10 });
  }
  return out;
}

/** T5 → { t, bpm, conf } for one HR reading. */
export function decodeT5(b64) {
  const bytes = b64ToBytes(b64);
  if (bytes.length < 12) return null;
  const dv = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
  return { bpm: dv.getUint8(1), conf: dv.getUint8(2), t: u64(dv, 4) };
}

/**
 * T6 → [{t, ax, ay, az}] — the OFFLINE workout-accel log frame (milli-g). 16-B header
 * [ver u8, rsvd u8, count u16, startLo u32, startHi u32, durMs u32] + count × 3 × int16.
 * Per-sample timestamps are reconstructed by spreading `count` samples across [start, start+dur].
 */
export function decodeT6(b64) {
  const bytes = b64ToBytes(b64);
  if (bytes.length < 16) return [];
  const dv = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
  const count = dv.getUint16(2, true);
  const start = u64(dv, 4);
  const durMs = dv.getUint32(12, true);
  const denom = Math.max(1, count - 1);
  const out = [];
  for (let i = 0; i < count; i++) {
    const o = 16 + i * 6;
    if (o + 6 > bytes.length) break;
    out.push({
      t: start + Math.round((durMs * i) / denom),
      ax: dv.getInt16(o, true),
      ay: dv.getInt16(o + 2, true),
      az: dv.getInt16(o + 4, true),
    });
  }
  return out;
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
    this.END_GAP_MS = opts.endGapMs ?? 120000;  // device-time gap that ends a workout
    this.FLUSH_MS = opts.flushMs ?? 180000;     // cap one window at ~3 min of data
    this.MIN_MS = opts.minMs ?? 60000;          // don't emit windows shorter than this
    this.reset();
  }

  reset() {
    this.active = false;
    this.accel = [];        // {t, ax, ay, az} milli-g
    this.hr = [];           // {t, bpm}
    this.gps = [];          // {t, speedKmh, alt}
    this.lastActivityT = 0; // device-time of the last WORKOUT-defining frame (T4/T6)
    this.winStart = 0;
  }

  // A workout-defining frame at device-time `t` that's far past the previous one means the prior
  // workout ended — finalize it (works for the morning-sync burst AND for back-to-back sessions).
  _gapFinalize(t) {
    if (this.active && this.lastActivityT && t - this.lastActivityT > this.END_GAP_MS) {
      const w = this._build(this.lastActivityT);
      this.reset();
      return w;
    }
    return null;
  }

  _open(t) { if (!this.active) { this.active = true; this.winStart = t; } }

  _periodic(t) {
    if (this.active && t - this.winStart >= this.FLUSH_MS) {
      const w = this._build(t);
      this.accel = this.accel.filter((s) => s.t >= t);
      this.hr = this.hr.filter((s) => s.t >= t);
      this.gps = this.gps.filter((s) => s.t >= t);
      this.winStart = this.gps.length ? this.gps[0].t : t;
      return w;
    }
    return null;
  }

  /** T4 GPS fix — opens/keeps a workout (GPS only powers on once a workout is detected). */
  addGps(fix) {
    if (!fix) return null;
    const ended = this._gapFinalize(fix.t);
    this._open(fix.t);
    this.gps.push(fix);
    this.lastActivityT = Math.max(this.lastActivityT, fix.t);
    return ended || this._periodic(fix.t);
  }

  /** T6 offline-logged accel — opens/keeps a workout (T6 only exists during a workout). */
  addWorkoutAccel(samples) {
    if (!samples || !samples.length) return null;
    const ended = this._gapFinalize(samples[0].t);
    this._open(samples[0].t);
    for (const s of samples) this.accel.push({ t: s.t, ax: s.ax, ay: s.ay, az: s.az });
    this.lastActivityT = Math.max(this.lastActivityT, samples[samples.length - 1].t);
    return ended || this._periodic(this.lastActivityT);
  }

  /** T1 live accel — buffered only while a workout is already open (GPS opened it). */
  addAccel(samples) {
    if (!this.active || !samples) return null;
    for (const s of samples) this.accel.push({ t: s.t, ax: s.ax, ay: s.ay, az: s.az });
    return null;
  }

  addHr(hr) { if (this.active && hr) this.hr.push(hr); }

  /** Finalize when device-time has advanced past the end-gap (live: T1 keeps `deviceNowT` moving). */
  tick(deviceNowT) { return this._gapFinalize(deviceNowT); }

  /** Force-emit whatever is buffered (disconnect, or the sync burst has settled). */
  flush() {
    if (!this.active) return null;
    const w = this._build(this.lastActivityT || this.winStart);
    this.reset();
    return w;
  }

  _build(endT) {
    return buildWorkoutWindow(this.accel, this.hr, this.gps, this.winStart, endT, this.MIN_MS);
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

  // Raw coordinate track for the route map: only fixes that carry real coords, timestamps preserved
  // (NOT per-second zero-filled — (0,0) is a real place in the ocean). The route builder draws the
  // polyline + computes haversine distance / splits from these.
  const track = gps
    .filter((g) => g.lat != null && g.lon != null)
    .map((g) => ({ t: g.t, lat: g.lat, lon: g.lon }));

  return {
    kind: 'workout',
    start: new Date(startT).toISOString(),
    end: new Date(endT).toISOString(),
    accel_xyz: { x: ax, y: ay, z: az },
    accel_fs: accelFs,
    accel_unit: 'mg', // milli-g — the server converts to m/s² for the classifier
    hr_bpm: hrBySec,
    accel_counts: counts,
    gps: { speed_kmh: speedBySec, grade, track },
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
  window.TitanBridge = { decodeT1, decodeT4, decodeT5, decodeT6, buildWorkoutWindow, WorkoutAssembler };
}
