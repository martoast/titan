import XCTest
@testable import TitanCore

/// ULID + ppg_raw window builder, verified against golden values from the bridge `_ulid()` /
/// `_shipSamples()` (and a node reference run). Confirms `batch_uid` format and the exact window
/// JSON shape the server's DeviceIngestionService expects.
final class WindowingTests: XCTestCase {

    func testUlidGoldenVector() {
        XCTAssertEqual(ULID.timePart(ms: 1750000123456), "01JXT25FJ0")
        let rb: [UInt8] = (0..<16).map { UInt8($0 * 7 + 3) }
        XCTAssertEqual(ULID.randomPart(bytes: rb), "3AHRZ6DMV29GQY5C")
        XCTAssertEqual(ULID.make(ms: 1750000123456, randomBytes: rb), "01JXT25FJ03AHRZ6DMV29GQY5C")
    }

    func testUlidLiveFormat() {
        let u = ULID.generate()
        XCTAssertEqual(u.count, 26)
        XCTAssertTrue(u.allSatisfy { "0123456789ABCDEFGHJKMNPQRSTVWXYZ".contains($0) })
    }

    func testAccelMagnitude() {
        XCTAssertEqual(accelMagCentiG(ax: -10, ay: 20, az: 1000), 100)
        XCTAssertEqual(accelMagCentiG(ax: 5, ay: -300, az: 990), 103)
        XCTAssertEqual(accelMagCentiG(ax: 0, ay: 0, az: 1000), 100)
    }

    func testWindowBuilderDrainsAt120s() {
        let base: UInt64 = 1_750_000_000_000
        let samples = (0..<4000).map {  // 4000 × 40ms = 160s of 25Hz data
            PpgSample(t: base + UInt64($0) * 40, ppg: Int16($0 % 100), ax: 0, ay: 0, az: 1000)
        }
        let b = PpgWindowBuilder()
        let wins = b.add(samples)
        XCTAssertEqual(wins.count, 1)
        let w = try! XCTUnwrap(wins.first)
        XCTAssertEqual(w.kind, "ppg_raw")
        XCTAssertEqual(w.sample_rate_hz, 25)
        XCTAssertEqual(w.src, "banglejs2")
        XCTAssertEqual(w.ppg.count, w.accel_mag_cg.count)
        XCTAssertTrue(w.accel_mag_cg.allSatisfy { $0 == 100 })
        XCTAssertNotNil(b.flush(live: false))  // trailing remainder
    }

    func testWindowEncodesToServerShape() throws {
        let w = PpgWindow(kind: "ppg_raw", start: "2026-06-23T12:00:00Z", end: "2026-06-23T12:02:00Z",
                          sample_rate_hz: 25, ppg: [1, 2, 3], accel_mag_cg: [100, 100, 100], src: "banglejs2")
        let json = try JSONEncoder().encode(w)
        let obj = try JSONSerialization.jsonObject(with: json) as! [String: Any]
        XCTAssertEqual(obj["kind"] as? String, "ppg_raw")
        XCTAssertEqual(obj["sample_rate_hz"] as? Int, 25)
        XCTAssertNotNil(obj["accel_mag_cg"])
    }

    // MARK: HrTrendBuilder — per-minute aggregation for the 24/7 graph

    func testHrTrendBucketsByMinuteWithMedian() throws {
        let b = HrTrendBuilder()
        let m0: UInt64 = 1_750_000_000_000              // some epoch ms
        // Minute 0: three readings → median 64. Confidence keeps the max.
        XCTAssertNil(b.add(t: m0 + 1_000, bpm: 60, conf: 80))
        XCTAssertNil(b.add(t: m0 + 2_000, bpm: 64, conf: 95))
        XCTAssertNil(b.add(t: m0 + 3_000, bpm: 70, conf: 90))
        // A reading in minute 1 closes minute 0; flush() then closes minute 1.
        XCTAssertNil(b.add(t: m0 + 61_000, bpm: 50, conf: 99))
        let w = try XCTUnwrap(b.flush())
        XCTAssertEqual(w.kind, "hr_trend")
        XCTAssertEqual(w.samples.count, 2)
        XCTAssertEqual(w.samples[0].bpm, 64)            // median of 60,64,70
        XCTAssertEqual(w.samples[0].conf, 95)           // max conf in the bucket
        XCTAssertEqual(w.samples[0].t, Int((m0 / 60_000) * 60))  // bucket start, epoch SECONDS
        XCTAssertEqual(w.samples[1].bpm, 50)
    }

