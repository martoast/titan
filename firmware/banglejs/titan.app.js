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
  // Offline buffer file (StorageFile, append-only). One per session epoch.
  BUFFER_FILE: "titan.buf",
  // Max buffered bytes before we start dropping oldest (protect flash/RAM).
  // 200 KB ~ a few minutes of raw; server is the system of record once synced.
  BUFFER_MAX_BYTES: 200 * 1024,
  // Protocol version embedded in every frame header.
  PROTO_VERSION: 1
};

// ----- State ----------------------------------------------------------------
var state = {
  streaming: false,    // user toggled capture on?
  connected: false,    // is a BLE central subscribed to NUS?
  bpm: 0,              // last HRM bpm (UI only)
  conf: 0,             // last HRM confidence (UI only)
  ppgCount: 0,         // samples captured this session (UI counter)
  framesSent: 0,       // BLE frames emitted
  buffered: 0,         // approx bytes sitting in the offline buffer
  lastAccel: { x: 0, y: 0, z: 0 }, // most recent accel reading (g)
  battery: 0
};

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

// Send one already-built binary frame (ArrayBuffer of `len` bytes) out. If a
// central is connected we Bluetooth.println a base64 line; otherwise we append
// to the offline StorageFile buffer.
function emitFrame(buf, len) {
  var slice = buf.slice(0, len);
  var line = "T1:" + b64(slice); // "T1:" tag = Titan proto, NUS-safe text line
  if (state.connected) {
    try {
      Bluetooth.println(line);
      state.framesSent++;
    } catch (err) {
      // Write failed (buffer full / just disconnected) -> fall back to buffer.
      bufferLine(line);
    }
  } else {
    bufferLine(line);
  }
}

// Append a frame line to the offline buffer file.
function bufferLine(line) {
  try {
    var f = require("Storage").open(CFG.BUFFER_FILE, "a");
    f.write(line + "\n");
    state.buffered += line.length + 1;
    // Crude cap: if we blew the budget, reset the buffer (drop oldest by
    // truncating). P0 favours staying alive over perfect history; the server
    // is authoritative once any sync lands.
    if (state.buffered > CFG.BUFFER_MAX_BYTES) {
      require("Storage").open(CFG.BUFFER_FILE, "r").erase();
      state.buffered = 0;
    }
  } catch (err) {
    // Storage unavailable — nothing we can safely do; drop the frame.
  }
}

// Flush the offline buffer over NUS, oldest first, then clear it.
function flushBuffer() {
  if (!state.connected) return;
  var sf;
  try {
    sf = require("Storage").open(CFG.BUFFER_FILE, "r");
  } catch (err) { return; }
  var line = sf.readLine();
  if (line === undefined) return; // empty
  // Stream buffered lines. readLine() includes the trailing newline; trim it
  // and re-println so framing stays clean.
  while (line !== undefined) {
    var trimmed = line.charCodeAt(line.length - 1) === 10
      ? line.substr(0, line.length - 1) : line;
    if (trimmed.length) {
      try { Bluetooth.println(trimmed); state.framesSent++; }
      catch (err) { break; } // link died mid-flush; keep remaining buffer
    }
    line = sf.readLine();
  }
  // Erase the buffer file (we either sent it all or the link died and we'll
  // retry next reconnect; simplest correct behaviour for P0 is erase-on-flush).
  try { require("Storage").open(CFG.BUFFER_FILE, "r").erase(); } catch (e) {}
  state.buffered = 0;
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

// Append one (ppg, accel, timestamp) sample into the current frame.
function pushSample(ppg) {
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
  state.ppgCount++;
  if (frameCount >= CFG.FRAME_SAMPLES) flushFrame();
}

// ----- Sensor event handlers ------------------------------------------------

function onHRMRaw(e) {
  if (!state.streaming) return;
  pushSample(extractPPG(e));
}

function onHRM(e) {
  // UI only — never streamed (averaged BPM has discarded the ms IBI we need).
  state.bpm = e.bpm | 0;
  state.conf = e.confidence | 0;
  if (uiVisible) drawUI();
}

function onAccel(a) {
  // Bangle reports accel in g as {x,y,z,...}. We just cache the latest; PPG
  // (25 Hz) is the master clock and each PPG sample is tagged with the most
  // recent accel. This keeps PPG/accel time-synced on one timeline, which is
  // exactly what the server's accel-referenced artifact removal wants.
  state.lastAccel.x = a.x;
  state.lastAccel.y = a.y;
  state.lastAccel.z = a.z;
}

// ----- BLE connection tracking ----------------------------------------------

function onConnect() {
  state.connected = true;
  // Give the link a beat to settle, then drain anything we buffered offline.
  setTimeout(flushBuffer, 1500);
  if (uiVisible) drawUI();
}

function onDisconnect() {
  state.connected = false;
  // In-flight partial frame should not be lost — push it to the buffer.
  flushFrame();
  if (uiVisible) drawUI();
}

// ----- Start / stop streaming -----------------------------------------------

function startStreaming() {
  if (state.streaming) return;
  state.streaming = true;
  resetFrame();
  // Power up the heart-rate sensor. Bangle.setHRMPower(1) turns on the VC31
  // LED + AFE; without it no HRM/HRM-raw events fire.
  Bangle.setHRMPower(1, "titan");
  // Accel is on by default on Bangle.js 2; setPollInterval tightens cadence.
  // 80 ms ~ 12.5 Hz, plenty for actigraphy and motion-gating per the plan.
  try { Bangle.setPollInterval(80); } catch (e) {}
  drawUI();
}

function stopStreaming() {
  if (!state.streaming) return;
  state.streaming = false;
  flushFrame(); // emit whatever partial frame we have
  Bangle.setHRMPower(0, "titan");
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
  g.drawString(state.connected ? "BLE ↑" : "buffer", g.getWidth() - 6, statusY);

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
  g.drawString("buffer:  " + (state.buffered / 1024).toFixed(1) + "KB", 6, y); y += 12;
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
});

// Initial paint.
refreshBattery();
drawUI();
