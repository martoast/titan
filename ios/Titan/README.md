# Titan (iOS app target)

The SwiftUI app — CoreBluetooth background sync + the Titan UI. **Compiles in Xcode against the
iOS SDK** (CoreBluetooth, Network, SwiftUI) — NOT on a bare Command Line Tools install — and
depends on the `TitanCore` SwiftPM package (the verified, platform-agnostic core: Signer,
FrameDecoder, Windowing).

> These files are authored scaffold: the architecture and wiring are real, but they reference
> iOS-only frameworks, so they're verified by building in Xcode (Phase 1, on device). The
> deterministic logic they lean on (HMAC, frame decode, windowing, ULID) is already proven in
> `TitanCore` via golden vectors.

## Sources/BandSync
- `BandManager.swift` — `CBCentralManager` with state restoration + `bluetooth-central` background
  mode; the always-on connection (see `tasks/native-ios/01-background-ble.md`). Feeds bytes to
  `FrameRouter`.
- `FrameRouter.swift` — reassembles newline frames, dispatches `T1…T7` to `TitanCore` decoders,
  feeds the `PpgWindowBuilder` + `WorkoutAssembler`, and hands finished windows to `SyncQueue`.
- `IngestClient.swift` — signs (`TitanCore.Signer`), gzips, POSTs a batch to `/api/devices/ingest`.
- `SyncQueue.swift` — offline-durable FIFO upload queue; persists windows and drains on network,
  with backoff. Enqueue runs during background wakes so a relaunch flushes the overnight buffer.

## Required Xcode setup (per `tasks/native-ios/02-deployment.md`)
- Background Modes: "Uses Bluetooth LE accessories" + "Remote notifications"
- Push Notifications, HealthKit capabilities
- Info.plist: `NSBluetoothAlwaysUsageDescription`, `NSHealthShareUsageDescription`,
  `NSHealthUpdateUsageDescription`
- Add the local `TitanCore` package as a dependency.
