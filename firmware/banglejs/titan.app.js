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
  LOG_FILE: "titan.log",             // legacy single-file name (erased on boot; superseded by the ring)
  LOG_FRAME_SAMPLES: 125,            // ~5 s @ 25 Hz per compact frame (low overhead)
  // RING BUFFER: worn 24/7, the band can't keep filling flash when it isn't syncing. The log is a ring
  // of fixed-size segments; when full we evict the OLDEST segment to make room, so flash always holds
  // the most-recent capture (~LOG_SEGMENTS × LOG_SEG_BYTES ≈ a night), and old un-synced data is dropped.
  LOG_SEGMENTS: 6,                   // number of ring segments (more = finer eviction, less lost per wrap)
  LOG_SEG_BYTES: 700 * 1024,         // ~700 KB each → ~4.1 MB total (~16 h of overnight PPG)
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
  GPS_PROTO_VERSION: 5,              // T4 frame: per-fix lat/lon (route map) + speed + altitude (v5 added coords)

  // During a WORKOUT we also stream the on-chip HR (bpm) — but ONLY as a fallback for offline
  // sessions (no raw PPG reaches the server). When connected, the server recomputes in-motion HR
  // from the raw PPG (T1) + accel with motion-artifact suppression, which beats the on-chip bpm
  // that cadence-locks onto rep/grip rhythm. T5 frames flow while a workout is active.
  HR_PROTO_VERSION: 5,               // T5 frame: per-reading bpm + confidence + sport-mode tag

  // --- HRM tuning (THE heavy-lifting fix) ------------------------------------
  // The stock Bangle.js HRM algorithm runs in "normal" mode (hrmSportMode 0) by DEFAULT, which is
  // documented to sit flat (~40-90 bpm) under exertion because it never engages the motion-tolerant
  // sport path — exactly the "it didn't pick up my heavy set" failure. So during a WORKOUT we force
  // sport mode, and raise the PPG sample rate to 50 Hz for cleaner raw windows the server's in-motion
  // estimator can work with. At rest we use 25 Hz (the rate our overnight HRV is validated at) in
  // normal mode. Bangle.js 2 supports hrmPollInterval ∈ {10,20,40,80,160,200} ms; filtering is tuned
  // for 20-40 ms. Sport modes: -1 auto, 0 normal, 1 running (general motion), 2 biking.
  HRM_MS_REST: 40,                   // 25 Hz — overnight HRV's validated rate
  HRM_MS_WORKOUT: 20,                // 50 Hz — finer raw PPG for the server in-motion HR estimator
  HRM_SPORT_RUN: 1,                  // general motion-tolerant sport profile (lifting, running, etc.)
  HRM_SPORT_BIKE: 2,                 // biking sport profile (steadier wrist, different artifact band)

  // --- 24/7 OFFLINE REST MODE (battery + light buffer) -----------------------
  // Worn all day with NO phone in range, running the HRM continuously at 25 Hz AND logging raw PPG
  // would drain the battery and fill the flash ring in ~16 h. So while OFFLINE + at REST (no workout,
  // no sleep session) we DUTY-CYCLE the HRM: power it on just long enough to read a stable bpm, then
  // off — and log ONE lightweight T5 HR-trend point per cycle (no raw PPG). ~20 B/min → the ring
  // holds WEEKS and the reconnect sync is a quick trickle. Continuous capture + raw PPG resume the
  // instant a workout or a sleep session starts, or a phone connects (the real-time path). This is
  // the Whoop trick: sample sparsely when still, densely when it matters.
  REST_DUTY: true,                   // master switch for offline-rest duty-cycling
  REST_DUTY_ON_MS: 15000,            // measure window — long enough for the VC31 to settle + average
  REST_DUTY_PERIOD_MS: 60000,        // MOVING cadence: one reading/min (25% duty) — keeps HR responsive while you're active
  // Motion-gated rest cadence (Whoop's trick): when you're STILL (desk / sitting), relax the period to
  // save battery; the instant you move, snap back to the tight REST_DUTY_PERIOD_MS. motionEMA is the
  // same always-on accel signal the GPS gate + auto-detect already maintain, so the gating is free.
  REST_DUTY_PERIOD_STILL_MS: 180000, // STILL cadence: one reading every 3 min (~8% duty → ~3x the active rest battery)
  REST_STILL_MOTION: 0.07,           // motionEMA below this = "still" (under AUTO_MOTION_LO: typing stays still, walking trips it)

  // --- OVERNIGHT SLEEP duty-cycle (battery) ----------------------------------
  // Running the HRM continuously at 25 Hz all night (for HRV) drains a ~200 mAh Bangle.js 2 in ~16 h
  // — i.e. ~50% per 8-h night, which is what was observed. Whoop doesn't run its PPG continuously
  // either; it samples a clean burst periodically. So overnight we DUTY-CYCLE the HRM in sleep too:
  // power it on for a window long enough to settle the VC31 and capture a clean RR series (HRV), then
  // off. Crucially — unlike REST duty (which logs only a light T5 HR point) — raw PPG IS still logged
  // during each ON window (pushSample → logSample, because restModeActive() stays false in sleep), so
  // the server gets per-burst nocturnal HRV across the whole night. ~17% duty → ~6x the HRM battery.
  SLEEP_DUTY: true,                  // master switch for overnight sleep duty-cycling
  SLEEP_DUTY_ON_MS: 30000,           // 30 s clean HRV burst (settle + a solid RR series)
  SLEEP_DUTY_PERIOD_MS: 180000,      // one burst every 3 min (~17% duty); widen ON if HRV looks thin

  // Overnight the screen should stay dark through tossing/turning — the LCD backlight is ~17 mA (~57x
  // idle) and wrist-twist against a pillow can fire it hundreds of times a night. During a sleep
  // session we disable the ACCIDENTAL wakes (twist/touch/face-up) and keep wake-on-button, so one
  // click of the side button still lights the watch. Restored when the session ends.
  SLEEP_SCREEN_OFF: true,            // master switch for the overnight screen-dark behaviour

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
  ALT_FRAME_SAMPLES: 60,            // one T7 per minute (60 samples; ~136 B, well under the MTU)

  // --- STEPS → server (capture a phone-free walk) ----------------------------
  // The on-watch step count comes from Espruino's built-in pedometer (Oxford C-Step-Counter port,
  // ~1% on real walks) — we DON'T reinvent it; we just relay it. The server merges it with the
  // phone's step count as a per-day MAX (never a sum), so band+phone never double-count. We stream
  // the running day total periodically while connected + on connect, so a walk taken with the phone
  // left behind still lands the moment the band reconnects. The server upserts DailyActivity.steps.
  STEP_PROTO_VERSION: 8,            // T8 frame: { day-step total, local YYYY-MM-DD }
  STEP_SUMMARY_MS: 60000,          // stream the step total once a minute while connected

  // --- SLEEP session markers (user-toggled, like a workout) ------------------
  // Sleep is logged overnight (T2 PPG + actigraphy) and staged server-side. The Sleep FACE lets the
  // user explicitly mark bedtime (start) and wake (end). On "mark awake" we emit a T9 marker with the
  // confirmed sleep window; on the morning sync the server seals THAT window and — only because the
  // user confirmed it — fires the coach's sleep summary. No marker = no sleep notification (so a nap
  // or a still evening never triggers a wrong-time push).
  SLEEP_PROTO_VERSION: 9,          // T9 frame: { confirmed flag, bedtime epoch, wake epoch }

  // --- AUTO workout detection (Whoop-style: no button) -----------------------
  // Watch motion energy (the accel EMA) + HR-above-resting and auto-start/stop a workout so you
  // never have to tap. The hard case is LIFTING (long inter-set rests): HR stays elevated THROUGH the
  // rest, so we gate the END on HR returning to baseline AND motion going quiet for a long hold — a
  // still wrist with a high HR is "resting between sets", not "done". This is Whoop's exact trick.
  // Numbers seeded from the NHANES/actigraphy + Whoop/Apple literature; tune on real device data.
  AUTO_DETECT: false,              // OFF — workouts start ONLY on a manual double-click (auto-detect
                                   // misfired on everyday exertion like carrying groceries upstairs)
  AUTO_TICK_MS: 5000,              // evaluate the detector once every 5 s (one "epoch")
  AUTO_MOTION_HI: 0.20,            // motion-EMA above this = vigorous activity → start candidate (cardio)
  AUTO_MOTION_LO: 0.09,            // below this = "quiet" (the HI/LO gap is hysteresis → no flapping)
  AUTO_START_SEC: 90,             // sustained ACTIVE this long → auto-start (Apple/Whoop feel)
  AUTO_END_SEC: 300,              // sustained QUIET+recovered this long → auto-end (≥ longest lifting rest)
  AUTO_HR_START_DELTA: 25,        // HR > resting + this (sustained) corroborates a workout — catches lifting
  AUTO_HR_END_DELTA: 10,          // HR back within resting + this = recovered (half of the end gate)
  REST_HR_ALPHA: 0.02             // slow EMA for the personal resting-HR baseline (low-motion windows only)
};

