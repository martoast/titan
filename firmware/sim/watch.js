'use strict';

// Loads the REAL firmware (firmware/banglejs/titan.app.js) into a sandboxed VM context with a
// mocked Espruino runtime (Bangle / Bluetooth / NRF / Storage / g / setWatch / …) driven by the
// shared VirtualClock. Nothing about the workout logic is re-implemented here — this runs the exact
// bytes that ship to the band, so a firmware bug reproduces faithfully. `control` is the test API:
// press the button, swipe faces, connect/disconnect a phone, feed HRM/accel/GPS, deliver commands.

const fs = require('fs');
const path = require('path');
const vm = require('vm');
const { makeVDate } = require('./clock');

function buildWatch(clock, opts = {}) {
  const tzRef = { h: -7 };
  const VDate = makeVDate(clock, tzRef);
  const listeners = {};                 // "Bangle:accel" -> [fn]
  const on = (ns) => (ev, cb) => { (listeners[ns + ':' + ev] ||= []).push(cb); };
  const fire = (key, ...a) => (listeners[key] || []).forEach((cb) => cb(...a));

  let connected = false;                 // is a phone subscribed to NUS?
  let hrmOn = false;                      // is the HRM/PPG LED powered right now? (setHRMPower) — the battery-critical bit
  let lcdOn = true;                       // is the screen lit? (drives lcdPower events → the rest-duty screen-wake kick)
  const connIntervals = [];              // every NRF.setConnectionInterval({minInterval,maxInterval}) call (battery: {30,45} rest ↔ {15,30} fast)
  let osStepCount = 0;                    // the built-in pedometer's running day count (getHealthStatus)
  // --- OS power-save ↔ built-in pedometer coupling (faithful to Espruino jswrap_bangle.c) -------------
  // The firmware's step counter ONLY runs while powerSaveTimer < POWER_SAVE_TIMEOUT (60s). The timer is
  // reset to 0 by motion and climbs while still — BUT ONLY inside the `if (powerSave)` block. Calling
  // Bangle.setPollInterval() force-clears powerSave (bangleFlags &= ~JSBF_POWER_SAVE), which FREEZES the
  // timer: if it was already ≥60s (watch sat still >1min), the pedometer stays gated OFF until a reboot,
  // or until powerSave is re-enabled (setOptions({powerSave:true})) so motion can reset the timer again.
  // Modelling this coupling is what lets the sim catch the "steps die after a manual workout" bug.
  let powerSave = true;                   // default ON at boot (Espruino default)
  let powerSaveTimer = 0;                 // ms of stillness; ≥ POWER_SAVE_TIMEOUT ⇒ step counter gated OFF
  const POWER_SAVE_TIMEOUT = 60000;
  let dataHandler = null;                // Bluetooth.on('data')
  const allFrames = [];                  // every println line (for inspection)
  let deliver = null;                    // hook: deliver a line to the phone when connected
  const log = [];

  // In-memory Storage: StorageFiles support append-write / readLine / erase; plus read/readJSON/write.
  // Passing opts.storageFiles reuses an existing flash image → a REBOOT (rebuild the VM against the same
  // Storage) faithfully re-runs the firmware's boot-restore path (titan.sleep / titan.wo reconcile).
  const files = opts.storageFiles || {};
  function storageOpen(name, mode) {
    files[name] ||= { data: '', pos: 0 };
    const f = files[name];
    if (mode === 'r') f.pos = 0;
    return {
      write(s) { f.data += s; },
      read() { return f.data || undefined; },
      readLine() {
        if (f.pos >= f.data.length) return undefined;
        let nl = f.data.indexOf('\n', f.pos);
        if (nl < 0) nl = f.data.length - 1;
        const line = f.data.substring(f.pos, nl + 1);
        f.pos = nl + 1;
        return line;
      },
      erase() { delete files[name]; },
    };
  }
  const Storage = {
    open: storageOpen,
    read(name) { return files[name] ? files[name].data : undefined; },
    write(name, s) { files[name] = { data: String(s), pos: 0 }; },
    erase(name) { delete files[name]; },
    readJSON(name) { try { return JSON.parse(files[name].data); } catch (e) { return undefined; } },
    writeJSON(name, o) { files[name] = { data: JSON.stringify(o), pos: 0 }; },
  };

  function btoa(x) {
    let bytes;
    if (x instanceof ArrayBuffer) bytes = new Uint8Array(x);
    else if (ArrayBuffer.isView(x)) bytes = new Uint8Array(x.buffer, x.byteOffset, x.byteLength);
    else bytes = Buffer.from(String(x), 'binary');
    return Buffer.from(bytes).toString('base64');
  }

  const gNums = { getWidth: () => 176, getHeight: () => 176, stringWidth: () => 10 };
  const g = new Proxy({}, { get: (_t, p) => (gNums[p] || (() => undefined)) });

  const Bangle = {
    on: on('Bangle'),
    setGPSPower() {}, setBarometerPower() {}, setHRMPower(v) { hrmOn = !!v; },
    // setOptions merges options; the only one that matters to the pedometer is powerSave.
    setOptions(o) { if (o && o.powerSave !== undefined) powerSave = !!o.powerSave; },
    getOptions() { return { powerSave: powerSave }; },
    // THE TRAP: Bangle.setPollInterval() force-clears powerSave (bangleFlags &= ~JSBF_POWER_SAVE) as a
    // side effect — which freezes powerSaveTimer and can permanently gate the step counter OFF.
    setPollInterval() { powerSave = false; },
    buzz() {}, isLCDOn() { return lcdOn; }, isCharging() { return false; },
    // The firmware pedometer. Real Bangle.js counts steps from the accel poll and exposes them here; it
    // keeps counting DURING a workout (that's the whole point of the 12.5 Hz poll) — but ONLY while the OS
    // powerSaveTimer stays under its 60s gate (see control.walk / control.sitStill).
    getHealthStatus() { return { steps: osStepCount }; }, setLocked() {},
  };
  const NRF = {
    on: on('NRF'),
    getSecurityStatus() { return { connected }; },
    disconnect() { control.disconnect(); },
    getAddress() { return 'aa:bb:cc:dd:e7:c3'; },
    setConnectionInterval(o) { if (o) connIntervals.push({ minInterval: o.minInterval, maxInterval: o.maxInterval }); },
    setMTU() {},
  };
  const Bluetooth = {
    println(line) { allFrames.push(String(line)); if (connected && deliver) deliver(String(line)); },
    on(ev, cb) { if (ev === 'data') dataHandler = cb; },
  };
  const E = {
    getBattery() { return 80; },
    setTimeZone(h) { tzRef.h = h; },
    on: on('E'),
    setConsole() {},
  };

  const sandbox = {
    Bangle, NRF, Bluetooth, E, g, Storage,
    BTN1: 'BTN1',
    setWatch(cb) { sandbox.__btn = cb; return 1; },
    clearWatch() {},
    require(m) { return m === 'Storage' ? Storage : {}; },
    btoa,
    getTime: () => clock.getTimeSec(),
    setTime: (s) => clock.setTimeSec(s),
    setInterval: (fn, d) => clock.setInterval(fn, d),
    setTimeout: (fn, d) => clock.setTimeout(fn, d),
    clearInterval: (id) => clock.clear(id),
    clearTimeout: (id) => clock.clear(id),
    Date: VDate,
    Math, JSON, parseInt, parseFloat, isFinite, isNaN,
    ArrayBuffer, Uint8Array, Int8Array, Uint16Array, Int16Array, Uint32Array, Int32Array,
    Float32Array, Float64Array, DataView,
    console: { log: (...a) => log.push(a.join(' ')), warn: () => {}, error: () => {} },
  };
  sandbox.global = sandbox;
  vm.createContext(sandbox);

  const src = fs.readFileSync(
    opts.firmwarePath || path.join(__dirname, '..', 'banglejs', 'titan.app.js'), 'utf8');
  vm.runInContext(src, sandbox, { filename: 'titan.app.js' });

  const control = {
    sandbox, allFrames, log,
    storageFiles: files,                          // the flash image (share it to a rebuilt VM = a reboot)
    onDeliver(fn) { deliver = fn; },              // phone registers to receive frames
    isConnected() { return connected; },
    hrmPower() { return hrmOn; },                 // is the HRM/PPG LED on right now? (asserts rest duty-cycling vs continuous)
    // Screen wake/sleep → fires the firmware's lcdPower handler (a wrist-glance kicks the rest-duty burst).
    lcdWake() { lcdOn = true; fire('Bangle:lcdPower', true); },
    lcdSleep() { lcdOn = false; fire('Bangle:lcdPower', false); },
    connIntervals() { return connIntervals.slice(); },              // every requested BLE interval, in order
    lastConnInterval() { return connIntervals[connIntervals.length - 1] || null; },

    connect() {
      if (connected) return;
      connected = true;
      fire('NRF:connect');
    },
    disconnect() {
      if (!connected) return;
      connected = false;
      fire('NRF:disconnect');
    },
    // A command line the phone sends to the band (e.g. "C0:\n").
    sendCommand(line) { if (dataHandler) dataHandler(line.endsWith('\n') ? line : line + '\n'); },

    press(n = 1) {                                 // n button clicks in a quick burst
      for (let i = 0; i < n; i++) { if (sandbox.__btn) sandbox.__btn(); clock.advance(60); }
      clock.advance(500);                          // let the TAP_GAP (0.4s) burst settle → handleTaps(n)
    },
    swipeRight(n = 1) { for (let i = 0; i < n; i++) fire('Bangle:swipe', 1); },
    swipeLeft(n = 1) { for (let i = 0; i < n; i++) fire('Bangle:swipe', -1); },
    page() { return sandbox.page; },

    hrm(bpm, conf = 96) { fire('Bangle:HRM', { bpm, confidence: conf }); },
    hrmRaw(raw) { fire('Bangle:HRM-raw', { raw }); },
    accel(x, y, z) { fire('Bangle:accel', { x, y, z }); },
    gps(fix) { fire('Bangle:GPS', fix); },

    // Walk `n` steps. Each step is a MOTION poll: it resets powerSaveTimer (only while powerSave is on —
    // exactly like the firmware) and advances the built-in pedometer ONLY while the step counter is
    // ungated (powerSaveTimer < POWER_SAVE_TIMEOUT). If powerSave was left OFF by setPollInterval with the
    // timer already frozen ≥60s, motion CAN'T reset it → getHealthStatus().steps stays frozen (the bug).
    walk(n = 1) {
      for (let i = 0; i < n; i++) {
        if (powerSave) powerSaveTimer = 0;                              // motion resets the timer — only if powerSave is on
        if (powerSaveTimer < POWER_SAVE_TIMEOUT) { osStepCount++; fire('Bangle:step'); }
      }
    },
    // Sit still for `ms`: the OS climbs powerSaveTimer toward its 60s gate — but only while powerSave is on
    // (once off, the timer is frozen). Lets a test park the watch in the ">1min still" state before a workout.
    sitStill(ms) { if (powerSave) powerSaveTimer = Math.min(powerSaveTimer + (ms | 0), 120000); },
    powerSaveOn() { return powerSave; },
    setOsSteps(n) { osStepCount = n | 0; },
    osSteps() { return osStepCount; },
    simulateReboot(steps = 0) { osStepCount = steps | 0; },   // a reflash/reboot resets the OS pedometer

    state() { return sandbox.state; },             // firmware state object (workout/connected/hrmSport/…)
  };
  return control;
}

module.exports = { buildWatch };
