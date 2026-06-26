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

/// One point on the 24/7 HR trend — a per-minute aggregate (median bpm). `t` is epoch SECONDS.
public struct HrTrendPoint: Codable, Equatable {
    public let t: Int
    public let bpm: Int
    public let conf: Int
    public init(t: Int, bpm: Int, conf: Int) { self.t = t; self.bpm = bpm; self.conf = conf }
}

/// A `kind=hr_trend` summary — a batch of per-minute HR points for the all-day graph. Sent in
/// `summaries[]`; the server's `writeHrTrend` inserts each point into `hr_samples` (deduped).
public struct HrTrendWindow: Codable, Equatable {
    public let kind: String          // "hr_trend"
    public let samples: [HrTrendPoint]
    public init(samples: [HrTrendPoint]) { self.kind = "hr_trend"; self.samples = samples }
}

/// Aggregates the band's HR readings (T5) into ONE point per wall-clock minute (median bpm, max
/// confidence) so the cloud series stays light whatever the source rate — ~1 Hz live when connected
/// and 1/min from the offline duty-cycle both collapse to 1/min. Emits an `hr_trend` window once
/// enough minutes have closed; `flush()` ships the tail on disconnect/suspend.
public final class HrTrendBuilder {
    private static let FLUSH_AT = 30          // upload after ~30 closed minutes (or on flush)

    private var bucketMin: UInt64 = 0         // current minute bucket (epoch minutes), 0 = none yet
    private var bpms: [Int] = []
    private var confMax = 0
    private var pending: [HrTrendPoint] = []  // closed buckets awaiting upload

    public init() {}

    /// Feed one T5 reading (`t` in ms). Returns a window once enough minutes have accumulated.
    public func add(t: UInt64, bpm: UInt8, conf: UInt8) -> HrTrendWindow? {
        let minute = t / 60_000
        // Monotonic guard: a reading that regresses into an already-passed minute (out-of-order
        // offline duty-cycle reads arriving after a reconnect flush) would reopen a closed bucket and
        // fragment that minute's median into stray single-sample points. bucketMin only advances.
        if bucketMin != 0, minute < bucketMin { return pending.count >= Self.FLUSH_AT ? drain() : nil }
        if bucketMin == 0 { bucketMin = minute }
        if minute != bucketMin { closeBucket(); bucketMin = minute }
        if bpm > 0 { bpms.append(Int(bpm)); confMax = max(confMax, Int(conf)) }
        return pending.count >= Self.FLUSH_AT ? drain() : nil
    }

    public func flush() -> HrTrendWindow? { closeBucket(); return drain() }

    private func closeBucket() {
        guard !bpms.isEmpty else { return }
        let sorted = bpms.sorted()
        pending.append(HrTrendPoint(t: Int(bucketMin * 60), bpm: sorted[sorted.count / 2], conf: confMax))
        bpms = []; confMax = 0
    }

    private func drain() -> HrTrendWindow? {
        guard !pending.isEmpty else { return nil }
        let w = HrTrendWindow(samples: pending)
        pending = []
        return w
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
            let last = samples[samples.count - 1].t
            // Underflow-safe: a non-monotonic buffer (e.g. older buffered data interleaved with live)
            // must never do `last - t0` when last < t0 — UInt64 wraps and TRAPS. Wait for more instead.
            guard last >= t0, last - t0 >= Self.WINDOW_MS else { break }
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
        let last = samples[samples.count - 1].t, first = samples[0].t
        let span = last >= first ? last - first : 0          // underflow-safe (see add)
        if span < 30_000 && live { return nil }
        let chunk = samples; samples = []
        return Self.build(chunk)
    }

    /// Build a ppg_raw window from a sample slice (≥30 samples), matching `_shipSamples`.
    static func build(_ s: [PpgSample]) -> PpgWindow? {
        guard s.count >= 30 else { return nil }
        let startMs = s[0].t, endMs = s[s.count - 1].t
        let durSec = max(1.0, Double(endMs >= startMs ? endMs - startMs : 0) / 1000)
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
