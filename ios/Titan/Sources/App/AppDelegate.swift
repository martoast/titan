import UIKit

/// Owns the band pipeline (`BandManager` + `FrameRouter` + `SyncQueue`) and builds it SYNCHRONOUSLY at
/// process launch — before any `await`/network and independent of login — so the `CBCentralManager` that
/// carries the State-Preservation restore id (`com.titan.band.central`) exists inside iOS's restoration
/// window on a COLD, BLE-triggered background relaunch of a terminated app (B1). Previously the central was
/// created lazily deep inside `AppModel.startBandIfPaired()`, itself behind `await api.onboardingStatus()`
/// / `dashboard()` in a detached Task, gated on the `@StateObject` AppModel only existing once the
/// WindowGroup body evaluated — so on a background relaunch the central was recreated too late (past the
/// restoration window) or not at all, and `willRestoreState` rarely fired.
///
/// AppModel adopts THIS SAME stack (never a second central with the same restore id — that's undefined).
@MainActor
final class AppDelegate: NSObject, UIApplicationDelegate {
    static var shared: AppDelegate?

    /// The pre-built band stack, if the phone is already paired (creds in Keychain). Held only until
    /// AppModel adopts it; nil when unpaired (AppModel builds one on first pair instead).
    private(set) var bandStack: AppModel.BandStack?

    /// Forwards the `SyncQueue`'s cumulative-uploaded count to whoever's listening (AppModel, once it has
    /// adopted the stack). The queue's `onUploaded` is fixed at construction — before AppModel exists — so
    /// it routes through here instead of capturing AppModel directly.
    var onWindowsUploaded: ((Int) -> Void)?

    func application(_ application: UIApplication,
                     didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]? = nil) -> Bool {
        AppDelegate.shared = self
        // When iOS relaunches a terminated app specifically for the band, `launchOptions[.bluetoothCentrals]`
        // carries the central restore identifiers. We don't need its contents — constructing the central
        // with the matching restore id (below, synchronously, before any await) is exactly what makes
        // CoreBluetooth deliver `willRestoreState`. Reading it documents that this path is honored.
        _ = launchOptions?[.bluetoothCentrals]
        // Build the whole stack NOW — its deps (Keychain creds, router, queue) are all synchronous — so the
        // restore-id central is alive before iOS delivers restoration or the first BLE event.
        bandStack = AppModel.makeBandStack()
        return true
    }

    /// Hand the launch-built stack to AppModel exactly once, transferring ownership (nil it out) so a later
    /// re-pair — which rebuilds the stack — can fully deallocate the old central. Returns nil when nothing
    /// was pre-built (unpaired at launch); AppModel then builds a fresh stack itself.
    func adoptBandStack() -> AppModel.BandStack? {
        defer { bandStack = nil }
        return bandStack
    }
}
