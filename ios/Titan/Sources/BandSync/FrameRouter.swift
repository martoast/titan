import Foundation
import TitanCore

/// Turns the raw NUS byte stream into signed, uploaded windows. Mirrors the bridge `_onBytes`
/// dispatch: reassemble newline-delimited base64 frames, decode `T1…T7` via `TitanCore`, feed the
/// `PpgWindowBuilder` (recovery PPG) AND the `WorkoutAssembler` (GPS/accel/HR workout windows) —
/// finished windows of either kind go to the `SyncQueue`. T5 also drives the live bpm display.
public final class FrameRouter {
    private static let maxLineBytes = 64 * 1024   // a frame should never exceed this before a newline
    private var rx = Data()                       // newline accumulator (== bridge's this._rx)
    private let ppg = PpgWindowBuilder()           // live T1 PPG
    private let ppgLog = PpgWindowBuilder()        // flushed T2 PPG — SEPARATE so old buffered timestamps
                                                   // never interleave with live T1 (would be non-monotonic)
    private let wa = WorkoutAssembler()            // the LIVE workout (current-timestamp frames)
    private let waLog: WorkoutAssembler = {        // replayed offline-ring frames (old timestamps) —
        let a = WorkoutAssembler()                 // SEPARATE for the same reason ppgLog is: a backlog
        a.alwaysEnded = true                       // T4/T5/T6 flushed mid-run must never splice old
        return a                                   // coords/HR into the live session's sealed window.
    }()                                            // alwaysEnded → a recovered phone-free workout seals
                                                   // immediately (and can show a catch-up summary).
    private let hrTrend = HrTrendBuilder()         // 24/7 HR graph — per-minute points from every T5
    private let queue: SyncQueue
    private var maxDeviceT: UInt64 = 0
    public var onBpm: ((UInt8) -> Void)?          // live HR for the UI
    /// Live stream stats for the UI: (cumulative samples, recent PPG for the waveform, ~Hz).
    public var onSamples: ((Int, [Int16], Int) -> Void)?
    /// Live GPS fix (drives the in-app live-run distance/pace + route trace).
    public var onGps: ((GpsFix) -> Void)?
    /// Every HR reading with its sport tag (sport==1 ⇒ a run/workout is live).
    public var onHr: ((HrReading) -> Void)?
    /// Live day-step total from the band (T8) — for the in-app "steps today" readout.
    public var onSteps: ((StepDailySummary) -> Void)?
    /// The user's explicit workout choice from the band's `TA:` frame: "run" (running tab) vs
    /// "strength" (heart-rate tab / lifting). Drives the app's run-vs-lift UX + the sealed summary.
    public var onActivityKind: ((String) -> Void)?
    /// The band says the workout is OVER (`TA:{"k":"end"}` — user finished on the watch). Deterministic
    /// end signal so the app closes + seals immediately instead of inferring it from the sport tag.
    public var onWorkoutEnd: (() -> Void)?
    /// The band finished draining its offline ring (`TS:`). A workout recovered from that backlog has
    /// just been sealed → the app checks for a catch-up summary to surface.
    public var onBacklogSynced: (() -> Void)?
    private var totalSamples = 0
    private var recentPpg: [Int16] = []
    private var recentTs: [UInt64] = []
    private var lastSamplesEmit = Date.distantPast   // throttles the live-stats UI feed to ≤10 Hz
    private var lastStepsKey = ""               // dedupe identical step summaries (don't upload while still)

    public init(queue: SyncQueue) { self.queue = queue }

    /// Inject a phone GPS fix into the SAME workout assembler the band's T4 frames would feed, so a run
    /// tracked by the iPhone (the band has no GPS) seals with a real route + GPS distance — the existing
    /// server route pass needs no change. Only call while a workout/run is open; addGps would otherwise
    /// open a phantom workout.
    public func ingestPhoneGps(_ fix: GpsFix) {
        if let w = wa.addGps(fix) { submit(.workout(w)) }
    }

    /// Inject a chest-strap HR reading into the SAME workout assembler the band's HR feeds, so a
    /// workout recorded with a strap seals with reference-grade HR (tagged `chest_strap`) instead of
    /// motion-corrupted wrist PPG. Only supplements an open workout — never opens one.
    public func ingestStrapHr(bpm: UInt8, rr: [Double] = [], t: UInt64) {
        if let w = wa.addStrapHr(bpm: bpm, rr: rr, t: t) { submit(.workout(w)) }
    }

    /// Feed a chunk of bytes from a CoreBluetooth notification.
    public func ingest(_ data: Data) {
        rx.append(data)
        while let nl = rx.firstIndex(of: 0x0A) {           // 0x0A == '\n'
            let line = rx.subdata(in: rx.startIndex..<nl)
            rx.removeSubrange(rx.startIndex...nl)
            handle(line)
        }
        // A frame with no terminating newline must not grow the accumulator without bound.
        if rx.count > Self.maxLineBytes { rx.removeAll(keepingCapacity: false) }
    }