// ----- State ----------------------------------------------------------------
var state = {
  streaming: false,    // user toggled capture on?
  connected: false,    // is a BLE central subscribed to NUS?
  bpm: 0,              // last HRM bpm (UI only)
  conf: 0,             // last HRM confidence (UI only)
  hrmSport: 0,         // active Bangle sport mode (0 normal / 1 run / 2 bike) — tags T5 frames
  restHr: null,        // personal resting-HR baseline (EMA from low-motion windows) — gates auto-detect
  swMode: "idle",      // Stopwatch face: "idle" | "watch" (plain timer) | "sleep" (logs as a sleep session)
  swStartMs: 0,        // unix-ms the running timer started
  count: 0,            // Counter face: a plain tally (tap +1, double-click button resets) — RAM only
  ppgCount: 0,         // samples captured this session (UI counter)
  framesSent: 0,       // BLE frames emitted/flushed
  logged: 0,           // approx bytes held in the overnight log ring (UI counter)
  lastAccel: { x: 0, y: 0, z: 0 }, // most recent accel reading (g)
  battery: 0,
  charging: false,     // is it on the charge cradle right now? (drives the bolt + buzz cue)
  fullBuzzed: false,   // already nudged "unplug, I'm full" this charge session?
  workout: false,      // a workout is in progress (drives HR/accel capture + 25 Hz rate)
  workoutManual: false,// started by hand (a gym session) → only ends by hand, not on a motion lull
  gps: false,          // is the GPS receiver powered right now? (a subset of a workout)
  gpsFix: false,       // do we have a satellite fix yet?
  speed: 0             // last GPS speed (m/s), UI only
};

// On-watch locomotion gate for GPS (battery). A slow EMA of per-sample |Δaccel| (g);
// when it stays above CFG.GPS_ON_MOTION we arm GPS, when it stays below we stand down.
var motionEMA = 0;
var activeSince = 0;        // getTime() the auto-detect ACTIVE condition first held (0 = not active)
var quietSince = 0;        // getTime() the auto-detect QUIET+recovered condition first held (0 = not quiet)
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
var PAGES = 7;            // swipeable faces: 0 Heart · 1 Clock · 2 Steps · 3 Status · 4 Stopwatch · 5 Counter · 6 Run
var STOPWATCH_PAGE = 4;   // the Stopwatch face (tap = plain timer; double-click button = log as sleep)
var COUNTER_PAGE = 5;     // the Counter face (tap = +1; double-click button = reset to zero)
var RUN_PAGE = 6;         // the Run face (tap = start/stop a GPS-tracked run → the app's route map)
var page = 0;             // current face (swipe to change)
// Run face state — a GPS-tracked run started from the watch; the workout's T4 coords build the route.
var runActive = false;    // a run is being tracked
var runStartMs = 0;       // run start (device ms)
var runDistM = 0;         // accumulated distance (m), summed from GPS fixes (haversine)
var runLastLat = null, runLastLon = null;  // last coord, for the distance increment
var runTimer = null;      // 1 Hz repaint while the run face is live (so the timer ticks)
var lastSwipeT = 0;       // getTime() of the last page swipe — so the tap that ends a swipe isn't a sleep toggle
var clockTickTimer = null; // minute-boundary redraw for the clock face

// Default timezone so the clock reads correctly out of the box without a phone: Tijuana / Baja
// California (PST/PDT). The app's time-sync (C2:) overrides this with the device's exact current
// offset — including DST — the moment it connects, so this is only the cold-boot fallback.
try { E.setTimeZone(-7); } catch (e) {}

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
var logSeg = 0;       // current ring segment index (0..LOG_SEGMENTS-1)
var logSegBytes = 0;  // bytes written to the current segment

// One ring segment's StorageFile name.
function logName(i) { return "titan.l" + i; }

// Append one line to the overnight log RING. When the current segment is full, advance and ERASE the
// next segment first — that segment holds the OLDEST data, so this evicts it to make room. The ring
// therefore never overflows and always keeps the most recent ~LOG_SEGMENTS×LOG_SEG_BYTES of capture.
function appendLog(line) {
  var data = line + "\n";
  var len = data.length;
  if (logSegBytes + len > CFG.LOG_SEG_BYTES) {
    logSeg = (logSeg + 1) % CFG.LOG_SEGMENTS;
    try { require("Storage").open(logName(logSeg), "r").erase(); } catch (e) {}   // evict the oldest
    logSegBytes = 0;
  }
  try {
    require("Storage").open(logName(logSeg), "a").write(data);
    logSegBytes += len;
    state.logged += len;
  } catch (err) { /* storage unavailable — drop */ }
}

function logSample(ppg) {
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
  appendLog("T2:" + b64(buf));
  logAccum = [];
  logMotion = 0;
}

