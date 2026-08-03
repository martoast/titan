import Foundation

// Decoders for the Bangle firmware's newline-delimited base64 NUS frames (T1/T4/T5/T6/T7).
// Faithful port of resources/js/bridge-decode.js — verified against golden vectors produced by
// running that exact JS module (see FrameDecoderTests / verify_frames.swift). All fields are
// little-endian. Timestamps are unix-ms in UInt64 (the JS uses hi*2^32 + lo).

public struct PpgSample: Equatable {
    public let t: UInt64; public let ppg: Int16
    public let ax: Int16; public let ay: Int16; public let az: Int16
}
public struct T1Frame: Equatable {
    public let epoch: UInt64
    public let samples: [PpgSample]
}
public struct GpsFix: Equatable {
    public let t: UInt64; public let sats: UInt8
    public let speedKmh: Double; public let alt: Double?   // nil = no altitude
    public let lat: Double?; public let lon: Double?       // nil = no position (deg); the route map
    public init(t: UInt64, sats: UInt8, speedKmh: Double, alt: Double?, lat: Double?, lon: Double?) {
        self.t = t; self.sats = sats; self.speedKmh = speedKmh; self.alt = alt; self.lat = lat; self.lon = lon
    }
}
/// Where an HR reading came from. Wrist PPG is motion-corrupted under load; a chest strap is
/// reference-grade, so the workout builder prefers strap readings and tags the window accordingly.
public enum HrSource: String, Equatable { case wristPpg, chestStrap }

public struct HrReading: Equatable {
    public let t: UInt64; public let bpm: UInt8; public let conf: UInt8
    public let sport: UInt8   // band sport-mode tag: 0 rest · 1 run/general (incl. lifting) · 2 bike
    public let source: HrSource
    public init(t: UInt64, bpm: UInt8, conf: UInt8, sport: UInt8 = 0, source: HrSource = .wristPpg) {
        self.t = t; self.bpm = bpm; self.conf = conf; self.sport = sport; self.source = source
    }
}
public struct AccelSample: Equatable {
    public let t: UInt64; public let ax: Int16; public let ay: Int16; public let az: Int16
}
public struct AltSample: Equatable {
    public let t: UInt64; public let alt: Double
}
public struct StepSummary: Equatable {
    public let steps: UInt32        // the built-in pedometer's running day total
    public let date: String         // the watch's LOCAL calendar date "YYYY-MM-DD"
    public let epochSec: UInt32     // when the reading was taken (provenance)
}
public struct SleepSession: Equatable {
    public let bedtime: UInt32      // epoch seconds the sleep session started
    public let wake: UInt32         // epoch seconds the user marked awake
    public let confirmed: Bool      // user ended it on the band → fire the morning summary
}
/// One continuous overnight-motion sample (T10). `t` is unix-ms; `motion` is the band's per-epoch
/// movement MAGNITUDE — motionEMA×1000 (milli-g EMA of gravity-cancelled |Δaccel|). It's a RELATIVE
/// level, not an absolute unit: the sleep-timeline strip normalizes it to the night's own max.
public struct MotionReading: Equatable {
    public let t: UInt64
    public let motion: Int
    public init(t: UInt64, motion: Int) { self.t = t; self.motion = motion }
}

public enum FrameDecoder {

    static let ALT_NONE: Int32 = -2147483648   // firmware "no altitude" sentinel

    /// T1 — live raw: 16-B header + count×12 [relT u32, ppg i16, ax i16, ay i16, az i16] (accel milli-g).
    public static func decodeT1(_ b64: String) -> T1Frame {
        guard let r = Reader(b64), r.count >= 16 else { return T1Frame(epoch: 0, samples: []) }
        let count = Int(r.u16(2))
        let epoch = r.u64(4)
        var out: [PpgSample] = []
        for i in 0..<count {
            let o = 16 + i * 12
            if o + 12 > r.count { break }
            // Wrapping (&+): epoch comes straight off the wire, so a corrupted/uninitialized-RTC frame can
            // carry an epoch near UInt64.max where a trapping `+` would CRASH the frame-router queue. A
            // wrapped (bad) timestamp is survivable — the seal path already tolerates bad clocks.
            out.append(PpgSample(t: epoch &+ UInt64(r.u32(o)),
                                 ppg: r.i16(o + 4), ax: r.i16(o + 6),
                                 ay: r.i16(o + 8), az: r.i16(o + 10)))
        }
        return T1Frame(epoch: epoch, samples: out)
    }