    private func handle(_ line: Data) {
        guard line.count > 3, let s = String(data: line, encoding: .utf8) else { return }
        let payload = String(s.dropFirst(3))             // strip "Tn:"
        switch s.prefix(3) {
        case "T1:":
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
            // Throttle the live UI feed to ≤10 Hz. The band streams T1 continuously while connected, and
            // republishing the waveform/stats through the app-wide AppModel on EVERY frame (on the main
            // thread) invalidates the whole SwiftUI tree + spawns a Task each time — the "High energy +
            // steadily climbing memory" seen while just sitting connected. 10 Hz is smooth for a live trace.
            let now = Date()
            if now.timeIntervalSince(lastSamplesEmit) >= 0.1 {
                lastSamplesEmit = now
                onSamples?(totalSamples, recentPpg, hz)
            }
            for w in ppg.add(frame.samples) { submit(.ppg(w)) }
            wa.addAccel(frame.samples)                    // buffered only if a workout is open
            if let last = frame.samples.last?.t {
                maxDeviceT = max(maxDeviceT, last)
                if let w = wa.tick(maxDeviceT) { submit(.workout(w)) }   // close a finished workout
                if let w = waLog.tick(maxDeviceT) { submit(.workout(w)) } // …and a drained offline one
            }
        case "T2:":
            // Compact offline/overnight PPG, flushed on reconnect. Its own decoder (PPG-only, 2-B
            // stride) + its own window builder — keeps this older buffered data off the live path so
            // it can't interleave with current T1 timestamps and underflow the window math (the crash
            // seen after a long offline sleep, when a big T2 backlog flushed on reopen).
            let frame = FrameDecoder.decodeT2(payload)
            totalSamples += frame.samples.count
            for w in ppgLog.add(frame.samples) { submit(.ppg(w)) }
        case "T4:":
            if let fix = FrameDecoder.decodeT4(payload) {
                if Self.frameIsLive(fix.t) {
                    onGps?(fix)                    // only a CURRENT fix may drive the live map/distance
                    if let w = wa.addGps(fix) { submit(.workout(w)) }
                } else {
                    // Replayed from the offline ring (a phone-free outdoor workout) → its own assembler,
                    // so yesterday's coords can't teleport into a live run's route or distance.
                    if let w = waLog.addGps(fix) { submit(.workout(w)) }
                }
            }
        case "T5:":
            if let hr = FrameDecoder.decodeT5(payload) {
                // The LIVE bpm readout must reflect only the CURRENT reading. On reconnect/sync the band
                // replays its offline ring as T5 lines carrying OLD bpm values; feeding those to the live
                // display made it jitter (live 100 vs replayed 125/127). Show only live frames. The window
                // builder + 24/7 trend below still ingest the backlog (that's how offline data is sealed).
                if Self.frameIsLive(hr.t) { onBpm?(hr.bpm) }
                onHr?(hr)   // ingestLiveHr applies the same liveness gate for the run/lift state machine
                // A sport-tagged reading OPENS/extends a workout — this is how a connected indoor
                // session (no GPS, no T6) becomes a sealable workout window. Live frames feed the live
                // assembler; replayed backlog (old timestamps) feeds its own, so a ring flush mid-run
                // can't merge an old offline session into the one currently streaming.
                if Self.frameIsLive(hr.t) {
                    if let w = wa.addWorkoutHr(hr) { submit(.workout(w)) }
                } else {
                    if let w = waLog.addWorkoutHr(hr) { submit(.workout(w)) }
                }
                // ...and the 24/7 trend, which keeps EVERY reading (rest or active) for the all-day graph.
                if let w = hrTrend.add(t: hr.t, bpm: hr.bpm, conf: hr.conf) { submit(.hrTrend(w)) }
            }
        case "T6:":
            // T6 is only ever LOGGED offline and flushed later, so it's backlog by construction —
            // route by timestamp anyway so a just-logged tail (an offline workout that reconnected
            // seconds ago) still joins the live session it belongs to.
            let acc = FrameDecoder.decodeT6(payload)
            if let first = acc.first, !Self.frameIsLive(first.t) {
                if let w = waLog.addWorkoutAccel(acc) { submit(.workout(w)) }
            } else {
                if let w = wa.addWorkoutAccel(acc) { submit(.workout(w)) }
            }
        case "TA:":
            // Activity kind for THIS workout (JSON {"k":"run"|"strength"}). The band sends it on workout
            // start and on reconnect. Stamp the assembler so every window seals with the user's choice,
            // and tell the app so it shows the right live screen (run map vs lift HR) + summary.
            if let obj = try? JSONSerialization.jsonObject(with: Data(payload.utf8)) as? [String: Any],
               let k = obj["k"] as? String, !k.isEmpty {
                if k == "end" {
                    onWorkoutEnd?()   // watch finished the workout → close + seal now (don't infer from sport)
                } else {
                    wa.activityKind = k
                    waLog.activityKind = k   // a backlog TA (offline workout) stamps the recovered window too
                    onActivityKind?(k)
                }
            }
        case "TS:":
            // The band finished draining its offline ring. Seal whatever workout we recovered from the
            // backlog RIGHT NOW (its windows are already `ended`, so the server seals in seconds) instead
            // of waiting for the next disconnect — this is what lets a phone-free lift/run show its
            // catch-up summary on the very sync it arrived on. onBacklogSynced pokes AppModel to look.
            if let w = waLog.flush() { submit(.workout(w)) }
            onBacklogSynced?()
        case "T7:":
            break  // ambient baro (floors) — server-side; not on the live upload path yet
        case "T8:":
            // Step total → a daily-activity summary (server merges with the phone's count, per-day MAX).
            if let s = FrameDecoder.decodeT8(payload) {
                let summary = StepDailySummary(date: s.date, steps: Int(s.steps))
                onSteps?(summary)                      // live "steps today" readout in the app (every frame)
                let key = "\(s.date)#\(s.steps)"
                if key != lastStepsKey {               // only enqueue an upload when the total changed
                    lastStepsKey = key
                    submit(.steps(summary))            // server per-day MAX merge
                }
            }
        case "T9:":
            // "I'm awake" marker → a sleep-session summary (server seals the night + fires the summary).
            if let s = FrameDecoder.decodeT9(payload), s.confirmed {
                submit(.sleep(SleepSessionSummary(bedtime: Int(s.bedtime), wake: Int(s.wake), confirmed: true)))
            }
        default:
            break
        }
    }