// Morning sync: stream the whole ring (oldest segment → newest), erasing each as it's sent, then reset.
function flushLog() {
  if (!state.connected) return;
  writeLogFrame(); // flush any partial T2 frame first
  for (var k = 1; k <= CFG.LOG_SEGMENTS; k++) {
    var seg = (logSeg + k) % CFG.LOG_SEGMENTS;   // (logSeg+1) is the oldest; logSeg itself is newest
    var sf;
    try { sf = require("Storage").open(logName(seg), "r"); } catch (err) { continue; }
    var line = sf.readLine();
    while (line !== undefined) {
      var trimmed = line.charCodeAt(line.length - 1) === 10 ? line.substr(0, line.length - 1) : line;
      if (trimmed.length) {
        try { Bluetooth.println(trimmed); state.framesSent++; }
        catch (err) { return; } // link died mid-sync — keep what's left, retry next connect
      }
      line = sf.readLine();
    }
    try { require("Storage").open(logName(seg), "r").erase(); } catch (e) {}
  }
  logSeg = 0;
  logSegBytes = 0;
  state.logged = 0;
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
  if (state.connected) pushLiveSample(ppg);
  else if (!restModeActive()) logSample(ppg);   // rest+offline logs a light T5 trend, not raw PPG
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
  dv.setUint8(3, state.hrmSport & 0xff);  // sport mode this reading came from (0 normal / 1 run / 2 bike)
  dv.setUint32(4, (nowMs - hi * 4294967296) >>> 0, true);
  dv.setUint32(8, hi >>> 0, true);
  var line = "T5:" + b64(buf);
  if (state.connected) {
    try { Bluetooth.println(line); state.framesSent++; } catch (e) {}
  } else {
    appendLog(line);
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
  appendLog("T6:" + b64(buf));
  woAccel = [];
}

// ----- Workout detection + GPS gating ---------------------------------------
// A WORKOUT (state.workout) drives sport-mode HR + 3-axis-accel capture. It can begin two ways:
//   - AUTO (Whoop-style, the default): updateAutoDetect() sees sustained activity and starts it —
//     no button. An auto workout can auto-END on a long quiet+HR-recovered lull.
//   - MANUAL (double-tap, or the coach priming a typed activity): pinned (workoutManual) so a still
//     gap between sets never ends it — only a second double-tap / a coach stand-down ends it.
// GPS (state.gps) is a battery-hungry SUBSET of a workout — powered only when a fix can plausibly
// help (outdoors), dropped indoors after GPS_FIX_TIMEOUT.
function updateGpsGate() {
  var now = getTime();
  // Indoors (treadmill / weights room): GPS never gets a fix → stop wasting battery on it, but
  // KEEP the workout. The accel still classifies run/walk/lift and logs via T6.
  if (state.gps && !state.gpsFix && (now - gpsArmedT) >= CFG.GPS_FIX_TIMEOUT) powerGps(false);
}

// Maintain a personal resting-HR baseline from LOW-MOTION windows only (the documented method — a
// resting HR measured while you're actually moving is meaningless). Drives the HR gates below.
function updateRestHr() {
  if (motionEMA < CFG.AUTO_MOTION_LO && state.bpm >= 40 && state.bpm <= 120) {
    state.restHr = (state.restHr === null) ? state.bpm
                 : state.restHr * (1 - CFG.REST_HR_ALPHA) + state.bpm * CFG.REST_HR_ALPHA;
  }
}

// The Whoop-style hands-free detector. Runs once per AUTO_TICK epoch. ACTIVE = vigorous motion OR
// elevated HR (the HR arm is what catches bursty lifting between reps). QUIET = low motion AND HR
// recovered to baseline — the conjunction is the whole trick: during an inter-set rest the wrist is
// still but HR is still high, so QUIET is false and the session stays open. Start/end each require a
// SUSTAINED hold (hysteresis + min-bout), so it never flaps. Manual workouts are left alone.
function updateAutoDetect() {
  if (!CFG.AUTO_DETECT || !state.streaming || state.swMode === "sleep") return;   // never auto-start a workout mid-sleep
  updateRestHr();
  var now = getTime();
  var rhr = state.restHr;
  var hrElevated = rhr !== null && state.bpm > rhr + CFG.AUTO_HR_START_DELTA;
  var hrRecovered = rhr === null || state.bpm <= rhr + CFG.AUTO_HR_END_DELTA;
  var active = motionEMA > CFG.AUTO_MOTION_HI || hrElevated;
  var quiet = motionEMA < CFG.AUTO_MOTION_LO && hrRecovered;

  if (!state.workout) {
    quietSince = 0;
    if (active) {
      if (activeSince === 0) activeSince = now;
      if (now - activeSince >= CFG.AUTO_START_SEC) {
        activeSince = 0;
        startWorkout(false);                                   // AUTO → may auto-end on a sustained lull
        try { Bangle.buzz(80); setTimeout(function () { try { Bangle.buzz(80); } catch (e) {} }, 160); } catch (e) {}
      }
    } else {
      activeSince = 0;
    }
  } else if (!state.workoutManual) {                            // only AUTO workouts auto-end
    activeSince = 0;
    if (quiet) {
      if (quietSince === 0) quietSince = now;
      if (now - quietSince >= CFG.AUTO_END_SEC) {
        quietSince = 0;
        endWorkout();
      }
    } else {
      quietSince = 0;                                           // motion OR still-elevated HR (a rest) keeps it open
    }
  }
}

function startWorkout(manual) {
  if (state.workout) { if (manual) state.workoutManual = true; return; }
  state.workout = true;
  state.workoutManual = !!manual;
  powerGps(true);          // try for outdoor pace; dropped after GPS_FIX_TIMEOUT if no fix
  reconcileHrm();          // continuous HRM + motion-tolerant SPORT mode + 50 Hz PPG (heavy-lifting fix)
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
  reconcileHrm();          // back to rest: continuous if connected, else duty-cycle the HRM
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
  // Position for the route map: only when the fix carries real coords (NaN before/at a marginal fix).
  var lat = (g.lat !== undefined && !isNaN(g.lat)) ? g.lat : null;
  var lon = (g.lon !== undefined && !isNaN(g.lon)) ? g.lon : null;
  emitGpsFrame(state.speed, alt, g.satellites | 0, lat, lon);
  // Live on-watch run distance: sum the gap between consecutive fixes (the server recomputes its own
  // distance from the full track on seal; this is just the glanceable number on the Run face).
  if (runActive && lat !== null && lon !== null) {
    if (runLastLat !== null) runDistM += haversineM(runLastLat, runLastLon, lat, lon);
    runLastLat = lat; runLastLon = lon;
  }
}

// Great-circle distance between two lat/lon points, in metres (on-watch, for the live run readout).
function haversineM(la1, lo1, la2, lo2) {
  var toR = Math.PI / 180;
  var dLa = (la2 - la1) * toR, dLo = (lo2 - lo1) * toR;
  var a = Math.sin(dLa / 2) * Math.sin(dLa / 2)
    + Math.cos(la1 * toR) * Math.cos(la2 * toR) * Math.sin(dLo / 2) * Math.sin(dLo / 2);
  return 2 * 6371000 * Math.asin(Math.min(1, Math.sqrt(a)));
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
  } else {
    appendLog(line);
  }
}

// T4 frame (v5, 24 B): one GPS fix → lat/lon (deg ×1e7, the route map) + speed (km/h ×100) +
// altitude (m ×10) + sats. Coords use the same -2147483648 = "no value" sentinel as altitude, so a
// speed-only fix still logs. Streamed live when connected; offline it's appended to the log for sync.
var GPS_NULL = -2147483648;   // i32 min — shared "no value" sentinel for alt + lat + lon
function emitGpsFrame(speedKmh, altM, sats, lat, lon) {
  var buf = new ArrayBuffer(24);
  var dv = new DataView(buf);
  var nowMs = Math.round(getTime() * 1000);
  var hi = Math.floor(nowMs / 4294967296);
  dv.setUint8(0, CFG.GPS_PROTO_VERSION);
  dv.setUint8(1, sats > 255 ? 255 : sats);
  dv.setInt16(2, clampI16(Math.round(speedKmh * 100)), true);
  dv.setUint32(4, (nowMs - hi * 4294967296) >>> 0, true);
  dv.setUint32(8, hi >>> 0, true);
  dv.setInt32(12, (altM === null ? GPS_NULL : Math.round(altM * 10)) | 0, true);
  dv.setInt32(16, (lat === null || lat === undefined ? GPS_NULL : Math.round(lat * 1e7)) | 0, true);
  dv.setInt32(20, (lon === null || lon === undefined ? GPS_NULL : Math.round(lon * 1e7)) | 0, true);
  var line = "T4:" + b64(buf);
  if (state.connected) {
    try { Bluetooth.println(line); state.framesSent++; } catch (e) {}
  } else {
    appendLog(line);
  }
}

// ----- Steps → server (T8) --------------------------------------------------

