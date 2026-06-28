import Foundation
import CoreLocation

/// Phone-side GPS for runs. The band (Bangle.js 2) has no GPS chip, so the route + distance come from
/// the iPhone's GNSS while a run is live (the approach the hardware plan calls for: "do it phone-side,
/// never watch GPS"). Foreground + background (the run keeps tracking with the screen off) via the
/// `location` background mode; we never run it outside a run/test, to spare battery.
final class RunLocationTracker: NSObject, CLLocationManagerDelegate {
    private let mgr = CLLocationManager()
    private var running = false

    /// A fresh, accurate fix. Delivered on the main thread (CLLocationManager was created there).
    var onFix: ((CLLocation) -> Void)?
    /// Authorization changed (so the UI can prompt / show "enable location"). Includes the precise-vs-
    /// reduced accuracy grant — with "Precise Location" OFF, fixes arrive at ~km accuracy and a run
    /// can't track, so the GPS test must catch it.
    var onAuth: ((CLAuthorizationStatus, CLAccuracyAuthorization) -> Void)?

    override init() {
        super.init()
        mgr.delegate = self
        mgr.desiredAccuracy = kCLLocationAccuracyBest
        mgr.activityType = .fitness
        // Stream fixes continuously (~1 Hz), NOT only after 5 m of movement. A distance filter starves
        // the GPS test when you stand still (it got one fix then nothing → stuck at 1/3) and stalls a run
        // when you pause at a light. The app de-jitters the saved route itself (a min-move gate), so we
        // want the steady stream here.
        mgr.distanceFilter = kCLDistanceFilterNone
        mgr.pausesLocationUpdatesAutomatically = false
    }

    var status: CLAuthorizationStatus { mgr.authorizationStatus }
    var accuracyAuthorization: CLAccuracyAuthorization { mgr.accuracyAuthorization }

    /// Push the current authorization to the UI on demand (the GPS test reads it the instant it starts,
    /// without waiting for the system to fire a change it may already be past).
    func emitAuth() { onAuth?(mgr.authorizationStatus, mgr.accuracyAuthorization) }

    func start() {
        running = true
        switch mgr.authorizationStatus {
        case .notDetermined:
            mgr.requestWhenInUseAuthorization()      // updates begin once granted (didChangeAuthorization)
        case .authorizedWhenInUse, .authorizedAlways:
            beginUpdates()
        default:
            break                                     // denied/restricted → onAuth lets the UI explain
        }
    }

    func stop() {
        running = false
        mgr.stopUpdatingLocation()
        mgr.allowsBackgroundLocationUpdates = false
    }

    private func beginUpdates() {
        // Keep tracking with the screen off during a run. CRITICAL: setting allowsBackgroundLocationUpdates
        // = true throws an Objective-C exception (→ hard crash) if "location" is missing from the build's
        // UIBackgroundModes, or if we're not yet authorized. That can happen on a stale/misconfigured build
        // and would crash the app the instant a run starts. Guard on the ACTUAL Info.plist + auth so the
        // worst case is foreground-only tracking, never a crash.
        let bgModes = Bundle.main.object(forInfoDictionaryKey: "UIBackgroundModes") as? [String] ?? []
        let authed = mgr.authorizationStatus == .authorizedAlways || mgr.authorizationStatus == .authorizedWhenInUse
        mgr.allowsBackgroundLocationUpdates = bgModes.contains("location") && authed
        mgr.startUpdatingLocation()
    }

    func locationManagerDidChangeAuthorization(_ m: CLLocationManager) {
        onAuth?(m.authorizationStatus, m.accuracyAuthorization)
        if running, m.authorizationStatus == .authorizedWhenInUse || m.authorizationStatus == .authorizedAlways {
            beginUpdates()
        }
    }

    func locationManager(_ m: CLLocationManager, didUpdateLocations locs: [CLLocation]) {
        // Pass through any fix with a real position (accuracy > 0) that isn't a stale cached one. We do NOT
        // drop coarse fixes here — the app layer shows your dot immediately (even while GPS sharpens) and
        // applies the strict route-quality gate itself. Dropping coarse fixes here is what made the test /
        // live run look "stuck" with no location during warm-up.
        guard let loc = locs.last,
              loc.horizontalAccuracy > 0,
              loc.timestamp.timeIntervalSinceNow > -15 else { return }
        onFix?(loc)
    }

    func locationManager(_ m: CLLocationManager, didFailWithError error: Error) { /* transient — keep trying */ }
}
