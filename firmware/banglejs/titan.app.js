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
  GPS_OFF_SEC: 90,                   // quiet time before GPS powers back off
  GPS_PROTO_VERSION: 4,              // T4 frame: per-fix speed + altitude (+ grade source)

  // During a WORKOUT we also stream the on-chip HR (bpm) — in-motion PPG→IBI is unreliable, so
  // workout HR uses the watch's hardware bpm register, not server-side peak detection. T5 frames
  // only flow while a workout is active (GPS armed), so sleep/rest never stream bpm.
  HR_PROTO_VERSION: 5                // T5 frame: per-reading bpm + confidence
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
  gps: false,          // is the GPS receiver powered right now?
  gpsFix: false,       // do we have a satellite fix yet?
  speed: 0             // last GPS speed (m/s), UI only
};

// On-watch locomotion gate for GPS (battery). A slow EMA of per-sample |Δaccel| (g);
// when it stays above CFG.GPS_ON_MOTION we arm GPS, when it stays below we stand down.
var motionEMA = 0;
var motionAboveSince = 0;   // getTime() when motion first crossed the on-threshold (0 = below)
var motionBelowSince = 0;   // getTime() when motion first dropped below it (0 = above)
var lastAltitude = null;    // last barometric altitude (m), for grade

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
    Bluetooth.println("T1:" + b64(buf.slice(0, len)));
    state.framesSent++;
  } catch (err) { /* link hiccup — drop this frame */ }
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
  // For SLEEP/REST this is UI only — never streamed (averaged BPM has discarded the ms IBI we
  // need for HRV). But during a WORKOUT (GPS armed) the on-chip bpm IS the signal we want, since
  // in-motion PPG→IBI is unreliable — so we stream it as a T5 frame.
  state.bpm = e.bpm | 0;
  state.conf = e.confidence | 0;
  if (state.streaming && state.gps) emitHrFrame(state.bpm, state.conf);
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
}

// ----- GPS gating: power the receiver only during real locomotion -----------
// This is the battery story. We never leave GPS on; the accel motion gate decides.
function updateGpsGate() {
  var now = getTime();
  if (motionEMA >= CFG.GPS_ON_MOTION) {
    motionBelowSince = 0;
    if (!motionAboveSince) motionAboveSince = now;
    // Sustained locomotion → arm GPS (and the barometer, for grade).
    if (!state.gps && (now - motionAboveSince) >= CFG.GPS_ARM_SEC) setGps(true);
  } else {
    motionAboveSince = 0;
    if (!motionBelowSince) motionBelowSince = now;
    // Quiet long enough → workout over, stand GPS back down.
    if (state.gps && (now - motionBelowSince) >= CFG.GPS_OFF_SEC) setGps(false);
  }
}

function setGps(on) {
  if (on === state.gps) return;
  state.gps = on;
  try {
    Bangle.setGPSPower(on ? 1 : 0, "titan");
    // The barometer (BMP280) is cheap; run it alongside GPS for altitude→grade + temperature.
    if (Bangle.setBarometerPower) Bangle.setBarometerPower(on ? 1 : 0, "titan");
  } catch (e) {}
  if (!on) { state.gpsFix = false; state.speed = 0; lastAltitude = null; }
  if (uiVisible) drawUI();
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
  // BMP280 barometric altitude — the grade source (Δaltitude / Δdistance, server-side).
  if (p && isFinite(p.altitude)) lastAltitude = p.altitude;
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
  state.connected = true;
  // Entering the live/workout path: sample accel at 25 Hz for the classifier.
  applyAccelRate();
  // Give the link a beat to settle, then sync the overnight log (morning sync).
  setTimeout(flushLog, 1500);
  if (uiVisible) drawUI();
}

function onDisconnect() {
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

// Pick the accel poll cadence: 25 Hz for a live (connected) workout so the server's
// classifier sees the rate it was validated at; 12.5 Hz overnight for actigraphy + power.
function applyAccelRate() {
  var ms = (state.streaming && state.connected) ? CFG.ACCEL_MS_LIVE : CFG.ACCEL_MS_OVERNIGHT;
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
  setGps(false); // never leave GPS on once capture stops
  motionEMA = 0;
  motionAboveSince = motionBelowSince = 0;
  drawUI();
}

function toggleStreaming() {
  if (state.streaming) stopStreaming(); else startStreaming();
}

// ----- On-watch UI ----------------------------------------------------------
var uiVisible = true;

function drawUI() {
  if (!uiVisible) return;
  g.reset();
  g.clearRect(0, 0, g.getWidth(), g.getHeight());

  // Title
  g.setFontAlign(0, 0);
  g.setFont("Vector", 22);
  g.drawString("TITAN", g.getWidth() / 2, 22);

  // Status line: streaming + link
  g.setFont("6x8", 2);
  g.setFontAlign(-1, 0);
  var statusY = 55;
  g.drawString(state.streaming ? "REC" : "idle", 6, statusY);
  g.setFontAlign(1, 0);
  g.drawString(state.connected ? "BLE ↑" : "log", g.getWidth() - 6, statusY);

  // BPM (UI only)
  g.setFontAlign(0, 0);
  g.setFont("Vector", 40);
  g.drawString((state.bpm || "--") + "", g.getWidth() / 2, 100);
  g.setFont("6x8", 1);
  g.drawString("bpm (display only)", g.getWidth() / 2, 128);

  // Counters
  g.setFontAlign(-1, 0);
  g.setFont("6x8", 1);
  var y = 145;
  g.drawString("samples: " + state.ppgCount, 6, y); y += 12;
  g.drawString("frames:  " + state.framesSent, 6, y); y += 12;
  g.drawString("logged:  " + (state.logged / 1024).toFixed(1) + "KB", 6, y); y += 12;
  // GPS only powers on during a detected workout — show why the battery is (or isn't) spending.
  g.drawString("gps:     " + (state.gps ? (state.gpsFix ? "fix " + state.speed.toFixed(1) : "search") : "off"), 6, y); y += 12;
  g.drawString("batt:    " + state.battery + "%", 6, y);

  // Footer hint
  g.setFontAlign(0, 0);
  g.setFont("6x8", 1);
  g.drawString(state.streaming ? "BTN: stop" : "BTN: start", g.getWidth() / 2, g.getHeight() - 10);
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

// Hardware button toggles capture.
setWatch(toggleStreaming, BTN1, { repeat: true, edge: "rising" });

// Reflect the current connection state at boot (in case a central is already
// bonded/connected when the app launches).
state.connected = NRF.getSecurityStatus().connected;

// Periodic UI/battery refresh (the sensor events drive most redraws, but BPM
// can go stale and battery needs polling).
var uiTimer = setInterval(function () {
  refreshBattery();
}, 5000);

// Clean up if the app is unloaded by the launcher.
E.on("kill", function () {
  if (uiTimer) clearInterval(uiTimer);
  try { Bangle.setHRMPower(0, "titan"); } catch (e) {}
  try { Bangle.setGPSPower(0, "titan"); if (Bangle.setBarometerPower) Bangle.setBarometerPower(0, "titan"); } catch (e) {}
});

// Initial paint.
refreshBattery();
drawUI();
