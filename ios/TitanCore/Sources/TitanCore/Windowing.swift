import Foundation

/// Crockford-base32 ULID for `batch_uid` — a faithful port of the bridge `_ulid()`:
/// 10 chars of 48-bit ms time + 16 chars of random, 26 chars total. The server requires a valid
/// ULID per batch. Time + random are injectable so it's deterministic in tests.
public enum ULID {
    static let ENC = Array("0123456789ABCDEFGHJKMNPQRSTVWXYZ")

    public static func timePart(ms: UInt64) -> String {
        var ts = ms
        var chars = [Character](repeating: "0", count: 10)
        for i in stride(from: 9, through: 0, by: -1) {
            chars[i] = ENC[Int(ts % 32)]
            ts /= 32
        }
        return String(chars)
    }

    public static func randomPart(bytes: [UInt8]) -> String {
        precondition(bytes.count == 16, "ULID random needs 16 bytes")
        return String(bytes.map { ENC[Int($0) % 32] })
    }

    /// Deterministic build (tests / golden vectors).
    public static func make(ms: UInt64, randomBytes: [UInt8]) -> String {
        timePart(ms: ms) + randomPart(bytes: randomBytes)
    }

    /// Live build using the current time and the system CSPRNG.
    public static func generate(now: Date = Date()) -> String {
        let ms = UInt64(now.timeIntervalSince1970 * 1000)
        var bytes = [UInt8](repeating: 0, count: 16)
        for i in bytes.indices { bytes[i] = UInt8.random(in: 0...255) }
        return make(ms: ms, randomBytes: bytes)
    }
}

/// Accel magnitude in centi-g, matching the bridge's per-sample `mag`:
/// `round(sqrt(ax² + ay² + az²) / 10)` where ax/ay/az are milli-g.
public func accelMagCentiG(ax: Int16, ay: Int16, az: Int16) -> Int {
    let x = Double(ax), y = Double(ay), z = Double(az)
    return Int((Foundation.sqrt(x * x + y * y + z * z) / 10).rounded())
}

/// A `ppg_raw` ingest window — the exact JSON shape the server expects
/// (`DeviceIngestionService` Shape A). Encodes to the same keys the bridge sends.
public struct PpgWindow: Codable, Equatable {
    public let kind: String          // "ppg_raw"
    public let start: String         // ISO-8601
    public let end: String
    public let sample_rate_hz: Int
    public let ppg: [Int16]
    public let accel_mag_cg: [Int]
    public let src: String           // "banglejs2"
}

/// A `kind=activity` daily summary — the band's step total for a day, sent in the ingest batch's
/// `summaries[]` (NOT `windows[]`). The server's `DeviceIngestionService::writeSummary` 'activity'
/// arm upserts it into `DailyActivity` via the per-day MAX merge, so it coexists with phone steps.
public struct StepDailySummary: Codable, Equatable {
    public let kind: String          // "activity"
    public let date: String          // "YYYY-MM-DD" (the watch's local day)
    public let steps: Int
    public init(date: String, steps: Int) { self.kind = "activity"; self.date = date; self.steps = steps }
}

/// A `kind=sleep_session` marker — the band's "I'm awake" confirmation. Sent in `summaries[]`; the
/// server seals that night and fires the coach's morning sleep summary (only because it's confirmed).
public struct SleepSessionSummary: Codable, Equatable {
    public let kind: String          // "sleep_session"
    public let confirmed: Bool
    public let bedtime: Int          // epoch seconds
    public let wake: Int
    public init(bedtime: Int, wake: Int, confirmed: Bool) {
        self.kind = "sleep_session"; self.confirmed = confirmed; self.bedtime = bedtime; self.wake = wake
    }
}

/// Accumulates decoded PPG samples and emits 120s `ppg_raw` windows — ports the bridge's
/// `_drainWindows` / `_flushWindow` / `_shipSamples`. Pure: the caller does the actual POST.
public final class PpgWindowBuilder {
    public static let WINDOW_MS: UInt64 = 120_000
    private var samples: [PpgSample] = []

    private static let iso: ISO8601DateFormatter = {
        let f = ISO8601DateFormatter()
        f.formatOptions = [.withInternetDateTime]
        return f
    }()

    public init() {}

    /// Feed decoded T1 samples. Returns any COMPLETE 120s windows ready to ship.
    public func add(_ newSamples: [PpgSample]) -> [PpgWindow] {
        samples.append(contentsOf: newSamples)
        var out: [PpgWindow] = []
        while samples.count > 1 {
            let t0 = samples[0].t
            guard samples[samples.count - 1].t - t0 >= Self.WINDOW_MS else { break }
            let cut = t0 + Self.WINDOW_MS
            var i = 0
            while i < samples.count && samples[i].t < cut { i += 1 }
            let chunk = Array(samples[0..<i])
            samples.removeFirst(i)
            if let w = Self.build(chunk) { out.append(w) }
        }
        return out
    }

    /// Flush the trailing partial window (on disconnect / settle). `live` keeps short windows
    /// collecting (matches the bridge: skip <30s while still connected).
    public func flush(live: Bool) -> PpgWindow? {
        guard samples.count >= 1 else { return nil }
        let span = samples[samples.count - 1].t - samples[0].t
        if span < 30_000 && live { return nil }
        let chunk = samples; samples = []
        return Self.build(chunk)
    }

    /// Build a ppg_raw window from a sample slice (≥30 samples), matching `_shipSamples`.
    static func build(_ s: [PpgSample]) -> PpgWindow? {
        guard s.count >= 30 else { return nil }
        let startMs = s[0].t, endMs = s[s.count - 1].t
        let durSec = max(1.0, Double(endMs - startMs) / 1000)
        let rate = max(1, Int((Double(s.count) / durSec).rounded()))
        return PpgWindow(
            kind: "ppg_raw",
            start: iso.string(from: Date(timeIntervalSince1970: Double(startMs) / 1000)),
            end: iso.string(from: Date(timeIntervalSince1970: Double(endMs) / 1000)),
            sample_rate_hz: rate,
            ppg: s.map { $0.ppg },
            accel_mag_cg: s.map { accelMagCentiG(ax: $0.ax, ay: $0.ay, az: $0.az) },
            src: "banglejs2"
        )
    }
}
