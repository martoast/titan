/* Titan Wearable — Firmware P0
 * Bangle.js 2 raw PPG + accelerometer streamer.
 *
 * Architecture (see tasks/titan-wearable/02-firmware.md §1,§6,§9 and
 * 01-hardware.md §1a): the watch is a "dumb" sensor. It samples raw PPG
 * (VC31B, ~25 Hz) and raw accel (KX022, ~12.5 Hz here), timestamps every
 * sample, binary-packs them, and streams them over the Nordic UART Service
 * (NUS) as line-delimited base64 frames. A phone (Gadgetbridge) or a laptop
 * (bridge.html, Web Bluetooth) receives the frames, batches them, and POSTs
 * to the Titan ingestion API. ALL heavy DSP (peak detect -> IBI, HRV, sleep)
 * runs server-side; we never trust the on-chip BPM register for HRV.
 *
 * Transport budget: the Bangle.js NUS / Web-BT link is ~2,500 B/s. So we:
 *   - pack PPG as int16 LE (the raw VC31 value, clipped to 16 bits),
 *   - round accel to int and pack as 3x int16 LE (milli-g),
 *   - prepend a tiny binary header per sample, batch N samples per BLE frame,
 *   - base64-encode the binary blob so it survives Bluetooth.println()
 *     (NUS is a text line protocol in Espruino; raw bytes with \n/\r would
 *     corrupt framing, so we base64 and newline-terminate).
 *
 * Offline resilience: if no BLE central is connected we append packed frames
 * to a StorageFile on the watch and flush them on reconnect (FIFO).
 *
 * --- HRM-raw field assumptions (IMPORTANT) ----------------------------------
 * Espruino's 'HRM-raw' event object `e` is firmware/sensor dependent. On
 * Bangle.js 2 (VC31B) the fields seen in the wild are:
 *   e.raw   : raw ADC value of the current PPG channel (integer)
 *   e.vcPPG : VC31 "filtered/processed" PPG value (integer, the one the HRM
 *             algorithm consumes) — present on VC31B builds
 *   e.filt  : an additional filtered value on some builds
 *   e.adc   : sometimes present (raw ADC, alt name)
 * We prefer e.vcPPG (the documented raw-PPG field in the hardware plan), fall
 * back to e.raw, then e.filt/e.adc, then 0. If your firmware exposes a
 * different field, adjust PPG_FIELDS below. We send whichever we picked plus a
 * channel tag so the server knows the provenance.
 *
 * The 'HRM' event (not raw) gives e.bpm + e.confidence — we use that only for
 * the on-watch UI, never for the data stream.
 * ---------------------------------------------------------------------------
 */

/* global Bangle, Bluetooth, Storage, NRF, g, E, btoa, require, setWatch, BTN1 */

// ----- Configuration --------------------------------------------------------
var CFG = {
  // Which HRM-raw fields to try, in priority order. First present wins.
  PPG_FIELDS: ["vcPPG", "raw", "filt", "adc"],
  // How many samples to accumulate before emitting one BLE frame.
  // Smaller = lower latency, larger = less BLE/base64 overhead. 12 samples
  // (~0.5 s of PPG) keeps each frame comfortably under the 244-byte ATT MTU.
  FRAME_SAMPLES: 12,
  // Accelerometer is reported by Bangle in g; multiply to milli-g and round.
  ACCEL_SCALE: 1000,
  // Protocol version embedded in every live (T1) frame header.
  PROTO_VERSION: 1,

  // --- Overnight logging (Path B: wear to bed, sync in the morning) ----------
  // When NOT connected, PPG is logged to flash in a COMPACT, PPG-only format (no
  // accel, no per-sample timestamps — a frame carries its start epoch + duration,
  // the server reconstructs the timeline). ~2.8 B/sample vs ~16 B/sample for live
  // T1, so a full 8 h night (~720k samples) is ~2 MB and fits the 8 MB flash. On
  // connect, the whole log streams out then erases — the morning sync.
  LOG_FILE: "titan.log",
  LOG_FRAME_SAMPLES: 125,            // ~5 s @ 25 Hz per compact frame (low overhead)
  LOG_MAX_BYTES: 4 * 1024 * 1024,    // ~16 h ceiling; STOP appending (never wipe the night)
  LOG_PROTO_VERSION: 2,

  // --- Accel cadence (poll interval, ms) -------------------------------------
  // The live T1 frame carries 3-axis accel, which the server classifies into a
  // workout type (rest/walk/run/cycle/stairs/other). That model was validated on
  // real data at 25 Hz; at 12.5 Hz accuracy drops ~3 pts (84% vs 87%). So while
  // CONNECTED (a live workout, plugged into a phone/laptop's attention) we sample
  // accel at 25 Hz to match the model; OVERNIGHT (offline, T2) we stay at 12.5 Hz,
  // which is ample for actigraphy sleep/wake and saves battery.
  ACCEL_MS_LIVE: 40,                 // 25 Hz — matches the workout classifier's training rate
  ACCEL_MS_OVERNIGHT: 80,            // 12.5 Hz — actigraphy + power

  // --- GPS gating (BATTERY-CRITICAL) -----------------------------------------
  // GPS is by far the biggest drain on the watch (the AT6558 receiver dwarfs the
  // accel + PPG draw). So GPS is OFF by default and we power it ONLY when the watch
  // itself detects sustained locomotion — the same accel signature the server
  // classifies as walk/run/cycle. A cheap on-watch motion gate ARMS the GPS; the
  // server's classifier later confirms the type and consumes the pace for VO2max.
  // If you're sitting, sleeping, or lifting, GPS never turns on.
  //   - arm after GPS_ARM_SEC of motion above GPS_ON_MOTION (sustained, not a one-off)
  //   - stand down after GPS_OFF_SEC below it (workout ended / went indoors-still)
  GPS_ON_MOTION: 0.18,               // mean |Δaccel| (g) per sample that counts as locomotion
  GPS_ARM_SEC: 25,                   // sustained motion before GPS powers on (covers ~30s cold fix)
  GPS_OFF_SEC: 90,                   // quiet time before an AUTO workout ends
  GPS_FIX_TIMEOUT: 90,               // no satellite fix this long → indoors; drop GPS, keep the workout
  GPS_PROTO_VERSION: 4,              // T4 frame: per-fix speed + altitude (+ grade source)

  // During a WORKOUT we also stream the on-chip HR (bpm) — in-motion PPG→IBI is unreliable, so
  // workout HR uses the watch's hardware bpm register, not server-side peak detection. T5 frames
  // only flow while a workout is active (GPS armed), so sleep/rest never stream bpm.
  HR_PROTO_VERSION: 5,               // T5 frame: per-reading bpm + confidence

  // OFFLINE workouts (a run with no phone, or a gym session): when not connected we log the
  // 3-axis accel to flash as compact T6 frames so the workout still CLASSIFIES on morning sync
  // (the overnight T2 log is PPG-only and can't). During a workout we log T6 instead of T2 PPG
  // (PPG in motion is noise; accel is the signal).
  WORKOUT_LOG_PROTO_VERSION: 6,      // T6 frame: compact 3-axis accel batch (milli-g)
  WORKOUT_LOG_SAMPLES: 125,          // ~5 s @ 25 Hz per T6 frame

  // AMBIENT ALTITUDE → floors climbed (Tier-2 #13). The BMP280 runs continuously (~µA, no GPS),
  // and the SERVER counts floors from the altitude series (drift/noise-robust) — the watch just
  // streams the trace, staying a dumb sensor. Sampled slowly (floors only need ~1 Hz).
  ALT_PROTO_VERSION: 7,              // T7 frame: ambient barometric altitude batch
  ALT_SAMPLE_MS: 1000,              // 1 Hz ambient altitude sampling (oversampled BMP280)
  ALT_FRAME_SAMPLES: 60             // one T7 per minute (60 samples; ~136 B, well under the MTU)
};