    /// Force-seal the current workout window NOW — the run ended on either side (watch sport→0, or the
    /// user tapped End). Without this a workout only sealed on disconnect or after a 120s idle gap, so
    /// finishing while the band stayed connected saved nothing. Idempotent: no-op if no workout is open.
    public func sealWorkout() {
        if let w = wa.flush(ended: true) { submit(.workout(w)) }
    }

    /// While a live run is in progress, keep the workout assembler OPEN across a BLE disconnect: a
    /// transient blip resumes the same window on reconnect, and a DURABLE drop is sealed as `ended` by
    /// AppModel's disconnect-confirm (which the watch's drop-at-end otherwise left hanging forever —
    /// no summary, no save). Set from AppModel.runActive. When true, flush(live:) leaves `wa` untouched.
    public var deferWorkoutFlush = false

    /// On disconnect / app suspend: flush trailing partial windows so nothing is lost.
    public func flush(live: Bool) {
        for w in ppg.flush(live: live) { submit(.ppg(w)) }
        for w in ppgLog.flush(live: live) { submit(.ppg(w)) }
        if !deferWorkoutFlush {                 // (see deferWorkoutFlush) — don't drain a live run's window
            if let w = wa.flush() { submit(.workout(w)) }
        }
        if let w = waLog.flush() { submit(.workout(w)) }   // backlog is independent of the live run
        if let w = hrTrend.flush() { submit(.hrTrend(w)) }
        // On a real disconnect, drop any half-received frame — the firmware re-flushes from scratch on
        // reconnect, so stale partial bytes would otherwise corrupt the first frame of the new stream.
        if !live { rx.removeAll(keepingCapacity: false) }
    }

    /// A frame is "live" if its band timestamp (ms epoch, C2-synced) is within ~60s of now. Replayed
    /// offline-backlog frames carry old timestamps and must not touch live UI. t==0 (unstamped) → treat
    /// as live (legacy path). Mirrors the gate in AppModel.ingestLiveHr.
    static func frameIsLive(_ t: UInt64) -> Bool {
        if t == 0 { return true }
        let nowMs = UInt64(Date().timeIntervalSince1970 * 1000)
        return nowMs <= t || nowMs - t <= 60_000
    }

    private func submit(_ w: AnyWindow) { Task { await queue.submit(w) } }
}

/// Either window kind the queue can ship, plus a daily step summary. Encodes to the underlying
/// JSON (each already carries its own `kind` field), and round-trips through the persisted queue.
/// `.steps` ships in the batch's `summaries[]`; the windows ship in `windows[]` (see IngestClient).
public enum AnyWindow: Codable {
    case ppg(PpgWindow), workout(WorkoutWindow), steps(StepDailySummary), sleep(SleepSessionSummary)
    case hrTrend(HrTrendWindow)

    public func encode(to encoder: Encoder) throws {
        var c = encoder.singleValueContainer()
        switch self {
        case .ppg(let w): try c.encode(w)
        case .workout(let w): try c.encode(w)
        case .steps(let s): try c.encode(s)
        case .sleep(let s): try c.encode(s)
        case .hrTrend(let w): try c.encode(w)
        }
    }
    public init(from decoder: Decoder) throws {
        let c = try decoder.singleValueContainer()
        if let s = try? c.decode(SleepSessionSummary.self), s.kind == "sleep_session" { self = .sleep(s) }
        else if let s = try? c.decode(StepDailySummary.self), s.kind == "activity" { self = .steps(s) }
        else if let w = try? c.decode(HrTrendWindow.self), w.kind == "hr_trend" { self = .hrTrend(w) }
        else if let w = try? c.decode(WorkoutWindow.self), w.kind == "workout" { self = .workout(w) }
        else { self = .ppg(try c.decode(PpgWindow.self)) }
    }
}