// T8 frame: the built-in pedometer's running day total + the watch's LOCAL calendar date, so the
// server attributes the steps to the right day in the user's timezone and merges them with the
// phone's count as a per-day MAX. 12 bytes: [ver u8, year-2000 u8, month u8, day u8, steps u32,
// epochSec u32]. Only streamed live (steps self-accumulate on the watch; on reconnect the current
// total already includes any phone-free walk taken since the last sync).
function emitStepFrame() {
  if (!state.connected) return;
  var steps = stepCount();
  if (!isFinite(steps) || steps < 0) return;
  var d = new Date();
  var buf = new ArrayBuffer(12);
  var dv = new DataView(buf);
  dv.setUint8(0, CFG.STEP_PROTO_VERSION);
  dv.setUint8(1, (d.getFullYear() - 2000) & 0xff);
  dv.setUint8(2, (d.getMonth() + 1) & 0xff);
  dv.setUint8(3, d.getDate() & 0xff);
  dv.setUint32(4, steps >>> 0, true);
  dv.setUint32(8, Math.round(getTime()) >>> 0, true);
  try { Bluetooth.println("T8:" + b64(buf)); state.framesSent++; } catch (e) {}
}

// ----- BLE connection tracking ----------------------------------------------

function onConnect() {
  if (state.connected) return;   // idempotent: the NRF event and the poll can both fire
  state.connected = true;
  // Whoop-style always-on: once the app is paired/connected, stream automatically so HR flows and the
  // auto-detector can see your workouts without a tap. (One tap still stops it if you want it off.)
  if (!state.streaming) startStreaming();
  reconcileHrm();   // a phone is here now → leave any duty-cycle, go continuous for real-time data
  // Flush any pending offline workout-accel to flash so the morning sync includes it.
  if (woAccel.length) writeWorkoutAccelFrame();
  // Sync today's step total right away (captures a walk taken while the phone was left behind).
  setTimeout(emitStepFrame, 1800);
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
  reconcileHrm();   // no phone → if we're idle, drop into the battery-saving HR duty cycle
  if (uiVisible) drawUI();
}

// ----- Start / stop streaming -----------------------------------------------

// Pick the accel poll cadence: 25 Hz during any WORKOUT (connected live, OR offline once the
// locomotion gate has armed) so the classifier sees its validated rate; 12.5 Hz the rest of the
// time (overnight actigraphy + power).
function applyAccelRate() {
  // 25 Hz ONLY during an actual workout (the classifier's training rate). NOT merely when connected:
  // the Bangle.js built-in pedometer (getHealthStatus().steps) only counts at the default 80 ms /
  // 12.5 Hz poll — a non-default interval silently kills it. Holding 25 Hz just because the phone was
  // attached zeroed steps for the whole connected session (and after every run). Runs pin 12.5 Hz via
  // primed.accelHz below, so the step counter keeps running even mid-run.
  var fast = state.streaming && state.workout;
  var ms = fast ? CFG.ACCEL_MS_LIVE : CFG.ACCEL_MS_OVERNIGHT;
  // A coach-primed activity carries its own accel cadence (e.g. 12.5 Hz for a run); honor it
  // while that activity's workout runs so a reconnect doesn't snap us back to 25 Hz.
  if (primed && state.workout && primed.accelHz) ms = Math.round(1000 / primed.accelHz);
  try { Bangle.setPollInterval(ms); } catch (e) {}
}

// Which Bangle sport mode fits the current state: normal (0) at rest, biking (2) for a primed
// cycle/bike workout, general sport (1) for any other workout (lifting included). Lifting has no
// dedicated mode; mode 1 is the motion-tolerant general profile and is far better than normal.
function hrmSportFor() {
  if (!(state.streaming && state.workout)) return 0;            // rest → normal
  var t = primed && primed.type;
  if (t === "bike" || t === "cycle" || t === "cycling") return CFG.HRM_SPORT_BIKE;
  return CFG.HRM_SPORT_RUN;
}

// Tune the HRM for rest vs workout: force a motion-tolerant SPORT mode + 50 Hz raw PPG during a
// workout, normal mode + 25 Hz at rest. This is the single biggest accuracy fix for in-motion HR —
// the stock default (normal mode) is the documented cause of flat/wrong bpm under load. Called on
// every state transition that can change rest↔workout. Guarded: pre-2v19 firmware may lack an option.
function applyHrmMode() {
  if (!state.streaming) return;
  state.hrmSport = hrmSportFor();
  try {
    Bangle.setOptions({
      hrmSportMode: state.hrmSport,
      hrmPollInterval: state.workout ? CFG.HRM_MS_WORKOUT : CFG.HRM_MS_REST
    });
  } catch (e) { state.lastHrmErr = '' + e; }   // surface (don't spam) so a future option bug isn't invisible
}

// ----- 24/7 HRM power: continuous when it matters, duty-cycled when idle+offline ----------
// Offline rest = streaming, no central, no workout, no sleep session. THE battery-critical 24/7 case.
var restDutyTimer = null;    // timeout to the NEXT burst (null = mid-window or not duty-cycling)
var restDutyOnTimer = null;  // the "measure window done → read + power off" timeout

function restModeActive() {
  return state.streaming && !state.connected && !state.workout && state.swMode !== "sleep";
}

// Motion-gated cadence: when you're STILL, relax the period (battery); when you're MOVING, keep it
// tight so HR stays responsive. motionEMA is the always-on accel signal — re-evaluated every cycle, so
// it tightens the instant you start moving and relaxes once you settle. Whoop does exactly this.
function restDutyPeriod() {
  return (motionEMA < CFG.REST_STILL_MOTION) ? CFG.REST_DUTY_PERIOD_STILL_MS : CFG.REST_DUTY_PERIOD_MS;
}

// One duty cycle: power the HRM on, let it settle for REST_DUTY_ON_MS, log the bpm as a light T5 trend
// point, power back off, then self-schedule the NEXT burst by the current motion state. The whole loop
// stands down the moment we leave rest mode (workout/connect/sleep own the power from there).
function restDutyTick() {
  restDutyTimer = null;
  if (!restModeActive()) { stopRestDuty(); return; }
  try { Bangle.setHRMPower(1, "titan"); } catch (e) {}
  applyHrmMode();   // force normal mode + 40 Hz rest cadence — else a burst after a workout inherits stale sportMode 1 + 20 ms (inflated HR + more power)
  if (restDutyOnTimer) clearTimeout(restDutyOnTimer);
  restDutyOnTimer = setTimeout(function () {
    restDutyOnTimer = null;
    if (state.bpm > 0) emitHrFrame(state.bpm, state.conf);   // → ring (offline), a tiny HR-trend point
    if (!restModeActive()) { stopRestDuty(); return; }       // left rest mid-window → don't power off, the new mode owns it
    try { Bangle.setHRMPower(0, "titan"); } catch (e) {}
    // gap = full cycle minus the ON window, so REST_DUTY_PERIOD_*_MS keeps meaning "one reading per period"
    restDutyTimer = setTimeout(restDutyTick, Math.max(1000, restDutyPeriod() - CFG.REST_DUTY_ON_MS));
  }, CFG.REST_DUTY_ON_MS);
}

function startRestDuty() {
  if (!CFG.REST_DUTY || restDutyTimer || restDutyOnTimer) return;   // already cycling (timer = waiting, onTimer = mid-window)
  restDutyTick();   // first reading immediately; it self-schedules from there
}

function stopRestDuty() {
  if (restDutyTimer) { clearTimeout(restDutyTimer); restDutyTimer = null; }
  if (restDutyOnTimer) { clearTimeout(restDutyOnTimer); restDutyOnTimer = null; }
}

