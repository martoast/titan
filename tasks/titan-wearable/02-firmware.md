# 02 — Firmware Plan

*Architecture: the device is a "dumb" sensor (Nordic nRF52840) that samples PPG + IMU and streams raw/
lightly-processed data over BLE to a phone, which forwards to Titan where the algorithms run. 2 units first,
open-sourced later.*

## 1. RTOS / SDK choice
**Recommendation: product on Zephyr RTOS via Nordic nRF Connect SDK (NCS); prototype fast on Espruino/Bangle.js 2.**
| Path | Stack | BLE | Verdict |
|---|---|---|---|
| **Zephyr / NCS** | C, Zephyr + Nordic, west/CMake/devicetree | Zephyr host + Nordic SoftDevice Controller | **Primary (product)** |
| Arduino-nRF52 | C++, Adafruit Bluefruit | Bluefruit | Quick bench only |
| **Espruino / Bangle.js 2** | JavaScript on nRF52840 | BLE from JS, Web BT upload | **Fast-prototyping sandbox** |
| InfiniTime / PineTime | C++ FreeRTOS + NimBLE | NimBLE | Reference code only (nRF52832) |

- NCS is Nordic's unified SDK (legacy nRF5 SDK deprecated; nRF54 is NCS-only). Current **NCS v2.9.0** (2025-09) /
  line in 3.x. BLE = host + SoftDevice Controller over HCI.
- Bangle.js 2 = nRF52840 + VC31 PPG + **KX022** accel, 175 mAh, IP67.
- Toolchain: `west init -m https://github.com/nrfconnect/sdk-nrf --mr v2.9.0`; daily work in **nRF Connect for VS Code**.

## 2. Sensor acquisition
**Recommended: MAX86141 (PPG, SPI) + BMI270 (IMU), both FIFO + watermark-interrupt with timestamps.**
- **MAX86141:** SPI, 19-bit, **128-sample FIFO**, 3 B/sample, 8-4096 sps; at 25 sps → 8.5 µA (AFE only). MAX30101
  is simpler (integrated LEDs, I2C) but smaller FIFO. **MAX86141 wins** for low-power server-offloaded design.
- **BMI270:** 25-50 Hz, 6 B/frame, 2 KB FIFO, ~10 µA. (Clinical actigraphy assumes 30 Hz; 25 Hz fine for sleep.)
- **Capture raw PPG waveform + time-synced accel, not on-device IBI only.** Server interpolates peaks (cubic
  spline → ~1 ms) to recover IBI. Raw lets the server run accel-referenced artifact removal (NLMS/spectral
  subtraction) impractical on the MCU. **Always enable timestamps** (MAX86141 reg 0x0A, LSM6 timestamp word).
- **Pattern: FIFO + watermark interrupt, not polling.** At 25 sps with 128-deep FIFO + watermark 113, MCU wakes
  ~once/4.5 s instead of 25×/s → system current cut ~78-96%+ (ST DT0011).

## 3. On-device vs off-device processing
- **Raw stream:** PPG 100 Hz × 3 B × 2 ch + accel 50 Hz × 6 B ≈ **900 B/s = 3.24 MB/hr = ~26 MB/8h.** ~0.6% of
  BLE ceiling. Real cost = keeping link up + flash churn when buffering offline.
- **IBI-only:** ~2-8 B/s → ~15-30 KB/hr (~100-200× less) — but discards the waveform; on-device errors are
  unrecoverable, no retroactive algorithm improvement.
- **Hybrid (recommended):** stream raw when connected; degrade to compact IBI when storage/connectivity is the
  constraint (8 MB QSPI holds ~2.5 h raw vs ~2 weeks IBI).

## 4. BLE data transport
- **Throughput:** ~**1.3 Mbps** realistic (2M PHY + DLE 251-byte PDU + ATT MTU 247). Connection interval
  7.5 ms-4 s; up to 6 notifications/event. Default MTU 23 → only 20 usable; **negotiate MTU 247 → 244 usable.**
  Use **notifications** (no ACK) for streams; indications only for must-not-drop config.
- **GATT:** custom 128-bit service (PPG Notify / Accel Notify / Control-point Write); reuse SIG Battery 0x180F,
  Device Info 0x180A. Packetize: 244-4 B header = 240 → **80 PPG int24 or 40 accel int16-triplets per notify.**
  **Also expose HR Service 0x180D / HR Measurement 0x2A37** (RR = uint16 LE @ 1/1024 s) so **Gadgetbridge** and
  generic HR apps read IBI with zero custom work.