// ----- State ----------------------------------------------------------------
var state = {
  streaming: false,    // user toggled capture on?
  connected: false,    // is a BLE central subscribed to NUS?
  bpm: 0,              // last HRM bpm (UI only)
  conf: 0,             // last HRM confidence (UI only)
  ppgCount: 0,         // samples captured this session (UI counter)
  framesSent: 0,       // BLE frames emitted/flushed
  logged: 0,           // approx bytes in the overnight log file
  logFull: false,      // hit LOG_MAX_BYTES → stop appending (preserve the night)
  lastAccel: { x: 0, y: 0, z: 0 }, // most recent accel reading (g)
  battery: 0,
  workout: false,      // a workout is in progress (drives HR/accel capture + 25 Hz rate)
  workoutManual: false,// started by hand (a gym session) → only ends by hand, not on a motion lull
  gps: false,          // is the GPS receiver powered right now? (a subset of a workout)
  gpsFix: false,       // do we have a satellite fix yet?
  speed: 0             // last GPS speed (m/s), UI only
};

// On-watch locomotion gate for GPS (battery). A slow EMA of per-sample |Δaccel| (g);
// when it stays above CFG.GPS_ON_MOTION we arm GPS, when it stays below we stand down.
var motionEMA = 0;
var motionAboveSince = 0;   // getTime() when motion first crossed the on-threshold (0 = below)
var motionBelowSince = 0;   // getTime() when motion first dropped below it (0 = above)
var gpsArmedT = 0;          // getTime() when GPS was last powered (for the indoor fix-timeout)
var lastAltitude = null;    // last barometric altitude (m), GPS-scoped, for grade
var ambientAlt = null;      // last barometric altitude (m), always-on, for floors (T7)
var altBuf = [];            // pending altitude samples (decimetres) for the current T7 frame
var altT0Ms = 0;            // unix-ms of the first sample in the current T7 frame

// Offline workout-accel log (T6): buffered 3-axis accel flushed to flash while a workout runs
// and we're not connected, so the session classifies on morning sync.
var woAccel = [];           // pending [ax,ay,az,...] milli-g triples for the current T6 frame
var woAccelEpochMs = 0;     // unix-ms of the first sample in the current T6 frame

// A frame is built up sample-by-sample then flushed. We use an ArrayBuffer
// sized for the worst case so we never reallocate in the hot path.
// Per-sample wire layout (little-endian):
//   uint32 t   : relative ms timestamp since frame epoch (saves bytes vs abs)
//   int16  ppg : raw PPG value
//   int16  ax  : accel x in milli-g
//   int16  ay  : accel y in milli-g
//   int16  az  : accel z in milli-g
// = 12 bytes/sample.
var SAMPLE_BYTES = 12;
// Frame header (little-endian), 16 bytes:
//   uint8  ver
//   uint8  ppgFieldCode (index into CFG.PPG_FIELDS, so server knows source)
//   uint16 sampleCount
//   uint32 epochMsLo   (frame epoch = getTime()*1000, low 32 bits)
//   uint32 epochMsHi   (high 32 bits — full 64-bit ms since unix epoch)
//   uint16 reserved
var HEADER_BYTES = 16;

var frameBuf = new ArrayBuffer(HEADER_BYTES + CFG.FRAME_SAMPLES * SAMPLE_BYTES);
var frameView = new DataView(frameBuf);
var frameCount = 0;       // samples currently in the frame
var frameEpochMs = 0;     // unix-ms timestamp of first sample in this frame
var ppgFieldCode = 0;     // which PPG_FIELDS index we settled on

// Coach-priming state. Declared up here (not next to the command channel below) because
// applyAccelRate() reads `primed`, and the connection poll can call applyAccelRate via
// onConnect before a later `var` line would have executed — Espruino doesn't hoist a
// top-level var ahead of its statement, so referencing it early throws ReferenceError.
var primed = null;        // active coach-primed activity, or null
var cmdBuf = "";          // inbound NUS command line buffer
var pairUntil = 0;        // pairing-mode end time (getTime); 0 = not pairing. drawUI() reads pairTimer.
var pairTimer = null;     // pairing-screen redraw interval, or null
var page = 0;             // watch face page: 0 = heart/home, 1 = clock, 2 = signals (swipe to change)
var clockTickTimer = null; // minute-boundary redraw for the clock face

// ----- Helpers --------------------------------------------------------------

// Clamp a number into signed int16 range so DataView.setInt16 never wraps
// unexpectedly on out-of-range raw values.
function clampI16(v) {
  v = v | 0;
  if (v > 32767) return 32767;
  if (v < -32768) return -32768;
  return v;
}

// Pick the PPG value from an HRM-raw event, remembering which field we used.
function extractPPG(e) {
  for (var i = 0; i < CFG.PPG_FIELDS.length; i++) {
    var f = CFG.PPG_FIELDS[i];
    if (e[f] !== undefined && e[f] !== null) {
      ppgFieldCode = i;
      return e[f];
    }
  }
  ppgFieldCode = 255; // unknown
  return 0;
}

