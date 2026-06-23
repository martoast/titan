# Titan iOS

Native iPhone app for Titan — Whoop-style always-on background BLE sync with the recovery band,
reusing the existing Laravel backend. **Plan + research:** `tasks/native-ios/`.

## Layout
- `TitanCore/` — a standalone SwiftPM package holding the **platform-agnostic, deterministic,
  unit-testable core**: HMAC signing, NUS frame decoding, windowing. No CoreBluetooth/SwiftUI
  here, so it builds and verifies on any Mac with the Swift toolchain — **no Xcode project,
  iPhone, or Apple Developer account needed.** The app target (CoreBluetooth restoration,
  SwiftUI, APNs) is added later in Xcode and depends on this package.

## Status
- ✅ **`Signer`** — ports the ingest HMAC exactly. **Verified byte-for-byte against the PHP
  backend** via golden vectors generated from `TerraClient::verifyDeviceSignature`
  (`Tests/TitanCoreTests/SignerTests.swift`). This was the highest-risk port; it's correct.
- ⏭️ Next: `FrameDecoder` (T1–T7), `Windowing`, then the CoreBluetooth `BandManager` (in Xcode).

## Verifying the signer
`swift test` requires **full Xcode** (XCTest). With only Command Line Tools, run the standalone
check (same logic, no XCTest):

```bash
# from repo root — regenerate golden vectors from the live backend any time:
docker exec fitness-ai-laravel.test-1 php -r '
$v=["secret"=>"0123…","t"=>1750000000,"body"=>"{\"ping\":true}"];
echo hash_hmac("sha256",$v["t"].".".$v["body"],hash("sha256",$v["secret"]));'
```

The golden vectors in `SignerTests.swift` were produced exactly this way; the Swift `Signer`
reproduces every `v1`. (A scratch `verify_signer.swift` confirmed all three pass with
`swift verify_signer.swift` under Command Line Tools.)

## The signing contract (don't break it)
```
key = sha256_hex(secret)                                    // hex digest of the hex secret
v1  = hmac_sha256("<t>.<rawBody>", key = keyHex_utf8_bytes) // lowercase hex
X-Device-Id: <deviceId>
X-Titan-Signature: t=<t>,v1=<v1>
```
Sign the **pre-gzip** body bytes (server gzdecodes, then verifies). ±300s replay window.
See `tasks/native-ios/05-protocol-port.md`.
