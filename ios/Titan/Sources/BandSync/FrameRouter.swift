import Foundation
import TitanCore

/// Turns the raw NUS byte stream into signed, uploaded windows. Mirrors the bridge `_onBytes`
/// dispatch: reassemble newline-delimited base64 frames, decode `T1…T7` via `TitanCore`, feed the
/// `PpgWindowBuilder` (live recovery PPG) — finished windows go to the `SyncQueue`. T5 drives the
/// live bpm display; T2 (overnight burst) is handled the same as T1 for ppg.
public final class FrameRouter {
    private var rx = Data()                       // newline accumulator (== bridge's this._rx)
    private let ppg = PpgWindowBuilder()
    private let queue: SyncQueue
    public var onBpm: ((UInt8) -> Void)?          // live HR for the UI

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
            for w in ppg.add(frame.samples) { Task { await queue.submit(w) } }
        case "T5:":
            if let hr = FrameDecoder.decodeT5(payload) { onBpm?(hr.bpm) }
        case "T4:", "T6:", "T7:":
            break  // workout/baro → WorkoutAssembler port (next); not on the recovery path
        default:
            break
        }
    }

    /// On disconnect / app suspend: flush the trailing partial window so nothing is lost.
    public func flush(live: Bool) {
        if let w = ppg.flush(live: live) { Task { await queue.submit(w) } }
    }
}
