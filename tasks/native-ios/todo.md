# Titan iOS — TODO

Full plan in `00-overview.md` → `05-protocol-port.md`. Detailed phases + DoD in `04-roadmap.md`.

## 🔴 Blocked on Alex (human-only — start these to unblock everything)
- [ ] Enroll **Apple Developer Program** (Individual, $99/yr) — long pole, do first.
- [ ] Create Xcode project `Titan` (SwiftUI), bundle `com.titan.app`, automatic signing.
- [ ] Create **APNs .p8** auth key (note Key ID + Team ID).
- [ ] Be available to test on a **real iPhone + the real band** (BLE/restoration can't be simulated).

## 🟢 Agent — DONE (verified in this repo, no Apple account needed)
**Backend foundation (Phase 0):**
- [x] Mobile auth WITHOUT Sanctum — reused the existing `ApiToken`/`auth.token` system:
      `POST /api/login` (→ bearer token, throttled), `POST /api/logout`. Tests green.
- [x] `POST /api/devices/push-token` + `push_tokens` table + `PushToken` model. Idempotent. Tests.
- [ ] Expose **coach + device-management routes under `auth.token`** for the mobile bearer token
      (currently session-only). ← next backend task.
- [ ] (Optional) `GET /api/me/dashboard` JSON aggregate so the app doesn't scrape Blade.
- [ ] APNs sender in Laravel (`laravel-notification-channels/apn`) wired to the scheduler/coach.

**Deterministic Swift core — `ios/TitanCore/` (ALL verified via golden vectors):**
- [x] `Signer.swift` — HMAC; **byte-matches PHP backend** (3 golden vectors).
- [x] `FrameDecoder.swift` (T1/T4/T5/T6/T7) — **byte-matches the JS decoder**.
- [x] `Windowing.swift` (ULID + 120s ppg_raw window builder) — **matches the bridge**.
- [ ] `WorkoutAssembler` port (T4/T6 → workout windows) — pure logic, next TitanCore piece.

**App-sync layer — `ios/Titan/Sources/BandSync/` (authored; compiles in Xcode vs iOS SDK):**
- [x] `BandManager.swift` — CoreBluetooth background + state restoration + no-timeout reconnect.
- [x] `FrameRouter.swift` — newline reassembly → decode → window → queue; T5 → live bpm.
- [x] `IngestClient.swift` — sign (TitanCore) + POST `/api/devices/ingest` (gzip TODO: zlib).
- [x] `SyncQueue.swift` — offline-durable FIFO drain (WindowStore protocol; GRDB backing TODO).

## Sequencing
1. Backend Sanctum + push-token (unblocks authed screens).
2. Golden-vector + `Signer` (de-risk the load-bearing HMAC).
3. **Phase 1 on device: prove background BLE** (the make-or-break) — needs Alex + Apple account.
4. Then UI phases (coach → dashboards) per `04-roadmap.md`.

## Status (2026-06-23 build session)
- ✅ Deep research (background BLE, deployment, architecture) — synthesized into this folder.
- ✅ **TitanCore complete + VERIFIED** (golden vectors, standalone `swift` runs): Signer (HMAC),
  FrameDecoder (T1–T7), Windowing (ULID + ppg_raw windows), WorkoutAssembler.
- ✅ **App sync backbone authored** (`ios/Titan/Sources/BandSync/`): BandManager (bg BLE +
  restoration), FrameRouter (→ both window kinds), IngestClient, SyncQueue.
- ✅ **Backend complete for the app, tested (371 green):** mobile login/logout/push-token;
  `auth.any` middleware → device + coach routes serve web AND mobile; `GET /api/me/dashboard`
  read aggregate (readiness + recovery + sleep + activity).

## ⏭️ Remaining (the bulk needs Xcode; backend bits are agent-doable)
- [ ] **SwiftUI feature modules** — Coach (SSE), Dashboard, Recovery, Sleep, Workouts, Devices
  (pairing), Onboarding, Profile. Best authored in Xcode (they compile against the iOS SDK);
  they consume the verified TitanCore + the API above.
- [ ] APNs sender in Laravel (`laravel-notification-channels/apn`) + a notification for nudges/
  briefings/"band synced" → the push_tokens table. (Dependency add.)
- [ ] App-layer impl details: zlib gzip in IngestClient, GRDB-backed WindowStore for SyncQueue.
- [ ] Then Xcode project assembly + **Phase 1 on device: prove background BLE** (needs Apple acct).
