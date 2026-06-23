# 04 — Phased roadmap

Goal: a native iOS app that pairs the Titan band and syncs biosignals **always-on in the
background** (Whoop-parity), with the full Titan experience (coach, recovery, sleep, workouts),
shipped to the App Store. iPhone-first; Android later.

Each phase has a **Definition of Done (DoD)**. Phases 0–2 de-risk the core; 3+ build the
product. The single highest-risk thing (background BLE) is proven first, on real hardware.

---

## Phase 0 — Foundations (parallelizable; Alex + agent)
**Alex (human-only gates):**
- Enroll Apple Developer Program (Individual). *[blocks device testing + TestFlight]*
- Create Xcode project `Titan` (SwiftUI lifecycle), bundle `com.titan.app`, auto-signing.
- Create the APNs `.p8` key (note Key ID + Team ID).

**Agent (in this repo, testable now):**
- **Backend mobile auth (Sanctum):** add Sanctum, `HasApiTokens`, `POST /api/login`/`logout`,
  `auth:sanctum` route group mirroring coach + device-management. Tests.
- **`POST /api/devices/push-token`** endpoint + migration to store per-user APNs tokens.
- Land the Swift source for the load-bearing, deterministic parts (no Xcode needed to author):
  `Signer.swift`, `FrameDecoder.swift`, `BandManager.swift`, `IngestClient.swift`,
  `SyncQueue.swift` under `ios/Titan/Sources/` (see `05-protocol-port.md`).

**DoD:** Apple account active; Xcode project builds an empty app to a real iPhone; backend
exposes Sanctum login + push-token, green tests.

## Phase 1 — Background BLE core (THE de-risk; on real hardware)
- Wire `BandManager` into the app: `bluetooth-central` background mode,
  `CBCentralManagerOptionRestoreIdentifierKey`, `willRestoreState`, no-timeout reconnect.
- Subscribe to NUS TX, reassemble newline frames, decode T1/T5/T7 (live path).
- Pair flow: call `POST /api/devices/pair`, capture the one-time secret into Keychain.
- Prove the killer test: **stream live, lock the phone, background the app, force the app
  out of foreground — confirm data keeps arriving and uploading.** Then test relaunch after
  termination via state restoration.

**DoD:** With the app backgrounded and the screen locked, the band's PPG windows arrive at
`/api/devices/ingest` continuously; killing+relaunching via a BLE event restores the
connection. This is Whoop-parity proven. (If this passes, the project's core risk is gone.)

## Phase 2 — End-to-end sync + offline durability
- `Signer` byte-matches the server (golden-vector test against a known
  `(secret, t, body) → v1` from the web bridge).
- `Windowing` builds 120s ppg_raw windows + the workout assembler; `IngestClient` gzips + POSTs.
- `SyncQueue`: persist windows offline (airplane mode), drain on reconnect, reconcile via
  `GET /ingestions?since=`. Morning-sync the overnight flash burst.
- Verify recovery/sleep land server-side and the coach can read them (the existing pipeline).

**DoD:** Wear overnight → open app in the morning → night syncs → recovery + sleep appear,
even if connectivity dropped mid-sync (queue recovers). No data loss.

## Phase 3 — Coach chat (primary surface)
- SSE client (`URLSession.bytes`) rendering streamed markdown + `titan-card` fences
  (readiness/stats/sparkline/cycle). Tool-status pills. History pagination.
- Photo scan (meal/bloodwork) + voice transcribe. Conversation list.

**DoD:** Full coach chat parity with the web — streaming replies, cards, photo/voice — on a
Sanctum-authed mobile session.

## Phase 4 — Core dashboards
- Dashboard / daily check-in, Recovery (HRV/RHR + confidence), Sleep (stages/hypnogram),
  Workouts (list/live/show), Devices, Profile/settings. Onboarding/intake.
- Back these with a `GET /api/me/dashboard` aggregate (or per-screen JSON endpoints).

**DoD:** A new user can onboard, pair the band, and see recovery/sleep/workouts + chat — the
whole loop — natively.

## Phase 5 — Platform polish for parity
- **HealthKit** write (recovery/sleep/workouts → Apple Health). Usage strings + privacy policy.
- **APNs** push: nudges, daily briefing, "band synced/low battery." Token registration.
- **Core Location region monitoring** fast-follow to recover the force-quit/reboot BLE case.
- Battery/throughput tuning for sustained background wear.

**DoD:** Data appears in Apple Health; push notifications fire from the Laravel scheduler;
sync survives a force-quit (region re-wake). Feels like Whoop.

## Phase 6 — Ship
- App Privacy nutrition label + privacy policy URL.
- Demo mode/account + **demo video of band pairing** for review (Guideline 2.1).
- TestFlight internal (Alex + band) → external beta → App Store submission with
  background-Bluetooth + health justifications. Budget ~5–10 days first review.

**DoD:** Live on the App Store, free, open-source. People can wear the band 24/7.

---

## Sequencing logic
- **Prove background BLE (Phase 1) before building UI.** It's the one thing that can kill the
  project; everything else is conventional app work. If restoration somehow underperforms on
  Alex's hardware, we learn it in week 1, not month 2.
- Backend auth (Phase 0) unblocks every authed screen — do it first, it's testable here.
- The deterministic ports (Signer/FrameDecoder) can be written + unit-tested before the Xcode
  project even exists, so they're ready to drop in.

## What's blocked on Alex (and why)
| Gate | Why it can't be automated |
|---|---|
| Apple Developer enrollment | Identity verification + payment |
| Real-device + real-band BLE testing | BLE can't be simulated; restoration only works on device |
| Xcode signing login | Apple ID credential |
| App Store submission | Apple account + demo video of physical hardware |

Everything else — backend changes, all Swift source, the protocol port, unit tests — proceeds
autonomously and is ready for Alex to assemble in Xcode.
