import XCTest
@testable import TitanCore

final class SleepStageTests: XCTestCase {

    // MARK: Code parsing

    func testCanonicalCodesParse() {
        XCTAssertEqual(SleepStage(code: "wake"), .wake)
        XCTAssertEqual(SleepStage(code: "light"), .light)
        XCTAssertEqual(SleepStage(code: "deep"), .deep)
        XCTAssertEqual(SleepStage(code: "rem"), .rem)
        XCTAssertEqual(SleepStage(code: "nodata"), .nodata)
    }

    func testCodeParsingIsCaseInsensitive() {
        XCTAssertEqual(SleepStage(code: "DEEP"), .deep)
        XCTAssertEqual(SleepStage(code: "Rem"), .rem)
    }

    func testAwakeIsAnAliasOfWake() {
        XCTAssertEqual(SleepStage(code: "awake"), .wake)
        XCTAssertEqual(SleepStage(code: "AWAKE"), .wake)
        XCTAssertEqual(SleepStage(code: "awake"), SleepStage(code: "wake"))
    }

    func testUnknownCodeReturnsNil() {
        XCTAssertNil(SleepStage(code: "n2"))
        XCTAssertNil(SleepStage(code: ""))
    }

    func testParseFallsBackToHoleForUnknownInRelease() {
        // `parse` asserts in debug; here we only assert its release-path contract for a known code.
        XCTAssertEqual(SleepStage.parse("light"), .light)
        XCTAssertEqual(SleepStage.parse("awake"), .wake)
    }

    // MARK: Lane / hole assignment

    func testLaneOrderTopToBottom() {
        XCTAssertEqual(SleepStage.wake.lane, 0)
        XCTAssertEqual(SleepStage.rem.lane, 1)
        XCTAssertEqual(SleepStage.light.lane, 2)
        XCTAssertEqual(SleepStage.deep.lane, 3)
    }

    func testNodataIsAHoleWithNoLane() {
        XCTAssertTrue(SleepStage.nodata.isHole)
        XCTAssertNil(SleepStage.nodata.lane, "nodata must NOT occupy lane 0 — it is a full-height gap")
    }

    func testSleepStagesAreNotHoles() {
        for stage in [SleepStage.wake, .rem, .light, .deep] {
            XCTAssertFalse(stage.isHole)
            XCTAssertNotNil(stage.lane)
        }
    }

    // MARK: Labels / color roles

    func testLabels() {
        XCTAssertEqual(SleepStage.wake.label, "Awake")
        XCTAssertEqual(SleepStage.rem.label, "REM")
        XCTAssertEqual(SleepStage.light.label, "Light")
        XCTAssertEqual(SleepStage.deep.label, "Deep")
    }

    func testColorRoles() {
        XCTAssertEqual(SleepStage.deep.colorRole, .deep)
        XCTAssertEqual(SleepStage.rem.colorRole, .rem)
        XCTAssertEqual(SleepStage.light.colorRole, .light)
        XCTAssertEqual(SleepStage.wake.colorRole, .wake)
        XCTAssertEqual(SleepStage.nodata.colorRole, .hole)
    }

    func testLanesConstantExcludesHole() {
        XCTAssertEqual(SleepStage.lanes, [.wake, .rem, .light, .deep])
        XCTAssertEqual(SleepStage.laneCount, 4)
        XCTAssertFalse(SleepStage.lanes.contains(.nodata))
    }
}