- **Buffering offline:** nRF52840 internal flash unusable for raw (~5 min); use **external QSPI** (DK = 8 MB
  MX25R6435F). RAM ring buffer → **littlefs** on QSPI (power-loss-resilient) → sync on reconnect; NVS for
  settings/bond/cursor. 8 MB ≈ 2.5 h raw / ~2 weeks IBI.
- **Security:** Bonding + **LE Secure Connections (ECDH P-256)**; headless device → Just Works; Filter Accept
  List scoped to the bonded phone's IRK.

## 5. Power management
- nRF52840: System ON idle ~1.5 µA (3.16 µA w/ full retention), System OFF 0.4 µA; radio TX@0dBm 4.8 mA. SoC
  sleep floor negligible vs PPG LEDs.
- BLE avg current dominated by wake frequency: ~50 ms → tens of µA; ~1 s → ~150 µA. **Caution:** un-optimized
  builds (UART logging, no DC/DC) sit at 450-700 µA regardless — disable those first.
- **PPG LED drive (1-10 mA avg when streaming) is the dominant cost.** Power-gate the PPG rail via GPIO load
  switch when idle. BMI270 accel-only ~10 µA.
- **To hit multi-day (ranked):** (1) cut PPG duty cycle (biggest lever); (2) lower LED current/rate; (3) hard-
  gate PPG rail; (4) longer BLE interval; (5) store-and-forward vs continuous stream; (6) IMU-triggered PPG;
  (7) DC/DC on, UART off. **Continuous PPG = ~1-day device; multi-day requires duty-cycling.**

## 6. Phone-side bridge (the gating constraint = iOS background BLE)
| Option | Background | iOS | Verdict |
|---|---|---|---|
| **Native/cross-platform** (RN `ble-plx`, Flutter `flutter_blue_plus`) | Yes (caveats) | **Yes (only option)** | **Public release** |
| **Web Bluetooth (PWA)** | **No** | **No (WebKit)** | Disqualified (desktop debug only) |
| **Gadgetbridge** (Android) | Yes (mature) | No | **Fast indie path now** |
- **iOS Core Bluetooth:** needs `bluetooth-central` UIBackgroundMode + State Preservation/Restoration; **cannot
  survive force-quit; reconnects at OS discretion.** This is the project's **#1 technical risk.**
- **Android:** BLE in a foreground service; Android 12+ `BLUETOOTH_CONNECT/SCAN`, 14+ `FOREGROUND_SERVICE_
  CONNECTED_DEVICE`. Aggressive auto-reconnect vs Doze.
- **Gadgetbridge:** cloudless, supports any device exposing **NUS + Bangle.js JSON**; a "banglejs" build flavor
  re-enables internet to push to your server. **Recommendation:** ride Gadgetbridge now (if firmware exposes
  NUS+JSON); build a native companion app for public/iOS release.

## 7. OTA, debug, reproducible build
- **OTA:** **MCUboot + nRF Connect Device Manager (SMP/mcumgr over BLE)**, dual-bank swap + rollback. Official
  phone libs (iOS/Android nRF-Connect-Device-Manager). **Avoid legacy nRF5 DFU** (no migration path to NCS).
- **Debug:** SEGGER RTT (frees UART) via Zephyr logging; J-Link; **Power Profiler Kit II** for current
  validation (essential for a battery wearable).
- **Reproducible:** NCS pinned via `west.yml` + `west build/flash`; nrfutil for DFU packaging. Pin the NCS
  version so both brothers + CI build byte-comparable images.