// base64 of an ArrayBuffer slice. Espruino has global btoa() that accepts an
// ArrayBuffer/typed array directly.
function b64(buf) {
  return btoa(buf);
}

// Send one already-built live (T1) binary frame over NUS. Only used while
// connected (desk/real-time); when offline we log compact T2 frames instead.
function emitFrame(buf, len) {
  if (!state.connected) return; // offline → logged via writeLogFrame(), drop stray T1
  try {
    // ArrayBuffer.slice() is NOT implemented in Espruino (2v29) — it throws
    // "Function slice not found", which the catch below silently swallowed and
    // killed ALL live PPG streaming (T1 frames) while T5/T6/T7 — which b64() the
    // whole buffer — kept working. Use a Uint8Array VIEW of the first `len` bytes
    // (btoa accepts a typed array directly, no copy needed).
    Bluetooth.println("T1:" + b64(new Uint8Array(buf, 0, len)));
    state.framesSent++;
  } catch (err) { state.lastEmitErr = '' + err; } // surface (don't spam) so a future emit bug isn't invisible
}

// ----- Overnight log (compact, PPG + activity) ------------------------------
// A T2 frame: 20-byte header [ver u8, rsvd u8, count u16, startLo u32, startHi
// u32, durMs u32, activity u32] + int16 PPG samples. The header's last 4 bytes
// carry a per-frame ACTIVITY count (summed |Δaccel|, gravity-cancelled) — real
// actigraphy for sleep/wake staging. No per-sample timestamps — the receiver
// spreads `count` samples evenly over [start, start+dur].
var logAccum = [];    // pending PPG samples for the current compact frame
var logEpochMs = 0;   // unix-ms of the first sample in the current frame
var logMotion = 0;    // accumulated movement (sum |Δaccel|, g) for the current frame
var lastAccelV = null; // previous accel sample, for the delta

function logSample(ppg) {
  if (state.logFull) return;
  // During a workout we log 3-axis accel (T6), not PPG — PPG in motion is noise, and dropping
  // it saves the flash for the accel the classifier actually needs.
  if (state.workout) return;
  if (logAccum.length === 0) logEpochMs = Math.round(getTime() * 1000);
  logAccum.push(clampI16(ppg));
  if (logAccum.length >= CFG.LOG_FRAME_SAMPLES) writeLogFrame();
}

function writeLogFrame() {
  var n = logAccum.length;
  if (n === 0) return;
  var durMs = Math.round(getTime() * 1000) - logEpochMs;
  if (durMs < 0) durMs = 0;
  var buf = new ArrayBuffer(20 + n * 2);
  var dv = new DataView(buf);
  dv.setUint8(0, CFG.LOG_PROTO_VERSION);
  dv.setUint16(2, n, true);
  var hi = Math.floor(logEpochMs / 4294967296);
  dv.setUint32(4, (logEpochMs - hi * 4294967296) >>> 0, true);
  dv.setUint32(8, hi >>> 0, true);
  dv.setUint32(12, durMs >>> 0, true);
  // Per-frame activity count (movement). Scaled to integer milli-g·samples; the
  // receiver/stager use it RELATIVELY so the exact scale doesn't matter.
  var activity = Math.round(logMotion * 1000);
  if (activity < 0) activity = 0;
  if (activity > 4294967295) activity = 4294967295;
  dv.setUint32(16, activity >>> 0, true);
  for (var i = 0; i < n; i++) dv.setInt16(20 + i * 2, logAccum[i], true);
  var line = "T2:" + b64(buf);
  try {
    require("Storage").open(CFG.LOG_FILE, "a").write(line + "\n");
    state.logged += line.length + 1;
    if (state.logged >= CFG.LOG_MAX_BYTES) state.logFull = true; // preserve, never wipe
  } catch (err) { /* storage unavailable — drop */ }
  logAccum = [];
  logMotion = 0;
}

// Morning sync: stream the whole overnight log over NUS, then erase it.
function flushLog() {
  if (!state.connected) return;
  writeLogFrame(); // flush any partial frame first
  var sf;
  try { sf = require("Storage").open(CFG.LOG_FILE, "r"); } catch (err) { return; }
  var line = sf.readLine();
  while (line !== undefined) {
    var trimmed = line.charCodeAt(line.length - 1) === 10
      ? line.substr(0, line.length - 1) : line;
    if (trimmed.length) {
      try { Bluetooth.println(trimmed); state.framesSent++; }
      catch (err) { return; } // link died mid-sync — keep the log, retry next connect
    }
    line = sf.readLine();
  }
  try { require("Storage").open(CFG.LOG_FILE, "r").erase(); } catch (e) {}
  state.logged = 0;
  state.logFull = false;
  if (uiVisible) drawUI();
}

// Reset the in-RAM frame to empty.
function resetFrame() {
  frameCount = 0;
  frameEpochMs = 0;
}

// Finalize the current frame: write header, emit, reset.
function flushFrame() {
  if (frameCount === 0) return;
  // Header.
  frameView.setUint8(0, CFG.PROTO_VERSION);
  frameView.setUint8(1, ppgFieldCode);
  frameView.setUint16(2, frameCount, true);
  // 64-bit ms epoch split into lo/hi (JS numbers are safe to ~2^53 so this is
  // really splitting a <=53-bit integer; hi will be small but future-proofs).
  var hi = Math.floor(frameEpochMs / 4294967296);
  var lo = frameEpochMs - hi * 4294967296;
  frameView.setUint32(4, lo >>> 0, true);
  frameView.setUint32(8, hi >>> 0, true);
  frameView.setUint16(12, 0, true); // reserved
  frameView.setUint16(14, 0, true); // reserved
  emitFrame(frameBuf, HEADER_BYTES + frameCount * SAMPLE_BYTES);
  resetFrame();
}

// Route each PPG sample: live T1 frames while connected (desk/real-time), compact
// T2 logging to flash while offline (overnight → morning sync).
function pushSample(ppg) {
  state.ppgCount++;
  if (state.connected) pushLiveSample(ppg); else logSample(ppg);
}

