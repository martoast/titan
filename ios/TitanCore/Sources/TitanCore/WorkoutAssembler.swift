import Foundation

/// A `kind=workout` ingest window (the shape SealActivityJob ingests). Faithful port of
/// bridge-decode.js `buildWorkoutWindow`. Encodes to the exact keys the server expects.
public struct WorkoutWindow: Codable, Equatable {
    public struct Accel: Codable, Equatable { public let x: [Int]; public let y: [Int]; public let z: [Int] }
    public struct TrackPoint: Codable, Equatable { public let t: UInt64; public let lat: Double; public let lon: Double; public let alt: Double? }
    public struct Gps: Codable, Equatable { public let speed_kmh: [Double]; public let grade: [Double]; public let track: [TrackPoint] }
    public let kind: String           // "workout"
    public let start: String          // ISO-8601
    public let end: String
    public let accel_xyz: Accel
    public let accel_fs: Int
    public let accel_unit: String     // "mg"
    public let hr_bpm: [Int]          // one per second
    public let accel_counts: [Int]    // per-30s activity
    public let gps: Gps
    public let src: String            // "banglejs2"
    public let hr_source: String?     // "chest_strap" when a paired strap drove HR (reference-grade);
                                      // nil = wrist (server may recompute from raw PPG). Optional → omitted when nil.
    public let hr_rr_ms: [Int]?       // beat-to-beat RR intervals (ms) from a strap → in-workout HRV.
                                      // nil/omitted when no strap RR (wrist PPG can't give these under motion).
    public let ended: Bool?           // true ONLY on the final window of a run the user/watch explicitly
                                      // ended → the server seals it immediately (Whoop-style), instead of
                                      // waiting for the stream to fall quiet. nil/omitted on mid-run windows.
    public let activity_kind: String? // the user's EXPLICIT watch choice: "run" (running tab, GPS) vs
                                      // "strength" (heart-rate tab, lifting). Authoritative on the server
                                      // over the accel classifier. nil for an auto-started/unprimed workout.
}

/// Accumulates the live frames of a workout and emits `kind=workout` windows. Port of the JS
/// `WorkoutAssembler`: a workout is active while GPS fixes (T4) / offline accel (T6) keep
/// arriving; it ends after END_GAP_MS without one, or on an explicit flush (disconnect).
public final class WorkoutAssembler {
    public let END_GAP_MS: UInt64
    public let FLUSH_MS: UInt64
    public let MIN_MS: UInt64
    /// Largest plausible single workout window. Beyond this the timestamps are corrupt (bad band clock),
    /// and computing per-second arrays would allocate gigabytes → OOM. Used to reject, not crash.
    static let MAX_WINDOW_MS: UInt64 = 24 * 3600 * 1000

    private var active = false
    private var accel: [AccelSample] = []
    private var hr: [HrReading] = []
    private var rr: [(t: UInt64, ms: Double)] = []   // strap beat-to-beat intervals (ms) → in-workout HRV
    private var gps: [GpsFix] = []
    private var lastActivityT: UInt64 = 0
    private var winStart: UInt64 = 0
    /// The user's explicit watch choice for the OPEN workout ("run"/"strength"), set from the band's
    /// `TA:` frame. Stamped onto every window built for this session; cleared when the session resets so
    /// a later auto-started workout (no `TA:`) carries no hint and the server classifies it.
    public var activityKind: String?

    public init(endGapMs: UInt64 = 120_000, flushMs: UInt64 = 180_000, minMs: UInt64 = 60_000) {
        END_GAP_MS = endGapMs; FLUSH_MS = flushMs; MIN_MS = minMs
    }

    private func reset() {
        active = false; accel = []; hr = []; rr = []; gps = []; lastActivityT = 0; winStart = 0
        activityKind = nil   // a fresh session re-learns its kind from the next `TA:` (or stays unhinted)
    }

    private func gapFinalize(_ t: UInt64) -> WorkoutWindow? {
        if active && lastActivityT != 0 && t > lastActivityT && t - lastActivityT > END_GAP_MS {
            let w = build(lastActivityT); reset(); return w
        }
        return nil
    }
    private func open(_ t: UInt64) { if !active { active = true; winStart = t } }

    private func periodic(_ t: UInt64) -> WorkoutWindow? {
        // Underflow-safe: a frame whose timestamp PREDATES winStart (older buffered data flushed on
        // reconnect, or a non-monotonic stream when the band switches rest→workout) must never do
        // `t - winStart` on UInt64 — it wraps and TRAPS. This is the crash seen opening the app
        // mid-workout. An out-of-order / older frame simply doesn't trigger a periodic flush.
        guard active, t >= winStart, t - winStart >= FLUSH_MS else { return nil }
        let w = build(t)
        accel = accel.filter { $0.t >= t }
        hr = hr.filter { $0.t >= t }
        rr = rr.filter { $0.t >= t }
        gps = gps.filter { $0.t >= t }
        winStart = gps.first?.t ?? t
        return w
    }

