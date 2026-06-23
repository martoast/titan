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
}
public struct HrReading: Equatable {
    public let t: UInt64; public let bpm: UInt8; public let conf: UInt8
}
public struct AccelSample: Equatable {
    public let t: UInt64; public let ax: Int16; public let ay: Int16; public let az: Int16
}
public struct AltSample: Equatable {
    public let t: UInt64; public let alt: Double
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
            out.append(PpgSample(t: epoch + UInt64(r.u32(o)),
                                 ppg: r.i16(o + 4), ax: r.i16(o + 6),
                                 ay: r.i16(o + 8), az: r.i16(o + 10)))
        }
        return T1Frame(epoch: epoch, samples: out)
    }

    /// T4 — GPS fix: [ver u8, sats u8, speed×100 i16, ts u64, alt×10 i32, rsvd u32] (20 B).
    public static func decodeT4(_ b64: String) -> GpsFix? {
        guard let r = Reader(b64), r.count >= 20 else { return nil }
        let altRaw = r.i32(12)
        return GpsFix(t: r.u64(4), sats: r.u8(1),
                      speedKmh: Double(r.i16(2)) / 100,
                      alt: altRaw == ALT_NONE ? nil : Double(altRaw) / 10)
    }

    /// T5 — HR: [ver u8, bpm u8, conf u8, rsvd u8, ts u64] (12 B).
    public static func decodeT5(_ b64: String) -> HrReading? {
        guard let r = Reader(b64), r.count >= 12 else { return nil }
        return HrReading(t: r.u64(4), bpm: r.u8(1), conf: r.u8(2))
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
            let t = start + UInt64((Double(durMs) * Double(i) / Double(denom)).rounded())
            out.append(AccelSample(t: t, ax: r.i16(o), ay: r.i16(o + 2), az: r.i16(o + 4)))
        }
        return out
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
            out.append(AltSample(t: t0 + UInt64(i) * intervalMs,
                                 alt: Double(base + Int32(r.i16(o))) / 10))
        }
        return out
    }
}

/// Little-endian byte reader over a base64-decoded frame.
struct Reader {
    let b: [UInt8]
    var count: Int { b.count }
    init?(_ b64: String) {
        guard let d = Data(base64Encoded: b64) else { return nil }
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
