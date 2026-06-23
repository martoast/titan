# Titan iOS — Native App Project

## Why this exists (the mission)

Titan is an open-source, subscription-free AI health/longevity OS with a DIY recovery
wearable. The web/PWA bridge proved the whole pipeline works (band → BLE → ingest →
biosignal → coach), but it hit a hard wall: **Web Bluetooth cannot run in the background**,
so the band only syncs while a browser tab is open and focused. That's a dealbreaker for
everyday wear.

To make this genuinely usable — and free — for everyone, we go native. A native iOS app
using **CoreBluetooth background mode + state preservation/restoration** is exactly how
Whoop/Oura achieve always-on sync (phone in pocket, app closed, data still flowing). This
is the difference between a demo and a product people actually wear 24/7.

**iPhone is the priority platform.** Android (Capacitor/Gadgetbridge) comes later.

## The decision (made)

- **Native Swift / SwiftUI**, not React Native / Flutter / Capacitor. Rationale: background
  BLE with CBCentralManager state restoration is the single hardest requirement, and native
  CoreBluetooth is the only approach with first-class, reliable support. (Being confirmed by
  research agent A; strong prior.)
- **Reuse the existing Laravel backend.** The server already does ingest (HMAC-signed
  windows), biosignal processing (HRV/sleep/recovery), and the AI coach. The native app
  replaces the **Blade UI + the web bridge** — NOT the backend. Backend changes are limited
  to what's needed for a mobile client (e.g. Sanctum token auth, APNs push).
- **The band firmware stays as-is.** It already streams the T1–T7 NUS frame protocol. The
  iOS app does what the bridge did (receive frames → HMAC-sign → POST to `/api/devices/ingest`)
  plus the full app experience plus background BLE.

## What the native app must do

1. **Background BLE sync** (the whole point): pair the band, then stay connected / reconnect
   automatically in the background and on relaunch-after-termination, ingesting the streamed
   PPG/accel/HR frames continuously. Morning-sync the overnight flash log on reconnect.
2. **Port the bridge logic to Swift**: decode the T1–T7 binary frames, batch into windows,
   HMAC-SHA256 sign, gzip, POST to the ingest API. (Deterministic port of the proven JS.)
3. **The full app UI** (replacing Blade screens): onboarding, dashboard / daily check-in,
   recovery (HRV/RHR/readiness), sleep, workouts, the **AI coach chat**, device pairing.
4. **Push notifications** via APNs (nudges, briefings, "band synced").
5. Optional but high-value: **write to Apple Health** (HealthKit) so Titan data shows up in
   the Health app and interops with the ecosystem.

## Honest scope & the human-in-the-loop steps

This is a multi-week build. An agent can write the app, the CoreBluetooth manager, the
protocol port, the SwiftUI screens, and the backend changes. It **cannot** do these — they
need Alex:
- Enroll in the **Apple Developer Program** ($99/yr) — required to run on a real device,
  use background BLE entitlements, and ship to TestFlight/App Store.
- Sign in to Xcode with the Apple ID for signing/provisioning.
- Physically test on an iPhone with the real band (BLE can't be simulated).
- Submit to App Store review (we'll prep everything to pass it).

## Plan documents (filled from research synthesis)

- `00-overview.md` — this file (mission, decision, scope)
- `01-background-ble.md` — CoreBluetooth background/restoration design (from research A)
- `02-deployment.md` — Apple Developer → signing → TestFlight → App Store checklist (research B)
- `03-architecture.md` — Swift module architecture + API surface + backend changes (research C)
- `04-roadmap.md` — phased build plan with milestones and a definition of done per phase
- `05-protocol-port.md` — the T1–T7 frame + HMAC ingest contract, ported to Swift

Status: research in flight (3 agents). This overview is the stable framing; the technical
docs land as each research stream returns and is synthesized.
