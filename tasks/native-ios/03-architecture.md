# 03 — Architecture & API surface

Synthesized from research C (which read the codebase + compared frameworks). Native
SwiftUI confirmed; the backend is reused; two backend gaps must close.

## Framework verdict: native Swift / SwiftUI
The hard requirement — being relaunched from a *terminated* state to handle BLE — is a
CoreBluetooth feature that only works cleanly when you own the native `CBCentralManager`
lifecycle. Ranking for THIS requirement:
- **Native SwiftUI — best.** You implement `willRestoreState` directly and act in the ~10s wake
  window with no bridge in the path. Reference implementation; least risk; lowest maintenance.
- **React Native (`react-native-ble-plx`) — viable, 2nd.** Exposes `restoreStateIdentifier`,
  but your JS runtime must spin up inside the wake window — extra latency on the most
  time-sensitive path.
- **Flutter — weaker.** Background restoration is an open/partially-unimplemented feature; you'd
  write a native plugin anyway.
- **Capacitor — disqualifying.** BLE runs through a WKWebView iOS can kill independently; on a
  restoration relaunch the WebView (and your JS handlers) may not even be alive to act.

Clincher: the logic to port is small (frame decode + one HMAC), there's no portable JS/Dart
codebase to reuse (current UI is Blade), so native carries the least risk.

## API surface the app consumes
Two auth domains already exist. **Key finding: there is NO mobile user-auth token today** —
web/coach/device-management routes are session-cookie only; the only bearer token (`auth.token`)
is a separate MCP/assistant token, not a user login. That's backend gap #1.

### Device ingestion — HMAC, tokenless — PORT AS-IS (no backend change)
- `POST /api/devices/ingest` → 202. `routes/api.php:27` → `app/Http/Controllers/Api/DeviceIngestionController.php:40`
- `GET /api/devices/activity` — band polls active activity / sampling profile. `:119`
- `GET /api/devices/commands` — drained coach commands (buzz, sync). `:109`
- Headers on every signed call: `X-Device-Id`, `X-Titan-Signature: t=<unix>,v1=<hexhmac>`
  (`DeviceIngestionController.php:233-234,467`).

### HMAC contract (EXACT — must byte-match) — `TerraClient::verifyDeviceSignature` `app/Services/Wearables/TerraClient.php:111-136`
- Signed string = `"<t>.<rawBody>"`, `t` = unix seconds.
- `v1 = hash_hmac('sha256', t.'.'.rawBody, KEY)` → lowercase hex, constant-time compared.
- **KEY = `sha256_hex(secret)`** — the sha256 *hex digest* of the 32-byte hex secret, NOT the
  raw secret. (Server stores only `sha256(secret)`; bridge `_sign()` does the same —
  `resources/views/devices/bridge.blade.php:489-496`, server `DeviceIngestionController.php:251-257`.)
- Replay window ±300s (`TerraClient.php:129`).
- Body JSON: `{ batch_uid:<ULID>, windows:[...], summaries:[...], device:{battery,firmware} }`.
  `batch_uid` must be a valid ULID. Optional `Content-Encoding: gzip`. Limits: 5 MB / 500
  windows / 120 req per 60s per device. Idempotent on `batch_uid`.
- Window shapes: A/B raw (`kind: ppg_raw|ibi`, `start,end,sample_rate_hz,ppg[],accel_mag_cg[],src`)
  → MinIO + `ProcessWindowJob`; C summaries (`kind: recovery|sleep|body|activity`) → canonical
  tables (`DeviceIngestionService.php:55-228`).
- See `05-protocol-port.md` for the full frame + signing port.

### Device management — currently session auth → NEEDS token auth for mobile
- `POST /api/devices/pair` → `{device_id, secret, source, connection_id}` (secret once). `:156`
- `GET /api/devices/ingestions?since=` — catch-up reconciliation. `:206`
- `DELETE /api/devices/{connection}` — revoke. `:189`

### Coach chat — currently session auth → NEEDS token auth — `routes/titan/coach.php`
- `POST /coach/stream`, `POST /coach/{conversation}/stream` — **SSE** (`text/event-stream`).
  Events: `meta{conversation_id}`, `delta{text}` (token stream), `tool{name,label}` (status
  pills), `done{conversation_id,content}`, `suggestions{items[]}`, `error{message}`
  (`CoachController.php:203-265`).
- `POST /coach/send` — non-streaming JSON fallback.
- `GET /coach/{conversation}/messages?before=` — history.
- `POST /coach/scan` (photo→meal/bloodwork, multipart), `/coach/transcribe` (voice→text),
  `/coach/briefing`.
