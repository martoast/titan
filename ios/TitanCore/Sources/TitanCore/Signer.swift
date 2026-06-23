import Foundation
import CryptoKit

/// Signs an ingest batch exactly the way the web bridge (`bridge.blade.php` `_sign()`) and the
/// server verifier (`TerraClient::verifyDeviceSignature`) expect. This is the single
/// load-bearing port in the whole app — if one byte is off, every upload is rejected — so it is
/// covered by golden vectors generated from the real PHP backend (see `SignerTests`).
///
/// Contract (must match the server):
///   key = sha256_hex(secret)                       // hex digest of the 32-byte hex secret
///   v1  = hmac_sha256("<t>.<rawBody>", key=keyHexAsUTF8Bytes)   // lowercase hex
///   headers: X-Device-Id: <deviceId>,  X-Titan-Signature: t=<t>,v1=<v1>
public enum Signer {

    /// The two header values to attach to an ingest POST.
    public struct Signature: Equatable {
        public let deviceId: String        // → X-Device-Id
        public let titanSignature: String  // → X-Titan-Signature  ("t=<t>,v1=<v1>")
        public let v1: String              // the raw HMAC hex (handy for tests/logging)
    }

    /// Compute the signature for `body` at unix time `t`.
    /// - Parameters:
    ///   - body: the EXACT bytes that will be sent as the request body, signed pre-gzip
    ///           (the server gzdecodes, then verifies the decoded bytes).
    ///   - deviceId: the paired device id (`titan_band_…`).
    ///   - secret: the 32-byte hex secret shown once at pairing (NOT its hash — we hash here).
    ///   - t: unix seconds; must be within ±300s of server time at send.
    public static func sign(body: Data, deviceId: String, secret: String, t: Int) -> Signature {
        // key = sha256_hex(secret), then use that hex STRING's UTF-8 bytes as the HMAC key.
        let keyHex = SHA256.hash(data: Data(secret.utf8))
            .map { String(format: "%02x", $0) }
            .joined()
        let key = SymmetricKey(data: Data(keyHex.utf8))

        // signed message = "<t>." + rawBody (concatenated bytes, not re-encoded).
        var message = Data("\(t).".utf8)
        message.append(body)

        let mac = HMAC<SHA256>.authenticationCode(for: message, using: key)
        let v1 = mac.map { String(format: "%02x", $0) }.joined()

        return Signature(deviceId: deviceId,
                         titanSignature: "t=\(t),v1=\(v1)",
                         v1: v1)
    }

    /// Convenience for a `String` body (UTF-8).
    public static func sign(bodyString: String, deviceId: String, secret: String, t: Int) -> Signature {
        sign(body: Data(bodyString.utf8), deviceId: deviceId, secret: secret, t: t)
    }
}
