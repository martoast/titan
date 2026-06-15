/* Titan Stream — Bangle.js 2 raw-PPG streamer (P0 firmware)
 * ----------------------------------------------------------------------------
 * The device is a DUMB sensor: it captures clean raw PPG (+ accel), timestamps
 * it, and streams fixed-length windows over BLE (Nordic UART / NUS) as one JSON
 * object per line. Titan's phone/desktop bridge forwards each window to
 *   POST /api/devices/ingest   (kind: "ppg_raw")
 * where NeuroKit2 turns raw PPG → IBI → HRV/recovery server-side.
 *
 * Why raw, not BPM: the on-chip BPM register has already thrown away the ms-level
 * inter-beat timing that HRV is made of. We need the raw FIFO samples.
 *
 * Install: paste into the Espruino Web IDE (https://www.espruino.com/ide/),
 * connect to the Bangle.js 2, and "Send to RAM" (or save to a .app.js). Then open
 * Titan → Devices → "Live stream" and connect over Bluetooth.
 *
 * Tunables below. Defaults target overnight / resting HRV.
 */

var WINDOW_SEC = 120;   // seconds of PPG per window (>=60 for stable RMSSD)
var KEEP_SCREEN = false; // true = don't sleep the screen (debug; costs battery)

var ppg = [];           // raw PPG samples for the current window
var acc = [];           // accel magnitude (g), coarse — for server motion gating
var winStartMs = null;  // wall-clock ms at first sample of the window
var sent = 0;           // windows shipped this session
var lastBeatBpm = 0;    // last on-chip BPM estimate, for the on-watch readout only

// --- raw PPG capture (VC31B on Bangle.js 2 fires HRM-raw ~25 Hz) -------------
function onHRMraw(e) {
  // e.vcPPG is the VC31 raw optical value; fall back to e.raw on other sensors.
  var v = (e.vcPPG !== undefined) ? e.vcPPG : e.raw;
  if (v === undefined) return;
  if (winStartMs === null) winStartMs = Date.now();
  ppg.push(v);
}

function onHRM(e) { // averaged BPM — display only, never streamed
  if (e.bpm) lastBeatBpm = e.bpm;
}

function onAccel(e) {
  // store coarse magnitude (centi-g) so the array stays small
  acc.push(Math.round(e.mag * 100));
}

// --- ship one window over NUS as a single JSON line -------------------------
function flush() {
  var n = ppg.length;
  if (n < WINDOW_SEC * 8 || winStartMs === null) { // too few samples — skip, keep collecting
    return;
  }
  var endMs = Date.now();
  var durS = (endMs - winStartMs) / 1000;
  var rate = Math.max(1, Math.round(n / durS)); // measured sample rate

  var win = {
    kind: "ppg_raw",
    start: new Date(winStartMs).toISOString(),
    end: new Date(endMs).toISOString(),
    sample_rate_hz: rate,
    ppg: ppg,
    accel_mag_cg: acc,       // optional; server keeps the whole blob for later
    src: "banglejs2"
  };

  // One JSON object per line. The bridge reassembles on '\n'.
  try {
    Bluetooth.println(JSON.stringify(win));
    sent++;
  } catch (err) {
    // TX buffer full (window too big for the link) — drop it; lower WINDOW_SEC.
    print("TX overflow, dropping window:", err);
  }

  ppg = [];
  acc = [];
  winStartMs = null;
  draw();
}

// --- minimal on-watch UI ----------------------------------------------------
function draw() {
  g.clear();
  g.setFontAlign(0, 0);
  g.setFont("Vector", 22);
  g.drawString("TITAN", g.getWidth() / 2, 30);
  g.setFont("6x8", 2);
  var connected = NRF.getSecurityStatus().connected;
  g.drawString(connected ? "streaming" : "waiting BLE", g.getWidth() / 2, 70);
  g.setFont("6x8", 1);
  g.drawString(lastBeatBpm ? (lastBeatBpm + " bpm") : "-- bpm", g.getWidth() / 2, 100);
  g.drawString("buf " + ppg.length, g.getWidth() / 2, 120);
  g.drawString("sent " + sent, g.getWidth() / 2, 138);
  g.flip();
}

// --- start ------------------------------------------------------------------
function start() {
  Bangle.setHRMPower(1, "titan");        // power the optical sensor
  Bangle.on('HRM-raw', onHRMraw);
  Bangle.on('HRM', onHRM);
  Bangle.on('accel', onAccel);
  if (KEEP_SCREEN) Bangle.setLCDTimeout(0);
  setInterval(flush, WINDOW_SEC * 1000); // ship a window every WINDOW_SEC
  setInterval(draw, 2000);               // refresh the readout
  draw();
  print("Titan Stream running. window=" + WINDOW_SEC + "s");
}

E.on('kill', function () { Bangle.setHRMPower(0, "titan"); });
start();