- Body: `{ message: string ≤4000 }`. Client renders streamed markdown +
  ` ```titan-card ` JSON fences (readiness/stats/stat/sparkline/cycle —
  `CoachService.php:437-441`).

## Screens to build (SwiftUI feature modules)
Core: **Onboarding/intake**, **Coach chat** (primary surface — the chat runs all of Titan),
**Dashboard/daily briefing** (readiness, vitals), **Recovery** (HRV/RHR + confidence),
**Sleep** (stages/hypnogram), **Workouts** (list/create/live/show), **Devices/pairing**
(the bridge becomes the in-app BLE manager — no Bluefy), **Profile/settings**.
Secondary verticals to follow: biomarkers, body, meals, cycle, physique, brain/knowledge,
research library, notifications, progress.

## Swift module architecture (SwiftUI + async/await; SPM package per area)
- **`TitanBLE`** — `CBCentralManager` (restore identifier, `bluetooth-central`, `willRestoreState`),
  NUS subscribe, newline frame reassembly. (See `01-background-ble.md`.)
- **`FrameDecoder`** — port `resources/js/bridge-decode.js` + bridge inline decoders (T1 live
  PPG/accel, T2 overnight, T4 GPS, T5 HR, T6 accel, T7 baro) via `withUnsafeBytes` little-endian
  loads. Port `WorkoutAssembler` (pure logic) verbatim.
- **`Windowing`** — 120s timestamp windowing, ppg_raw window builder, ULID generator.
- **`Signer` (CryptoKit)** — `SHA256.hash(secret)`→hex→key →
  `HMAC<SHA256>.authenticationCode(for:"\(t).\(body)", using: SymmetricKey(keyHexUtf8))`→hex →
  header `t=…,v1=…`. **The single load-bearing port — must byte-match the server.**
- **`IngestClient`** — gzip large bodies, POST, handle 202/`duplicate:true`/429 `Retry-After`.
- **`SyncQueue`** — offline-durable (GRDB/SQLite): persist each window
  (kind/start/end/payload/batch_uid/attempts), FIFO drain on `NWPathMonitor`, exponential
  backoff, reconcile against `GET /ingestions?since=`. **Enqueue during `willRestoreState`** so a
  relaunch flushes the overnight buffer.
- **`APIClient`** — typed endpoints; SSE via `URLSession.bytes` `AsyncSequence` parsing
  `delta/tool/done/...`.
- **`Auth` + Keychain** — store HMAC `secret` + user token with
  `kSecAttrAccessibleAfterFirstUnlock` (so a background wake can sign).
- **`Push`** — APNs (`UNUserNotificationCenter`), device-token registration.
- **Feature modules** (SwiftUI view + view-model → `APIClient`): Coach, Dashboard, Recovery,
  Sleep, Workouts, Devices, Onboarding, Profile.

## Backend changes required (the only blockers)
1. **Mobile user auth — REQUIRED.** No token login exists. Add **Laravel Sanctum**
   (`composer require laravel/sanctum`), `HasApiTokens` on `User`, `POST /api/login` →
   `createToken()` (+ logout). Then add `auth:sanctum` variants of the coach + device-management
   routes (or accept Sanctum on the existing ones). Don't conflate with the MCP `auth.token`
   middleware (different token type) — run Sanctum alongside it. **HMAC ingest needs no change.**
2. **APNs push — REQUIRED for parity.** Current push is Web Push/VAPID (`WebPushService.php`),
   which can't reach native iOS. Add an APNs provider + `POST /api/devices/push-token` to
   register the per-user APNs token; branch `NotificationService` to APNs for mobile.
- **Optional:** a `GET /api/me/dashboard` aggregate (JSON) so the app doesn't scrape Blade
  pages — the coach's data tools already compute this server-side. Confirm SSE passes through
  prod Caddy unbuffered (stream already sets `X-Accel-Buffering: no`).

## Sources
- Apple TN3115 — Bluetooth state restoration relaunch rules: https://developer.apple.com/documentation/technotes/tn3115-bluetooth-state-restoration-app-relaunch-rules
- CoreBluetooth Background Processing (archive): https://developer.apple.com/library/archive/documentation/NetworkingInternetWeb/Conceptual/CoreBluetooth_concepts/CoreBluetoothBackgroundProcessingForIOSApps/PerformingTasksWhileYourAppIsInTheBackground.html
- react-native-ble-plx background mode: https://github.com/dotintent/react-native-ble-plx/wiki/Background-mode-(iOS)
- flutter_blue_plus background #846: https://github.com/chipweinberger/flutter_blue_plus/issues/846
- Capacitor WKWebView termination: https://github.com/ionic-team/capacitor/discussions/7097
