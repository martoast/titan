# Titan Stream — Bangle.js 2 firmware (P0)

The P0 device side of the open-source recovery wearable. The Bangle.js 2 is a "dumb
sensor": it captures **raw PPG (+ coarse accel)**, timestamps it, and streams fixed-length
windows over BLE. Titan computes HRV/recovery server-side with NeuroKit2.

```
Bangle.js 2  ──raw PPG windows (NUS/JSON)──▶  Titan bridge (browser, Web Bluetooth)
                                                   │  HMAC-signs each batch
                                                   ▼
                                  POST /api/devices/ingest  (kind: "ppg_raw")
                                                   ▼
                            ProcessWindowJob → biosignal (NeuroKit2) → recovery_logs
```

## Install on the watch
1. Open the **Espruino Web IDE**: https://www.espruino.com/ide/
2. Connect to the Bangle.js 2 (top-left Bluetooth icon → select `Bangle.js xxxx`).
3. Paste `titan-stream.js` into the right-hand editor and click **Send to RAM** (▶).
   - To make it survive a reboot, save it as an app instead (`require("Storage").write(...)`),
     or add it to your own BangleApps fork.
4. The watch shows `TITAN · streaming / waiting BLE · <bpm> · buf <n> · sent <n>`.

## Stream into Titan
1. In Titan, go to **Devices → Pair a device → Bangle.js**. Copy the **device ID** and the
   **one-time secret** (shown once).
2. Open **Devices → Live stream a Bangle.js**, paste both (stored only in your browser).
3. Click **Connect Bangle over Bluetooth**, pick your Bangle. Each window (default 120 s)
   is signed and shipped; HRV appears on **Recovery** after the queue + NeuroKit2 run.

> No hardware yet? The bridge's **"Send test window"** button synthesizes a 120 s / 25 Hz
> PPG window and runs it through the *real* signing + ingest + NeuroKit2 path — proven to
> produce a recovery RMSSD end-to-end.

## Wire format (one JSON object per line, over NUS)
```json
{
  "kind": "ppg_raw",
  "start": "2026-06-14T05:00:00.000Z",
  "end":   "2026-06-14T05:02:00.000Z",
  "sample_rate_hz": 25,
  "ppg": [2048, 2051, ...],
  "accel_mag_cg": [101, 99, ...],
  "src": "banglejs2"
}
```
The bridge wraps each window as `{ "batch_uid": "<ULID>", "windows": [ <window> ] }` and signs:
`X-Titan-Signature: t=<unix>,v1=HMAC_SHA256("<t>.<body>", sha256hex(secret))`, `X-Device-Id: <id>`.

## Tunables (`titan-stream.js`)
- `WINDOW_SEC` (default 120) — seconds of PPG per window. **≥60** for a stable RMSSD. Lower it
  if you see `TX overflow` (large windows can exceed the NUS transmit buffer).
- `KEEP_SCREEN` (default false) — keep the LCD on for debugging (costs battery).

## Scope / limits (P0)
- **Web Bluetooth bridge = desktop/Android Chrome only.** iOS WebKit has no Web Bluetooth;
  an overnight iOS path needs the native companion (firmware roadmap P5) or Gadgetbridge (Android).
- Streams only while connected (no on-watch store-and-forward yet — that's the custom-band P3).
- This is the **prove-the-loop** rig. The product firmware path is Zephyr/NCS on a custom
  nRF52840 board — see [`../../tasks/titan-wearable/02-firmware.md`](../../tasks/titan-wearable/02-firmware.md).

## Claim discipline
Wellness vocabulary only — recovery / sleep / fitness. Never diagnose, monitor, or treat;
no ECG/AFib/SpO2/BP medical claims. (Keeps us off the FDA/MDR device-regulation rails.)