    /// T2 — compact offline/overnight PPG log: 20-B header [ver u8, rsvd u8, count u16, epoch u64,
    /// durMs u32, activity u32] + count×i16 PPG (no accel). This is the band's buffered-while-offline
    /// format, flushed on reconnect. Per-sample t is spread evenly over [epoch, epoch+durMs] — so the
    /// timeline is MONOTONIC by construction (unlike feeding it through decodeT1, whose 12-B sample
    /// stride misreads the 2-B PPG payload into garbage out-of-order timestamps → window-math underflow).
    public static func decodeT2(_ b64: String) -> T1Frame {
        guard let r = Reader(b64), r.count >= 20 else { return T1Frame(epoch: 0, samples: []) }
        let count = Int(r.u16(2))
        let epoch = r.u64(4)
        let durMs = UInt64(r.u32(12))
        let denom = max(1, count - 1)
        var out: [PpgSample] = []
        for i in 0..<count {
            let o = 20 + i * 2
            if o + 2 > r.count { break }
            let t = epoch &+ UInt64((Double(durMs) * Double(i) / Double(denom)).rounded())   // &+: see decodeT1
            out.append(PpgSample(t: t, ppg: r.i16(o), ax: 0, ay: 0, az: 0))   // PPG-only; no accel
        }
        return T1Frame(epoch: epoch, samples: out)
    }

    /// T4 — GPS fix. v5 (24 B): [ver u8, sats u8, speed×100 i16, ts u64, alt×10 i32, lat×1e7 i32,
    /// lon×1e7 i32]. v4 (20 B): same without lat/lon (rsvd u32 at byte 16) → lat/lon decode to nil.
    public static func decodeT4(_ b64: String) -> GpsFix? {
        guard let r = Reader(b64), r.count >= 20 else { return nil }
        let altRaw = r.i32(12)
        var lat: Double? = nil, lon: Double? = nil
        if r.count >= 24 {
            let latRaw = r.i32(16), lonRaw = r.i32(20)
            if latRaw != ALT_NONE { lat = Double(latRaw) / 1e7 }
            if lonRaw != ALT_NONE { lon = Double(lonRaw) / 1e7 }
        }
        return GpsFix(t: r.u64(4), sats: r.u8(1),
                      speedKmh: Double(r.i16(2)) / 100,
                      alt: altRaw == ALT_NONE ? nil : Double(altRaw) / 10,
                      lat: lat, lon: lon)
    }

    /// T5 — HR: [ver u8, bpm u8, conf u8, sport u8, ts u64] (12 B). The sport byte tags workout mode
    /// (0 rest / 1 run-general / 2 bike) — used to open a workout even when connected indoors.
    public static func decodeT5(_ b64: String) -> HrReading? {
        guard let r = Reader(b64), r.count >= 12 else { return nil }
        return HrReading(t: r.u64(4), bpm: r.u8(1), conf: r.u8(2), sport: r.u8(3))
    }

    /// T6 — offline workout accel: 16-B header [ver u8, rsvd u8, count u16, start u64, durMs u32]
    /// + count×3×i16. Per-sample t spread evenly across [start, start+durMs].
    public static func decodeT6(_ b64: String) -> [AccelSample] {
        guard let r = Reader(b64), r.count >= 16 else { return [] }
        let count = Int(r.u16(2))
        let start = r.u64(4)
        let durMs = UInt64(r.u32(12))
        let denom = max(1, count - 1)
        var out: [AccelSample] = []
        for i in 0..<count {
            let o = 16 + i * 6
            if o + 6 > r.count { break }
            let t = start &+ UInt64((Double(durMs) * Double(i) / Double(denom)).rounded())   // &+: see decodeT1
            out.append(AccelSample(t: t, ax: r.i16(o), ay: r.i16(o + 2), az: r.i16(o + 4)))
        }
        return out
    }