    /// T4 GPS fix — opens/keeps a workout (GPS only powers on once a workout is detected).
    @discardableResult public func addGps(_ fix: GpsFix) -> WorkoutWindow? {
        let ended = gapFinalize(fix.t)
        open(fix.t)
        gps.append(fix)
        lastActivityT = max(lastActivityT, fix.t)
        return ended ?? periodic(fix.t)
    }

    /// T6 offline workout-accel — opens/keeps a workout.
    @discardableResult public func addWorkoutAccel(_ samples: [AccelSample]) -> WorkoutWindow? {
        guard let first = samples.first, let last = samples.last else { return nil }
        let ended = gapFinalize(first.t)
        open(first.t)
        accel.append(contentsOf: samples)
        lastActivityT = max(lastActivityT, last.t)
        return ended ?? periodic(lastActivityT)
    }

    /// T1 live accel — buffered only while a workout is already open.
    public func addAccel(_ samples: [PpgSample]) {
        guard active else { return }
        accel.append(contentsOf: samples.map { AccelSample(t: $0.t, ax: $0.ax, ay: $0.ay, az: $0.az) })
    }

    public func addHr(_ h: HrReading) { if active { hr.append(h) } }

    /// A chest-strap HR reading. Supplements an ALREADY-OPEN workout (it never opens one — wearing a
    /// strap at rest must not fabricate a workout); it provides reference-grade HR that wins over the
    /// wrist PPG when the window is built, and bumps the activity clock so the strap keeps a no-GPS
    /// session (treadmill, lifting) alive between the band's sport frames.
    @discardableResult public func addStrapHr(bpm: UInt8, rr: [Double] = [], t: UInt64) -> WorkoutWindow? {
        guard active else { return nil }
        hr.append(HrReading(t: t, bpm: bpm, conf: 100, sport: 1, source: .chestStrap))
        for ms in rr where ms >= 250 && ms <= 2000 {   // physiologic guard (30–240 bpm) before HRV
            self.rr.append((t: t, ms: ms))
        }
        lastActivityT = max(lastActivityT, t)
        return periodic(t)
    }

    /// T5 with a sport-mode tag — the band's "I'm in a workout" signal. This is what OPENS a workout
    /// when you're connected INDOORS (no GPS/T4, and T6 is suppressed while connected): the band keeps
    /// sending sport>0 HR throughout the session, and the live T1 accel fills it in via addAccel.
    /// Resting readings (sport 0) don't open or extend, so the end-gap closes the session when you stop.
    @discardableResult public func addWorkoutHr(_ h: HrReading) -> WorkoutWindow? {
        guard h.sport > 0 else { return nil }
        let ended = gapFinalize(h.t)
        open(h.t)
        hr.append(h)
        lastActivityT = max(lastActivityT, h.t)
        return ended ?? periodic(h.t)
    }

    /// Live: T1 keeps device-time moving so a workout's end-gap is detected.
    @discardableResult public func tick(_ deviceNowT: UInt64) -> WorkoutWindow? { gapFinalize(deviceNowT) }

    /// Force-emit whatever is buffered (disconnect / settled sync burst). `ended` tags the result as the
    /// definitive end of the run (set by FrameRouter.sealWorkout when the user/watch tapped End) so the
    /// server seals it at once; a plain disconnect flush leaves it false (the seal scheduler is the safety net).
    public func flush(ended: Bool = false) -> WorkoutWindow? {
        guard active else { return nil }
        let w = build(lastActivityT != 0 ? lastActivityT : winStart, ended: ended)
        reset()
        return w
    }

    private func build(_ endT: UInt64, ended: Bool = false) -> WorkoutWindow? {
        let rrMs = rr.filter { $0.t >= winStart && $0.t <= endT }.map { $0.ms }
        return Self.buildWorkoutWindow(accel: accel, hr: hr, gps: gps, rrMs: rrMs,
                                       startT: winStart, endT: endT, minMs: MIN_MS, ended: ended,
                                       activityKind: activityKind)
    }

    private static let iso: ISO8601DateFormatter = {
        let f = ISO8601DateFormatter()
        f.formatOptions = [.withInternetDateTime, .withFractionalSeconds]  // match JS toISOString()
        return f
    }()