// ----- Overnight SLEEP HRM duty-cycle (battery) -------------------------------------------
// Sleep wants HRV, which needs raw PPG + a clean RR series — but holding the HRM on continuously
// at 25 Hz all night flattens a ~175 mAh Bangle.js 2 in ~16 h (the CPU never deep-sleeps to run the
// VC31 algorithm — ~5 mA, ~85-90% of the overnight drain). Whoop doesn't sample continuously at rest
// either. So overnight we BURST: power the HRM on for SLEEP_DUTY_ON_MS (long enough to settle + grab a
// solid RR window for HRV), then off for the rest of the period. Unlike rest-duty (which logs only a
// light T5 point), restModeActive() stays false during sleep — so each ON window logs raw PPG to flash
// (T2) and HR (T1) through the normal streaming path, exactly as continuous sleep did, just in bursts.
var sleepDutyTimer = null;    // the per-period "take a burst" interval (null = not duty-cycling)
var sleepDutyOnTimer = null;  // the "burst window done → power off" timeout

function sleepModeActive() {
  return state.streaming && state.swMode === "sleep" && !state.workout;
}

function sleepDutyTick() {
  if (!sleepModeActive()) { stopSleepDuty(); return; }
  try { Bangle.setHRMPower(1, "titan"); } catch (e) {}
  applyHrmMode();   // 25 Hz rest cadence → clean RR + raw PPG for HRV during the burst
  if (sleepDutyOnTimer) clearTimeout(sleepDutyOnTimer);
  sleepDutyOnTimer = setTimeout(function () {
    sleepDutyOnTimer = null;
    // The HRM handler logged HR (T1) + raw PPG (T2) across the burst — just power the LED+AFE back down.
    if (sleepModeActive()) { try { Bangle.setHRMPower(0, "titan"); } catch (e) {} }
  }, CFG.SLEEP_DUTY_ON_MS);
}

function startSleepDuty() {
  if (!CFG.SLEEP_DUTY || sleepDutyTimer) return;
  sleepDutyTimer = setInterval(sleepDutyTick, CFG.SLEEP_DUTY_PERIOD_MS);
  sleepDutyTick();   // first burst immediately
}

function stopSleepDuty() {
  if (sleepDutyTimer) { clearInterval(sleepDutyTimer); sleepDutyTimer = null; }
  if (sleepDutyOnTimer) { clearTimeout(sleepDutyOnTimer); sleepDutyOnTimer = null; }
}

// ----- Overnight screen-dark (battery) ----------------------------------------------------
// During a sleep session, suppress the accidental screen wakes (wrist-twist / touch / face-up) so the
// backlight doesn't fire all night against a pillow — but LEAVE wakeOnBTN1 alone, so one click of the
// side button still lights the watch to peek. We snapshot the current wake options on the way down and
// restore them exactly on the way back up (no-op if we never touched them).
var sleepWakeSaved = null;   // captured wake options (null = we haven't changed anything)

function sleepScreenOff() {
  if (!CFG.SLEEP_SCREEN_OFF || sleepWakeSaved) return;
  try {
    var o = Bangle.getOptions();   // recent firmware; falls back to Bangle.js 2 defaults if absent
    sleepWakeSaved = { wakeOnTwist: o.wakeOnTwist, wakeOnTouch: o.wakeOnTouch, wakeOnFaceUp: o.wakeOnFaceUp };
  } catch (e) {
    sleepWakeSaved = { wakeOnTwist: true, wakeOnTouch: false, wakeOnFaceUp: false };
  }
  try { Bangle.setOptions({ wakeOnTwist: false, wakeOnTouch: false, wakeOnFaceUp: false }); } catch (e) {}
  try { Bangle.setLocked(true); } catch (e) {}   // drop the screen now; the untouched wakeOnBTN1 still wakes it
}

function sleepScreenRestore() {
  if (!sleepWakeSaved) return;                    // nothing to undo
  try { Bangle.setOptions(sleepWakeSaved); } catch (e) {}
  sleepWakeSaved = null;
}

// Single source of truth for HRM power, called on every state transition. Sleep → burst duty-cycle
// (battery); rest+offline → per-minute duty-cycle; everything else (connected live, workout) →
// continuous HRM at the right sport mode + rate. If SLEEP_DUTY is off, sleep falls through to
// continuous (the old behaviour) so the night is never left un-sampled.
function reconcileHrm() {
  if (!state.streaming) { stopRestDuty(); stopSleepDuty(); return; }   // stopStreaming() owns the power-off
  if (sleepModeActive() && CFG.SLEEP_DUTY) {
    stopRestDuty();
    startSleepDuty();
  } else if (restModeActive()) {
    stopSleepDuty();
    startRestDuty();
  } else {
    stopRestDuty();
    stopSleepDuty();
    try { Bangle.setHRMPower(1, "titan"); } catch (e) {}
    applyHrmMode();
  }
}

// Persist the run state so a watch-only wearer keeps recording across a reboot (no app to re-arm it).
function setStreamPref(on) { try { require("Storage").write("titan.run", on ? "1" : "0"); } catch (e) {} }

function startStreaming() {
  if (state.streaming) return;
  state.streaming = true;
  setStreamPref(true);
  resetFrame();
  // reconcileHrm() powers the VC31 LED+AFE — continuously when connected/working out/sleeping, or in
  // a per-minute duty cycle when idle+offline. Without power no HRM/HRM-raw events fire.
  reconcileHrm();
  // Accel is on by default on Bangle.js 2; setPollInterval tightens cadence.
  applyAccelRate();
  drawUI();
}