## 8. Reference open firmware to fork
- **[HealthyPi Move FW](https://github.com/Protocentral/healthypi-move-fw)** — most relevant: nRF5340 + Zephyr/
  NCS + MAX86141, full PPG/HR/HRV/SpO2 + OTA. Architecture ports closely.
- [InfiniTime](https://github.com/InfiniTimeOrg/InfiniTime) (HRS3300 + reworked HR processing);
  [WASP-OS](https://github.com/wasp-os/wasp-os) (clean HRS3300 MicroPython driver).
- [Espruino/BangleApps](https://github.com/espruino/BangleApps) (HRM/accel logging apps).
- Zephyr samples: `samples/bluetooth/peripheral_hr`, `st_ble_sensor`; NCS `samples/bluetooth/throughput`.
- [ProtoCentral Pulse Express](https://github.com/Protocentral/protocentral-pulse-express) (raw-PPG streaming).

## 9. Phased firmware roadmap
| Phase | Goal | Stack | Diff |
|---|---|---|---|
| **P0** | Stream raw HR from a hackable watch | Bangle.js JS app + Gadgetbridge → Titan | **2** |
| **P1** | Server-side algorithm validation on P0 data | JS capture + Laravel | **3** |
| **P2** | NCS bring-up on DK + real AFE (MAX86141 + BMI270), FIFO IRQ, HR over 0x180D | Zephyr/NCS | **6** |
| **P3** | Custom GATT streaming + littlefs buffer + bonding/LESC | Zephyr/NCS | **7** |
| **P4** | Power optimization to multi-day (duty cycle, rail gating, IMU trigger) | Zephyr/NCS | **7** |
| **P5** | MCUboot OTA + companion app (iOS restoration + Android FG service) | NCS + RN/Flutter | **8** |
| **P6** | Custom-board firmware (devicetree, power profile, signed OTA, field validation) | Zephyr/NCS | **9** |

**Critical risks:** (a) iOS background BLE (P5) — design for a persistent notifying connection from the start;
(b) multi-day power (P4) — PPG LEDs dominate, duty-cycling is mandatory; (c) raw-buffer storage (P3) — 8 MB QSPI
holds only ~2.5 h raw, so the IBI fallback is required for long offline windows.

## 10. Activity priming (coach → bridge → watch)

> **Status: implemented** in `firmware/banglejs/titan.app.js` (inbound command channel + `applyPriming()`,
> priming-aware `applyAccelRate()`) and `firmware/banglejs/bridge.html` (`pollActivity()`). The snippets below
> document the design; the live files already contain them, ready to flash on setup day.

**Goal.** Let the user *tell the app* what they're about to do — "going for a run" in the coach chat — and have
the watch switch into the right sensing mode for it (GPS + faster HR for a run, low-power for a swim/lift),
instead of waiting for the on-watch motion gate to guess. This is the device half of the server feature
already shipped (`start_activity` / `finish_activity` coach tools writing `profile.settings.active_activity`,
read back over `GET /api/devices/activity`).

**Architecture — the watch never does HTTP.** The Bangle.js is a dumb NUS sensor; only the **bridge**
(`firmware/banglejs/bridge.html`, or the companion app) has internet. So priming flows in three hops:

```
coach chat  ──start_activity──▶  Titan server  (settings.active_activity = {type, sampling})
                                       ▲ │
              GET /api/devices/activity│ │ {active, type, sampling:{gps,hr_hz,accel_hz}}
                                       │ ▼
                                   BRIDGE (polls every ~20 s, HMAC-signed)
                                       │
                       NUS RX write:  "C1:{\"type\":\"run\",\"gps\":true,\"accel_hz\":12.5}\n"
                                       ▼
                                   WATCH  → applyPriming() → typed manual workout, GPS/accel per profile
```

`finish_activity` clears the server state; the bridge then sees `{active:false}` and sends `C0:` to stand
the watch back down. The command channel reuses the existing NUS link — the bridge already holds the RX
characteristic (`bridge.html`: `NUS_RX = 6e400002-…`, `rxChar = await svc.getCharacteristic(NUS_RX)`).

### 10a. Bridge side — poll the endpoint, relay a NUS command

Add to `bridge.html`. It reuses the exact ingest auth: read `apiUrl`/`deviceId`/`secret` from the form inputs
and sign with the existing `hmacSha256Hex(secret, message)` helper (which derives `sha256(secret)` = the
server's `device_token_hash` internally). The activity GET signs the **same `"{t}.{body}"` string with an
empty body**, identical to `verifyDeviceSignature`:

```js
// --- Activity priming: poll what the coach started, relay it to the watch over NUS ---
let primedType = null;                       // last activity we pushed to the watch (de-dupe)

async function pollActivity() {
  if (!rxChar) return;                        // only while a watch is connected
  const base = $("apiUrl").value.trim();
  const deviceId = $("deviceId").value.trim();
  const secret = $("secret").value.trim();
  if (!base || !deviceId || !secret) return;
  const url = base.replace(/\/ingest$/, "/activity");
  try {
    const t = Math.floor(Date.now() / 1000);
    const sig = await hmacSha256Hex(secret, t + ".");      // empty body → sign "{t}."
    const res = await fetch(url, {
      method: "GET",
      headers: { "X-Device-Id": deviceId, "X-Titan-Signature": "t=" + t + ",v1=" + sig,
                 "Accept": "application/json" },
    });
    if (!res.ok) return;
    const a = await res.json();
    if (a.active && a.type !== primedType) {            // newly started → prime
      primedType = a.type;
      const cmd = "C1:" + JSON.stringify({ type: a.type, gps: !!a.sampling.gps,
        hr_hz: a.sampling.hr_hz, accel_hz: a.sampling.accel_hz }) + "\n";
      await rxChar.writeValue(new TextEncoder().encode(cmd));
    } else if (!a.active && primedType !== null) {      // finished → stand down
      primedType = null;
      await rxChar.writeValue(new TextEncoder().encode("C0:\n"));
    }
  } catch (e) { /* offline / link hiccup — retry next tick */ }
}
setInterval(pollActivity, 20000);   // 20 s cadence: responsive without hammering the API or the BLE link
```

### 10b. Watch side — receive the command, apply the sensing profile

The watch currently only *writes* NUS (`Bluetooth.println`). To *receive* commands we add a line-buffered
inbound handler. Inbound NUS bytes are normally fed to the Espruino REPL, so we take the console off BLE
first (it stays on USB for debugging) — then the channel is ours alone. Add near "Wire everything up" in
`titan.app.js`:

```js
// --- Inbound command channel (coach activity priming) -----------------------
// Keep the JS REPL on USB only, so inbound NUS is OUR command stream, not code eval.
try { E.setConsole("USB", { force: false }); } catch (e) {}

var primed = null;     // active coach-primed activity, or null
var cmdBuf = "";
Bluetooth.on("data", function (d) {
  cmdBuf += d;
  var nl;
  while ((nl = cmdBuf.indexOf("\n")) >= 0) {
    var line = cmdBuf.substr(0, nl).trim();
    cmdBuf = cmdBuf.substr(nl + 1);
    if (line.substr(0, 3) === "C1:") {            // prime: start a typed activity
      try { applyPriming(JSON.parse(line.substr(3))); } catch (e) {}
    } else if (line.substr(0, 2) === "C0") {      // stand down
      primed = null;
      if (state.workout && state.workoutManual) endWorkout();
    }
  }
});

// Switch the watch into the activity's sensing profile. We start a MANUAL workout (so a still
// gap mid-set / red light won't auto-end it — the user explicitly began this), then honor the
// per-activity sampling: GPS only when it helps, accel rate to match the server classifier.
function applyPriming(p) {
  primed = { type: (p && p.type) || "other", gps: !!(p && p.gps),
             accelHz: (p && p.accel_hz) || 25 };
  if (!state.streaming) startStreaming();         // ensure HR + accel are powered
  startWorkout(true);                             // manual → only C0/finish ends it
  powerGps(primed.gps);                           // override the motion-gate default (off for swim/lift)
  try { Bangle.setPollInterval(Math.round(1000 / primed.accelHz)); } catch (e) {}
  try { Bangle.buzz(150); } catch (e) {}          // haptic ack so the user knows it primed
  if (uiVisible) drawUI();
}
```

**Notes & caveats (for whoever flashes this):**
- *Console relocation* — `E.setConsole("USB", …)` requires USB to be the debug path; if you debug over BLE,
  keep a physical-button escape (long-press already toggles a manual workout) so you're never locked out.
- *De-dupe* — the bridge only sends `C1:` when `type` *changes*, so re-polling the same active run is a no-op;
  the watch also guards (`startWorkout` early-returns if a workout is already running).
- *Honesty* — this is **near-real-time, not instant**: priming lands on the next 20 s bridge poll while the
  watch is connected. If the watch is offline (phone-free run) the motion gate still auto-starts the workout;
  the coach's typing just makes it *immediate and correctly typed* when the bridge is in range. Matches how the
  rest of the device already trades real-time for power.
- *Security* — the command carries no secrets and the watch only acts on `C1:`/`C0:`; the trust boundary is the
  bonded BLE link + the HMAC the bridge uses to read the server. The watch never authenticates the command
  itself (it can't), so treat the bonded bridge as trusted, as the rest of the NUS protocol already does.
