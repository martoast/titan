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
    @Published var onboarded = true        // gate: false → show the onboarding wizard
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

    // Body + intake
    @Published var weightCard: WeightCard?
    @Published var hydration: HydrationToday?
    @Published var fasting: FastingStatus?

    // Apple Health
    @Published var healthConnected = false
    @Published var healthSyncing = false
    @Published var lastHealthSync: String?

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
        onboarded = (try? await api.onboardingStatus()) ?? true
        if let d = try? await api.dashboard() { dashboard = d }
        startBandIfPaired()
        bandBound = band?.isBound ?? false
        await loadHealthStatus()
        if healthConnected { await syncAppleHealth() }   // keep Apple Health fresh on launch
    }

    // MARK: Apple Health

    func loadHealthStatus() async {
        if let s = try? await api.healthStatus() { healthConnected = s.connected; lastHealthSync = s.last_sync_at }
    }

    /// First connect: request authorization, then backfill ~60 days to seed baselines.
    func connectAppleHealth() async {
        guard HealthKitManager.shared.isAvailable else {
            error = "Apple Health isn't available on this device."; return
        }
        Haptic.rigid()
        _ = await HealthKitManager.shared.requestAuthorization()
        await syncAppleHealth(days: 60, initial: true)
    }

    func syncAppleHealth(days: Int = 14, initial: Bool = false) async {
        guard HealthKitManager.shared.isAvailable, !healthSyncing else { return }
        healthSyncing = true; defer { healthSyncing = false }

        let payload = await HealthKitManager.shared.collectPayload(days: days)
        let hasData = ["activity", "recovery", "sleep", "body", "workouts"].contains {
            (payload[$0] as? [Any])?.isEmpty == false
        } || payload["vo2max"] != nil
        guard hasData else {
            if initial { error = "No Apple Health data yet — allow Titan in Settings → Health → Data Access." }
            return
        }
        if let r = try? await api.ingestHealth(payload) {
            healthConnected = true
            lastHealthSync = r.synced_at
            await refresh(); await loadInsights()       // the engine recomputes off the new data
        }
    }

    /// Submit the onboarding wizard → unlock the app.
    func completeOnboarding(_ fields: [String: Any]) async -> Bool {
        do {
            onboarded = try await api.submitOnboarding(fields).onboarded
            await bootstrap()
            return true
        } catch {
            self.error = (error as? APIError)?.errorDescription ?? error.localizedDescription
            return false
        }
    }

    func loadProfileSnapshot() async -> ProfileSnapshot? { try? await api.profileSnapshot() }

    func saveProfile(_ fields: [String: Any]) async -> Bool {
        do { _ = try await api.updateProfile(fields); return true }
        catch { self.error = (error as? APIError)?.errorDescription ?? error.localizedDescription; return false }
    }

    // MARK: auth

    func login(email: String, password: String) async {
        loading = true; error = nil; defer { loading = false }
        do {
            let res = try await api.login(email: email, password: password, deviceName: deviceName())
            api.token = res.token
            Keychain.set(res.token, for: Keychain.userToken)
            user = res.user
            onboarded = res.user.onboarded ?? true
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

    // MARK: body + intake (weight / hydration / fasting)

    func loadWeight() async { if let w = try? await api.weight() { weightCard = w } }
    func logWeight(_ kg: Double) async {
        do { weightCard = try await api.logWeight(kg: kg) }
        catch { self.error = (error as? APIError)?.errorDescription ?? error.localizedDescription }
    }

    func loadHydration() async { if let h = try? await api.hydration() { hydration = h } }
    func addWater(_ ml: Int) async {
        Haptic.soft()
        if let h = try? await api.logWater(ml: ml) { hydration = h }
    }

    func loadFasting() async { if let f = try? await api.fasting() { fasting = f } }
    func startFast(_ goalHours: Double) async {
        Haptic.rigid()
        if let f = try? await api.startFast(goalHours: goalHours) { fasting = f }
    }
    func endFast() async {
        Haptic.success()
        if let f = try? await api.endFast() { fasting = f }
    }

    // MARK: nutrition + progress (Fuel tab)

    func loadNutrition() async {
        do { nutrition = try await api.nutritionToday() }
        catch { if case APIError.unauthorized = error { await logout() } }
    }

    @Published var sleepDetail: SleepResponse?
    @Published var cycle: CycleResponse?
    /// Whether to show the women's Cycle segment (server gates on sex/cycle config).
    var showsCycle: Bool { cycle?.available == true }

    func loadSleepDetail() async { sleepDetail = try? await api.sleepDetail() }
    func loadCycle() async { cycle = try? await api.cycle() }

    func logPeriod(_ date: Date) async {
        try? await api.logCyclePeriod(date: Self.ymd(date))
        await loadCycle()
    }
    func logCycleDay(flow: String?, symptoms: [String]) async {
        try? await api.logCycleDay(date: Self.ymd(Date()), flow: flow, symptoms: symptoms)
        await loadCycle()
    }
    static func ymd(_ d: Date) -> String { let f = DateFormatter(); f.dateFormat = "yyyy-MM-dd"; return f.string(from: d) }

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