// Append one (ppg, accel, timestamp) sample into the current live T1 frame.
function pushLiveSample(ppg) {
  var nowMs = Math.round(getTime() * 1000);
  if (frameCount === 0) frameEpochMs = nowMs;
  var relT = nowMs - frameEpochMs;
  if (relT < 0) relT = 0;
  var off = HEADER_BYTES + frameCount * SAMPLE_BYTES;
  frameView.setUint32(off, relT >>> 0, true);
  frameView.setInt16(off + 4, clampI16(ppg), true);
  frameView.setInt16(off + 6, clampI16(Math.round(state.lastAccel.x * CFG.ACCEL_SCALE)), true);
  frameView.setInt16(off + 8, clampI16(Math.round(state.lastAccel.y * CFG.ACCEL_SCALE)), true);
  frameView.setInt16(off + 10, clampI16(Math.round(state.lastAccel.z * CFG.ACCEL_SCALE)), true);
  frameCount++;
  if (frameCount >= CFG.FRAME_SAMPLES) flushFrame();
}

// ----- Sensor event handlers ------------------------------------------------

function onHRMRaw(e) {
  if (!state.streaming) return;
  pushSample(extractPPG(e));
}

function onHRM(e) {
  // The on-chip averaged bpm. We use it two ways:
  //  - whenever a bridge is CONNECTED, stream it (T5) so the live bpm shown in the app
  //    mirrors exactly what's on the watch face — same value, ~1 Hz, rest or workout.
  //  - during a WORKOUT even while OFFLINE, log it (emitHrFrame routes to flash) so a
  //    phone-free run still recovers its HR on morning sync.
  // This averaged bpm is for HR display only — HRV/recovery is always computed server-side
  // from the raw PPG (T1) windows, which carry the ms-level IBI the average has discarded.
  state.bpm = e.bpm | 0;
  state.conf = e.confidence | 0;
  if (state.streaming && (state.connected || state.workout)) emitHrFrame(state.bpm, state.conf);
  if (uiVisible) drawUI();
}

// T5 frame: one HR reading → bpm + confidence + timestamp. 12 bytes. Live when connected, else
// appended to the overnight log so a phone-free outdoor run still recovers its HR on morning sync.
function emitHrFrame(bpm, conf) {
  var buf = new ArrayBuffer(12);
  var dv = new DataView(buf);
  var nowMs = Math.round(getTime() * 1000);
  var hi = Math.floor(nowMs / 4294967296);
  dv.setUint8(0, CFG.HR_PROTO_VERSION);
  dv.setUint8(1, bpm > 255 ? 255 : (bpm < 0 ? 0 : bpm));
  dv.setUint8(2, conf > 100 ? 100 : (conf < 0 ? 0 : conf));
  dv.setUint8(3, 0);
  dv.setUint32(4, (nowMs - hi * 4294967296) >>> 0, true);
  dv.setUint32(8, hi >>> 0, true);
  var line = "T5:" + b64(buf);
  if (state.connected) {
    try { Bluetooth.println(line); state.framesSent++; } catch (e) {}
  } else if (!state.logFull) {
    try { require("Storage").open(CFG.LOG_FILE, "a").write(line + "\n"); state.logged += line.length + 1; } catch (e) {}
  }
}

function onAccel(a) {
  // Bangle reports accel in g as {x,y,z,...}. We cache the latest for live T1
  // frames, AND accumulate movement (sum of |Δaccel|, which cancels the constant
  // 1 g of gravity) into the current overnight frame — a real actigraphy count
  // for sleep/wake staging.
  if (lastAccelV !== null) {
    var d = Math.abs(a.x - lastAccelV.x) + Math.abs(a.y - lastAccelV.y) + Math.abs(a.z - lastAccelV.z);
    logMotion += d;
    // Slow EMA of per-sample motion drives the GPS gate (battery).
    motionEMA = motionEMA * 0.92 + d * 0.08;
    if (state.streaming) updateGpsGate();
  }
  lastAccelV = { x: a.x, y: a.y, z: a.z };
  state.lastAccel.x = a.x;
  state.lastAccel.y = a.y;
  state.lastAccel.z = a.z;
  // Offline + in a workout → log the 3-axis accel (T6) so it classifies on sync.
  if (state.streaming && !state.connected && state.workout) logWorkoutAccel(a);
}

// Append one accel sample to the current T6 frame; flush when full.
function logWorkoutAccel(a) {
  if (state.logFull) return;
  if (woAccel.length === 0) woAccelEpochMs = Math.round(getTime() * 1000);
  woAccel.push(clampI16(Math.round(a.x * CFG.ACCEL_SCALE)),
               clampI16(Math.round(a.y * CFG.ACCEL_SCALE)),
               clampI16(Math.round(a.z * CFG.ACCEL_SCALE)));
  if (woAccel.length >= CFG.WORKOUT_LOG_SAMPLES * 3) writeWorkoutAccelFrame();
}

// T6 frame: 16-B header [ver u8, rsvd u8, count u16, startLo u32, startHi u32, durMs u32] +
// count × 3 × int16 accel (milli-g). Timestamps are reconstructed by spreading count samples
// evenly across [start, start+dur] on the receiver (same scheme as T2).
function writeWorkoutAccelFrame() {
  var count = woAccel.length / 3;
  if (count < 1) return;
  var durMs = Math.round(getTime() * 1000) - woAccelEpochMs;
  if (durMs < 0) durMs = 0;
  var buf = new ArrayBuffer(16 + count * 6);
  var dv = new DataView(buf);
  dv.setUint8(0, CFG.WORKOUT_LOG_PROTO_VERSION);
  dv.setUint16(2, count, true);
  var hi = Math.floor(woAccelEpochMs / 4294967296);
  dv.setUint32(4, (woAccelEpochMs - hi * 4294967296) >>> 0, true);
  dv.setUint32(8, hi >>> 0, true);
  dv.setUint32(12, durMs >>> 0, true);
  for (var i = 0; i < woAccel.length; i++) dv.setInt16(16 + i * 2, woAccel[i], true);
  var line = "T6:" + b64(buf);
  try {
    require("Storage").open(CFG.LOG_FILE, "a").write(line + "\n");
    state.logged += line.length + 1;
    if (state.logged >= CFG.LOG_MAX_BYTES) state.logFull = true;
  } catch (err) { /* storage unavailable — drop */ }
  woAccel = [];
}

// ----- Workout + GPS gating -------------------------------------------------
// A WORKOUT (state.workout) drives HR + 3-axis-accel capture. GPS (state.gps) is a battery-
// hungry SUBSET of a workout — powered only when a fix can plausibly help (outdoors), dropped
// indoors. Workouts are MANUAL ONLY: the user starts/ends every workout by hand (long-press
// BTN, or the coach priming a typed activity). The band never auto-starts or auto-ends a
// workout from motion — clearer UX, and no surprise sessions from a brisk walk to the kitchen.
// The motion gate's sole remaining job is battery: drop GPS indoors when it can't get a fix.
function updateGpsGate() {
  var now = getTime();
  // Indoors (treadmill / weights room): GPS never gets a fix → stop wasting battery on it, but
  // KEEP the workout. The accel still classifies run/walk/lift and logs via T6.
  if (state.gps && !state.gpsFix && (now - gpsArmedT) >= CFG.GPS_FIX_TIMEOUT) powerGps(false);
}

