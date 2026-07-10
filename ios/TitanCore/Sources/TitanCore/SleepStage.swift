import Foundation

/// The single source of truth for sleep-stage vocabulary across the Titan iOS app.
///
/// Before this existed there were FIVE divergent stage→lane/color switches (two on iOS, the web
/// blade, plus the SleepDetail color-name strings). This enum consolidates the iOS ones: canonical
/// code, hypnogram lane, display label, and a platform-neutral *color role* the app layer maps to a
/// real `Color` in ONE place (TitanCore stays free of SwiftUI so it builds/tests with `swift test`).
///
/// Codes mirror the biosignal stager (`biosignal/app/staging.py`): `wake` / `light` / `deep` / `rem`
/// / `nodata`. `awake` is treated as a defensive alias of `wake`.
///
/// **`nodata` is a coverage HOLE** — an epoch the band never sampled — and is NOT a lane: it renders
/// as an honest full-height hatched gap, never painted as sleep or wake. That honesty (showing what we
/// actually measured, holes included) is the product differentiator, so `lane` is `nil` for it and the
/// renderer must branch on `isHole` first.
public enum SleepStage: String, CaseIterable, Sendable {
    case wake, light, deep, rem, nodata

    /// Parse a raw stage code. Treats `awake` as an alias of `wake`; returns `nil` for an unrecognized
    /// code. Callers that need a value (every UI surface) should use ``parse(_:)`` — the ONE place an
    /// unknown/new stage fails loudly.
    public init?(code: String) {
        switch code.lowercased() {
        case "wake", "awake": self = .wake
        case "light": self = .light
        case "deep": self = .deep
        case "rem": self = .rem
        case "nodata": self = .nodata
        default: return nil
        }
    }

    /// The single, fail-loud parse used by every iOS sleep surface. An unknown code (e.g. a stage
    /// added server-side) trips an assertion in debug so it's caught immediately, and degrades to a
    /// `nodata` hole in release rather than silently mis-rendering as sleep in three different charts.
    public static func parse(_ code: String) -> SleepStage {
        if let stage = SleepStage(code: code) { return stage }
        assertionFailure("Unknown sleep-stage code '\(code)' — add it to SleepStage (the staging.py vocabulary changed).")
        return .nodata
    }

    /// True only for `nodata`: a coverage hole to be drawn as a full-height gap, never as a lane.
    public var isHole: Bool { self == .nodata }

    /// Hypnogram lane, top→bottom: Awake=0, REM=1, Light=2, Deep=3 (depth reads intuitively as depth).
    /// `nil` for `nodata` — a hole has no lane; render it full-height instead of painting it into lane 0.
    public var lane: Int? {
        switch self {
        case .wake: return 0
        case .rem: return 1
        case .light: return 2
        case .deep: return 3
        case .nodata: return nil
        }
    }

    /// Human label for legends/tooltips. The shared canonical label for deep is "Deep" (the SleepDetail
    /// reader's longer "Deep (SWS)" may stay in that reader).
    public var label: String {
        switch self {
        case .wake: return "Awake"
        case .rem: return "REM"
        case .light: return "Light"
        case .deep: return "Deep"
        case .nodata: return "No data"
        }
    }

    /// Platform-neutral color role. The app maps this to a real `Color` in ONE extension.
    public var colorRole: ColorRole {
        switch self {
        case .wake: return .wake
        case .rem: return .rem
        case .light: return .light
        case .deep: return .deep
        case .nodata: return .hole
        }
    }

    /// The color roles the app maps to concrete colors: deep→indigo, rem→violet, light→cyan,
    /// wake→amber, hole→faint gray (hatched gap).
    public enum ColorRole: String, Sendable { case deep, rem, light, wake, hole }

    /// The four sleep lanes, top→bottom, for axis/legend rendering (excludes the `nodata` hole).
    public static let lanes: [SleepStage] = [.wake, .rem, .light, .deep]

    /// Number of hypnogram lanes.
    public static let laneCount = 4
}
