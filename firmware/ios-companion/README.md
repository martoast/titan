# Titan iOS Companion

The **overnight path for iPhone.** Web Bluetooth doesn't run in the background on iOS, so
the in-app bridge (`/devices/bridge`) can't capture sleep. This native app can: it keeps a
Core Bluetooth link to the Bangle.js alive in the background, decodes the raw-PPG stream,
and forwards 2-minute windows to Titan — all night, while your phone is on the nightstand.

```
Bangle.js 2  ──T1 frames (BLE/NUS)──▶  Titan Companion (iPhone, background)
                                             │  assembles ppg_raw windows
                                             │  HMAC-signs (= server scheme)
                                             ▼
                                POST /api/devices/ingest  → NeuroKit2 → recovery/sleep
```

## Why native (not React Native / Flutter / PWA)
Reliable overnight BLE on iOS needs **`bluetooth-central` background mode + CBCentralManager
state restoration** — iOS relaunches the app into the background and hands back the live
connection even if the app is evicted. That's a first-class Core Bluetooth feature; native
Swift gives the most dependable background behaviour, which is the entire job here.

## What's in here
| File | Role |
|---|---|
| `Signer.swift` | HMAC-SHA256 request signing + ULID. **Verified byte-identical to the PHP server.** |
| `FrameDecoder.swift` | Decodes the Bangle `T1:<base64>` binary frames → PPG samples. |
| `WindowAssembler.swift` | Buffers samples → 2-minute `ppg_raw` windows. |
| `IngestUploader.swift` | Signs + POSTs batches; disk store-and-forward if offline. |
| `BangleManager.swift` | Core Bluetooth: scan/connect, **background state restoration**, auto-reconnect. |
| `Settings.swift` | Device ID + ingest URL (UserDefaults); secret (Keychain). |
| `ContentView.swift` / `TitanCompanionApp.swift` | Minimal SwiftUI status + setup UI. |
| `Info.plist` / `project.yml` | Background mode + BLE usage string; XcodeGen project spec. |

## Build & install (≈10 min, on your Mac)

**Prerequisites:** Xcode, and a free Apple ID (no paid account needed for personal use).

1. **Generate the project**
   - With [XcodeGen](https://github.com/yonaskolb/XcodeGen) (`brew install xcodegen`): `cd firmware/ios-companion && xcodegen generate && open TitanCompanion.xcodeproj`
   - **Or manually:** Xcode → *New → App* (SwiftUI, iOS), delete its template files, drag in everything under `TitanCompanion/`, then in *Signing & Capabilities* add **Background Modes → Uses Bluetooth LE accessories**, and confirm `Info.plist` has `UIBackgroundModes=[bluetooth-central]` + `NSBluetoothAlwaysUsageDescription`.
2. **Sign:** select the target → *Signing & Capabilities* → pick your Apple ID team. Set a unique bundle id if needed.
3. **Run on your iPhone:** plug in, select it as the run destination, press ▶. First launch on the phone: *Settings → General → VPN & Device Management → trust your developer cert*.
4. **Grant Bluetooth** when prompted.

> **Free Apple ID caveat:** apps signed with a free account expire after **7 days** — re-run from
> Xcode to renew. A paid Apple Developer account ($99/yr) gives 1-year signing. For two phones
> (you + Tester C) the free path works, just re-deploy weekly.

## Configure
1. **Ingest URL:** the address your phone can reach Titan at.
   - Deployed Titan → its https URL.
   - Local testing → your Mac's LAN IP, e.g. `http://192.168.1.42:8088` (NOT `localhost` — that's the phone). Same Wi-Fi.
2. **Pair:** in Titan → **Devices → Pair a device → Bangle.js**. Copy the **device ID** + **one-time secret**.
3. In the app: paste both, set the ingest URL, tap **Connect Bangle**. Make sure `titan.app.js` is running on the watch (see `../banglejs/README.md`).

## How overnight actually works
- Once paired, the app issues a **pending connect** — iOS reconnects automatically when the band
  is in range, even while the app is suspended. No need to keep it foregrounded.
- Notifications from the watch **wake the app briefly** in the background to decode + upload.
- If Wi-Fi drops, windows are **buffered to disk** and flushed on reconnect — a dropped link
  doesn't lose the night.
- **Limits:** force-quitting the app (swipe up to kill) stops background BLE until you reopen it —
  just leave it running. Keep the phone within BLE range (nightstand is fine) and **charge the band**
  (continuous PPG ≈ ~1 day). iOS may briefly delay reconnects at its own discretion.

## Verified
- HMAC signature is **byte-identical** to the server verifier (cross-checked Swift vs PHP).
- All logic files type-check against the iOS/macOS SDK; UI files parse clean.
- Wire format matches `firmware/banglejs/titan.app.js` (T1 frame: 16-byte header + 12-byte samples).

This is P0/P5 scaffolding — a working overnight forwarder. Hardening later: a background
`URLSession` for upload-while-suspended, MCUboot OTA, and richer on-device status.
