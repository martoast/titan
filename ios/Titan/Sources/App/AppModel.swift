import Foundation
import SwiftUI
import CoreLocation
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
    @Published var dashboardPhase: LoadPhase = .idle
    @Published var hrvTrend: [Double] = []
    @Published var bandConnected = false
    @Published var bandBound = false       // bound to a specific band's BLE identity
    @Published var pairing = false         // BLE pairing in progress (picker open)
    @Published var pairCandidates: [BandManager.PairCandidate] = []  // nearby bands, by code
    @Published var liveBpm: Int?
    @Published var syncedSamples = 0       // cumulative PPG samples received this session
    @Published var windowsUploaded = 0     // windows confirmed by the server
    @Published var liveHz = 0
    @Published var bandSyncing = false     // a manual "Sync now" is in flight
    @Published var lastBandSyncAt: Date?   // when the last manual sync completed
    @Published var bandIdle = false        // power-saving: we released the live link, band is duty-cycling
    @Published var bandBattery: Int?       // band battery % (BLE Battery Service), last-known
    @Published var waveform: [Double] = []  // recent PPG for the live trace
    // Chest-strap (workout HR): a separate BLE device from the band; both run at once.
    @Published var strapPaired = false
    @Published var strapConnected = false
    @Published var strapBpm: Int?          // last live HR from the strap
    @Published var strapBattery: Int?
    @Published var strapPairing = false
    @Published var strapCandidates: [StrapManager.StrapCandidate] = []
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

    // What you take (supplements & medications)
    @Published var stackToday: StackToday?
    @Published var stackItems: [StackItem] = []
    @Published var stackFlags: [InteractionFlag] = []
    @Published var stackDisclaimer: String?
    @Published var stackSearchResults: [StackCatalogResult] = []
    @Published var stackSearching = false
    @Published var stackScan: StackScanResult?
    @Published var stackScanning = false
    @Published var stackBusy = false

    // Apple Health
    @Published var healthConnected = false
    @Published var healthSyncing = false
    @Published var lastHealthSync: String?

    let api: APIClient

    private var band: BandManager?
    private var router: FrameRouter?
    private var syncQueue: SyncQueue?
    private var strap: StrapManager?

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
        startStrap()                                  // resume a paired chest strap (independent of the band)
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
        if dashboard == nil { dashboardPhase = .loading }
        do { dashboard = try await api.dashboard(); dashboardPhase = .loaded }
        catch {
            if case APIError.unauthorized = error { await logout() }
            else { dashboardPhase = dashboard == nil ? .failed : .loaded }
        }
    }

    func loadTrends() async {
        if let t = try? await api.trends(metric: "hrv", days: 30) { hrvTrend = t.points.map(\.value) }
    }

    // MARK: band

    /// Pair a fresh band: server mints a one-time secret, then bind to the CLOSEST band over BLE
    /// Manual "Sync now": pull the band's overnight log on demand (free accounts have no background
    /// BLE, so you open the app in the morning and tap this). Connects if needed, then asks the band to
    /// flush; the live counters show frames arriving. We clear the spinner after a short window.
    func syncBand() {
        startBandIfPaired()        // ensure the BandManager exists
        bandIdle = false
        band?.syncNow()
        bandSyncing = true
        Task {
            try? await Task.sleep(nanoseconds: 8_000_000_000)
            bandSyncing = false
            lastBandSyncAt = Date()
        }
    }

    // MARK: burst-sync connection policy
    // The band runs heavy (continuous + streaming) only while we hold a live BLE link. So we hold it
    // when it's worth it — app foreground (workouts, checking stats) — and RELEASE it when the app
    // sits idle in the background, dropping the band into its low-power offline duty-cycle. The
    // firmware auto-flushes its buffered trend on every reconnect, so opening the app catches up the
    // whole day with no held connection. This is what makes real all-day wear viable on the band.

    private var connectionReleaseTask: Task<Void, Never>?

    /// App came forward (or a workout/sync) → hold a live link.
    func holdConnection() {
        connectionReleaseTask?.cancel(); connectionReleaseTask = nil
        startBandIfPaired()
        bandIdle = false
        band?.setDesiredConnection(true)
    }

    /// App went to the background → after a short grace (survives quick app switches), release the link
    /// so the band starts saving battery. Cancelled if we come forward again first.
    func releaseConnectionAfterGrace() {
        guard band != nil else { return }
        connectionReleaseTask?.cancel()
        connectionReleaseTask = Task { [weak self] in
            try? await Task.sleep(nanoseconds: 120_000_000_000)   // 2-min grace
            guard let self, !Task.isCancelled else { return }
            self.band?.setDesiredConnection(false)
            self.bandIdle = true
        }
    }


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

    /// "Reconnect" — kick a fresh BLE connection attempt when we're paired but stuck on Searching.
    /// Cheap: no new server creds, just re-establishes the link to the band we're already bound to.
    func reconnectBand() {
        startBandIfPaired()
        error = nil
        band?.reconnectKick()
    }

    /// "Forget band & re-pair" — the clean reset. Drops the BLE binding so the UI falls back to the
    /// pairing flow, then immediately reopens the picker. Use when Reconnect won't take (wrong band,
    /// swapped hardware, binding gone bad). Re-pairing mints fresh creds for whichever band you pick.
    func repairBand() async {
        band?.unbind()
        bandBound = false
        bandConnected = false
        pairCandidates = []
        liveBpm = nil
        error = nil
        await pairBand()
    }

    // MARK: chest strap (workout HR)

    /// Create the strap manager once (independent of the band / server creds). Idempotent.
    private func startStrap() {
        guard strap == nil else { return }
        let s = StrapManager()
        s.onConnectionChange = { [weak self] up in Task { @MainActor in
            self?.strapConnected = up
            if !up { self?.strapBpm = nil }
        } }
        s.onBattery = { [weak self] pct in Task { @MainActor in self?.strapBattery = pct } }
        s.onPaired = { [weak self] ok in Task { @MainActor in
            self?.strapPairing = false
            self?.strapCandidates = []
            self?.strapPaired = ok
            if !ok { self?.error = "Couldn't find a heart-rate strap. Wet the electrodes, put it on, and try again." }
        } }
        s.onCandidates = { [weak self] list in Task { @MainActor in self?.strapCandidates = list } }
        s.onHr = { [weak self] bpm, rr, t in Task { @MainActor in self?.ingestStrapHr(bpm, rr, t) } }
        strap = s
        strapPaired = s.isBound
    }

    /// A live strap reading: drive the HR display and, during a workout, feed the SAME assembler the
    /// band HR feeds (so the sealed workout gets reference-grade, chest-strap-tagged HR).
    private func ingestStrapHr(_ bpm: UInt8, _ rr: [Double], _ t: UInt64) {
        strapBpm = Int(bpm)
        liveBpm = Int(bpm)                                  // strap wins the live readout when present
        if runActive {
            runLiveBpm = Int(bpm)
            runMaxBpm = max(runMaxBpm, Int(bpm))
            runLastSignal = Date()                          // a strap-only treadmill run stays alive
        }
        router?.ingestStrapHr(bpm: bpm, rr: rr, t: t)       // rr (may be empty) → in-workout HRV at seal
    }

    var isStrapPaired: Bool { strap?.isBound ?? false }

    func startStrapPairing() {
        startStrap()
        strapCandidates = []
        strapPairing = true
        strap?.startPairing()
    }

    func bindStrap(_ id: UUID) {
        Haptic.success()
        strap?.bind(to: id)
    }

    func cancelStrapPairing() {
        strap?.cancelPairing()
        strapPairing = false
        strapCandidates = []
    }

    func forgetStrap() {
        strap?.unbind()
        strapPaired = false
        strapConnected = false
        strapBpm = nil
        strapBattery = nil
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
        router.onGps = { [weak self] fix in Task { @MainActor in self?.ingestLiveGps(fix) } }
        router.onHr = { [weak self] hr in Task { @MainActor in self?.ingestLiveHr(hr) } }
        router.onSteps = { [weak self] s in
            Task { @MainActor in
                // Live "steps today" from the band. Trust it only if it's for the current local day.
                if s.date == Self.localDayString() { self?.bandStepsToday = s.steps; self?.bandStepsAt = Date() }
            }
        }
        let band = BandManager(router: router)
        band.onConnectionChange = { [weak self] up in Task { @MainActor in self?.bandConnected = up } }
        band.onBattery = { [weak self] pct in Task { @MainActor in self?.bandBattery = pct } }
        band.onPaired = { [weak self] ok in
            Task { @MainActor in
                self?.pairing = false
                self?.pairCandidates = []
                self?.bandBound = ok
                if !ok { self?.error = "Couldn't find your band. Put it in pairing mode (swipe to the Status face and click the button) and try again." }
            }
        }
        band.onCandidates = { [weak self] list in
            Task { @MainActor in self?.pairCandidates = list }
        }
        self.syncQueue = queue; self.router = router; self.band = band

        // Phone-side GPS for runs (the band has no GPS chip). Fixes feed the live tracker AND, while a
        // run is active, the same workout assembler the band's GPS would have → the sealed run gets a
        // real route + distance with no server change.
        locator.onAuth = { [weak self] st, acc in
            Task { @MainActor in
                guard let self else { return }
                self.locationDenied = (st == .denied || st == .restricted)
                let authorized = (st == .authorizedWhenInUse || st == .authorizedAlways)
                self.gpsPreciseOff = authorized && (acc == .reducedAccuracy)
                // If a test is waiting on a fix that can never qualify, fail it now — don't make the user
                // stare at "Locating…" for 30 s when the cause (no permission / Precise off) is known.
                if self.gpsTestActive {
                    if self.locationDenied { self.finishGpsTest(.denied) }
                    else if self.gpsPreciseOff { self.finishGpsTest(.preciseOff) }
                }
            }
        }
        locator.onFix = { [weak self] loc in
            Task { @MainActor in self?.handlePhoneFix(loc) }
        }
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

    /// Per-section fetch state, so the UI can show a skeleton on first load and an inline retry on
    /// failure — instead of flashing a false "no data" empty state before the request even returns.
    enum LoadPhase { case idle, loading, loaded, failed }

    @Published var sleepDetail: SleepResponse?
    @Published var cycle: CycleResponse?
    @Published var hrDay: HrResponse?
    @Published var sleepPhase: LoadPhase = .idle
    @Published var cyclePhase: LoadPhase = .idle
    @Published var hrPhase: LoadPhase = .idle
    /// Whether to show the women's Cycle segment (server gates on sex/cycle config).
    var showsCycle: Bool { cycle?.available == true }

    // A failed fetch only counts as "failed" when there's nothing to show yet; if we already have
    // data, a dropped poll keeps the last-good view rather than yanking it for an error row.
    func loadSleepDetail() async {
        if sleepDetail == nil { sleepPhase = .loading }
        do { sleepDetail = try await api.sleepDetail(); sleepPhase = .loaded }
        catch { sleepPhase = sleepDetail == nil ? .failed : .loaded }
    }
    func loadCycle() async {
        if cycle == nil { cyclePhase = .loading }
        do { cycle = try await api.cycle(); cyclePhase = .loaded }
        catch { cyclePhase = cycle == nil ? .failed : .loaded }
    }
    func loadHr() async {
        if hrDay == nil { hrPhase = .loading }
        do { hrDay = try await api.hr(); hrPhase = .loaded }
        catch { hrPhase = hrDay == nil ? .failed : .loaded }
    }

    func logPeriod(_ date: Date) async {
        try? await api.logCyclePeriod(date: Self.ymd(date))
        await loadCycle()
    }
    func logCycleDay(flow: String?, symptoms: [String]) async {
        try? await api.logCycleDay(date: Self.ymd(Date()), flow: flow, symptoms: symptoms)
        await loadCycle()
    }
    static func ymd(_ d: Date) -> String { let f = DateFormatter(); f.dateFormat = "yyyy-MM-dd"; return f.string(from: d) }

    func cycleCalendar(from: Date, days: Int) async -> [CycleCalendarResponse.Day] {
        (try? await api.cycleCalendar(from: Self.ymd(from), days: days))?.days ?? []
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

    // MARK: what you take (the stack)

    /// Fold a returned payload into our published snapshot (mutations echo the refreshed state).
    private func applyStack(_ r: StackResponse) {
        if let t = r.today { stackToday = t }
        if let i = r.items { stackItems = i }
        if let f = r.flags { stackFlags = f }
        if let d = r.disclaimer { stackDisclaimer = d }
    }

    func loadStack() async {
        if let r = try? await api.stack() { applyStack(r) }
    }

    /// One tap = taken. Creates an intake event; the payload comes back with the row already checked.
    func markStackTaken(_ item: StackTodayItem) async {
        Haptic.success()
        if let r = try? await api.logStackIntake(itemID: item.id, status: "taken", slot: item.slot) { applyStack(r) }
    }

    func skipStackDose(_ item: StackTodayItem, note: String? = nil) async {
        Haptic.soft()
        if let r = try? await api.logStackIntake(itemID: item.id, status: "skipped", slot: item.slot, notes: note) { applyStack(r) }
    }

    func undoStackIntake(_ eventID: Int) async {
        Haptic.tap()
        if let r = try? await api.deleteStackIntake(eventID) { applyStack(r) }
    }

    /// Log a one-off dose by name (not in the protocol) — `POST /api/me/stack/intake`.
    func quickLogIntake(_ fields: [String: Any]) async {
        if let r = try? await api.logQuickIntake(fields) { applyStack(r) }
    }

    func searchStack(_ q: String) async {
        let query = q.trimmingCharacters(in: .whitespaces)
        guard query.count >= 2 else { stackSearchResults = []; return }
        stackSearching = true; defer { stackSearching = false }
        if let r = try? await api.stackSearch(query) { stackSearchResults = r.results }
    }

    func scanStack(_ imageData: Data, mode: String) async {
        stackScanning = true; defer { stackScanning = false }
        do { stackScan = try await api.stackScan(imageData, mode: mode) }
        catch { self.error = (error as? APIError)?.errorDescription ?? error.localizedDescription }
    }

    @discardableResult
    func addStackItem(_ fields: [String: Any]) async -> Bool {
        stackBusy = true; defer { stackBusy = false }
        do { applyStack(try await api.addStackItem(fields)); return true }
        catch { self.error = (error as? APIError)?.errorDescription ?? error.localizedDescription; return false }
    }

    func updateStackItem(_ id: Int, fields: [String: Any]) async {
        do { applyStack(try await api.updateStackItem(id, fields: fields)) }
        catch { self.error = (error as? APIError)?.errorDescription ?? error.localizedDescription }
    }

    func deleteStackItem(_ id: Int) async {
        do { applyStack(try await api.deleteStackItem(id)) }
        catch { self.error = (error as? APIError)?.errorDescription ?? error.localizedDescription }
    }

    // MARK: community (opt-in social)

    @Published var communitySettings: CommunitySettings?
    @Published var feed: [ActivityCard] = []
    @Published var feedPhase: LoadPhase = .idle
    @Published var board: LeaderboardResponse?
    @Published var boardPhase: LoadPhase = .idle
    @Published var boardMetric = "effort"
    @Published var boardWindow = "week"
    @Published var followRequests: [FollowRequest] = []
    @Published var achievements: [Achievement] = []
    @Published var recap: CommunityRecap?

    var communityEnabled: Bool { communitySettings?.community_enabled == true }

    func loadCommunitySettings() async { communitySettings = try? await api.communitySettings() }

    @discardableResult
    func updateCommunity(_ fields: [String: Any]) async -> Bool {
        do { communitySettings = try await api.updateCommunitySettings(fields); return true }
        catch { self.error = (error as? APIError)?.errorDescription ?? error.localizedDescription; return false }
    }

    @discardableResult
    func uploadAvatar(_ data: Data) async -> Bool {
        do { communitySettings = try await api.uploadCommunityAvatar(data); return true }
        catch { self.error = (error as? APIError)?.errorDescription ?? error.localizedDescription; return false }
    }

    func loadFeed() async {
        if feed.isEmpty { feedPhase = .loading }
        do { feed = try await api.communityFeed().items; feedPhase = .loaded }
        catch { feedPhase = feed.isEmpty ? .failed : .loaded }
    }

    func loadBoard() async {
        if board == nil { boardPhase = .loading }
        do { board = try await api.leaderboard(metric: boardMetric, window: boardWindow); boardPhase = .loaded }
        catch { boardPhase = board == nil ? .failed : .loaded }
    }

    func setBoard(metric: String? = nil, window: String? = nil) async {
        if let metric { boardMetric = metric }
        if let window { boardWindow = window }
        board = nil                       // force the skeleton on a dimension switch
        await loadBoard()
    }

    func loadFollowRequests() async { followRequests = (try? await api.followRequests()) ?? [] }
    func loadAchievements() async { achievements = (try? await api.achievements()) ?? [] }
    func loadRecap() async { recap = try? await api.communityRecap() }

    func accept(_ r: FollowRequest) async {
        Haptic.success()
        try? await api.acceptRequest(r.follow_id)
        await loadFollowRequests(); await loadFeed()
    }

    func decline(_ r: FollowRequest) async {
        try? await api.declineRequest(r.follow_id)
        await loadFollowRequests()
    }

    /// Optimistic kudos toggle on a feed card — flips instantly, reconciles with the server, reverts on failure.
    func toggleKudos(_ card: ActivityCard) async {
        guard let i = feed.firstIndex(where: { $0.id == card.id }) else { return }
        let want = !feed[i].did_kudos
        Haptic.tap()
        feed[i].did_kudos = want
        feed[i].kudos_count = max(0, feed[i].kudos_count + (want ? 1 : -1))
        do {
            let st = want ? try await api.kudos(card.id) : try await api.unkudos(card.id)
            if let j = feed.firstIndex(where: { $0.id == card.id }) {
                feed[j].did_kudos = st.did_kudos; feed[j].kudos_count = st.kudos_count
            }
        } catch {
            if let j = feed.firstIndex(where: { $0.id == card.id }) {
                feed[j].did_kudos = card.did_kudos; feed[j].kudos_count = card.kudos_count
            }
        }
    }

    // MARK: live run (the band streams a GPS run → watch it tracking in real time)

    @Published var runActive = false
    @Published var runDistanceKm = 0.0
    @Published var runElapsedSec = 0
    @Published var runPaceSecPerKm = 0
    @Published var runLiveBpm: Int?
    @Published var runMaxBpm = 0
    @Published var runTrack: [CGPoint] = []        // streamed coords for the live trace (x=lon, y=lat)
    @Published var showLiveRunSheet = false        // drives the full-screen live tracker (app-wide)
    private var runStartedAt: Date?
    private var runLastLat: Double?
    private var runLastLon: Double?
    private var runLastSignal: Date?
    private var runSawSport1 = false               // we've seen a sport-tagged frame this run (so a fall back to 0 = ended)
    private var runSportLostAt: Date?              // when the sport tag first fell to 0 this run (end debounce)
    private var lastSportWas1 = false              // the previous HR frame's sport tag — so we start on the RISING edge only
    private var runTicker: Task<Void, Never>?
    private let runEndGapSec: TimeInterval = 90    // fallback only: frames stop entirely (disconnect) this long ⇒ end

    var runHasGps: Bool { !runTrack.isEmpty }

    // MARK: GPS diagnostics — every phone fix updates these (independent of a run), powering the GPS test.
    @Published var gpsHasFix = false
    @Published var gpsLastLat: Double?
    @Published var gpsLastLon: Double?
    @Published var gpsLastFrameAt: Date?
    @Published var gpsAccuracyM: Double?           // horizontal accuracy of the last phone fix (metres)
    @Published var locationDenied = false          // location permission denied/restricted → UI explains
    @Published var gpsPreciseOff = false           // authorized but "Precise Location" is OFF → fixes too coarse
    @Published var gpsTestActive = false           // a user-initiated GPS self-test window is running
    @Published var gpsTestProgress = 0             // good (run-grade) fixes seen so far this test
    @Published var gpsReadiness: GpsReadiness?     // last test verdict (nil = never tested)
    private var gpsTestTimer: Task<Void, Never>?
    private var gpsTestBestAccuracy: Double?        // best accuracy seen this test (for the weak-signal report)
    private static let gpsTestRequiredFixes = 3     // a run needs a STREAM of fixes — prove 3 good ones land
    private let locator = RunLocationTracker()      // phone GPS (the band has none)

    /// The outcome of the in-app GPS test: does the phone actually meet a real run's conditions, right now?
    /// A run needs (1) location permission, (2) Precise Location ON, and (3) a stream of fixes accurate
    /// enough to survive the run's ≤50 m gate. The test checks all three and reports one clear verdict.
    enum GpsReadiness: Equatable {
        case ready(accuracyM: Double)   // ✓ run will track — got `requiredFixes` run-grade fixes
        case denied                     // location permission off → Settings
        case preciseOff                 // permission on but Precise Location off → fixes too coarse for a run
        case weakSignal(bestM: Double?) // permission + precise OK, but no run-grade fix in the window (indoors?)
    }

    // MARK: live steps — the band's persistent day total, streamed ~every 15 s while connected.
    @Published var bandStepsToday = 0
    @Published var bandStepsAt: Date?              // when we last heard a band step total (freshness)

    /// Local YYYY-MM-DD, matching the band's T8 date so we only trust today's live count.
    static func localDayString() -> String {
        let f = DateFormatter(); f.dateFormat = "yyyy-MM-dd"; f.calendar = .current; f.timeZone = .current
        return f.string(from: Date())
    }

    /// A live GPS fix during a run → accumulate distance (haversine) + extend the trace.
    private func ingestLiveGps(_ fix: GpsFix) {
        // Record status for the GPS test FIRST (a fix updates the test screen even outside a run).
        gpsLastFrameAt = Date()
        gpsHasFix = fix.lat != nil && fix.lon != nil
        guard let lat = fix.lat, let lon = fix.lon else { return }   // below here needs a real position fix
        gpsLastLat = lat; gpsLastLon = lon
        // GPS only EXTENDS a run; it never STARTS one. A run is defined by the workout sport tag (below),
        // so a bare GPS test (no workout) shows your location without ever popping the run tracker.
        guard runActive else { return }
        runLastSignal = Date()
        if let la = runLastLat, let lo = runLastLon {
            let d = Self.haversineM(la, lo, lat, lon)
            if d.isFinite && d < 200 { runDistanceKm += d / 1000 }   // drop GPS teleports
        }
        runLastLat = lat; runLastLon = lon
        runTrack.append(CGPoint(x: lon, y: lat))
        if runTrack.count > 3000 { runTrack.removeFirst(runTrack.count - 3000) }
        recomputePace()
    }

    /// A sport-tagged HR reading (sport==1) means a workout is live; drive the live bpm + start gate.
    /// When the watch ENDS the workout it keeps streaming HR but the sport tag falls back to 0 — that
    /// transition is our prompt to close the live run immediately (instead of waiting out the 90s gap).
    private func ingestLiveHr(_ hr: HrReading) {
        if hr.sport == 1 {
            // Start ONLY on the rising edge (a workout just began). Starting on the level would re-open
            // the run on the very next frame after you end it — while the band is still mid-workout and
            // streaming sport==1 — which looped it back open forever. Now an ended run stays ended until
            // the workout actually stops (sport→0) and a new one begins.
            if !lastSportWas1 { startRunIfNeeded() }
            lastSportWas1 = true
            if runActive { runSawSport1 = true; runSportLostAt = nil; runLastSignal = Date() }
        } else {
            lastSportWas1 = false
            if runActive && runSawSport1 {
                // Sport tag fell away. Confirm it's SUSTAINED (a few seconds) before ending, so a single
                // stray sport==0 reading can't end-and-restart the run.
                if let lost = runSportLostAt {
                    if Date().timeIntervalSince(lost) > 4 { endRun(notifyBand: false); return }   // watch already ended
                } else {
                    runSportLostAt = Date()
                }
            }
        }
        if runActive {
            runLiveBpm = Int(hr.bpm)
            runMaxBpm = max(runMaxBpm, Int(hr.bpm))
        }
    }

    private func startRunIfNeeded() {
        guard !runActive else { return }
        runActive = true
        runStartedAt = Date(); runLastSignal = Date()
        runDistanceKm = 0; runElapsedSec = 0; runPaceSecPerKm = 0
        runLastLat = nil; runLastLon = nil; runTrack = []; runMaxBpm = 0; runLiveBpm = nil
        runSawSport1 = false; runSportLostAt = nil
        showLiveRunSheet = true        // pop the live tracker the moment a run begins
        updateLocator()                // start phone GPS → route + distance for this run
        Haptic.success()
        runTicker?.cancel()
        runTicker = Task { @MainActor [weak self] in
            while true {
                try? await Task.sleep(nanoseconds: 1_000_000_000)
                guard let self, self.runActive else { break }
                if let s = self.runStartedAt { self.runElapsedSec = Int(Date().timeIntervalSince(s)) }
                self.recomputePace()
                if let last = self.runLastSignal, Date().timeIntervalSince(last) > self.runEndGapSec {
                    self.endRun()
                }
            }
        }
    }

    /// End the live run (the band stopped streaming sport frames, or the user dismissed it). The
    /// sealed run shows up in the runs list shortly after via the normal upload→seal path.
    /// End the live run. `notifyBand` sends the band a C0 so finishing in the app finishes on the watch
    /// too (the user tapped "End run"); pass false when the watch ALREADY ended it (sport→0) or in the
    /// simulator, so we don't echo a command back.
    func endRun(notifyBand: Bool = true) {
        guard runActive else { return }
        runActive = false
        runSawSport1 = false; runSportLostAt = nil
        runTicker?.cancel(); runTicker = nil
        showLiveRunSheet = false       // dismiss the live panel
        updateLocator()                // stop phone GPS unless a test is still using it
        if notifyBand { band?.endRunOnBand() }
    }

    /// Worst horizontal accuracy (m) we'll trust INTO THE SAVED ROUTE. Coarse cell/Wi-Fi fixes at run
    /// start (often 65–1400 m) and urban-canyon outliers zigzag the route and inflate distance, so we keep
    /// them out of the sealed/server route. The LIVE dot is shown regardless (you must see where you are
    /// right away) — this only gates what gets persisted. 50 m is generous (real GNSS outdoors is ~5–15 m).
    private static let maxFixAccuracyM = 50.0
    /// Reject an impossible jump from the last good fix (GPS spike) before it reaches the saved route.
    private static let maxFixJumpM = 200.0

    /// A phone GPS fix (during a run, or a GPS test). Two jobs, deliberately separated:
    ///   1. LIVE DISPLAY — show your real location the instant ANY valid fix lands, even while GPS is
    ///      still sharpening from a coarse warm-up fix. This is what the GPS test and the live map read;
    ///      gating it behind ≤50 m was the bug that left the user staring at a blank/placeholder map.
    ///   2. SAVED ROUTE — strict: only run-grade (≤50 m, non-teleport) fixes extend the persisted track,
    ///      accumulate distance, and feed the seal pipeline, so the saved run stays clean.
    private func handlePhoneFix(_ loc: CLLocation) {
        let acc = loc.horizontalAccuracy
        gpsAccuracyM = acc
        gpsLastFrameAt = Date()
        guard acc > 0 else { return }                 // invalid reading = no real position; ignore entirely
        let lat = loc.coordinate.latitude, lon = loc.coordinate.longitude

        // (1) LIVE — surface the dot immediately, however coarse. Refines as accuracy improves.
        gpsHasFix = true
        gpsLastLat = lat; gpsLastLon = lon

        // GPS TEST — every valid fix updates "best accuracy" so we can report it; only run-grade fixes
        // count toward "ready" (the exact bar the saved route uses), and we want a STREAM, not one lock.
        if gpsTestActive {
            gpsTestBestAccuracy = min(gpsTestBestAccuracy ?? acc, acc)
            if acc <= Self.maxFixAccuracyM {
                gpsTestProgress += 1
                if gpsTestProgress >= Self.gpsTestRequiredFixes { finishGpsTest(.ready(accuracyM: acc)) }
            }
        }

        // (2) SAVED ROUTE — only while a run is live. Any fix proves the phone's alive (keeps the run
        // open); only an accurate, non-teleport fix actually extends the route + distance.
        guard runActive else { return }
        runLastSignal = Date()
        guard acc <= Self.maxFixAccuracyM else { return }
        if let la = runLastLat, let lo = runLastLon,
           Self.haversineM(la, lo, lat, lon) > Self.maxFixJumpM { return }   // drop a GPS teleport spike
        if let la = runLastLat, let lo = runLastLon {
            let d = Self.haversineM(la, lo, lat, lon)
            if d.isFinite && d < Self.maxFixJumpM { runDistanceKm += d / 1000 }
        }
        runLastLat = lat; runLastLon = lon
        runTrack.append(CGPoint(x: lon, y: lat))
        if runTrack.count > 3000 { runTrack.removeFirst(runTrack.count - 3000) }
        recomputePace()
        let t = UInt64(max(0, loc.timestamp.timeIntervalSince1970) * 1000)
        router?.ingestPhoneGps(GpsFix(t: t, sats: 0, speedKmh: max(0, loc.speed) * 3.6,
                                      alt: loc.altitude, lat: lat, lon: lon))   // seal pipeline → saved route
    }

    /// Phone GPS runs only while a run or a GPS test is active (battery).
    private func updateLocator() {
        if runActive || gpsTestActive { locator.start() } else { locator.stop() }
    }

    /// Verify — right here, before you head out — that a real run will actually track. The band has no
    /// GPS, so runs are mapped by the phone; this checks the EXACT conditions a run needs: permission is
    /// granted, "Precise Location" is on, and a stream of fixes is landing accurate enough to survive the
    /// run's ≤50 m gate. It ends with one verdict (ready / denied / precise-off / weak signal) so you
    /// never burn a run discovering the phone couldn't see the sky.
    func startGpsTest() {
        gpsTestActive = true
        gpsReadiness = nil
        gpsTestProgress = 0; gpsTestBestAccuracy = nil
        gpsHasFix = false; gpsLastLat = nil; gpsLastLon = nil; gpsLastFrameAt = nil; gpsAccuracyM = nil
        Haptic.tap()
        updateLocator()
        // Reflect a known-bad permission state immediately (and re-check once the system answers a fresh
        // prompt — the onAuth handler short-circuits the test if permission/precise come back wrong).
        locator.emitAuth()
        gpsTestTimer?.cancel()
        gpsTestTimer = Task { @MainActor [weak self] in
            try? await Task.sleep(nanoseconds: 30_000_000_000)
            guard let self, self.gpsTestActive else { return }   // already finished (ready/denied/precise)
            self.finishGpsTest(.weakSignal(bestM: self.gpsTestBestAccuracy))
        }
    }

    /// Close the GPS test with a verdict, stop the locator (unless a run still needs it), and signal it.
    private func finishGpsTest(_ verdict: GpsReadiness) {
        guard gpsTestActive else { return }
        gpsTestTimer?.cancel(); gpsTestTimer = nil
        gpsTestActive = false
        gpsReadiness = verdict
        updateLocator()
        if case .ready = verdict { Haptic.success() } else { Haptic.warning() }
    }

    private func recomputePace() {
        runPaceSecPerKm = runDistanceKm > 0.02 ? Int(Double(runElapsedSec) / runDistanceKm) : 0
    }

    #if DEBUG
    /// Feed a synthetic GPS run through the SAME live-ingest path as the band, in real time, so the
    /// live-run UI can be exercised without a device. ~A loop near Golden Gate Park at ~5:30/km.
    func simulateLiveRun(seconds: Int = 90) {
        guard !runActive else { return }
        let lat0 = 37.7694, lon0 = -122.4862
        let mLat = 111_320.0, mLon = 111_320.0 * cos(lat0 * .pi / 180)
        let speed = 1000.0 / 330.0   // m/s ≈ 5:30/km
        Task { @MainActor [weak self] in
            for i in 0..<seconds {
                guard let self else { return }
                if i > 0 && !self.runActive { return }   // user ended the run → stop feeding
                let f = Double(i) / Double(seconds)
                let r = 220.0
                let th = 2 * Double.pi * f
                let x = r * sin(th), y = r * (cos(th) - 1)
                let lat = lat0 + y / mLat, lon = lon0 + x / mLon
                self.ingestLiveGps(GpsFix(t: UInt64(i) * 1000, sats: 9, speedKmh: speed * 3.6, alt: 40, lat: lat, lon: lon))
                self.ingestLiveHr(HrReading(t: UInt64(i) * 1000, bpm: UInt8(min(180, 120 + Int(40 * f))), conf: 95, sport: 1))
                try? await Task.sleep(nanoseconds: 1_000_000_000)
            }
            self?.endRun(notifyBand: false)
        }
    }
    #endif

    static func haversineM(_ la1: Double, _ lo1: Double, _ la2: Double, _ lo2: Double) -> Double {
        let toR = Double.pi / 180
        let dLa = (la2 - la1) * toR, dLo = (lo2 - lo1) * toR
        let a = sin(dLa / 2) * sin(dLa / 2) + cos(la1 * toR) * cos(la2 * toR) * sin(dLo / 2) * sin(dLo / 2)
        return 2 * 6_371_000 * asin(min(1, sqrt(a)))
    }

    private func deviceName() -> String {
        #if canImport(UIKit)
        return UIDevice.current.name
        #else
        return "iPhone"
        #endif
    }
}
