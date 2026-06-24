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
    @Published var bandBound = false       // bound to a specific band's BLE identity
    @Published var pairing = false         // BLE pairing in progress (picker open)
    @Published var pairCandidates: [BandManager.PairCandidate] = []  // nearby bands, by code
    @Published var liveBpm: Int?
    @Published var syncedSamples = 0       // cumulative PPG samples received this session
    @Published var windowsUploaded = 0     // windows confirmed by the server
    @Published var liveHz = 0
    @Published var waveform: [Double] = []  // recent PPG for the live trace
    @Published var error: String?
    @Published var loading = false

    // Fuel (nutrition + progress)
    @Published var nutrition: NutritionToday?
    @Published var scanResult: MealScanResult?      // drives the post-scan result sheet
    @Published var scanning = false
    @Published var progressPhotos: [ProgressPhoto] = []
    @Published var progressBusy = false
    @Published var targets: Targets?

    // Insights + journal (the moat, surfaced)
    @Published var insights: [Insight] = []
    @Published var journalCatalog: [JournalItem] = []
    @Published var journalLogged: Set<String> = []

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
        bandBound = band?.isBound ?? false
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

    /// Pair a fresh band: server mints a one-time secret, then bind to the CLOSEST band over BLE
    /// (hold yours to the phone) so two nearby bands never cross-connect.
    func pairBand() async {
        do {
            let p = try await api.pairDevice()
            Keychain.set(p.device_id, for: Keychain.deviceId)
            Keychain.set(p.secret, for: Keychain.deviceSecret)
            startBandIfPaired()                    // ensures `band` (BandManager) exists
            pairCandidates = []
            pairing = true
            band?.startPairing()                   // scan + surface nearby bands by code
        } catch {
            pairing = false
            self.error = (error as? APIError)?.errorDescription ?? error.localizedDescription
        }
    }

    /// User tapped the band whose code matches their band's screen → bind to it.
    func bindBand(_ id: UUID) {
        Haptic.success()
        band?.bind(to: id)
    }

    func cancelPairing() {
        band?.cancelPairing()
        pairing = false
        pairCandidates = []
    }

    /// Paired = we have server creds AND a band bound to this phone's BLE identity.
    var isBandPaired: Bool { bandBound && Keychain.get(Keychain.deviceId) != nil }

    private func startBandIfPaired() {
        guard band == nil,
              let id = Keychain.get(Keychain.deviceId),
              let secret = Keychain.get(Keychain.deviceSecret) else { return }
        let client = IngestClient(baseURL: Self.baseURL, deviceId: id, secret: secret)
        // Durable on-disk queue so the overnight buffer survives an app kill / relaunch.
        let queue = SyncQueue(store: SqliteWindowStore(), client: client, onUploaded: { [weak self] count in
            Task { @MainActor in self?.windowsUploaded = count }
        })
        let router = FrameRouter(queue: queue)
        router.onBpm = { [weak self] bpm in Task { @MainActor in self?.liveBpm = Int(bpm) } }
        router.onSamples = { [weak self] total, ppg, hz in
            Task { @MainActor in
                self?.syncedSamples = total
                self?.liveHz = hz
                self?.waveform = ppg.map { Double($0) }
            }
        }
        let band = BandManager(router: router)
        band.onConnectionChange = { [weak self] up in Task { @MainActor in self?.bandConnected = up } }
        band.onPaired = { [weak self] ok in
            Task { @MainActor in
                self?.pairing = false
                self?.pairCandidates = []
                self?.bandBound = ok
                if !ok { self?.error = "Couldn't find your band. Put it in pairing mode (hold its button 3s) and try again." }
            }
        }
        band.onCandidates = { [weak self] list in
            Task { @MainActor in self?.pairCandidates = list }
        }
        self.syncQueue = queue; self.router = router; self.band = band
    }

    // MARK: insights + journal

    func loadInsights() async {
        if let i = try? await api.insights() { insights = i }
    }

    func loadJournal() async {
        if let j = try? await api.journal() { journalCatalog = j.catalog; journalLogged = Set(j.logged) }
    }

    /// Optimistic toggle, then reconcile with the server's truth.
    func toggleBehavior(_ key: String) async {
        let wasOn = journalLogged.contains(key)
        if wasOn { journalLogged.remove(key) } else { journalLogged.insert(key) }
        do {
            let logged = try await api.logJournal(add: wasOn ? [] : [key], remove: wasOn ? [key] : [])
            journalLogged = Set(logged)
        } catch { await loadJournal() }
    }

    // MARK: nutrition + progress (Fuel tab)

    func loadNutrition() async {
        do { nutrition = try await api.nutritionToday() }
        catch { if case APIError.unauthorized = error { await logout() } }
    }

    /// Snap a meal → AI macros (grounded + logged) → show the result + refresh the rings.
    func scanMeal(_ imageData: Data, caption: String? = nil) async {
        scanning = true; defer { scanning = false }
        do {
            scanResult = try await api.scanMeal(imageData, caption: caption)
            await loadNutrition()
        } catch { self.error = (error as? APIError)?.errorDescription ?? error.localizedDescription }
    }

    func updateMeal(_ id: Int, name: String, calories: Int, protein: Double, carbs: Double, fat: Double) async {
        do {
            _ = try await api.updateMeal(id, fields: [
                "name": name, "calories": calories, "protein_g": protein, "carbs_g": carbs, "fat_g": fat,
            ])
            await loadNutrition()
        } catch { self.error = (error as? APIError)?.errorDescription ?? error.localizedDescription }
    }

    func deleteMeal(_ id: Int) async {
        do { try await api.deleteMeal(id); await loadNutrition() }
        catch { self.error = (error as? APIError)?.errorDescription ?? error.localizedDescription }
    }

    func loadTargets() async {
        do { targets = try await api.targets() }
        catch { if case APIError.unauthorized = error { await logout() } }
    }

    func saveTargets(calories: Int, protein: Int, carbs: Int, fat: Int, sleepH: Double) async {
        do {
            targets = try await api.updateTargets([
                "calories": calories, "protein_g": protein, "carbs_g": carbs, "fat_g": fat, "sleep_h": sleepH,
            ])
            await loadNutrition()   // rings reflect the new targets
        } catch { self.error = (error as? APIError)?.errorDescription ?? error.localizedDescription }
    }

    func resetTargets() async {
        do {
            targets = try await api.resetTargets()
            await loadNutrition()
        } catch { self.error = (error as? APIError)?.errorDescription ?? error.localizedDescription }
    }

    func loadProgress() async {
        do { progressPhotos = try await api.progressPhotos() }
        catch { if case APIError.unauthorized = error { await logout() } }
    }

    func uploadProgress(_ imageData: Data, pose: String?, weightKg: Double?, notes: String?) async {
        progressBusy = true; defer { progressBusy = false }
        do {
            let photo = try await api.uploadProgressPhoto(imageData, pose: pose, weightKg: weightKg, notes: notes)
            progressPhotos.insert(photo, at: 0)
        } catch { self.error = (error as? APIError)?.errorDescription ?? error.localizedDescription }
    }

    func deleteProgress(_ id: Int) async {
        await api.deleteProgressPhoto(id)
        progressPhotos.removeAll { $0.id == id }
    }

    private func deviceName() -> String {
        #if canImport(UIKit)
        return UIDevice.current.name
        #else
        return "iPhone"
        #endif
    }
}
