import Foundation
import TitanCore

/// Signs and POSTs one ingest batch to `/api/devices/ingest`, exactly like the web bridge
/// `_ship()` — using the verified `TitanCore.Signer`. Sign the PRE-gzip body; the server
/// gzdecodes then verifies. See tasks/native-ios/05-protocol-port.md.
public struct IngestClient {
    public let baseURL: URL            // e.g. https://titan.fullstacklabs.org
    public let deviceId: String
    public let secret: String          // 32-byte hex, from Keychain (captured at pairing)
    public let session: URLSession

    public init(baseURL: URL, deviceId: String, secret: String, session: URLSession = .shared) {
        self.baseURL = baseURL; self.deviceId = deviceId; self.secret = secret; self.session = session
    }

    public enum Result { case accepted(queued: Int), duplicate, rejected(status: Int, error: String), transport(Error) }

    /// Wrap one window in a batch, sign, gzip if worthwhile, and POST.
    public func ship(window: PpgWindow) async -> Result {
        let batch = Batch(batch_uid: ULID.generate(), windows: [window])
        guard let body = try? JSONEncoder().encode(batch) else {
            return .rejected(status: 0, error: "encode failed")
        }
        let t = Int(Date().timeIntervalSince1970)
        let sig = Signer.sign(body: body, deviceId: deviceId, secret: secret, t: t)

        var req = URLRequest(url: baseURL.appendingPathComponent("/api/devices/ingest"))
        req.httpMethod = "POST"
        req.setValue("application/json", forHTTPHeaderField: "Content-Type")
        req.setValue(sig.deviceId, forHTTPHeaderField: "X-Device-Id")
        req.setValue(sig.titanSignature, forHTTPHeaderField: "X-Titan-Signature")

        // gzip large bodies (server honors Content-Encoding: gzip and gzdecodes before verifying,
        // so we still sign the *uncompressed* bytes above).
        if body.count > 4096, let gz = Gzip.compress(body) {
            req.setValue("gzip", forHTTPHeaderField: "Content-Encoding")
            req.httpBody = gz
        } else {
            req.httpBody = body
        }

        do {
            let (data, resp) = try await session.data(for: req)
            let status = (resp as? HTTPURLResponse)?.statusCode ?? 0
            let json = (try? JSONSerialization.jsonObject(with: data)) as? [String: Any] ?? [:]
            if (200...299).contains(status) {
                if json["duplicate"] as? Bool == true { return .duplicate }
                return .accepted(queued: json["windows_queued"] as? Int ?? 0)
            }
            return .rejected(status: status, error: json["error"] as? String ?? "http \(status)")
        } catch {
            return .transport(error)
        }
    }

    struct Batch: Encodable { let batch_uid: String; let windows: [PpgWindow] }
}

/// Minimal gzip via zlib (Compression framework alternative). Placeholder — wire to
/// `Compression`/`libz` in Xcode; the signing is independent of compression.
enum Gzip {
    static func compress(_ data: Data) -> Data? { nil /* TODO: zlib in app target */ }
}
