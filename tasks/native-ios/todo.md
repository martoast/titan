# Titan iOS — TODO

Full plan in `00-overview.md` → `05-protocol-port.md`. Detailed phases + DoD in `04-roadmap.md`.

## 🔴 Blocked on Alex (human-only — start these to unblock everything)
- [ ] Enroll **Apple Developer Program** (Individual, $99/yr) — long pole, do first.
- [ ] Create Xcode project `Titan` (SwiftUI), bundle `com.titan.app`, automatic signing.
- [ ] Create **APNs .p8** auth key (note Key ID + Team ID).
- [ ] Be available to test on a **real iPhone + the real band** (BLE/restoration can't be simulated).

## 🟢 Agent can do now (testable in this repo — no Apple account needed)
**Backend foundation (Phase 0):**
- [ ] Add **Laravel Sanctum**; `HasApiTokens` on User; `POST /api/login` + `/api/logout`; tests.
- [ ] `auth:sanctum` route variants for coach + device-management (don't conflate with the MCP
      `auth.token` middleware — run alongside).
- [ ] `POST /api/devices/push-token` + migration (store per-user APNs token).
- [ ] (Optional) `GET /api/me/dashboard` JSON aggregate so the app doesn't scrape Blade.

**Deterministic Swift core (author + unit-test; drop into Xcode later):**
- [ ] `Signer.swift` + golden-vector tests (generate vectors from PHP first — see 05 §5).
- [ ] `FrameDecoder.swift` (T1/T2/T4/T5/T6/T7) + `WorkoutAssembler` port.
- [ ] `Windowing.swift` (120s ppg_raw windows, ULID).
- [ ] `BandManager.swift` (CoreBluetooth restoration skeleton from 01).
- [ ] `IngestClient.swift` + `SyncQueue.swift` (offline-durable, GRDB/SQLite).

## Sequencing
1. Backend Sanctum + push-token (unblocks authed screens).
2. Golden-vector + `Signer` (de-risk the load-bearing HMAC).
3. **Phase 1 on device: prove background BLE** (the make-or-break) — needs Alex + Apple account.
4. Then UI phases (coach → dashboards) per `04-roadmap.md`.

## Status
- ✅ Deep research complete (background BLE, deployment, architecture) — all 3 streams synthesized.
- ✅ Full plan written (this folder).
- ⏭️ Next autonomous step: backend Sanctum auth + push-token endpoint + the `Signer` golden vectors.
