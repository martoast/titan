import Foundation

/// A raw-PPG window in Titan's ingest shape (kind=ppg_raw). Encodes straight to the
/// JSON the server expects inside a batch.
struct PPGWindow: Codable {
    let kind: String
    let start: String
    let end: String
    let sample_rate_hz: Int
    let ppg: [Int]
    let src: String

    init(start: String, end: String, sampleRateHz: Int, ppg: [Int]) {
        self.kind = "ppg_raw"
        self.start = start
        self.end = end
        self.sample_rate_hz = sampleRateHz
        self.ppg = ppg
        self.src = "banglejs2-ios"
    }
}

struct Batch: Codable {
    let batch_uid: String
    let windows: [PPGWindow]
}

/// Accumulates samples and emits a `PPGWindow` every `windowMs` of wall-clock signal.
/// 120 s windows clear the server's 60-beat HRV validity gate even at a low resting HR.
final class WindowAssembler {
    private let windowMs: UInt64
    private var buf: [PPGSample] = []

    init(windowMs: UInt64 = 120_000) { self.windowMs = windowMs }

    private static let iso: ISO8601DateFormatter = {
        let f = ISO8601DateFormatter()
        f.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
        return f
    }()

    /// Add samples; returns a window if the buffer now spans >= windowMs, else nil.
    func add(_ samples: [PPGSample]) -> PPGWindow? {
        buf.append(contentsOf: samples)
        guard let first = buf.first, let last = buf.last else { return nil }
        guard last.t - first.t >= windowMs else { return nil }
        return flush()
    }

    /// Force-emit the current buffer (e.g. on disconnect). nil if too short to be useful.
    func flush() -> PPGWindow? {
        guard let first = buf.first, let last = buf.last, buf.count >= 200 else {
            return nil
        }
        let durSec = max(1.0, Double(last.t - first.t) / 1000.0)
        let rate = max(1, Int((Double(buf.count) / durSec).rounded()))
        let win = PPGWindow(
            start: Self.iso.string(from: Date(timeIntervalSince1970: Double(first.t) / 1000.0)),
            end: Self.iso.string(from: Date(timeIntervalSince1970: Double(last.t) / 1000.0)),
            sampleRateHz: rate,
            ppg: buf.map { Int($0.ppg) }
        )
        buf.removeAll(keepingCapacity: true)
        return win
    }
}