    /// T8 — step summary: [ver u8, year-2000 u8, month u8, day u8, steps u32, epochSec u32] (12 B).
    /// The watch's built-in pedometer day total + its LOCAL date; the server merges it with the
    /// phone's step count as a per-day MAX (see DailyActivity::mergeDaily).
    public static func decodeT8(_ b64: String) -> StepSummary? {
        guard let r = Reader(b64), r.count >= 12 else { return nil }
        let year = 2000 + Int(r.u8(1)), month = Int(r.u8(2)), day = Int(r.u8(3))
        guard (1...12).contains(month), (1...31).contains(day) else { return nil }
        return StepSummary(steps: r.u32(4),
                           date: String(format: "%04d-%02d-%02d", year, month, day),
                           epochSec: r.u32(8))
    }

    /// T9 — sleep session marker: [ver u8, confirmed u8, rsvd u16, bedtime u32 (epoch s), wake u32] (12 B).
    public static func decodeT9(_ b64: String) -> SleepSession? {
        guard let r = Reader(b64), r.count >= 12 else { return nil }
        return SleepSession(bedtime: r.u32(4), wake: r.u32(8), confirmed: r.u8(1) == 1)
    }

    /// T10 — continuous overnight motion: [ver u8, rsvd u8, motion u16 (milli-g EMA), ts u64] (12 B).
    /// One per-epoch movement magnitude the band banks to its ring during offline sleep (~1 / 30 s),
    /// so the v2 sleep timeline's movement strip is DENSE everywhere — not only where an HRV burst
    /// happened to land. The two-digit tag is exactly why the router splits on the first ':' (a
    /// `prefix(3)` would read this as "T10" with a stray ":"-led payload and never route it).
    public static func decodeT10(_ b64: String) -> MotionReading? {
        guard let r = Reader(b64), r.count >= 12 else { return nil }
        return MotionReading(t: r.u64(4), motion: Int(r.u16(2)))
    }

    /// T7 — ambient baro altitude batch: 16-B header [ver u8, count u8, ts u64, intervalMs u16,
    /// base×10 i32] + count×i16 (Δ from base ×10). alt = (base + Δ)/10 m, t = ts + i*intervalMs.
    public static func decodeT7(_ b64: String) -> [AltSample] {
        guard let r = Reader(b64), r.count >= 16 else { return [] }
        let count = Int(r.u8(1))
        let t0 = r.u64(2)
        let intervalMs = UInt64(r.u16(10))
        let base = r.i32(12)
        var out: [AltSample] = []
        for i in 0..<count {
            let o = 16 + i * 2
            if o + 2 > r.count { break }
            out.append(AltSample(t: t0 &+ UInt64(i) &* intervalMs,
                                 alt: Double(Int64(base) + Int64(r.i16(o))) / 10))   // Int64 widen: no i32 overflow
        }
        return out
    }
}

/// Little-endian byte reader over a base64-decoded frame.
struct Reader {
    let b: [UInt8]
    var count: Int { b.count }
    init?(_ b64: String) {
        // Tolerate stray whitespace (notably the trailing "\r" from Espruino's "\r\n" line endings)
        // exactly like JS atob does — strict Swift base64 returns nil on a single CR, which would
        // silently drop every live frame. The web bridge worked only because atob is forgiving.
        guard let d = Data(base64Encoded: b64, options: .ignoreUnknownCharacters) else { return nil }
        b = [UInt8](d)
    }
    func u8(_ o: Int) -> UInt8 { b[o] }
    func u16(_ o: Int) -> UInt16 { UInt16(b[o]) | (UInt16(b[o + 1]) << 8) }
    func i16(_ o: Int) -> Int16 { Int16(bitPattern: u16(o)) }
    func u32(_ o: Int) -> UInt32 {
        UInt32(b[o]) | (UInt32(b[o + 1]) << 8) | (UInt32(b[o + 2]) << 16) | (UInt32(b[o + 3]) << 24)
    }
    func i32(_ o: Int) -> Int32 { Int32(bitPattern: u32(o)) }
    func u64(_ o: Int) -> UInt64 { UInt64(u32(o)) | (UInt64(u32(o + 4)) << 32) }
}