    /// Pure builder — port of `buildWorkoutWindow`.
    public static func buildWorkoutWindow(accel: [AccelSample], hr: [HrReading], gps: [GpsFix],
                                          rrMs: [Double] = [],
                                          startT: UInt64, endT: UInt64, minMs: UInt64,
                                          ended: Bool = false, activityKind: String? = nil) -> WorkoutWindow? {
        guard endT >= startT, endT - startT >= minMs, accel.count >= 25 else { return nil }
        // Corruption guard: a single window can't plausibly span more than a day. A bad/unsynced band
        // clock (mixed epochs) can make endT-startT enormous → `secs` huge → a multi-GB array alloc
        // below → OOM crash that also loses the workout. Reject the corrupt window instead of dying.
        guard endT - startT <= Self.MAX_WINDOW_MS else { return nil }
        let ax = accel.map { Int($0.ax) }, ay = accel.map { Int($0.ay) }, az = accel.map { Int($0.az) }
        let accelFs = max(1, Int((Double(accel.count) * 1000 / Double(max(endT - startT, 1))).rounded()))
        let secs = max(1, Int((Double(endT - startT) / 1000).rounded(.up)))

        // Prefer chest-strap HR when present — it's reference-grade and immune to the motion/grip that
        // wreck wrist PPG. If ANY strap reading is in the window, build HR from strap readings only and
        // tag the window so the server trusts it outright (skips the PPG recompute).
        let strapHr = hr.filter { $0.source == .chestStrap }
        let hrForSeries = strapHr.isEmpty ? hr : strapHr
        let hrSource = strapHr.isEmpty ? nil : "chest_strap"
        let hrBySec = perSecond(hrForSeries.map { ($0.t, Double($0.bpm)) }, startT, secs).map { Int($0) }
        let speedBySec = perSecond(gps.map { ($0.t, $0.speedKmh) }, startT, secs)
        let altBySec = perSecond(gps.map { ($0.t, $0.alt) }, startT, secs)

        var grade = [Double](repeating: 0, count: secs)
        for i in 1..<max(secs, 1) {
            let dAlt = altBySec[i] - altBySec[i - 1]
            let dDist = speedBySec[i] / 3.6                       // km/h → m/s (m in 1s)
            grade[i] = dDist > 0.5 ? min(max(dAlt / dDist, -0.3), 0.3) : 0
        }

        // Per-30s accel activity counts.
        var counts: [Int] = []
        let epoch = accelFs * 30
        var acc = 0.0, prev: Double? = nil, k = 0
        for i in 0..<accel.count {
            let mag = (Double(ax[i] * ax[i] + ay[i] * ay[i] + az[i] * az[i])).squareRoot() / 1000
            if let p = prev { acc += abs(mag - p) }
            prev = mag
            k += 1
            if k >= epoch { counts.append(Int((min(acc * 2, 300)).rounded())); acc = 0; k = 0 }
        }
        if k > 0 { counts.append(Int((min(acc * 2, 300)).rounded())) }

        // Raw coordinate track for the route map: only fixes with real coords, timestamps preserved
        // (NOT per-second zero-filled — (0,0) is a real ocean location). Drives polyline + distance.
        let track: [WorkoutWindow.TrackPoint] = gps.compactMap { f in
            guard let lat = f.lat, let lon = f.lon else { return nil }
            return WorkoutWindow.TrackPoint(t: f.t, lat: lat, lon: lon, alt: f.alt)
        }

        return WorkoutWindow(
            kind: "workout",
            start: iso.string(from: Date(timeIntervalSince1970: Double(startT) / 1000)),
            end: iso.string(from: Date(timeIntervalSince1970: Double(endT) / 1000)),
            accel_xyz: .init(x: ax, y: ay, z: az),
            accel_fs: accelFs, accel_unit: "mg",
            hr_bpm: hrBySec, accel_counts: counts,
            gps: .init(speed_kmh: speedBySec, grade: grade, track: track),
            src: "banglejs2", hr_source: hrSource,
            hr_rr_ms: rrMs.isEmpty ? nil : rrMs.map { Int($0.rounded()) },
            ended: ended ? true : nil, activity_kind: activityKind)
    }

    /// Bucket timestamped events into per-second slots, carrying last value forward; nil→0.
    /// Matches the JS `perSecond` (later event in a second wins, even a nil value).
    static func perSecond(_ events: [(t: UInt64, v: Double?)], _ startT: UInt64, _ secs: Int) -> [Double] {
        var out = [Double?](repeating: nil, count: secs)
        for e in events {
            let s = Int((Int64(bitPattern: e.t) - Int64(bitPattern: startT)) / 1000)
            if s >= 0 && s < secs { out[s] = e.v }
        }
        var last: Double? = nil
        for i in 0..<secs { if out[i] == nil { out[i] = last } else { last = out[i] } }
        let first = out.first(where: { $0 != nil }) ?? 0
        for i in 0..<secs { if out[i] == nil { out[i] = first } }
        return out.map { $0 ?? 0 }
    }
}