function startWorkout(manual) {
  if (state.workout) { if (manual) state.workoutManual = true; return; }
  state.workout = true;
  state.workoutManual = !!manual;
  powerGps(true);          // try for outdoor pace; dropped after GPS_FIX_TIMEOUT if no fix
  applyAccelRate();        // 25 Hz accel for the classifier, even offline
  if (manual) { try { Bangle.buzz(120); } catch (e) {} }
  if (uiVisible) drawUI();
}

function endWorkout() {
  if (!state.workout) return;
  state.workout = false;
  state.workoutManual = false;
  primed = null;           // clear any coach priming so a later auto-workout doesn't inherit its rate
  powerGps(false);
  if (woAccel.length) writeWorkoutAccelFrame(); // flush the offline workout-accel tail
  applyAccelRate();
  if (uiVisible) drawUI();
}

// Power the GPS receiver (+ the cheap barometer for grade) on/off. Independent of the workout
// flag so we can drop GPS indoors while the workout continues.
function powerGps(on) {
  if (on === state.gps) return;
  state.gps = on;
  if (on) gpsArmedT = getTime();
  try {
    Bangle.setGPSPower(on ? 1 : 0, "titan");
    if (Bangle.setBarometerPower) Bangle.setBarometerPower(on ? 1 : 0, "titan");
  } catch (e) {}
  if (!on) { state.gpsFix = false; state.speed = 0; lastAltitude = null; }
}

function onGPS(g) {
  if (!state.gps) return;
  state.gpsFix = isFinite(g.fix) ? !!g.fix : (g.satellites > 3);
  if (!state.gpsFix || g.speed === undefined || isNaN(g.speed)) return;
  state.speed = g.speed; // Bangle GPS speed is km/h on most builds; server treats it as such
  // Prefer barometric altitude (smoother for grade); fall back to GPS altitude.
  var alt = (lastAltitude !== null) ? lastAltitude : (isNaN(g.alt) ? null : g.alt);
  emitGpsFrame(state.speed, alt, g.satellites | 0);
}

function onPressure(p) {
  // BMP280 barometric altitude → grade (during a workout) AND ambient floors (always). `lastAltitude`
  // is GPS-scoped (nulled when GPS drops); `ambientAlt` persists for all-day floor counting.
  if (p && isFinite(p.altitude)) { lastAltitude = p.altitude; ambientAlt = p.altitude; }
}

// ----- Ambient altitude → T7 (floors, server-side) --------------------------

// Sample the barometric altitude at ~1 Hz into a batch; emit a T7 frame each minute. The server
// runs the drift/noise-robust floor counter over the day's trace — the watch never decides "floors".
function sampleAltitude() {
  if (ambientAlt === null) return;            // barometer not warmed up yet
  if (!altBuf.length) altT0Ms = Math.round(getTime() * 1000);
  altBuf.push(Math.round(ambientAlt * 10));   // decimetres
  if (altBuf.length >= CFG.ALT_FRAME_SAMPLES) emitAltFrame();
}

// T7 frame: [ver u8, count u8, tsLo u32, tsHi u32, intervalMs u16, base×10 i32, count× i16 Δ×10].
// Δ-from-base keeps each sample 2 B and sidesteps int16 range limits at high absolute altitudes.
function emitAltFrame() {
  var n = altBuf.length;
  if (!n) return;
  var buf = new ArrayBuffer(16 + n * 2);
  var dv = new DataView(buf);
  var hi = Math.floor(altT0Ms / 4294967296);
  var base = altBuf[0];
  dv.setUint8(0, CFG.ALT_PROTO_VERSION);
  dv.setUint8(1, n > 255 ? 255 : n);
  dv.setUint32(2, (altT0Ms - hi * 4294967296) >>> 0, true);
  dv.setUint32(6, hi >>> 0, true);
  dv.setUint16(10, CFG.ALT_SAMPLE_MS, true);
  dv.setInt32(12, base | 0, true);
  for (var i = 0; i < n; i++) dv.setInt16(16 + i * 2, clampI16(altBuf[i] - base), true);
  altBuf = [];
  var line = "T7:" + b64(buf);
  if (state.connected) {
    try { Bluetooth.println(line); state.framesSent++; } catch (e) {}
  } else if (!state.logFull) {
    try { require("Storage").open(CFG.LOG_FILE, "a").write(line + "\n"); state.logged += line.length + 1; } catch (e) {}
  }
}

// T4 frame: one GPS fix → speed (km/h ×100) + altitude (m ×10) + sats. Streamed live when
// connected; when offline it's appended to the same overnight log for morning sync.
function emitGpsFrame(speedKmh, altM, sats) {
  var buf = new ArrayBuffer(20);
  var dv = new DataView(buf);
  var nowMs = Math.round(getTime() * 1000);
  var hi = Math.floor(nowMs / 4294967296);
  dv.setUint8(0, CFG.GPS_PROTO_VERSION);
  dv.setUint8(1, sats > 255 ? 255 : sats);
  dv.setInt16(2, clampI16(Math.round(speedKmh * 100)), true);
  dv.setUint32(4, (nowMs - hi * 4294967296) >>> 0, true);
  dv.setUint32(8, hi >>> 0, true);
  dv.setInt32(12, (altM === null ? -2147483648 : Math.round(altM * 10)) | 0, true);
  dv.setUint32(16, 0, true);
  var line = "T4:" + b64(buf);
  if (state.connected) {
    try { Bluetooth.println(line); state.framesSent++; } catch (e) {}
  } else if (!state.logFull) {
    try { require("Storage").open(CFG.LOG_FILE, "a").write(line + "\n"); state.logged += line.length + 1; } catch (e) {}
  }
}

// ----- BLE connection tracking ----------------------------------------------

function onConnect() {
  if (state.connected) return;   // idempotent: the NRF event and the poll can both fire
  state.connected = true;
  // Flush any pending offline workout-accel to flash so the morning sync includes it.
  if (woAccel.length) writeWorkoutAccelFrame();
  // Entering the live/workout path: sample accel at 25 Hz for the classifier.
  applyAccelRate();
  // Give the link a beat to settle, then sync the overnight log (morning sync).
  setTimeout(flushLog, 1500);
  if (uiVisible) drawUI();
}

