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
        gps.enumerated().forEach { i, g in
            wa.addGps(GpsFix(t: g.0, sats: 8, speedKmh: g.1, alt: g.2,
                             lat: 37.0 + Double(i) * 0.001, lon: -122.0 + Double(i) * 0.001))
        }
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
        // Route track: one point per coord-bearing fix, timestamps + coords preserved (not zero-filled).
        XCTAssertEqual(w.gps.track.count, 3)
        XCTAssertEqual(w.gps.track.first?.lat ?? 0, 37.0, accuracy: 1e-9)
        XCTAssertEqual(w.gps.track.first?.lon ?? 0, -122.0, accuracy: 1e-9)
        XCTAssertEqual(w.gps.track[2].lat, 37.002, accuracy: 1e-9)
    }

    // The connected-indoor case: NO GPS, NO offline accel — a workout must still open from the
    // sport-tagged HR (T5) + be filled by live T1 accel, and produce a window on flush.
    func testSportTaggedHrOpensWorkoutAndLiveAccelFillsIt() {
        let start: UInt64 = 2_000_000_000
        let wa = WorkoutAssembler()
        // Resting HR (sport 0) must NOT open a workout.
        wa.addWorkoutHr(HrReading(t: start, bpm: 60, conf: 95, sport: 0))
        wa.addAccel([PpgSample(t: start + 10, ppg: 0, ax: 5, ay: 3, az: 1000)])  // ignored — not active
        // Workout begins: sport>0 opens it; live T1 accel now fills in.
        wa.addWorkoutHr(HrReading(t: start + 1000, bpm: 120, conf: 96, sport: 1))
        for i in 0...1600 {   // ~64s @ 25Hz of live accel
            wa.addAccel([PpgSample(t: start + 1000 + UInt64(i) * 40, ppg: 0, ax: Int16((i % 8) - 4), ay: 2, az: 1000)])
        }
        wa.addWorkoutHr(HrReading(t: start + 65000, bpm: 138, conf: 96, sport: 1))

        let w = try! XCTUnwrap(wa.flush())
        XCTAssertEqual(w.kind, "workout")
        XCTAssertGreaterThanOrEqual(w.accel_xyz.x.count, 25)
        XCTAssertTrue(w.gps.speed_kmh.allSatisfy { $0 == 0 })   // no GPS indoors
        XCTAssertFalse(w.hr_bpm.isEmpty)
    }

    func testTooShortReturnsNil() {
        let wa = WorkoutAssembler()
        wa.addWorkoutAccel([AccelSample(t: 1000, ax: 0, ay: 0, az: 1000)])
        XCTAssertNil(wa.flush())  // < MIN_MS and < 25 samples
    }

    // Regression: opening the app mid-workout flushes buffered frames; one can arrive with a timestamp
    // PREDATING when the workout opened (winStart). periodic() used to do `t - winStart` on UInt64 →
    // arithmetic-overflow TRAP (the real-device crash). It must now no-op safely on an older frame.
    func testNonMonotonicFrameDoesNotUnderflowPeriodic() {
        let wa = WorkoutAssembler()
        let start: UInt64 = 2_000_000_000
        wa.addWorkoutHr(HrReading(t: start, bpm: 120, conf: 96, sport: 1))   // opens; winStart = start
        // Older HR frame (the crash trigger) — must return nil, not trap.
        XCTAssertNil(wa.addWorkoutHr(HrReading(t: start - 500_000, bpm: 121, conf: 96, sport: 1)))
        // The other periodic() caller: an older strap reading.
        XCTAssertNil(wa.addStrapHr(bpm: 122, rr: [], t: start - 1_000_000))
        // An older GPS fix too (addGps → periodic).
        XCTAssertNil(wa.addGps(GpsFix(t: start - 250_000, sats: 8, speedKmh: 5, alt: 10, lat: 37, lon: -122)))
    }

    // A corrupt/unsynced band clock can yield a window spanning days; building per-second arrays for
    // that allocates gigabytes → OOM crash. It must be rejected (nil), not built.
    func testAbsurdDurationRejectedNotOOM() {
        let accel = (0...30).map { _ in AccelSample(t: 0, ax: 1, ay: 1, az: 1000) }
        let w = WorkoutAssembler.buildWorkoutWindow(accel: accel, hr: [], gps: [],
                                                    startT: 0, endT: 25 * 3600 * 1000, minMs: 60_000)
        XCTAssertNil(w)
    }

    func testGapFinalizesPriorWorkout() {
        let wa = WorkoutAssembler(endGapMs: 60_000)
        let s: UInt64 = 1_000_000
        wa.addWorkoutAccel((0...700).map { AccelSample(t: s + UInt64($0) * 100, ax: 1, ay: 1, az: 1000) }) // 70s
        // A fix far past the last activity ends the prior session.
        let ended = wa.addGps(GpsFix(t: s + 200_000, sats: 8, speedKmh: 5, alt: 10, lat: nil, lon: nil))
        XCTAssertNotNil(ended)
        XCTAssertEqual(ended?.kind, "workout")
    }
}
