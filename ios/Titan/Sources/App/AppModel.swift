import Foundation
import SwiftUI
import TitanCore

/// In-memory `WindowStore` so the sync queue runs out of the box. Swap for a GRDB/SQLite-backed
/// store for crash-durable offline buffering (tasks/native-ios todo).
final class InMemoryWindowStore: WindowStore {
    private var items: [(id: Int64, window: AnyWindow)] = []
    private var seq: Int64 = 0
    private let lock = NSLock()

    func enqueue(_ window: AnyWindow) throws {
        lock.lock(); defer { lock.unlock() }
        seq += 1; items.append((seq, window))
    }
    func pending(limit: Int) throws -> [(id: Int64, window: AnyWindow)] {
        lock.lock(); defer { lock.unlock() }
        return Array(items.prefix(limit))
    }
    func remove(id: Int64) throws {
        lock.lock(); defer { lock.unlock() }
        items.removeAll { $0.id == id }
    }
    func bumpAttempt(id: Int64) throws { /* in-memory: nothing to persist */ }
}

/// Top-level app state + dependency wiring: auth/session, the dashboard data, and the live band
/// (CoreBluetooth → decode → window → signed upload). Injected into the SwiftUI environment.
@MainActor
final class AppModel: ObservableObject {
    // Point at your deployment. (For local dev, an http URL on a trusted LAN needs an ATS exception.)
    static let baseURL = URL(string: "https://titan.fullstacklabs.org")!

    @Published var user: AuthUser?
    @Published var dashboard: Dashboard?
    @Published var hrvTrend: [Double] = []
    @Published var bandConnected = false
    @Published var liveBpm: Int?
    @Published var error: String?
    @Published var loading = false

    let api: APIClient

    private var band: BandManager?
    private var router: FrameRouter?
    private var syncQueue: SyncQueue?

    var isLoggedIn: Bool { user != nil }

    init() {
        let token = Keychain.get(Keychain.userToken)
        api = APIClient(baseURL: Self.baseURL, token: token)
        if token != nil { Task { await self.bootstrap() } }
    }

    /// Restore a logged-in session (token already in Keychain): load data + resume band sync.
    func bootstrap() async {
        if let d = try? await api.dashboard() { dashboard = d }
        startBandIfPaired()
    }

    // MARK: auth

    func login(email: String, password: String) async {
        loading = true; error = nil; defer { loading = false }
        do {
            let res = try await api.login(email: email, password: password, deviceName: deviceName())
            api.token = res.token
            Keychain.set(res.token, for: Keychain.userToken)
            user = res.user
            await bootstrap()
        } catch { self.error = (error as? APIError)?.errorDescription ?? error.localizedDescription }
    }

    func logout() async {
        await api.logout()
        Keychain.delete(Keychain.userToken)
        api.token = nil
        user = nil; dashboard = nil
    }

    func refresh() async {
        do { dashboard = try await api.dashboard() }
        catch { if case APIError.unauthorized = error { await logout() } }
    }

    func loadTrends() async {
        if let t = try? await api.trends(metric: "hrv", days: 30) { hrvTrend = t.points.map(\.value) }
    }

    // MARK: band

    /// Pair a fresh band: server mints a one-time secret → store it → start the BLE sync.
    func pairBand() async {
        do {
            let p = try await api.pairDevice()
            Keychain.set(p.device_id, for: Keychain.deviceId)
            Keychain.set(p.secret, for: Keychain.deviceSecret)
            startBandIfPaired()
        } catch { self.error = (error as? APIError)?.errorDescription ?? error.localizedDescription }
    }

    var isBandPaired: Bool { Keychain.get(Keychain.deviceId) != nil && Keychain.get(Keychain.deviceSecret) != nil }

    private func startBandIfPaired() {
        guard band == nil,
              let id = Keychain.get(Keychain.deviceId),
              let secret = Keychain.get(Keychain.deviceSecret) else { return }
        let client = IngestClient(baseURL: Self.baseURL, deviceId: id, secret: secret)
        // Durable on-disk queue so the overnight buffer survives an app kill / relaunch.
        let queue = SyncQueue(store: SqliteWindowStore(), client: client)
        let router = FrameRouter(queue: queue)
        router.onBpm = { [weak self] bpm in Task { @MainActor in self?.liveBpm = Int(bpm) } }
        let band = BandManager(router: router)
        band.onConnectionChange = { [weak self] up in Task { @MainActor in self?.bandConnected = up } }
        self.syncQueue = queue; self.router = router; self.band = band
    }

    private func deviceName() -> String {
        #if canImport(UIKit)
        return UIDevice.current.name
        #else
        return "iPhone"
        #endif
    }
}
