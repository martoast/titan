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
    /// Authorization changed (so the UI can prompt / show "enable location").
    var onAuth: ((CLAuthorizationStatus) -> Void)?

    override init() {
        super.init()
        mgr.delegate = self
        mgr.desiredAccuracy = kCLLocationAccuracyBest
        mgr.activityType = .fitness
        mgr.distanceFilter = 5            // a point roughly every 5 m of movement
        mgr.pausesLocationUpdatesAutomatically = false
    }

    var status: CLAuthorizationStatus { mgr.authorizationStatus }

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
        // Keep tracking with the screen off during a run (we only ever enable this while running).
        mgr.allowsBackgroundLocationUpdates = true
        mgr.startUpdatingLocation()
    }

    func locationManagerDidChangeAuthorization(_ m: CLLocationManager) {
        onAuth?(m.authorizationStatus)
        if running, m.authorizationStatus == .authorizedWhenInUse || m.authorizationStatus == .authorizedAlways {
            beginUpdates()
        }
    }

    func locationManager(_ m: CLLocationManager, didUpdateLocations locs: [CLLocation]) {
        guard let loc = locs.last,
              loc.horizontalAccuracy > 0, loc.horizontalAccuracy < 100,   // drop garbage / no-fix readings
              loc.timestamp.timeIntervalSinceNow > -10 else { return }      // and stale cached fixes
        onFix?(loc)
    }

    func locationManager(_ m: CLLocationManager, didFailWithError error: Error) { /* transient — keep trying */ }
}
