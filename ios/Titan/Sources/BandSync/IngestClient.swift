import CryptoKit
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

    /// Wrap one item in a batch, sign, gzip if worthwhile, and POST. A raw window (ppg_raw/workout)
    /// goes in `windows[]`; a daily step summary goes in `summaries[]` (the server routes by array,
    /// not by inspecting kind inside windows).
    public func ship(window: AnyWindow) async -> Result {
        let isSummary: Bool
        switch window { case .steps, .sleep, .hrTrend: isSummary = true; default: isSummary = false }
        // batch_uid must be STABLE across retries — the server dedups on it. Deriving it from the
        // window's content (not a fresh ULID per call) means a retry after a lost response reuses the
        // same uid and the server returns `duplicate` instead of double-ingesting the samples.
        guard let windowData = try? JSONEncoder().encode(window) else {
            return .rejected(status: 0, error: "encode failed")
        }
        let uid = Self.stableUID(for: windowData)
        let batch = isSummary
            ? Batch(batch_uid: uid, windows: [], summaries: [window])
            : Batch(batch_uid: uid, windows: [window], summaries: [])
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

        // Send the signed, uncompressed JSON. A ppg_raw window is well under the server's 5 MB
        // limit, so we don't gzip — and crucially the bytes on the wire match what we signed.
        // (gzip would need RFC-1952 framing via zlib windowBits=31 to satisfy the server's
        // gzdecode; left out deliberately rather than risk a header mismatch rejecting uploads.)
        req.httpBody = body

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

    struct Batch: Encodable { let batch_uid: String; let windows: [AnyWindow]; let summaries: [AnyWindow] }

    /// A deterministic 32-hex-char id for a window's bytes (SHA-256 prefix) — identical content always
    /// yields the same uid, which is exactly what makes server-side dedup work across retries.
    static func stableUID(for data: Data) -> String {
        SHA256.hash(data: data).prefix(16).map { String(format: "%02x", $0) }.joined()
    }
}
