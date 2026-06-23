# 05 — Protocol port: NUS frames + HMAC ingest (JS bridge → Swift)

The iOS `TitanBLE` + `Signer` + `IngestClient` replace the web bridge. This is a **deterministic
port** of proven, working code — the source of truth is `resources/views/devices/bridge.blade.php`
and `resources/js/bridge-decode.js`. Port logic exactly; verify with golden vectors.

## 1. NUS transport (recap from 01)
Subscribe to TX `6E400003-…` (notify). The firmware sends **newline-delimited base64 lines**,
each prefixed by a frame tag (`T1:`…`T7:`). One BLE notification is NOT one frame — **buffer
bytes, split on `\n`**, then dispatch by prefix. (Mirror the bridge's `this._rx` accumulator.)

## 2. Frame types (decode rules — port from bridge-decode.js + bridge inline decoders)
All multi-byte fields are **little-endian**. After stripping `Tn:`, base64-decode the payload to
bytes, then:

- **T1 — live raw PPG+accel.** 16-byte header `[u8 ver, u8 ppgFieldCode, u16 count, u32 epochLo,
  u32 epochHi, u16 rsvd, u16 rsvd]` then `count` × 12-byte samples
  `[u32 relT_ms, i16 ppg, i16 ax, i16 ay, i16 az]` (accel in milli-g). Absolute sample time =
  `epoch(ms) + relT`. → push to the 120s ppg window + feed the workout assembler.
- **T2 — overnight compact (the morning-sync burst).** 20-byte header `[u8 ver, u8 rsvd, u16
  count, u32 startLo, u32 startHi, u32 durMs, u32 activity]` then `count` × `i16 ppg`. No
  per-sample timestamps — spread `count` samples evenly over `[start, start+durMs]`. `activity`
  = per-frame actigraphy sum.
- **T4 — GPS fix.** decode lat/lon/etc. (see `decodeT4`) → feed workout assembler.
- **T5 — heart rate.** 12 bytes `[u8 ver, u8 bpm, u8 conf, u8 rsvd, u32 tsLo, u32 tsHi]` → live
  bpm display + HR series.
- **T6 — offline workout accel.** 16-byte header + `count` × 3×`i16` (milli-g). Workout path.
- **T7 — barometer/altitude.** one per minute; altitude series.

Port `WorkoutAssembler` (the GPS-gated session builder) **verbatim** — it's pure logic that
emits a `workout` window when a session's GPS frames stop.

## 3. Windowing → ingest body
- Accumulate decoded PPG samples; when the span ≥ `WINDOW_MS` (120000) or on
  disconnect/gap, emit a window. ppg_raw window:
  `{ kind:"ppg_raw", start, end, sample_rate_hz, ppg:[i16…], accel_mag_cg:[…], src:"bangle" }`.
- Workout windows come from the assembler: `{ kind:"workout", accel_xyz, hr_bpm, gps… }`.
- Body: `{ batch_uid:<ULID>, windows:[…], device:{battery,firmware} }`. Generate a **ULID**
  (port the bridge's ULID gen). gzip if large. POST to `/api/devices/ingest`.

## 4. HMAC signing — `Signer.swift` (CryptoKit) — THE load-bearing port
Must byte-match `TerraClient::verifyDeviceSignature` (`TerraClient.php:111-136`) and the bridge
`_sign()` (`bridge.blade.php:489-496`):

```
t        = unix seconds (string)
keyHex   = sha256_hex(secret)          // secret = the 32-byte hex shown once at pairing
v1       = hmac_sha256(  "<t>.<rawBody>",  key = keyHex_as_utf8_bytes )  // lowercase hex
header   = "X-Titan-Signature: t=<t>,v1=<v1>"   +   "X-Device-Id: <device_id>"
```

CryptoKit:
```swift
import CryptoKit
enum Signer {
    static func sign(body: Data, deviceId: String, secret: String, t: Int) -> (String, String) {
        let keyHex = SHA256.hash(data: Data(secret.utf8))
            .map { String(format: "%02x", $0) }.joined()        // sha256_hex(secret)
        let key = SymmetricKey(data: Data(keyHex.utf8))         // KEY is the hex STRING's bytes
        var signed = Data("\(t).".utf8); signed.append(body)    // "<t>.<rawBody>"
        let mac = HMAC<SHA256>.authenticationCode(for: signed, using: key)
        let v1 = mac.map { String(format: "%02x", $0) }.joined()
        return ("\(deviceId)", "t=\(t),v1=\(v1)")
    }
}
```
⚠️ Two easy mistakes to avoid: (1) the HMAC key is `sha256_hex(secret)` as an **ASCII/utf8 hex
string**, not the raw 32 sha256 bytes and not the raw secret; (2) sign the **raw body bytes**
(pre-gzip if you gzip — sign what you SEND; check the bridge: it signs the JSON string, then the
server gzdecodes before verifying, so **sign the uncompressed JSON and set Content-Encoding only
if the server verifies post-decode** — confirm against `DeviceIngestionController.php:275` order
during Phase 2).

## 5. Golden-vector test (do this first, before any device work)
Generate a known-good vector from the running backend / web bridge:
- Pick `secret`, `t`, `body` (a tiny JSON). Compute `v1` with PHP
  (`hash_hmac('sha256', "$t.$body", hash('sha256',$secret))`) — produce 2–3 vectors.
- Commit them as `SignerTests` fixtures; assert `Signer.sign` reproduces each `v1` exactly.
- This guarantees the single most failure-prone piece is correct before touching CoreBluetooth.

## 6. Reconciliation
After uploads, call `GET /api/devices/ingestions?since=<cursor>` to learn which `batch_uid`s the
server accepted, and drop them from `SyncQueue`. Treat `202` + `{duplicate:true}` as success
(idempotent). Honor `429 Retry-After`.

## Source of truth (port from these)
- `resources/views/devices/bridge.blade.php` — `_onBytes`/`_decodeFrame`/`_decodeT2`/`_ship`/
  `_sign`/`_drainWindows`/ULID/WorkoutAssembler glue.
- `resources/js/bridge-decode.js` — `decodeT4/T5/T6`, `WorkoutAssembler`.
- `app/Services/Wearables/TerraClient.php:111-136` — server-side signature verification (match it).
- `app/Http/Controllers/Api/DeviceIngestionController.php` — headers, ULID, gzip, limits.
