import XCTest
@testable import TitanCore

/// Golden vector from running the real JS WorkoutAssembler (bridge-decode.js) on a deterministic
/// 65s workout. Confirms the Swift port produces the identical kind=workout window — accel rate,
/// per-second HR/speed carry-forward, the per-30s activity counts, and the baro-grade clamp.
final class WorkoutAssemblerTests: XCTestCase {

    func testBuildsWorkoutWindowMatchingJS() {
        let start: UInt64 = 1_000_000_000
        let accel = (0...650).map { AccelSample(t: start + UInt64($0) * 100, ax: Int16(($0 % 10) - 5), ay: 3, az: 1000) }
        let hr: [(UInt64, UInt8)] = [(start + 1000, 110), (start + 11000, 120), (start + 31000, 135), (start + 61000, 140)]
        let gps: [(UInt64, Double, Double)] = [(start + 2000, 9.0, 100.0), (start + 17000, 10.5, 101.5), (start + 47000, 11.0, 103.0)]

        let wa = WorkoutAssembler()
        wa.addWorkoutAccel(accel)
        hr.forEach { wa.addHr(HrReading(t: $0.0, bpm: $0.1, conf: 100)) }
        gps.forEach { wa.addGps(GpsFix(t: $0.0, sats: 8, speedKmh: $0.1, alt: $0.2)) }
        let w = try! XCTUnwrap(wa.flush())

        XCTAssertEqual(w.kind, "workout")
        XCTAssertEqual(w.start, "1970-01-12T13:46:40.000Z")
        XCTAssertEqual(w.end, "1970-01-12T13:47:45.000Z")
        XCTAssertEqual(w.accel_fs, 10)
        XCTAssertEqual(w.accel_unit, "mg")
        XCTAssertEqual(w.src, "banglejs2")
        XCTAssertEqual(w.accel_xyz.x.count, 651)
        XCTAssertEqual(Array(w.accel_xyz.x.prefix(5)), [-5, -4, -3, -2, -1])
        XCTAssertEqual(w.hr_bpm.count, 65)
        XCTAssertEqual(Array(w.hr_bpm.prefix(5)), [110, 110, 110, 110, 110])
        XCTAssertEqual(Array(w.hr_bpm.suffix(3)), [140, 140, 140])
        XCTAssertEqual(w.accel_counts, [0, 0, 0])
        XCTAssertEqual(w.gps.speed_kmh.count, 65)
        XCTAssertEqual(Array(w.gps.speed_kmh.prefix(5)), [9, 9, 9, 9, 9])
        XCTAssertEqual(w.gps.grade[17], 0.3, accuracy: 1e-9)
        XCTAssertTrue(w.gps.grade.prefix(17).allSatisfy { $0 == 0 })
    }

    func testTooShortReturnsNil() {
        let wa = WorkoutAssembler()
        wa.addWorkoutAccel([AccelSample(t: 1000, ax: 0, ay: 0, az: 1000)])
        XCTAssertNil(wa.flush())  // < MIN_MS and < 25 samples
    }

    func testGapFinalizesPriorWorkout() {
        let wa = WorkoutAssembler(endGapMs: 60_000)
        let s: UInt64 = 1_000_000
        wa.addWorkoutAccel((0...700).map { AccelSample(t: s + UInt64($0) * 100, ax: 1, ay: 1, az: 1000) }) // 70s
        // A fix far past the last activity ends the prior session.
        let ended = wa.addGps(GpsFix(t: s + 200_000, sats: 8, speedKmh: 5, alt: 10))
        XCTAssertNotNil(ended)
        XCTAssertEqual(ended?.kind, "workout")
    }
}