function onDisconnect() {
  if (!state.connected) return;  // idempotent (see onConnect)
  state.connected = false;
  // Back to the overnight path: drop accel to 12.5 Hz (actigraphy + power).
  applyAccelRate();
  // Abandon any partial live frame; resume compact overnight logging fresh.
  resetFrame();
  logAccum = [];
  logMotion = 0;
  lastAccelV = null;
  if (uiVisible) drawUI();
}

// ----- Start / stop streaming -----------------------------------------------

// Pick the accel poll cadence: 25 Hz during any WORKOUT (connected live, OR offline once the
// locomotion gate has armed) so the classifier sees its validated rate; 12.5 Hz the rest of the
// time (overnight actigraphy + power).
function applyAccelRate() {
  var fast = state.streaming && (state.connected || state.workout);
  var ms = fast ? CFG.ACCEL_MS_LIVE : CFG.ACCEL_MS_OVERNIGHT;
  // A coach-primed activity carries its own accel cadence (e.g. 12.5 Hz for a run); honor it
  // while that activity's workout runs so a reconnect doesn't snap us back to 25 Hz.
  if (primed && state.workout && primed.accelHz) ms = Math.round(1000 / primed.accelHz);
  try { Bangle.setPollInterval(ms); } catch (e) {}
}

function startStreaming() {
  if (state.streaming) return;
  state.streaming = true;
  resetFrame();
  // Power up the heart-rate sensor. Bangle.setHRMPower(1) turns on the VC31
  // LED + AFE; without it no HRM/HRM-raw events fire.
  Bangle.setHRMPower(1, "titan");
  // Accel is on by default on Bangle.js 2; setPollInterval tightens cadence.
  applyAccelRate();
  drawUI();
}

function stopStreaming() {
  if (!state.streaming) return;
  state.streaming = false;
  flushFrame(); // emit whatever partial frame we have
  Bangle.setHRMPower(0, "titan");
  endWorkout(); // close any workout (flushes T6, powers GPS down)
  motionEMA = 0;
  motionAboveSince = motionBelowSince = 0;
  drawUI();
}

function toggleStreaming() {
  if (state.streaming) stopStreaming(); else startStreaming();
}

// ----- On-watch UI ----------------------------------------------------------
var uiVisible = true;

// ----- Premium watch face --------------------------------------------------
var C = {                          // palette — pure colors for text/icons; dithered for fills/ring
  white: "#ffffff", dim: "#7a7a8c", faint: "#4a4a55", track: "#23232e",
  rec: "#ff3355", heart: "#ff3b5b", cyan: "#33d6ff", mint: "#2fe6b0",
  amber: "#ffb020", violet: "#8b7bff", bg: "#000000",
};

function battIcon(x, y, pct) {
  g.setColor(C.dim); g.drawRect(x, y, x + 20, y + 10); g.fillRect(x + 21, y + 3, x + 22, y + 7);
  g.setColor(pct < 20 ? C.rec : (pct < 50 ? C.amber : C.mint));
  g.fillRect(x + 2, y + 2, x + 2 + Math.max(0, Math.round(16 * pct / 100)), y + 8);
}

function heartIcon(cx, cy, s) {
  g.setColor(C.heart);
  g.fillCircle(cx - s * 0.42, cy - s * 0.18, s * 0.5);
  g.fillCircle(cx + s * 0.42, cy - s * 0.18, s * 0.5);
  g.fillPoly([cx - s * 0.92, cy - s * 0.05, cx + s * 0.92, cy - s * 0.05, cx, cy + s]);
}

// A thick ring arc (the app's signature ring, drawn with stamped circles — sharp at any size).
// a0/a1 in turns (0..1) clockwise from top. Used as a full faint track + a colored value arc.
function arc(cx, cy, r, w, a0, a1, col) {
  g.setColor(col);
  var step = 0.9 / r;                       // ~1px spacing → smooth, no gaps
  for (var t = a0; t <= a1; t += step) {
    var a = t * 6.2832;
    g.fillCircle(cx + r * Math.sin(a), cy - r * Math.cos(a), w / 2);
  }
}

// HR → color + ring fraction (resting green → elevated amber → high red).
function hrColor(bpm) { return bpm >= 140 ? C.rec : (bpm >= 100 ? C.amber : C.mint); }
function hrFrac(bpm) { return Math.max(0.02, Math.min(1, (bpm - 40) / 150)); }

// Page indicator dots (which of the 3 swipeable faces you're on).
function pageDots() {
  var W = g.getWidth(), H = g.getHeight(), n = 3, sp = 11, x0 = W / 2 - (n - 1) * sp / 2;
  for (var i = 0; i < n; i++) {
    g.setColor(i === page ? C.white : C.faint);
    g.fillCircle(x0 + i * sp, H - 7, i === page ? 3 : 2);
  }
}

// Page 0 — Heart / home: live BPM + capture/sync state.
function drawHome() {
  var W = g.getWidth(), H = g.getHeight();
  g.setFont("6x8", 1); g.setFontAlign(-1, 0);
  g.setColor(state.streaming ? C.rec : C.faint); g.fillCircle(11, 13, 4);
  g.setColor(state.streaming ? C.white : C.dim);
  g.drawString(state.streaming ? "RECORDING" : "IDLE", 21, 13);
  battIcon(W - 27, 8, state.battery);

  g.setFontAlign(0, 0);
  if (state.streaming) {
    g.setFont("6x8", 1);
    g.setColor(state.connected ? C.cyan : C.amber);
    g.drawString(state.connected ? "SYNCING LIVE" : "LOGGING", W / 2, 34);

    // HR ring (faint full track + colored value arc) with the BPM in the middle.
    var cx = W / 2, cy = 100, r = 50, bpm = state.bpm || 0, col = hrColor(bpm);
    arc(cx, cy, r, 7, 0, 1, C.track);
    if (bpm) arc(cx, cy, r, 7, 0, hrFrac(bpm), col);
    heartIcon(cx, cy - 22, 9);
    g.setColor(C.white); g.setFont("Vector", 44); g.drawString((bpm || "--") + "", cx, cy + 6);
    g.setColor(C.dim); g.setFont("6x8", 1); g.drawString("BPM", cx, cy + 30);

    if (state.workout) {
      g.setColor(C.violet); g.setFont("6x8", 1);
      g.drawString(state.workoutManual ? "GYM" : (state.gps ? (state.gpsFix ? "RUN · GPS" : "RUN") : "WORKOUT"), W / 2, 160);
    }
  } else {
    heartIcon(W / 2, 60, 13);
    g.setColor(C.white); g.setFont("Vector", 40); g.drawString("TITAN", W / 2, 104);
    g.setColor(C.cyan); g.setFont("6x8", 1); g.drawString("RECOVERY BAND", W / 2, 132);
    g.setColor(C.faint); g.drawString("tap: start   2x-tap: pair", W / 2, 152);
  }
}