    func testHrTrendIgnoresZeroBpmAndEmptyFlush() {
        let b = HrTrendBuilder()
        XCTAssertNil(b.add(t: 1_750_000_000_000, bpm: 0, conf: 0))  // no valid reading
        XCTAssertNil(b.flush())                                      // nothing to ship
    }

    // MARK: Non-monotonic safety — the offline-sleep resync crash

    // The builder must NEVER trap on out-of-order timestamps. Before the fix, a buffer whose last
    // sample was EARLIER than its first did `last.t - first.t` on UInt64 → wrap → crash. This is what
    // happened when a big T2 sleep backlog (older) flushed in alongside live T1 (newer) on reopen.
    func testWindowBuilderSurvivesNonMonotonicTimestamps() {
        let base: UInt64 = 1_750_000_000_000
        let b = PpgWindowBuilder()
        // Live "now" samples first, then a flood of OLDER buffered samples appended after them.
        let live = (0..<60).map { PpgSample(t: base + UInt64($0) * 40, ppg: 1, ax: 0, ay: 0, az: 1000) }
        let older = (0..<60).map { PpgSample(t: base - 3_600_000 + UInt64($0) * 40, ppg: 2, ax: 0, ay: 0, az: 1000) }
        _ = b.add(live)
        _ = b.add(older)          // must not trap
        _ = b.flush(live: false)  // must not trap
    }

    func testDecodeT2IsMonotonicAndPpgOnly() {
        // Build a real T2 frame: 20-B header + 125 i16 PPG, spread over 5s.
        let count = 125
        var bytes = [UInt8](repeating: 0, count: 20 + count * 2)
        bytes[0] = 2                                   // LOG_PROTO_VERSION
        bytes[2] = UInt8(count & 0xff); bytes[3] = UInt8(count >> 8)   // count u16
        let epoch: UInt64 = 1_750_000_000_000
        for i in 0..<8 { bytes[4 + i] = UInt8((epoch >> (8 * i)) & 0xff) }   // epoch u64 @4
        let dur: UInt32 = 5000
        for i in 0..<4 { bytes[12 + i] = UInt8((dur >> (8 * UInt32(i))) & 0xff) }  // durMs @12
        for i in 0..<count {                           // ppg @20, value = i (i16 LE)
            bytes[20 + i * 2] = UInt8(i & 0xff); bytes[20 + i * 2 + 1] = UInt8((i >> 8) & 0xff)
        }
        let b64 = Data(bytes).base64EncodedString()
        let frame = FrameDecoder.decodeT2(b64)
        XCTAssertEqual(frame.samples.count, count)
        XCTAssertEqual(frame.epoch, epoch)
        XCTAssertEqual(frame.samples.first?.t, epoch)
        XCTAssertEqual(frame.samples.last?.t, epoch + UInt64(dur))   // spread end == epoch+dur
        // Strictly non-decreasing timestamps (the property that prevents the underflow crash).
        for i in 1..<frame.samples.count { XCTAssertGreaterThanOrEqual(frame.samples[i].t, frame.samples[i - 1].t) }
        // PPG carried through, accel zeroed.
        XCTAssertEqual(frame.samples[3].ppg, 3)
        XCTAssertTrue(frame.samples.allSatisfy { $0.ax == 0 && $0.ay == 0 && $0.az == 0 })
    }
}