function stopStreaming() {
  if (!state.streaming) return;
  state.streaming = false;
  setStreamPref(false);
  flushFrame(); // emit whatever partial frame we have
  stopRestDuty();
  stopSleepDuty();
  sleepScreenRestore();   // safety net: never leave twist/touch wake disabled if a sleep was active
  Bangle.setHRMPower(0, "titan");
  endWorkout(); // close any workout (flushes T6, powers GPS down)
  motionEMA = 0;
  activeSince = quietSince = 0;
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

function battIcon(x, y, pct, charging) {
  g.setColor(C.dim); g.drawRect(x, y, x + 20, y + 10); g.fillRect(x + 21, y + 3, x + 22, y + 7);
  g.setColor(charging ? C.cyan : (pct < 20 ? C.rec : (pct < 50 ? C.amber : C.mint)));
  g.fillRect(x + 2, y + 2, x + 2 + Math.max(0, Math.round(16 * pct / 100)), y + 8);
  if (charging) {                       // lightning bolt over the cell — the universal charging cue
    var bx = x + 10, by = y + 5;        // (community parity: widbatpc draws a bolt glyph too)
    g.setColor(C.amber);
    g.fillPoly([bx + 1, by - 5, bx - 3, by + 1, bx, by + 1, bx - 1, by + 5, bx + 3, by - 1, bx, by - 1]);
  }
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

// Page indicator dots.
function pageDots() {
  var W = g.getWidth(), H = g.getHeight(), sp = 14, x0 = W / 2 - (PAGES - 1) * sp / 2;
  for (var i = 0; i < PAGES; i++) {
    g.setColor(i === page ? C.white : C.faint);
    g.fillCircle(x0 + i * sp, H - 8, i === page ? 4 : 2);
  }
}

// Shared top bar: REC/IDLE pill (left) + battery (right). High-contrast, no dithered greys.
function topBar() {
  var W = g.getWidth();
  g.setFont("6x8", 2); g.setFontAlign(-1, 0);
  g.setColor(state.streaming ? C.rec : C.cyan); g.fillCircle(12, 16, 5);
  g.setColor(state.streaming ? C.white : C.cyan);
  g.drawString(state.streaming ? "REC" : "IDLE", 24, 16);
  battIcon(W - 28, 9, state.battery, state.charging);
}

// A centered tab title (big + pure color so it's actually readable on the 3-bit panel).
function tabTitle(t, col) {
  g.setFont("6x8", 2); g.setFontAlign(0, 0); g.setColor(col);
  g.drawString(t, g.getWidth() / 2, 38);
}

function stepCount() {
  try { return Bangle.getHealthStatus("day").steps; } catch (e) {}
  try { return Bangle.getStepCount(); } catch (e) {}
  return 0;
}

// Page 0 — HEART RATE: big BPM inside a color-mapped ring.
function drawHeart() {
  var W = g.getWidth();
  topBar();
  tabTitle("HEART RATE", C.heart);
  var cx = W / 2, cy = 104, r = 47, bpm = state.bpm || 0;
  arc(cx, cy, r, 8, 0, 1, C.track);
  if (bpm) arc(cx, cy, r, 8, 0, hrFrac(bpm), hrColor(bpm));
  g.setColor(C.white); g.setFont("Vector", 52); g.setFontAlign(0, 0);
  g.drawString((bpm || "--") + "", cx, cy);
  // Bottom status. A WORKOUT (sport-mode HR engaged) is the headline state — show it in red so you
  // can SEE the motion-tolerant mode is on before a heavy set.
  var hlabel = !state.streaming ? "TAP TO START"
             : state.workout ? "WORKOUT" + (state.connected ? "" : " ·LOG")
             : (state.connected ? "SYNCING" : "LOGGING");
  g.setColor(state.workout ? C.rec : (state.connected ? C.cyan : C.amber)); g.setFont("6x8", 2);
  g.drawString(hlabel, cx, 160);
}

// Page 1 — CLOCK: big time + date (timezone synced from the phone).
function drawClock() {
  var W = g.getWidth(), d = new Date();
  var hh = ("0" + d.getHours()).substr(-2), mm = ("0" + d.getMinutes()).substr(-2);
  battIcon(W - 28, 9, state.battery, state.charging);
  g.setColor(C.white); g.setFont("Vector", 68); g.setFontAlign(0, 0);
  g.drawString(hh + ":" + mm, W / 2, 86);
  var DOW = ["SUN", "MON", "TUE", "WED", "THU", "FRI", "SAT"];
  var MON = ["JAN", "FEB", "MAR", "APR", "MAY", "JUN", "JUL", "AUG", "SEP", "OCT", "NOV", "DEC"];
  g.setColor(C.cyan); g.setFont("6x8", 2);
  g.drawString(DOW[d.getDay()] + " " + d.getDate() + " " + MON[d.getMonth()], W / 2, 132);
}

// Page 2 — STEPS: big count.
function drawSteps() {
  var W = g.getWidth();
  topBar();
  tabTitle("STEPS", C.mint);
  g.setColor(C.white); g.setFont("Vector", 54); g.setFontAlign(0, 0);
  g.drawString(stepCount() + "", W / 2, 104);
  g.setColor(C.cyan); g.setFont("6x8", 2); g.drawString("TODAY", W / 2, 150);
}

// Page 3 — STATUS: a few big, readable operational rows.
function drawStatus() {
  var W = g.getWidth();
  tabTitle("STATUS", C.cyan);
  var y = 70;
  function row(label, val, col) {
    g.setFont("6x8", 1); g.setFontAlign(-1, 0); g.setColor(C.cyan); g.drawString(label, 14, y - 6);
    g.setFont("6x8", 2); g.setFontAlign(1, 0); g.setColor(col); g.drawString(val, W - 14, y);
    y += 28;
  }
  row("LINK", state.connected ? "LIVE" : (state.streaming ? "LOGGING" : "OFF"), state.connected ? C.mint : C.amber);
  row("BATTERY", state.battery + "%" + (state.charging ? (state.battery >= 100 ? " FULL" : " CHG") : ""),
      state.charging ? C.cyan : (state.battery < 20 ? C.rec : C.mint));
  row("SAMPLES", state.ppgCount + "", C.white);
  row("SYNCED", state.framesSent + "", C.cyan);
}

// Page 5 — STOPWATCH (doubles as the sleep timer). Tap = plain timer; double-click button = run it as
// a logged SLEEP session. Shows the elapsed time big, with the mode + how to stop.
function drawStopwatch() {
  var W = g.getWidth(), cx = W / 2;
  topBar();
  var sleep = state.swMode === "sleep";
  tabTitle(sleep ? "SLEEP" : "STOPWATCH", sleep ? C.violet : C.cyan);
  if (state.swMode === "idle") {
    g.setColor(C.dim); g.setFont("Vector", 40); g.setFontAlign(0, 0);
    g.drawString("00:00", cx, 100);
    g.setColor(C.cyan); g.setFont("6x8", 1);
    g.drawString("tap: start timer", cx, 150);
    g.setColor(C.violet);
    g.drawString("double-click button: sleep", cx, 166);
  } else {
    var s = Math.floor((getTime() * 1000 - state.swStartMs) / 1000);
    var hh = Math.floor(s / 3600), mm = Math.floor((s % 3600) / 60), ss = s % 60;
    var t = (hh > 0 ? hh + ":" + ("0" + mm).substr(-2) : mm) + ":" + ("0" + ss).substr(-2);
    g.setColor(sleep ? C.violet : C.white); g.setFont("Vector", 48); g.setFontAlign(0, 0);
    g.drawString(t, cx, 102);
    g.setColor(C.dim); g.setFont("6x8", 1);
    g.drawString(sleep ? "sleeping · tap when you wake" : "tap to stop", cx, 152);
  }
}

// Page 6 — COUNTER: a dead-simple tally. Tap the screen to add 1; double-click the button to reset
// to zero. Lives in RAM (resets on reboot) — it's a quick rep/set/round/lap counter, not a logged
// metric, so it never writes flash or emits a frame.
function drawCounter() {
  var W = g.getWidth(), cx = W / 2;
  topBar();
  tabTitle("COUNTER", C.amber);
  g.setColor(C.white); g.setFont("Vector", 64); g.setFontAlign(0, 0);
  g.drawString((state.count || 0) + "", cx, 102);
  g.setColor(C.cyan); g.setFont("6x8", 1);
  g.drawString("tap: +1", cx, 150);
  g.setColor(C.amber);
  g.drawString("double-click button: reset", cx, 166);
}

// "m:ss" (or "h:mm:ss") for an elapsed/pace second count — the Run face's time + pace.
function fmtMMSS(s) {
  s = Math.max(0, Math.round(s));
  var hh = Math.floor(s / 3600), mm = Math.floor((s % 3600) / 60), ss = s % 60;
  return (hh > 0 ? hh + ":" + ("0" + mm).substr(-2) : mm) + ":" + ("0" + ss).substr(-2);
}

// Page 7 — RUN: a GPS-tracked run you start from the watch. Tap to start (arms GPS + a workout pinned
// as a run); the live time / distance / pace show here, and the workout's T4 coords build the route
// map in the app on the next sync. Tap again to finish.
function drawRun() {
  var W = g.getWidth(), cx = W / 2;
  topBar();
  tabTitle("RUN", C.mint);
  if (!runActive) {
    g.setColor(C.dim); g.setFont("Vector", 40); g.setFontAlign(0, 0);
    g.drawString("0.00", cx, 96);
    g.setFont("6x8", 1); g.drawString("km", cx, 124);
    g.setColor(C.mint); g.drawString("tap: start run", cx, 150);
    g.setColor(state.gpsFix ? C.mint : C.amber);
    g.drawString(state.gpsFix ? "GPS ready" : "GPS searching", cx, 166);
    return;
  }
  var sec = (getTime() * 1000 - runStartMs) / 1000;
  var km = runDistM / 1000;
  g.setColor(C.white); g.setFont("Vector", 44); g.setFontAlign(0, 0);
  g.drawString(fmtMMSS(sec), cx, 80);
  g.setColor(C.mint); g.setFont("Vector", 34);
  g.drawString(km.toFixed(2) + " km", cx, 124);
  var pace = km > 0.02 ? fmtMMSS(sec / km) + " /km" : "--:-- /km";
  g.setColor(C.dim); g.setFont("6x8", 2); g.drawString(pace, cx, 158);
  g.setColor(state.gpsFix ? C.mint : C.amber); g.setFont("6x8", 1);
  g.drawString(state.gpsFix ? "tracking · tap to finish" : "acquiring GPS · tap to finish", cx, 184);
}

// Tap on the Run face: start or finish a GPS-tracked run. Start arms GPS + a manual run workout (so
// it logs T4 coords + T6 accel and seals as a run with a route); finish closes the workout.
function runTap() {
  if (runActive) {
    runActive = false;
    if (runTimer) { clearInterval(runTimer); runTimer = null; }
    endWorkout();
    try { Bangle.buzz(60); } catch (e) {}
  } else {
    runActive = true;
    runStartMs = Math.round(getTime() * 1000);
    runDistM = 0; runLastLat = null; runLastLon = null;
    primed = { type: "run", accelHz: 12.5 };   // pin the run profile (sport mode + cadence)
    if (!state.streaming) startStreaming();     // make sure the session is captured offline too
    startWorkout(true);                         // manual workout → arms GPS now (no motion gate)
    if (runTimer) clearInterval(runTimer);
    runTimer = setInterval(function () { if (page === RUN_PAGE) drawUI(); }, 1000);   // tick the live readout
    try { Bangle.buzz(120); } catch (e) {}
  }
  if (uiVisible) drawUI();
}

// Dispatcher: clears, draws the current page + page dots. All sensor-event drawUI() calls
// just repaint whichever face you're on.
function drawUI() {
  if (!uiVisible) return;
  if (pairTimer) return;            // pairing screen owns the display
  if (!Bangle.isLCDOn()) return;    // power: don't redraw while the screen is asleep
  g.reset(); g.setColor(C.bg); g.fillRect(0, 0, g.getWidth(), g.getHeight());
  if (page === 1) drawClock();
  else if (page === 2) drawSteps();
  else if (page === 3) drawStatus();
  else if (page === STOPWATCH_PAGE) drawStopwatch();
  else if (page === COUNTER_PAGE) drawCounter();
  else if (page === RUN_PAGE) drawRun();
  else drawHeart();
  pageDots();
}

function refreshBattery() {
  state.battery = E.getBattery();
  var chg = false;
  try { chg = Bangle.isCharging(); } catch (e) {}
  state.charging = chg;
  // Overcharge: the watch's charge IC already stops at full — there's NO software hook to limit it.
  // The smart move for LiPo longevity is to not LEAVE it at 100% on the cradle, so we buzz once when
  // it tops out to nudge a unplug. One nudge per charge session.
  if (chg && state.battery >= 100) {
    if (!state.fullBuzzed) {
      state.fullBuzzed = true;
      try { Bangle.buzz(150); setTimeout(function () { try { Bangle.buzz(150); } catch (e) {} }, 220); } catch (e) {}
    }
  } else if (!chg) {
    state.fullBuzzed = false;          // reset for the next charge session
  }
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
  lastSwipeT = getTime();
  page = (page + (lr > 0 ? 1 : PAGES - 1)) % PAGES;   // right = +1, left = −1
  try { Bangle.buzz(15); } catch (e) {}
  drawUI();
});

// Screen taps act on the two interactive faces: the Stopwatch (start/stop the timer) and the Counter
// (+1). We ignore a tap that lands right after a swipe (so flipping to the face doesn't fire it).
Bangle.on("touch", function () {
  if (pairTimer || !uiVisible) return;
  if (getTime() - lastSwipeT < 0.4) return;
  if (page === STOPWATCH_PAGE) swTap();
  else if (page === COUNTER_PAGE) bumpCounter();
  else if (page === RUN_PAGE) runTap();
});

// Repaint the moment the screen wakes (the per-event redraws are skipped while it's asleep). If we're
// in the offline HR duty cycle, also kick an immediate reading so a glance shows a fresh bpm, not a
// minute-old one.
Bangle.on("lcdPower", function (on) {
  if (!on) return;
  drawUI();
  if (restModeActive() && !restDutyOnTimer) restDutyTick();
});

// Charging cue: buzz the moment it's plugged in (a firm double-pulse) or unplugged (a short blip),
// and redraw so the battery shows the bolt. Community parity: widbatpc uses this same 'charging'
// event + Bangle.isCharging() + a lightning-bolt glyph (espruino/BangleApps widgets/widbatpc).
Bangle.on("charging", function (charging) {
  state.charging = charging;
  if (!charging) state.fullBuzzed = false;
  try {
    Bangle.buzz(charging ? 200 : 60);
    if (charging) setTimeout(function () { try { Bangle.buzz(200); } catch (e) {} }, 280);
  } catch (e) {}
  if (uiVisible) { drawUI(); try { g.flip(); } catch (e) {} }
});

// Keep the clock face honest: redraw on each minute boundary (only repaints if you're on the
// clock page and the screen is on — drawUI() guards both), instead of a wasteful 1 s interval.
function queueClockTick() {
  if (clockTickTimer) clearTimeout(clockTickTimer);
  clockTickTimer = setTimeout(function () {
    clockTickTimer = null;
    if (page === 1) drawUI();   // clock face shows the time
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
  // Free the radio so the phone can SEE us: a Bangle stops advertising while another central holds
  // the connection (very often the Espruino IDE, still connected right after a flash). Dropping it
  // makes the band advertise again so the app's scan can discover it.
  try { NRF.disconnect(); } catch (e) {}
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

// Button gestures — CLICK BURSTS ONLY. A long button HOLD is reserved by the Bangle OS (it REBOOTS
// the watch) and cannot be intercepted, so we never use holds for anything. Instead we count taps in
// a quick burst and act once it settles:
// 1- and 2-tap are FACE-SCOPED so you never start a recording by accident from the clock/steps:
//   1 tap  → start/stop streaming (capture) — ONLY on the Heart face
//   2 taps → context-aware: Heart = start/stop a WORKOUT · Stopwatch = sleep · Counter = reset
//   3 taps → enter pairing mode — works from ANY face (the one global gesture, rare one-time setup)
// (Whoop-style "tap like a heartbeat", and it sidesteps the reboot-on-hold entirely.)
var tapCount = 0, tapTimer = null;
var TAP_GAP = 0.45;   // seconds; a new tap within this window extends the burst

function handleTaps(n) {
  if (n >= 3) {
    // Triple-tap = pairing. The only gesture that works from every face.
    try { Bangle.buzz(120); } catch (e) {}
    if (state.streaming) stopStreaming();          // back to idle, then show the pairing code
    enterPairing();
    return;
  }
  if (n === 2) {
    // Double-click, context-aware per face. Each branch buzzes its own ack; an unhandled face stays
    // silent so a stray double-tap there does nothing.
    if (page === STOPWATCH_PAGE) { try { Bangle.buzz(80); } catch (e) {} swSleepToggle(); }
    else if (page === COUNTER_PAGE) { resetCounter(); }   // resetCounter() buzzes
    else if (page === 0) {                                 // Heart face → manual WORKOUT
      try { Bangle.buzz(80); } catch (e) {}
      if (!state.streaming) startStreaming();     // ensure the HR sensor is powered before the workout
      toggleManualWorkout();                       // start/stop the workout → sport-mode HR
    }
    return;
  }
  // Single tap → start/stop HR recording, but ONLY on the Heart face. Everywhere else it's ignored so
  // you can't kick off a recording just by bumping the button while checking the time or your steps.
  if (page === 0) {
    try { Bangle.buzz(40); } catch (e) {}
    toggleStreaming();
  }
}

setWatch(function () {
  if (pairTimer) {                                 // in pairing → any press exits, swallow the burst
    exitPairing();
    tapCount = 0;
    if (tapTimer) { clearTimeout(tapTimer); tapTimer = null; }
    return;
  }
  tapCount++;
  if (tapTimer) clearTimeout(tapTimer);
  tapTimer = setTimeout(function () {
    var n = tapCount;
    tapCount = 0;
    tapTimer = null;
    handleTaps(n);
  }, TAP_GAP * 1000);
}, BTN1, { repeat: true, edge: "falling" });

function toggleManualWorkout() {
  if (state.workout && state.workoutManual) endWorkout();
  else startWorkout(true);
}

// ----- Stopwatch + sleep (the Stopwatch face) -------------------------------
// One face, two uses. TAP the screen = a plain stopwatch (general timer, nothing logged). DOUBLE-CLICK
// the button on this face = run the timer as a SLEEP session: starting marks bedtime + ensures the
// night logs (PPG + actigraphy → server staging); stopping emits the confirmed T9 window, which on the
// next sync triggers the server seal + the coach's sleep summary push.
function startStopwatch() {                 // plain timer (tap from idle)
  state.swMode = "watch";
  state.swStartMs = Math.round(getTime() * 1000);
  try { Bangle.buzz(40); } catch (e) {}
  if (uiVisible) drawUI();
}

function startSleepSession() {              // double-click button → time it AS sleep
  state.swMode = "sleep";
  state.swStartMs = Math.round(getTime() * 1000);
  if (state.workout) endWorkout();          // sleep isn't a workout → log T2 PPG (not T6 accel)
  if (!state.streaming) startStreaming();   // guarantee the night is captured for HRV + staging
  reconcileHrm();                           // sleep → burst the HRM (SLEEP_DUTY): ~30s of 25 Hz raw PPG every 3 min, so HRV is captured all night without flattening the battery
  sleepScreenOff();                         // dark screen all night (no twist/touch wakes); one button click still wakes it
  try { Bangle.buzz(80); setTimeout(function () { try { Bangle.buzz(80); } catch (e) {} }, 150); } catch (e) {}
  if (uiVisible) drawUI();
}

function stopTimer() {                       // stop either mode; a SLEEP session gets logged
  if (state.swMode === "sleep") {
    var bedSec = Math.round(state.swStartMs / 1000);
    var wakeSec = Math.round(getTime());
    emitSleepFrame(bedSec, wakeSec, 1);      // confirmed window → the morning sync fires the sleep summary
  }
  state.swMode = "idle";
  sleepScreenRestore();   // sleep ended → give back wrist-twist/touch wake
  reconcileHrm();   // sleep ended → if still offline + idle, drop back into the HR duty cycle
  try { Bangle.buzz(60); } catch (e) {}
  if (uiVisible) drawUI();
}

// A screen tap on the Stopwatch face: idle → start plain timer; running → stop (sleep logs, plain doesn't).
function swTap() {
  if (state.swMode === "idle") startStopwatch();
  else stopTimer();
}

// Double-click of the button while on the Stopwatch face: start a SLEEP session, or stop+log one.
function swSleepToggle() {
  if (state.swMode === "sleep") stopTimer();
  else startSleepSession();
}

// ----- Counter (the Counter face) -------------------------------------------
// A plain tally: screen tap adds 1, double-click of the button resets to zero. RAM only.
function bumpCounter() {
  state.count = (state.count || 0) + 1;
  try { Bangle.buzz(20); } catch (e) {}
  if (uiVisible) drawUI();
}

function resetCounter() {
  state.count = 0;
  try { Bangle.buzz(60); } catch (e) {}
  if (uiVisible) drawUI();
}

// T9 frame: [ver u8, confirmed u8, rsvd u16, bedtime u32 (epoch s), wake u32 (epoch s)] (12 B). Sent
// live when connected, else appended to the overnight log so it flushes on the morning sync.
function emitSleepFrame(bedSec, wakeSec, confirmed) {
  var buf = new ArrayBuffer(12);
  var dv = new DataView(buf);
  dv.setUint8(0, CFG.SLEEP_PROTO_VERSION);
  dv.setUint8(1, confirmed ? 1 : 0);
  dv.setUint32(4, bedSec >>> 0, true);
  dv.setUint32(8, wakeSec >>> 0, true);
  var line = "T9:" + b64(buf);
  if (state.connected) {
    try { Bluetooth.println(line); state.framesSent++; } catch (e) {}
  } else {
    appendLog(line);
  }
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
    } else if (line.substr(0, 3) === "C2:") {     // set time + timezone from the phone
      try {
        var c = JSON.parse(line.substr(3));       // { t: unixSeconds (UTC), tz: hoursOffset }
        if (typeof c.tz === "number") E.setTimeZone(c.tz);
        if (typeof c.t === "number") setTime(c.t);
        if (page === 1) drawUI();
      } catch (err) { /* malformed — ignore */ }
    } else if (line.substr(0, 2) === "C3") {      // "sync now" — flush the overnight ring on demand
      try { emitStepFrame(); } catch (e) {}        // push today's step total too
      flushLog();
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
  updateAutoDetect();   // Whoop-style hands-free workout start/stop (one 5 s epoch)
}, 5000);

// Tick the stopwatch once a second, but ONLY while you're actually looking at a running timer
// (right page + running + screen on) — so it never wastes battery when idle or while you sleep.
var swTimer = setInterval(function () {
  if (page === STOPWATCH_PAGE && state.swMode !== "idle" && uiVisible && Bangle.isLCDOn()) drawUI();
}, 1000);

// Continuous ambient barometer for all-day floors (its own power owner, so dropping GPS doesn't
// stop it). The 'pressure' events keep `ambientAlt` fresh; a slow timer batches them into T7.
try { if (Bangle.setBarometerPower) Bangle.setBarometerPower(1, "titan-alt"); } catch (e) {}
var altTimer = setInterval(sampleAltitude, CFG.ALT_SAMPLE_MS);

// Relay the built-in pedometer's day total to the server once a minute while connected (the server
// merges it with the phone's count as a per-day MAX). No-op when offline.
var stepTimer = setInterval(emitStepFrame, CFG.STEP_SUMMARY_MS);

// Clean up if the app is unloaded by the launcher.
E.on("kill", function () {
  if (uiTimer) clearInterval(uiTimer);
  if (altTimer) clearInterval(altTimer);
  if (stepTimer) clearInterval(stepTimer);
  if (swTimer) clearInterval(swTimer);
  stopRestDuty();
  if (altBuf.length) { try { emitAltFrame(); } catch (e) {} }   // don't lose the partial minute
  try { Bangle.setHRMPower(0, "titan"); } catch (e) {}
  try {
    Bangle.setGPSPower(0, "titan");
    if (Bangle.setBarometerPower) { Bangle.setBarometerPower(0, "titan"); Bangle.setBarometerPower(0, "titan-alt"); }
  } catch (e) {}
});

// One-time: reclaim the legacy single-file log from pre-ring firmware (superseded by titan.l0..N).
try { require("Storage").open(CFG.LOG_FILE, "r").erase(); } catch (e) {}

// Watch-only resume: if recording was on before a reboot, bring it back — there's no app to re-arm it,
// and a 24/7 wearer shouldn't silently stop capturing because the watch restarted.
try { if (require("Storage").read("titan.run") === "1") startStreaming(); } catch (e) {}

// Initial paint.
refreshBattery();
drawUI();