// Page 1 — Clock: big time, date, glanceable HR + battery.
function drawClock() {
  var W = g.getWidth(), H = g.getHeight(), d = new Date();
  var hh = ("0" + d.getHours()).substr(-2), mm = ("0" + d.getMinutes()).substr(-2);
  battIcon(W - 27, 8, state.battery);
  g.setColor(C.white); g.setFont("Vector", 62); g.setFontAlign(0, 0);
  g.drawString(hh + ":" + mm, W / 2, 78);
  var DOW = ["SUN", "MON", "TUE", "WED", "THU", "FRI", "SAT"];
  var MON = ["JAN", "FEB", "MAR", "APR", "MAY", "JUN", "JUL", "AUG", "SEP", "OCT", "NOV", "DEC"];
  g.setColor(C.cyan); g.setFont("6x8", 1);
  g.drawString(DOW[d.getDay()] + "  " + d.getDate() + " " + MON[d.getMonth()], W / 2, 116);
  if (state.bpm) {
    heartIcon(W / 2 - 22, 146, 8);
    g.setColor(C.white); g.setFont("6x8", 2); g.setFontAlign(-1, 0);
    g.drawString(state.bpm + "", W / 2 - 10, 146);
  }
}

// Page 2 — Signals: the live data streams in one place.
function drawSignals() {
  var W = g.getWidth();
  g.setColor(C.white); g.setFont("6x8", 2); g.setFontAlign(0, 0);
  g.drawString("SIGNALS", W / 2, 16);
  g.setFont("6x8", 1);
  var y = 42;
  function row(label, val, col) {
    g.setColor(C.dim); g.setFontAlign(-1, 0); g.drawString(label, 10, y);
    g.setColor(col || C.white); g.setFontAlign(1, 0); g.drawString(val, W - 10, y);
    y += 18;
  }
  row("Heart rate", (state.bpm || "--") + " bpm", C.heart);
  row("Samples", state.ppgCount + "", C.cyan);
  row("Frames sent", state.framesSent + "", C.cyan);
  row("Buffered", (state.logged / 1024).toFixed(1) + " KB", C.amber);
  row("Link", state.connected ? "live" : "logging", state.connected ? C.mint : C.amber);
  row("Workout", state.workout ? (state.workoutManual ? "gym" : "auto") : "—", C.violet);
  row("Battery", state.battery + "%", C.mint);
}

// Dispatcher: clears, draws the current page, then the page dots. All the existing drawUI()
// calls on sensor events just redraw whichever face you're on.
function drawUI() {
  if (!uiVisible) return;
  if (pairTimer) return;            // pairing screen owns the display
  if (!Bangle.isLCDOn()) return;    // power: don't redraw while the screen is asleep
  g.reset(); g.setColor(C.bg); g.fillRect(0, 0, g.getWidth(), g.getHeight());
  if (page === 1) drawClock();
  else if (page === 2) drawSignals();
  else drawHome();
  pageDots();
}

function refreshBattery() {
  state.battery = E.getBattery();
  if (uiVisible) drawUI();
}

// ----- Wire everything up ---------------------------------------------------

Bangle.on("HRM-raw", onHRMRaw);
Bangle.on("HRM", onHRM);
Bangle.on("accel", onAccel);
Bangle.on("GPS", onGPS);
Bangle.on("pressure", onPressure);
NRF.on("connect", onConnect);
NRF.on("disconnect", onDisconnect);

// Touchscreen — swipe left/right to flip between the Heart, Clock and Signals faces. (Up/down
// are left to the Bangle OS for widgets/launcher.)
Bangle.on("swipe", function (lr) {
  if (pairTimer || !uiVisible || !lr) return;
  page = (page + (lr > 0 ? 1 : 2)) % 3;   // right = +1, left = -1 (≡ +2 mod 3)
  try { Bangle.buzz(15); } catch (e) {}
  drawUI();
});

// Repaint the moment the screen wakes (the per-event redraws are skipped while it's asleep).
Bangle.on("lcdPower", function (on) { if (on) drawUI(); });

// Keep the clock face honest: redraw on each minute boundary (only repaints if you're on the
// clock page and the screen is on — drawUI() guards both), instead of a wasteful 1 s interval.
function queueClockTick() {
  if (clockTickTimer) clearTimeout(clockTickTimer);
  clockTickTimer = setTimeout(function () {
    clockTickTimer = null;
    if (page === 1) drawUI();
    queueClockTick();
  }, 60000 - (Date.now() % 60000) + 50);
}
queueClockTick();

// Source-of-truth connection poll. Web Bluetooth (notably on macOS) can make the NRF
// 'disconnect' event fire spuriously while the GATT link is actually still up — which made
// the watch think no central was listening, so it silently routed every live PPG sample to
// the flash log instead of streaming it (the stream only appeared as a burst on each
// reconnect, when flushLog dumped the log). NRF.getSecurityStatus().connected reflects the
// real link state, so we reconcile against it every second and drive on/offConnect from it.
// The edge events above stay as the fast path; this just heals their flakiness.
setInterval(function () {
  var up = NRF.getSecurityStatus().connected;
  if (up === state.connected) return;
  if (up) onConnect(); else onDisconnect();
}, 1000);

// Hardware button toggles capture.
// ----- Pairing mode (Whoop-style: a deliberate gesture puts the band in a pairable state and
// shows a CODE on its own screen, so the app can list nearby bands by code and the user taps the
// one that matches what's on their wrist — unambiguous even with two bands side by side). The
// code is the BLE address suffix, which is exactly the "Bangle.js XXXX" advertised-name suffix.
// (pairUntil / pairTimer are declared in the globals block near the top.)
function pairCode() {
  try { return NRF.getAddress().substr(-5).replace(":", "").toUpperCase(); } // "7C3F"
  catch (e) { return "----"; }
}

