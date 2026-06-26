# Titan Wearable — Firmware P0 (Bangle.js 2)

Streams **raw PPG + accelerometer** from a stock [Bangle.js 2](https://www.espruino.com/Bangle.js2)
over Bluetooth to the Titan server, where all HRV / sleep / activity DSP runs.
This is **P0** of the firmware roadmap (`tasks/titan-wearable/02-firmware.md` §9):
prove the whole server-side pipeline on real raw data using a hackable
off-the-shelf watch, no firmware toolchain required.

> The watch is a **dumb sensor**. It samples raw PPG (`HRM-raw`, ~25 Hz) + raw
> accel, timestamps every sample, binary-packs them, and streams them. It never
> computes HRV on-device — the averaged on-chip BPM has already thrown away the
> ms-level inter-beat timing that HRV is made of. The on-watch BPM display is
> for the human only and is never sent.

## Files

| File | Role |
|---|---|
| `titan.app.js` | The watch app. Raw PPG + accel capture, binary packing, NUS streaming, offline flash buffer, on-watch UI. |
| `titan.icon.js` | App Loader icon source (`evaluate:true` → `titan.img`). |
| `titan.app.json` | Bangle App Loader app entry (storage manifest). |
| `titan.info` | Per-app metadata written to watch storage by the loader. |
| `bridge.html` | Standalone Web Bluetooth page: connect → parse → batch → POST to Titan with HMAC. No-login fallback. |
| **in-app bridge** | The recommended path: **Titan → Devices → Live stream** (`/devices/bridge`). Same frame-decode + HMAC math, but inside your logged-in session, with a live waveform + ingest log + a no-hardware test window. |

## Data flow

```
            raw PPG (HRM-raw ~25Hz) + accel
Bangle.js 2  ─────────────────────────────►  binary-pack (int16 PPG, int16 milli-g accel)
   titan.app.js                                       │  base64 "T1:<…>\n" frames
                                                      ▼
                              Nordic UART Service (NUS notify, ~2.5 KB/s link)
                                                      │
                        ┌─────────────────────────────┴─────────────────────────────┐
                        ▼ (a) Android                                                ▼ (b) Desktop debug
                   Gadgetbridge                                                  bridge.html
              (NUS → HTTP forward)                                          (Web Bluetooth → fetch)
                        │                                                              │
                        └───────────────► POST /api/devices/ingest ◄──────────────────┘
                              X-Device-Id + X-Titan-Signature (HMAC-SHA256)
                                                      │
                                                      ▼
                                Titan (Laravel) → MinIO raw + device_ingestions
                                                      │
                                                      ▼
                          FastAPI biosignal: PPG → IBI → HRV / sleep / activity
                                  → recovery_logs / sleep_logs / workouts
```

When **no central is connected**, the watch logs compact PPG-only `T2` frames to a
flash file (`titan.log`, a StorageFile) and **streams the whole log out on reconnect,
then erases it** — this is the overnight-logging / morning-sync path (see "Path B" below).

## Install

### Option 1 — Espruino Web IDE (fastest for one watch)
1. Open the [Espruino Web IDE](https://www.espruino.com/ide/), connect to the
   Bangle.js 2 over Web Bluetooth (Chrome/Edge).
2. Paste the contents of `titan.app.js` into the right-hand editor and click
   **Send to Espruino** (the upload-to-RAM/Flash button). It runs immediately.
   The icon and `.json`/`.info` files are not needed for this path.
3. **Swipe** left/right to move between faces; **click the side button** to act on
   the face you're on. The touchscreen only navigates — taps never trigger anything,
   so you can't start a run or timer by accident mid-swipe. The button is
   context-aware: 1 click = the face's primary verb (Heart = start/end a workout,
   Stopwatch = timer, Counter = +1, Run = start/finish, Status = sync), 2 clicks =
   the secondary verb (Heart = capture on/off, Stopwatch = sleep, Counter = reset).
   Pairing lives on the **Status** face (click the button there when unpaired).

### Option 2 — Bangle App Loader (proper install, survives reboot)
1. Drop this folder into a checkout of
   [`espruino/BangleApps`](https://github.com/espruino/BangleApps) under
   `apps/titan/`, and add the `titan.app.json` object to the top-level
   `apps.json` (the loader's app index).
2. Run the loader locally (`npx http-server` in the BangleApps root) or use the
   hosted loader, connect the watch, and install **Titan Streamer** from the
   list. The loader writes `titan.app.js`, `titan.img`, and `titan.info` to
   watch storage and reserves the `titan.log` overnight-log data file.
3. Launch **Titan** from the watch's app menu.

## Path B — overnight logging + morning sync (simplest for iPhone)

**No phone needed by the bed, no companion app.** When the watch is *not* connected, it
logs PPG to its own flash in a compact PPG-only format (`T2` frames: ~2.8 B/sample, so a
full 8 h night ≈ ~2 MB, fits the 8 MB flash; the log is preserved, never wiped). On the
next connection it streams the whole night out in a few seconds, then erases.

**The routine:**
1. **Before bed:** make sure the watch clock is set (so timestamps are right), open Titan,
   press **BTN** to start (screen shows `REC` + `log` + a growing `logged: …KB`). Charge it
   first — continuous PPG is ~1 day.
2. **Wear it to sleep.** It logs to flash all night. No phone required.
3. **In the morning:** open Titan → **Devices → Live stream** on your Mac (or any
   desktop/Android Chrome), click **Connect**. The watch auto-dumps the night; the page
   decodes it, cuts it into 2-minute windows, signs each, and POSTs to `/api/devices/ingest`
   → HRV/sleep land on your dashboards. Takes seconds.

> Verified end-to-end: a simulated 6-min night (72 `T2` frames) synced as one burst →
> decoded → 3× 120 s windows → all accepted (202) → NeuroKit2. Live (`T1`) and synced
> (`T2`) both flow through the same bridge.

## Live / always-on paths

### (a) Gadgetbridge (Android — the real-wear path)
Espruino exposes the stream over the **Nordic UART Service**, which
[Gadgetbridge](https://codeberg.org/Freeyourgadget/Gadgetbridge) speaks
natively for Bangle.js. The **"banglejs" build flavor** of Gadgetbridge
re-enables internet access so it can forward NUS lines to an HTTP endpoint
(`02-firmware.md` §6). Point that forwarder at your Titan ingest URL and supply
the `X-Device-Id` + HMAC header. Gadgetbridge runs a foreground service, so it
survives Doze and works overnight — unlike Web Bluetooth.

> Note: a tiny forwarder/transform is still needed to (1) batch the `T1:` lines
> and (2) attach the HMAC signature. `bridge.html`'s `buildBody` + HMAC logic is
> the reference implementation to port into that forwarder (or a small companion
> app) — it is identical math.

### (b) In-app bridge — Titan → **Devices → Live stream** (recommended desktop path)
The bridge is built into Titan at **`/devices/bridge`** (linked from the Devices
page). Pair a Bangle.js there to get a device ID + one-time secret, click
**Connect Bangle over Bluetooth**, and Titan decodes the `T1:` frames, assembles
`ppg_raw` windows, HMAC-signs each batch (key = `sha256hex(secret)`, via WebCrypto)
and POSTs to `/api/devices/ingest` — all inside your logged-in session, with a live
PPG waveform, bpm, counters, and an ingest log. A **"Send test window"** button
synthesizes a 120 s / 25 Hz window through the *real* sign + ingest + NeuroKit2 path,
so the pipeline is verifiable before the hardware lands (proven to produce a recovery
RMSSD end-to-end).

### (c) `bridge.html` (standalone, no-login fallback)
`bridge.html` is the original standalone version — open it in Chrome/Edge
(`file://` works), paste the Ingest URL + Device ID + secret, **Connect watch**.
Same frame parsing + HMAC math as the in-app bridge; useful when you want a bridge
without a Titan session.

Web Bluetooth is **desktop/Android-only — no iOS, no background mode** — a debug/
dogfood tool, not the shipping path. (`02-firmware.md` §6 disqualifies it for
release; the overnight path is Gadgetbridge today, a native companion at P5.)

## Wire format (watch → bridge)

Each NUS line is `T1:` + **base64** of one binary frame. Base64 + newline
framing keeps raw bytes from corrupting Espruino's text-line NUS console.

**Frame header (16 bytes, little-endian):**

| Offset | Type | Field |
|---|---|---|
| 0 | uint8 | proto version (=1) |
| 1 | uint8 | PPG field code (index into `["vcPPG","raw","filt","adc"]`; 255 = unknown) |
| 2 | uint16 | sample count |
| 4 | uint32 | epoch ms, low 32 bits |
| 8 | uint32 | epoch ms, high 32 bits (full 64-bit unix ms of first sample) |
| 12 | uint16×2 | reserved |

**Per sample (12 bytes, little-endian), repeated `sample count` times:**

| Offset | Type | Field |
|---|---|---|
| +0 | uint32 | ms since frame epoch |
| +4 | int16 | raw PPG value |
| +6 | int16 | accel X (milli-g) |
| +8 | int16 | accel Y (milli-g) |
| +10 | int16 | accel Z (milli-g) |

Default frame = 12 samples → 16 + 12·12 = **160 bytes** binary → ~216 chars
base64, well under the 244-byte ATT payload and the ~2.5 KB/s link budget.

## Batch JSON (bridge → Titan)

`bridge.html` POSTs to `POST /api/devices/ingest` (`04-platform-pipeline.md`
§2.2). Two shapes, both accepted by the same endpoint:

**Shape B — raw PPG (default, honest P0):** server peak-detects and computes HRV.
```jsonc
{
  "batch_uid": "01J…ULID",
  "device_id": "tb_xxxxxxxx",
  "timezone": "America/Mexico_City",
  "windows": [{
    "kind": "ppg_raw",
    "start": "2026-06-14T07:01:10.000Z",
    "end":   "2026-06-14T07:01:20.000Z",
    "sample_rate_hz": 25,
    "ppg":      [ 1023, 1041, 1102, /* …int16… */ ],
    "accel_xyz":[ [12,-987,55], [10,-985,60], /* …milli-g… */ ],
    "t_ms":     [ 1781506870000, 1781506870040, /* …abs ms per sample… */ ],
    "source": "bangle",
    "ppg_field": "vcPPG"
  }]
}
```

**Shape A — IBI + accel (optional, cheaper, lossier):** on-page peak detector.
```jsonc
{
  "batch_uid": "01J…ULID",
  "device_id": "tb_xxxxxxxx",
  "timezone": "America/Mexico_City",
  "windows": [{
    "kind": "ibi",
    "start": "…Z", "end": "…Z",
    "ibi_ms": [812, 798, 805, 1190, 801],
    "accel_counts": [3, 1, 0, 0, 2],
    "confidence": 0.9,
    "source": "bangle",
    "ppg_field": "vcPPG"
  }]
}
```

**Auth header (both shapes):**
```
X-Device-Id: tb_xxxxxxxx
X-Titan-Signature: t=<unix_seconds>,v1=<hex HMAC_SHA256("<t>.<rawBody>", deviceSecret)>
```
Identical to `TerraClient::verifySignature()` — one verifier covers Terra and
the band. The server rejects if `|now − t| > 300s`. Response is always **202**
`{accepted, batch_uid, windows_queued, duplicate}`; `batch_uid` is the
idempotency key.

## Assumptions / things to verify on real hardware

- **`HRM-raw` fields.** Espruino's `HRM-raw` event object is sensor/firmware
  dependent. We try `e.vcPPG` → `e.raw` → `e.filt` → `e.adc` and tag each frame
  with which one we used (`ppg_field`). On VC31B Bangle.js 2 builds `vcPPG` is
  the documented raw-PPG field; confirm with `Bangle.on('HRM-raw', e => print(e))`
  in the IDE and adjust `CFG.PPG_FIELDS` in `titan.app.js` if your build differs.
- **Effective PPG rate** is ~25 Hz but firmware-controlled; `bridge.html`
  measures and reports the real `sample_rate_hz` from sample timestamps rather
  than hard-coding it.
- **Accel cadence** is set with `Bangle.setPollInterval(80)` (~12.5 Hz). PPG is
  the master clock; each PPG sample carries the most recent accel reading so
  both share one timeline (what the server's accel-referenced artifact removal
  needs).
- **Base64 over NUS** trades ~33% bandwidth for line-safe framing. At 25 Hz / 12
  bytes per sample this is ~300 B/s raw → ~400 B/s base64, comfortably inside
  the ~2.5 KB/s Bangle link.
- **Offline buffer** is a simple FIFO capped at 200 KB; on overflow it drops the
  whole buffer (the server is authoritative once any sync lands). A
  littlefs-style ring with cursors is a P3 concern, not P0.
