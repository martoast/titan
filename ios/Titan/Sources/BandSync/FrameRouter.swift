import Foundation
import TitanCore

/// Turns the raw NUS byte stream into signed, uploaded windows. Mirrors the bridge `_onBytes`
/// dispatch: reassemble newline-delimited base64 frames, decode `T1…T7` via `TitanCore`, feed the
/// `PpgWindowBuilder` (recovery PPG) AND the `WorkoutAssembler` (GPS/accel/HR workout windows) —
/// finished windows of either kind go to the `SyncQueue`. T5 also drives the live bpm display.
public final class FrameRouter {
    private var rx = Data()                       // newline accumulator (== bridge's this._rx)
    private let ppg = PpgWindowBuilder()
    private let wa = WorkoutAssembler()
    private let queue: SyncQueue
    private var maxDeviceT: UInt64 = 0
    public var onBpm: ((UInt8) -> Void)?          // live HR for the UI
    /// Live stream stats for the UI: (cumulative samples, recent PPG for the waveform, ~Hz).
    public var onSamples: ((Int, [Int16], Int) -> Void)?
    private var totalSamples = 0
    private var recentPpg: [Int16] = []
    private var recentTs: [UInt64] = []

    public init(queue: SyncQueue) { self.queue = queue }

    /// Feed a chunk of bytes from a CoreBluetooth notification.
    public func ingest(_ data: Data) {
        rx.append(data)
        while let nl = rx.firstIndex(of: 0x0A) {           // 0x0A == '\n'
            let line = rx.subdata(in: rx.startIndex..<nl)
            rx.removeSubrange(rx.startIndex...nl)
            handle(line)
        }
    }

    private func handle(_ line: Data) {
        guard line.count > 3, let s = String(data: line, encoding: .utf8) else { return }
        let payload = String(s.dropFirst(3))             // strip "Tn:"
        switch s.prefix(3) {
        case "T1:", "T2:":
            let frame = FrameDecoder.decodeT1(payload)
            // Live stats for the UI (waveform + counters).
            totalSamples += frame.samples.count
            for s in frame.samples { recentPpg.append(s.ppg); recentTs.append(s.t) }
            if recentPpg.count > 240 {
                recentPpg.removeFirst(recentPpg.count - 240)
                recentTs.removeFirst(recentTs.count - 240)
            }
            var hz = 0
            if recentTs.count > 1, let f = recentTs.first, let l = recentTs.last, l > f {
                hz = Int((Double(recentTs.count) * 1000 / Double(l - f)).rounded())
            }
            onSamples?(totalSamples, recentPpg, hz)
            for w in ppg.add(frame.samples) { submit(.ppg(w)) }
            wa.addAccel(frame.samples)                    // buffered only if a workout is open
            if let last = frame.samples.last?.t {
                maxDeviceT = max(maxDeviceT, last)
                if let w = wa.tick(maxDeviceT) { submit(.workout(w)) }   // close a finished workout
            }
        case "T4:":
            if let fix = FrameDecoder.decodeT4(payload), let w = wa.addGps(fix) { submit(.workout(w)) }
        case "T5:":
            if let hr = FrameDecoder.decodeT5(payload) { onBpm?(hr.bpm); wa.addHr(hr) }
        case "T6:":
            let acc = FrameDecoder.decodeT6(payload)
            if let w = wa.addWorkoutAccel(acc) { submit(.workout(w)) }
        case "T7:":
            break  // ambient baro (floors) — server-side; not on the live upload path yet
        case "T8:":
            // Step total → a daily-activity summary (server merges with the phone's count, per-day MAX).
            if let s = FrameDecoder.decodeT8(payload) {
                submit(.steps(StepDailySummary(date: s.date, steps: Int(s.steps))))
            }
        default:
            break
        }
    }

    /// On disconnect / app suspend: flush trailing partial windows so nothing is lost.
    public func flush(live: Bool) {
        if let w = ppg.flush(live: live) { submit(.ppg(w)) }
        if let w = wa.flush() { submit(.workout(w)) }
    }

    private func submit(_ w: AnyWindow) { Task { await queue.submit(w) } }
}

/// Either window kind the queue can ship, plus a daily step summary. Encodes to the underlying
/// JSON (each already carries its own `kind` field), and round-trips through the persisted queue.
/// `.steps` ships in the batch's `summaries[]`; the windows ship in `windows[]` (see IngestClient).
public enum AnyWindow: Codable {
    case ppg(PpgWindow), workout(WorkoutWindow), steps(StepDailySummary)

    public func encode(to encoder: Encoder) throws {
        var c = encoder.singleValueContainer()
        switch self {
        case .ppg(let w): try c.encode(w)
        case .workout(let w): try c.encode(w)
        case .steps(let s): try c.encode(s)
        }
    }
    public init(from decoder: Decoder) throws {
        let c = try decoder.singleValueContainer()
        if let s = try? c.decode(StepDailySummary.self), s.kind == "activity" { self = .steps(s) }
        else if let w = try? c.decode(WorkoutWindow.self), w.kind == "workout" { self = .workout(w) }
        else { self = .ppg(try c.decode(PpgWindow.self)) }
    }
}
