import Foundation
import CryptoKit

/// Request signing — byte-identical to Titan's server verifier and the web bridge.
///
///   X-Titan-Signature: t=<unix>,v1=HMAC_SHA256("<t>.<rawBody>", key)
///   key = sha256_hex(secret)        (= the server's stored device_token_hash)
///
/// The plaintext 32-byte secret is shown once at pairing and stored in the Keychain;
/// both sides derive sha256(secret) and HMAC with that hex string as the key.
enum Signer {
    static func sha256Hex(_ s: String) -> String {
        SHA256.hash(data: Data(s.utf8)).map { String(format: "%02x", $0) }.joined()
    }

    static func hmacHex(key: String, message: String) -> String {
        let mac = HMAC<SHA256>.authenticationCode(for: Data(message.utf8),
                                                  using: SymmetricKey(data: Data(key.utf8)))
        return Data(mac).map { String(format: "%02x", $0) }.joined()
    }

    /// Returns (deviceIdHeaderValue, signatureHeaderValue) for a raw JSON body.
    static func signatureHeader(secret: String, body: String, now: Date = Date()) -> String {
        let ts = String(Int(now.timeIntervalSince1970))
        let key = sha256Hex(secret)
        let v1 = hmacHex(key: key, message: "\(ts).\(body)")
        return "t=\(ts),v1=\(v1)"
    }
}

/// Crockford base32 ULID for batch_uid (matches Str::isUlid on the server: 26 chars,
/// 48-bit time + 80-bit randomness, uppercase).
enum ULID {
    private static let enc = Array("0123456789ABCDEFGHJKMNPQRSTVWXYZ")

    static func generate(now: Date = Date()) -> String {
        var ts = UInt64(now.timeIntervalSince1970 * 1000)
        var time = [Character](repeating: "0", count: 10)
        var i = 9
        while i >= 0 { time[i] = enc[Int(ts % 32)]; ts /= 32; i -= 1 }
        var rand = ""
        for _ in 0..<16 { rand.append(enc[Int.random(in: 0..<32)]) }
        return String(time) + rand
    }
}