function drawPairing() {
  var W = g.getWidth(), H = g.getHeight();
  g.reset(); g.setColor(C.bg); g.fillRect(0, 0, W, H);
  g.setFontAlign(0, 0);
  g.setColor(C.cyan); g.setFont("6x8", 2); g.drawString("PAIR THIS BAND", W / 2, 28);
  // the code in a bordered chip
  g.setColor("#10203a"); g.fillRect(20, 60, W - 20, 116);
  g.setColor(C.cyan); g.drawRect(20, 60, W - 20, 116);
  g.setColor(C.white); g.setFont("Vector", 46); g.drawString(pairCode(), W / 2, 90);
  g.setColor(C.dim); g.setFont("6x8", 1); g.drawString("tap this code in the app", W / 2, 134);
  var left = Math.max(0, Math.ceil(pairUntil - getTime()));
  g.setColor(C.faint); g.drawString(left + "s left  ·  press to exit", W / 2, H - 14);
}

function enterPairing() {
  pairUntil = getTime() + 120;          // pairable for 2 minutes
  try { Bangle.buzz(200); } catch (e) {}
  if (pairTimer) clearInterval(pairTimer);
  drawPairing();
  pairTimer = setInterval(function () {
    if (getTime() >= pairUntil) { exitPairing(); return; }
    drawPairing();
  }, 1000);
}

function exitPairing() {
  if (pairTimer) { clearInterval(pairTimer); pairTimer = null; }
  pairUntil = 0;
  drawUI();
}

// Button gestures. NOTE: a long button HOLD is reserved by the Bangle OS (it resets/reboots the
// watch), so we must NOT use a hold for our own actions beyond the brief gym toggle. Instead,
// pairing uses a DOUBLE-TAP — exactly like Whoop's "double-tap like a heartbeat" — which the OS
// never intercepts. Single tap = start/stop capture; double tap = enter pairing mode.
var btnDownT = 0, lastTapT = 0;
setWatch(function () { btnDownT = getTime(); }, BTN1, { repeat: true, edge: "rising" });
setWatch(function () {
  var held = getTime() - btnDownT;
  if (pairTimer) { exitPairing(); return; }      // in pairing → any press exits
  if (held > 1.2) {                               // a (short) hold while streaming = gym toggle
    if (state.streaming) toggleManualWorkout();
    lastTapT = 0;
    return;
  }
  // Short tap: a quick second tap (<0.5 s) is a DOUBLE-TAP → pairing mode.
  var now = getTime();
  if (now - lastTapT < 0.5) {
    lastTapT = 0;
    if (state.streaming) stopStreaming();         // back to idle, then show the pairing code
    enterPairing();
    return;
  }
  lastTapT = now;
  toggleStreaming();                              // single tap acts immediately
}, BTN1, { repeat: true, edge: "falling" });

function toggleManualWorkout() {
  if (state.workout && state.workoutManual) endWorkout();
  else startWorkout(true);
}

// ----- Inbound command channel (coach activity priming) ---------------------
// The watch does no HTTP — the BRIDGE (bridge.html / companion app) polls the Titan
// server's GET /api/devices/activity and relays what the user started in the coach chat
// ("going for a run") down to us as a NUS command. We switch into the right sensing mode
// for that activity instead of waiting for the on-watch motion gate to guess it.
//   C1:{"type":"run","gps":true,"hr_hz":1,"accel_hz":12.5}  → prime a typed activity
//   C0:                                                     → stand down (activity finished)
// Inbound NUS bytes are normally fed to the Espruino REPL, so we move the console to USB
// first (kept for debugging) — then this channel is ours alone.
try { E.setConsole("USB", { force: false }); } catch (e) {}

// `primed` and `cmdBuf` are declared near the top (frame globals) so the connection
// poll can't reference `primed` before its declaration runs. See that block.
Bluetooth.on("data", function (d) {
  cmdBuf += d;
  var nl;
  while ((nl = cmdBuf.indexOf("\n")) >= 0) {
    var line = cmdBuf.substr(0, nl).trim();
    cmdBuf = cmdBuf.substr(nl + 1);
    if (line.substr(0, 3) === "C1:") {            // prime: start a typed activity
      try { applyPriming(JSON.parse(line.substr(3))); } catch (err) { /* malformed — ignore */ }
    } else if (line.substr(0, 2) === "C0") {      // stand down
      primed = null;
      if (state.workout && state.workoutManual) endWorkout();
    }
  }
});

// Switch into the activity's sensing profile. We start a MANUAL workout (so a still gap
// mid-set / a red light won't auto-end it — the user explicitly began this), then honor
// the per-activity sampling: GPS only when it helps (off for swim/lift), accel rate to
// match the server's workout classifier.
function applyPriming(p) {
  primed = {
    type: (p && p.type) || "other",
    gps: !!(p && p.gps),
    accelHz: (p && p.accel_hz) || 25
  };
  if (!state.streaming) startStreaming();         // ensure HR + accel are powered
  startWorkout(true);                             // manual → only C0/finish ends it (applyAccelRate honors primed.accelHz)
  powerGps(primed.gps);                           // override the motion-gate default (off for swim/lift)
  try { Bangle.buzz(150); } catch (e) {}          // haptic ack so the user knows it primed
  if (uiVisible) drawUI();
}

// Reflect the current connection state at boot (in case a central is already
// bonded/connected when the app launches).
state.connected = NRF.getSecurityStatus().connected;

// Periodic UI/battery refresh (the sensor events drive most redraws, but BPM
// can go stale and battery needs polling).
var uiTimer = setInterval(function () {
  refreshBattery();
}, 5000);

// Continuous ambient barometer for all-day floors (its own power owner, so dropping GPS doesn't
// stop it). The 'pressure' events keep `ambientAlt` fresh; a slow timer batches them into T7.
try { if (Bangle.setBarometerPower) Bangle.setBarometerPower(1, "titan-alt"); } catch (e) {}
var altTimer = setInterval(sampleAltitude, CFG.ALT_SAMPLE_MS);

// Clean up if the app is unloaded by the launcher.
E.on("kill", function () {
  if (uiTimer) clearInterval(uiTimer);
  if (altTimer) clearInterval(altTimer);
  if (altBuf.length) { try { emitAltFrame(); } catch (e) {} }   // don't lose the partial minute
  try { Bangle.setHRMPower(0, "titan"); } catch (e) {}
  try {
    Bangle.setGPSPower(0, "titan");
    if (Bangle.setBarometerPower) { Bangle.setBarometerPower(0, "titan"); Bangle.setBarometerPower(0, "titan-alt"); }
  } catch (e) {}
});

// Initial paint.
refreshBattery();
drawUI();
