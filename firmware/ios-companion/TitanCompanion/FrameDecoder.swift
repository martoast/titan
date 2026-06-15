import Foundation

/// One PPG sample with an absolute unix-ms timestamp.
struct PPGSample {
    let t: UInt64      // unix ms
    let ppg: Int16
}

/// Decodes the Bangle.js `titan-stream` wire format: newline-delimited `T1:<base64>`
/// frames over Nordic UART. Each frame = 16-byte header + 12-byte samples (little-endian),
/// matching firmware/banglejs/titan.app.js exactly.
///
///   header: ver u8, ppgField u8, count u16, epochLo u32, epochHi u32, rsvd u32
///   sample: relT u32, ppg i16, ax i16, ay i16, az i16   (= 12 bytes)
final class FrameDecoder {
    private var rx = ""

    /// Feed raw NUS bytes; returns any complete frames' samples decoded so far.
    func feed(_ data: Data) -> [PPGSample] {
        rx += String(decoding: data, as: UTF8.self)
        var out: [PPGSample] = []
        while let nl = rx.firstIndex(of: "\n") {
            let line = String(rx[rx.startIndex..<nl]).trimmingCharacters(in: .whitespaces)
            rx = String(rx[rx.index(after: nl)...])
            if line.hasPrefix("T1:") {
                out.append(contentsOf: decode(String(line.dropFirst(3))))
            }
        }
        // Guard against an unbounded buffer if a partial line never terminates.
        if rx.utf8.count > 32_768 { rx = "" }
        return out
    }

    private func decode(_ b64: String) -> [PPGSample] {
        guard let data = Data(base64Encoded: b64), data.count >= 16 else { return [] }
        let b = [UInt8](data)
        func u16(_ o: Int) -> UInt16 { UInt16(b[o]) | (UInt16(b[o + 1]) << 8) }
        func u32(_ o: Int) -> UInt32 {
            UInt32(b[o]) | (UInt32(b[o + 1]) << 8) | (UInt32(b[o + 2]) << 16) | (UInt32(b[o + 3]) << 24)
        }
        func i16(_ o: Int) -> Int16 { Int16(bitPattern: u16(o)) }

        let count = Int(u16(2))
        let epoch = (UInt64(u32(8)) << 32) | UInt64(u32(4))
        var out: [PPGSample] = []
        out.reserveCapacity(count)
        for i in 0..<count {
            let o = 16 + i * 12
            if o + 12 > b.count { break }
            out.append(PPGSample(t: epoch + UInt64(u32(o)), ppg: i16(o + 4)))
        }
        return out
    }
}
