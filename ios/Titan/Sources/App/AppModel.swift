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

/// Radio/permission state for the band's Bluetooth, so the connection screen can show an actionable
/// card ("turn on Bluetooth" / "allow it in Settings") instead of a forever-"Searching…" dead end.
public enum BluetoothAvailability { case unknown, ok, off, denied, unsupported }

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
    @Published var bluetooth: BluetoothAvailability = .unknown   // radio/permission state, for an actionable card
    @Published var bandSyncFailed = false  // the last manual "Sync now" couldn't reach the band (no data arrived)
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
    @Published var mealLibrary: [MealTemplate] = []  // "Your meals" — remembered dishes for one-tap re-log
    @Published var relogging: Int?                   // template id currently being re-logged (row spinner)
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
    @Published var stress: StressResponse?
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
        // Rehydrate the signed-in user from the Keychain cache so a valid token means "logged in"
        // IMMEDIATELY on launch — even offline. Without this, `isLoggedIn` (== user != nil) stayed false
        // on every cold start because bootstrap() never set `user`, so the app showed the login screen
        // despite a perfectly good token. The token is validated in the background by bootstrap().
        if token != nil, let u = Self.cachedUser() {
            user = u
            onboarded = u.onboarded ?? true
        }
        // Seed the catch-up watermark to "now" on first launch, so installing the app never retro-pops
        // a historical workout — only ones that finish after this point get a catch-up summary.
        if UserDefaults.standard.object(forKey: Self.lastSeenWorkoutKey) == nil {
            UserDefaults.standard.set(Date(), forKey: Self.lastSeenWorkoutKey)
        }
        if token != nil { Task { await self.bootstrap() } }
    }

    // The most recent workout we've already surfaced to the user (a live end-summary OR a catch-up),
    // persisted, so a workout that finished OFFLINE (started in the background / phone left behind) and
    // synced from the band's ring later pops its summary exactly once — and an old or already-seen one
    // never re-pops.
    private static let lastSeenWorkoutKey = "titan.lastSeenWorkoutAt"
    private var lastSeenWorkoutAt: Date {
        get { UserDefaults.standard.object(forKey: Self.lastSeenWorkoutKey) as? Date ?? .distantPast }
        set { UserDefaults.standard.set(newValue, forKey: Self.lastSeenWorkoutKey) }
    }

    // The bedtime epoch (s) of the most recent NIGHT we've already surfaced, persisted — so a sleep that
    // synced from the band's ring in the morning (the normal offline case) pops its summary exactly once.
    private static let lastSeenSleepKey = "titan.lastSeenSleepEpoch"
    private var lastSeenSleepEpoch: Int {
        get { UserDefaults.standard.integer(forKey: Self.lastSeenSleepKey) }
        set { UserDefaults.standard.set(newValue, forKey: Self.lastSeenSleepKey) }
    }
    private var checkingSyncedSleep = false   // one poller at a time

    /// Restore a logged-in session (token already in Keychain): load data + resume band sync.
    func bootstrap() async {
        onboarded = (try? await api.onboardingStatus()) ?? true
        if let d = try? await api.dashboard() { dashboard = d }
        startBandIfPaired()
        bandBound = band?.isBound ?? false
        stepTracker.start()                           // count the phone's steps so we can mirror them to the band
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
            // Keep the cached user's onboarded flag current so a relaunch (even offline) doesn't flash the
            // onboarding screen again.
            if let u = user { setUser(AuthUser(id: u.id, name: u.name, email: u.email, onboarded: onboarded)) }
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
            setUser(res.user)                         // persists the user so the next launch is instant + offline-safe
            onboarded = res.user.onboarded ?? true
            await bootstrap()
        } catch { self.error = (error as? APIError)?.errorDescription ?? error.localizedDescription }
    }

    func logout() async {
        await api.logout()
        Keychain.delete(Keychain.userToken)
        Keychain.delete(Keychain.userProfile)
        api.token = nil
        user = nil; dashboard = nil
    }

    /// Set + persist the signed-in user (Keychain-cached JSON), so `init()` can restore it on the next
    /// cold launch without a network round-trip. The single place `user` is assigned on sign-in.
    func setUser(_ u: AuthUser) {
        user = u
        if let data = try? JSONEncoder().encode(u), let s = String(data: data, encoding: .utf8) {
            Keychain.set(s, for: Keychain.userProfile)
        }
    }

    /// The Keychain-cached user from a prior login (nil if none / undecodable).
    static func cachedUser() -> AuthUser? {
        guard let s = Keychain.get(Keychain.userProfile), let data = s.data(using: .utf8) else { return nil }
        return try? JSONDecoder().decode(AuthUser.self, from: data)
    }

    /// A 401 from a data endpoint may be transient (a race, a briefly-flaky gateway). Don't nuke a valid
    /// Keychain token + bounce to login on ONE — confirm with /api/me first. Only a CONFIRMED 401 there is
    /// a genuinely revoked token → real logout. A network/other failure is treated as transient (keep the
    /// session). This is what stops a stray 401 from silently logging the user out.
    func handleUnauthorized() async {
        do { try await api.verifyToken() }                       // token still good → keep the session
        catch APIError.unauthorized { await logout() }           // confirmed dead → real logout
        catch { /* transient / offline → keep the session */ }
    }

    func refresh() async {
        if dashboard == nil { dashboardPhase = .loading }
        do { dashboard = try await api.dashboard(); dashboardPhase = .loaded }
        catch {
            if case APIError.unauthorized = error { await handleUnauthorized() }
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
        bandSyncFailed = false
        band?.syncNow()
        bandSyncing = true
        // Honesty: only claim "synced" if data actually arrived. Snapshot the counters that advance on a
        // real frame/upload, then check them after the window — otherwise a Sync-now with Bluetooth off or
        // the band out of range would still stamp "Last synced just now" and the user would trust a no-op.
        let samplesBefore = syncedSamples
        let uploadedBefore = windowsUploaded
        Task {
            try? await Task.sleep(nanoseconds: 8_000_000_000)
            bandSyncing = false
            let gotData = bandConnected || syncedSamples > samplesBefore || windowsUploaded > uploadedBefore
            if gotData {
                lastBandSyncAt = Date()
            } else {
                bandSyncFailed = true
            }
        }
    }

    // MARK: live-trace UI gating (heat)
    // The band streams ~50 Hz PPG whenever connected; republishing the live waveform through this app-wide
    // model re-renders the whole UI. Only run that feed while a live trace is actually on screen. Tracked as
    // a SET of visible screens (not a counter): two screens show it (the Band screen + the Daily "Heart"
    // card), and SwiftUI onAppear/onDisappear aren't guaranteed balanced — a set is idempotent, so a stray
    // disappear from one screen can't switch the feed off while the other is still up (the counter bug that
    // left the Band screen showing "Waiting for signal…" with the band connected).
    private var liveScreens: Set<String> = []
    func liveSignalAppeared(_ id: String = "band") {
        liveScreens.insert(id)
        router?.setLiveUI(true)
    }
    func liveSignalDisappeared(_ id: String = "band") {
        liveScreens.remove(id)
        if liveScreens.isEmpty { router?.setLiveUI(false) }
    }

    /// Re-read Bluetooth availability (the connection screen opening) so the card is correct even if the
    /// one-shot didUpdateState fired before the callback was wired.
    func refreshBluetooth() { startBandIfPaired(); band?.refreshState() }

    // MARK: always-on connection policy (Whoop-style)
    // The band is the ONLY data pipeline, so we hold a persistent 24/7 BLE link — foreground AND
    // background, phone locked or not. There is no "release": burst-sync is retired. `holdConnection`
    // still runs on foreground because its clock-resync / step-push / catch-up polls are wanted when
    // the user opens the app; it just no longer TOGGLES the link (the link is always armed).

    private var connectionReleaseTask: Task<Void, Never>?

    /// App came forward (or a workout/sync) → make sure the (always-on) link is up AND pull the band's
    /// data now. If it briefly dropped while we were away, ensureConnected nudges a reconnect and the
    /// firmware auto-flushes on connect; if we're still connected, flushIfConnected forces a C3 so opening
    /// the app always pushes the latest steps + any buffered data to the server.
    func holdConnection() {
        connectionReleaseTask?.cancel(); connectionReleaseTask = nil
        startBandIfPaired()
        bandIdle = false
        band?.ensureConnected()
        band?.flushIfConnected()
        band?.syncClockIfConnected()   // re-push the phone clock so a drifted/un-synced band can't mis-time a workout
        pushStepsToBand()         // opening the app tops the watch's Steps face back up to the phone's count
        checkForSyncedWorkout()   // surface any workout that finished while we weren't watching
        checkForSyncedSleep()     // …and a night that sealed from the ring while we were away
    }

    /// App is going to the background → ship the trailing partial windows NOW so nothing waits in RAM.
    /// Under the always-on model a disconnect (the old only caller of flush) is rare, so without this the
    /// HR-trend tail could sit un-uploaded for up to a full FLUSH_AT window — and be lost if iOS jetsams
    /// the suspended app. `flush(live: true)` drains the HR trend + trailing PPG windows and, via
    /// `deferWorkoutFlush`, leaves a live run's window intact. The link itself stays up (always-on).
    func flushWindowsForBackground() {
        router?.flush(live: true)
    }

    /// Surface a workout that FINISHED while the app wasn't watching — started offline or in the
    /// background, then recovered from the band's ring on this sync (the phone never saw it live, so
    /// `endRun` never showed a summary). Polls the runs list for a sealed session newer than the last
    /// one we surfaced and recent enough to be worth a catch-up, then shows its server-enriched summary
    /// ONCE. No-op during a live run or while a summary is already open. The backlog assembler tags its
    /// windows `ended`, so the session seals within seconds of the sync rather than after the 10-min
    /// quiet rule. Verified end-to-end in the watch simulator ("offline-started lift").
    func checkForSyncedWorkout() {
        guard isLoggedIn, !runActive, workoutSummary == nil else { return }
        Task { @MainActor [weak self] in
            for attempt in 0..<6 {
                try? await Task.sleep(nanoseconds: attempt == 0 ? 1_500_000_000 : 3_000_000_000)
                guard let self, !self.runActive, self.workoutSummary == nil else { return }
                guard let runs = try? await self.api.runs(), let newest = runs.first,
                      let iso = newest.started_at, let started = ISO8601DateFormatter().date(from: iso)
                else { continue }
                // Meaningfully newer than the last one we surfaced (>2 min separates the live-shown one
                // from a genuinely new session), and recent enough to be worth popping.
                guard started.timeIntervalSince(self.lastSeenWorkoutAt) > 120 else { return }
                guard Date().timeIntervalSince(started) < 12 * 3600 else { self.lastSeenWorkoutAt = started; return }
                self.lastSeenWorkoutAt = started
                let detail = try? await self.api.runDetail(newest.id)
                var s = WorkoutSummaryState(
                    kind: newest.activity_type ?? "run", distanceKm: newest.distance_km ?? 0,
                    elapsedSec: (newest.duration_min ?? 0) * 60, maxBpm: detail?.max_hr ?? 0,
                    startedAt: started, hasGps: newest.has_route)
                s.detail = detail; s.loading = false; s.failed = (detail == nil)
                self.workoutSummary = s
                return
            }
        }
    }

    /// Surface a SLEEP that sealed while we weren't watching — the normal overnight case. The phone is
    /// disconnected while you sleep, so the WAKE marker (T9) only reaches the server when the app
    /// reconnects and drains the night in the morning; there's no live `TN s:0` then, so `endSleep`
    /// never fires. This polls for a freshly-sealed night and pops its summary ONCE. Mirrors
    /// `checkForSyncedWorkout`. No-op during a live sleep or while a summary is already open.
    func checkForSyncedSleep() {
        guard isLoggedIn, !sleeping, sleepSummary == nil, !checkingSyncedSleep else { return }
        checkingSyncedSleep = true
        Task { @MainActor [weak self] in
            defer { self?.checkingSyncedSleep = false }
            var poppedEpoch = 0   // the night we've surfaced this run (0 = none) — lets us keep polling IT to final
            // ~50 slow polls (~200s): catch the bulk-synced night AND cover the confirmed seal's worst case —
            // it can defer up to ~120s waiting for windows to process, then stage — so we keep the loading card
            // up and keep polling until the night FINALIZES.
            for attempt in 0..<50 {
                try? await Task.sleep(nanoseconds: attempt == 0 ? 2_000_000_000 : 4_000_000_000)
                guard let self, !self.sleeping else { return }
                if poppedEpoch > 0 { if self.sleepSummary == nil { return } }   // user dismissed → stop
                else if self.sleepSummary != nil { return }                    // another summary already open
                guard let resp = try? await self.api.sleepDetail(), let d = resp.detail,
                      (d.duration_min ?? 0) > 0, let bedEpoch = d.epoch_sec, bedEpoch > 0 else { continue }
                // A night NEWER than the last one we surfaced — OR the very night we already popped this run
                // (so we can follow it computing→final without the "already seen" guard bailing). CONTINUE
                // (don't return) when it isn't yet: poll #1 serves last night's row because tonight's seal
                // queues behind the window jobs — returning here killed the whole 50-poll budget ~2s in.
                guard bedEpoch > self.lastSeenSleepEpoch || bedEpoch == poppedEpoch else { continue }
                let wakeApprox = bedEpoch + (d.duration_min ?? 0) * 60
                guard Date().timeIntervalSince1970 - Double(wakeApprox) < 18 * 3600 else { self.lastSeenSleepEpoch = bedEpoch; return }
                // Surface the night the moment it appears — a LOADING card if it's still computing (open-early),
                // then refined to the full stats in place when it finalizes. Stamp lastSeenSleepEpoch on the POP
                // (not just finalize) so a card the user dismisses mid-compute stays dismissed and can't re-pop.
                var s = self.sleepSummary ?? SleepSummaryState(
                    bedtime: Date(timeIntervalSince1970: Double(bedEpoch)),
                    wake: Date(timeIntervalSince1970: Double(wakeApprox)),
                    inBedSec: (d.in_bed_min ?? d.duration_min ?? 0) * 60)
                s.detail = d; s.assess = resp.assess; s.loading = resp.isComputing
                self.sleepSummary = s
                self.lastSeenSleepEpoch = bedEpoch
                poppedEpoch = bedEpoch
                if !resp.isComputing {
                    self.sleepDetail = resp            // only a FINAL night feeds Daily/Recovery — no partial cards
                    await self.refresh()               // cascade: recovery + strain-target reflect the settled night
                    return
                }
            }
            // Exhausted while still computing — don't strand the spinner: mark it saved-but-pending (the final
            // night shows on the next foreground refresh / in Daily).
            if poppedEpoch > 0, var s = self?.sleepSummary, s.loading {
                s.loading = false
                s.failed = true
                self?.sleepSummary = s
            }
        }
    }

    /// RETIRED (Whoop-style always-on): backgrounding no longer drops the band. The persistent 24/7 link
    /// is the whole point — it keeps streaming while the app is backgrounded / the phone is locked. Kept
    /// as a no-op so the scenePhase wiring (and any other caller) compiles without change of intent.
    func releaseConnectionAfterGrace() { /* no-op — the link is held 24/7 */ }


    /// (hold yours to the phone) so two nearby bands never cross-connect.
    func pairBand() async {
        do {
            let p = try await api.pairDevice()
            Keychain.set(p.device_id, for: Keychain.deviceId)
            Keychain.set(p.secret, for: Keychain.deviceSecret)
            // RE-pair: the live stack's IngestClient is an immutable struct built with the OLD
            // device_id/secret — left in place, every upload after a re-pair 401s until the app is
            // force-restarted. Tear the stack down so startBandIfPaired rebuilds it on fresh creds.
            if band != nil {
                band?.unbind()
                band = nil; router = nil; syncQueue = nil
            }
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

    /// The pre-wired band pipeline (CoreBluetooth central → decode → signed upload), built ONCE — at launch
    /// by the AppDelegate so the restore-id central exists before iOS delivers `willRestoreState` (B1), or
    /// on first pair by AppModel — then adopted by AppModel, which attaches the live/UI callbacks.
    final class BandStack {
        let band: BandManager
        let router: FrameRouter
        let queue: SyncQueue
        init(band: BandManager, router: FrameRouter, queue: SyncQueue) {
            self.band = band; self.router = router; self.queue = queue
        }
    }

    /// Build the band pipeline from the Keychain creds — SYNCHRONOUS (no await), so the AppDelegate can call
    /// it at launch before any network. nil when unpaired. The queue's `onUploaded` forwards through the
    /// AppDelegate because AppModel may not exist yet when this runs at launch.
    static func makeBandStack() -> BandStack? {
        guard let id = Keychain.get(Keychain.deviceId),
              let secret = Keychain.get(Keychain.deviceSecret) else { return nil }
        let client = IngestClient(baseURL: baseURL, deviceId: id, secret: secret)
        // Durable on-disk queue so the overnight buffer survives an app kill / relaunch.
        let queue = SyncQueue(store: SqliteWindowStore(), client: client, onUploaded: { count in
            Task { @MainActor in AppDelegate.shared?.onWindowsUploaded?(count) }
        })
        let router = FrameRouter(queue: queue)
        let band = BandManager(router: router)
        return BandStack(band: band, router: router, queue: queue)
    }

    private func startBandIfPaired() {
        guard band == nil else { return }
        // Adopt the stack the AppDelegate already built at launch (its central holds the State-Restoration
        // restore id, so `willRestoreState` fires on a cold BLE relaunch) — or build one now if we paired
        // after launch. Either branch yields exactly ONE CBCentralManager with that restore id.
        guard let stack = AppDelegate.shared?.adoptBandStack() ?? Self.makeBandStack() else { return }
        let queue = stack.queue
        let router = stack.router
        // The SyncQueue's onUploaded is fixed at construction (before AppModel existed), so it forwards the
        // cumulative-uploaded count through the AppDelegate — pick it up here for the live UI counter.
        AppDelegate.shared?.onWindowsUploaded = { [weak self] count in self?.windowsUploaded = count }
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
                // Live "steps today" from the band. Accept today ±1 day so a timezone/midnight clock skew
                // between the watch and phone doesn't silently drop every frame (steps stuck at 0).
                if Self.bandDateIsCurrent(s.date) { self?.bandStepsToday = s.steps; self?.bandStepsAt = Date() }
                // …and reply with the phone's count (~15 s cadence) so the watch MAX-merges it back.
                self?.pushStepsToBand()
            }
        }
        router.onActivityKind = { [weak self] k in Task { @MainActor in self?.setWorkoutKind(k) } }
        // The watch finished the workout → end + seal it on the app, deterministically (no sport-tag guessing).
        router.onWorkoutEnd = { [weak self] in Task { @MainActor in self?.endRun(notifyBand: false) } }
        // The band drained its offline ring → a phone-free workout may have just sealed → catch-up summary.
        router.onBacklogSynced = { [weak self] in Task { @MainActor in self?.checkForSyncedWorkout(); self?.checkForSyncedSleep() } }
        // The watch's Sleep face: START → enter the live "Sleeping" state; WAKE → show the sleep summary.
        router.onSleepStart = { [weak self] in Task { @MainActor in self?.beginSleeping() } }
        router.onSleepEnd = { [weak self] bed, wake in Task { @MainActor in self?.endSleep(bedSec: bed, wakeSec: wake) } }
        // The confirmed wake T9 (live OR flushed from the ring) — clears a stuck Sleeping state + surfaces
        // the night even when the live TN s:0 never arrived (the offline-wake case).
        router.onSleepConfirmed = { [weak self] in Task { @MainActor in self?.clearLiveSleep(); self?.checkForSyncedSleep() } }
        // The confirmed workout envelope (TW, live OR flushed from the ring) — resolve a stuck live run
        // (endRun is idempotent) and surface the finished workout, even when the live TA:end never arrived
        // (a workout started/stopped out of BLE range). The workout counterpart of onSleepConfirmed.
        router.onWorkoutSession = { [weak self] in Task { @MainActor in self?.endRun(notifyBand: false); self?.checkForSyncedWorkout() } }
        let band = stack.band
        band.onConnectionChange = { [weak self] up in Task { @MainActor in
            self?.bandConnected = up
            self?.handleBandConnectionChange(up)
        } }
        // The CB link came up (pre-data) — cancel a pending end-run confirm the instant the link is
        // restored, so a transient drop mid-run can't wrongly end + seal the workout just because reconnect
        // + service discovery + the first frame didn't all land inside the 8 s confirm window (H6).
        band.onLinkUp = { [weak self] in Task { @MainActor in self?.handleLinkRestored() } }
        band.onBattery = { [weak self] pct in Task { @MainActor in self?.bandBattery = pct } }
        band.onState = { [weak self] a in Task { @MainActor in self?.bluetooth = a } }
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
        // A fresh router defaults its live-UI gate OFF; re-apply the current on-screen state so the live
        // feed works immediately if a live screen is already open (e.g. the router was built/rebuilt after
        // the Band screen appeared, or after a re-pair).
        router.setLiveUI(!liveScreens.isEmpty)

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
    func loadStress() async { if let s = try? await api.stress() { stress = s } }
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

    /// The day the Fuel tab is showing (local). Today by default; the pager moves it back/forward.
    @Published var fuelDate = Calendar.current.startOfDay(for: Date())

    private var isFuelToday: Bool { Calendar.current.isDateInToday(fuelDate) }

    private static let ymd: DateFormatter = {
        let f = DateFormatter(); f.dateFormat = "yyyy-MM-dd"; f.calendar = .current; return f
    }()

    func loadNutrition() async {
        // Pass the date only for a past day — today stays nil so the card keeps its "next meal" + title.
        let date = isFuelToday ? nil : Self.ymd.string(from: fuelDate)
        do { nutrition = try await api.nutritionToday(date: date) }
        catch { if case APIError.unauthorized = error { await handleUnauthorized() } }
        await loadMealLibrary()
    }

    /// Fuel history pager: step the viewed day and reload. Forward is capped at today.
    func stepFuelDay(_ days: Int) async {
        let cal = Calendar.current
        let next = cal.startOfDay(for: cal.date(byAdding: .day, value: days, to: fuelDate) ?? fuelDate)
        guard next <= cal.startOfDay(for: Date()) else { return }   // never past today
        fuelDate = next
        Haptic.tap()
        await loadNutrition()
    }

    var canFuelGoForward: Bool { !isFuelToday }

    /// Copy the viewed PAST day's meals to today, then jump to today showing the refreshed totals (2.3).
    func copyCurrentFuelDay() async {
        guard !isFuelToday else { return }
        let date = Self.ymd.string(from: fuelDate)
        do {
            let today = try await api.copyDay(date: date)
            fuelDate = Calendar.current.startOfDay(for: Date())   // jump to today (the copy target)
            nutrition = today
            Haptic.success()
            await loadMealLibrary()
        } catch { if case APIError.unauthorized = error { await handleUnauthorized() } else { self.error = (error as? APIError)?.errorDescription ?? error.localizedDescription } }
    }

    /// "Your meals" — the remembered dishes, ranked. Best-effort; a failure just leaves the last list.
    func loadMealLibrary() async {
        if let lib = try? await api.mealLibrary() { mealLibrary = lib.meals }
    }

    /// One-tap re-log a remembered meal (optionally scaled). No camera, no AI — it just lands in today's
    /// meals and updates the rings. Mirrors the memory back so "your meals" re-ranks immediately.
    func relogMeal(_ template: MealTemplate, portion: Double = 1.0) async {
        relogging = template.id; defer { relogging = nil }
        do {
            _ = try await api.relogMeal(template.id, portion: portion)
            Haptic.success()
            await loadNutrition()
        } catch { self.error = (error as? APIError)?.errorDescription ?? error.localizedDescription }
    }

    func toggleFavoriteMeal(_ template: MealTemplate) async {
        do { _ = try await api.favoriteMealTemplate(template.id, favorite: !template.favorite); await loadMealLibrary() }
        catch { self.error = (error as? APIError)?.errorDescription ?? error.localizedDescription }
    }

    func forgetMeal(_ template: MealTemplate) async {
        do { try await api.forgetMealTemplate(template.id); await loadMealLibrary() }
        catch { self.error = (error as? APIError)?.errorDescription ?? error.localizedDescription }
    }

    /// Per-section fetch state, so the UI can show a skeleton on first load and an inline retry on
    /// failure — instead of flashing a false "no data" empty state before the request even returns.
    enum LoadPhase { case idle, loading, loaded, failed }

    @Published var sleepDetail: SleepResponse?
    @Published var strainDetail: StrainResponse?
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

    @Published var longevity: LongevityPage?
    func loadLongevity() async {
        longevity = try? await api.longevity()
    }

    @Published var glucose: GlucoseDay?
    func loadGlucose() async { glucose = try? await api.glucose() }
    func connectGlucose(provider: String, nightscoutUrl: String?, token: String?) async -> Bool {
        do { glucose = try await api.connectGlucose(provider: provider, nightscoutUrl: nightscoutUrl, token: token); Haptic.success(); return true }
        catch { self.error = (error as? APIError)?.errorDescription ?? error.localizedDescription; return false }
    }
    func loadCycle() async {
        if cycle == nil { cyclePhase = .loading }
        do { cycle = try await api.cycle(); cyclePhase = .loaded }
        catch { cyclePhase = cycle == nil ? .failed : .loaded }
    }
    @Published var strainPhase: LoadPhase = .idle
    func loadStrain() async {
        if strainDetail == nil { strainPhase = .loading }
        do { strainDetail = try await api.strain(); strainPhase = .loaded }
        catch { strainPhase = strainDetail == nil ? .failed : .loaded }
    }

    @Published var overview: OverviewResponse?
    @Published var overviewDays = 30
    func loadOverview() async { overview = try? await api.overview(days: overviewDays) }
    func setOverviewDays(_ d: Int) async {
        guard d != overviewDays else { return }
        overviewDays = d; overview = nil; await loadOverview()
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

    /// A scanned barcode → look the product up (Open Food Facts, cached) → show the confirm draft (or a
    /// not-found message) through the SAME result sheet as a photo scan.
    func scanBarcode(_ code: String) async {
        scanning = true; defer { scanning = false }
        do {
            scanResult = try await api.scanBarcode(code)
            await loadNutrition()
        } catch { self.error = (error as? APIError)?.errorDescription ?? error.localizedDescription }
    }

    /// Confirm a scanned meal draft (with the amount the user set) → log it, close the sheet, refresh.
    /// Manual quick-add from the Fuel tab — log a meal without a photo/barcode. Returns success so the
    /// sheet can close only on a good save.
    func addMeal(name: String, calories: Int, protein: Double?, carbs: Double?, fat: Double?, eatenAt: Date?) async -> Bool {
        do {
            _ = try await api.storeMeal(name: name, calories: calories, protein: protein, carbs: carbs, fat: fat, eatenAt: eatenAt)
            Haptic.success()
            await loadNutrition()
            return true
        } catch { self.error = (error as? APIError)?.errorDescription ?? error.localizedDescription; return false }
    }

    // MARK: Native food search (3.1)
    @Published var foodResults: [FoodSearchResult] = []
    @Published var foodSearching = false
    private var foodSearchToken = 0

    /// Only the latest query's results are published (stale responses are dropped). Every call bumps the
    /// token FIRST — including the clear/short-query path — so an in-flight request can't repopulate a
    /// field the user has since cleared or shortened.
    func searchFood(_ q: String) async {
        foodSearchToken += 1
        let token = foodSearchToken
        let query = q.trimmingCharacters(in: .whitespaces)
        guard query.count >= 2 else { foodResults = []; foodSearching = false; return }
        foodSearching = true
        do {
            let results = try await api.searchFood(query)
            guard token == foodSearchToken else { return }   // a newer search superseded this one
            foodResults = results
        } catch {
            if token == foodSearchToken { foodResults = [] }
        }
        if token == foodSearchToken { foodSearching = false }
    }

    /// Log a searched food at the chosen amount (macros already scaled by the caller).
    func logFood(name: String, calories: Int, protein: Double, carbs: Double, fat: Double, fiber: Double?) async -> Bool {
        do {
            _ = try await api.storeMeal(name: name, calories: calories, protein: protein, carbs: carbs, fat: fat, fiber: fiber, eatenAt: nil)
            Haptic.success()
            await loadNutrition()
            return true
        } catch { self.error = (error as? APIError)?.errorDescription ?? error.localizedDescription; return false }
    }

    func confirmScannedMeal(name: String, calories: Int, protein: Double, carbs: Double, fat: Double, fiber: Double? = nil, photoPath: String?, source: String? = nil, lineItems: [[String: Any]]? = nil) async {
        do {
            _ = try await api.confirmMeal(name: name, calories: calories, protein: protein, carbs: carbs, fat: fat, fiber: fiber, photoPath: photoPath, source: source, lineItems: lineItems)
            Haptic.success()
            scanResult = nil
            await loadNutrition()
        } catch { self.error = (error as? APIError)?.errorDescription ?? error.localizedDescription }
    }

    func updateMeal(_ id: Int, name: String, calories: Int, protein: Double, carbs: Double, fat: Double, eatenAt: Date? = nil, mealType: String? = nil) async {
        do {
            var fields: [String: Any] = [
                "name": name, "calories": calories, "protein_g": protein, "carbs_g": carbs, "fat_g": fat,
            ]
            if let eatenAt { fields["eaten_at"] = ISO8601DateFormatter().string(from: eatenAt) }
            if let mealType { fields["meal_type"] = mealType }   // user re-files the meal's group (3.5)
            _ = try await api.updateMeal(id, fields: fields)
            await loadNutrition()
        } catch { self.error = (error as? APIError)?.errorDescription ?? error.localizedDescription }
    }

    func deleteMeal(_ id: Int) async {
        do { try await api.deleteMeal(id); await loadNutrition() }
        catch { self.error = (error as? APIError)?.errorDescription ?? error.localizedDescription }
    }

    func loadTargets() async {
        do { targets = try await api.targets() }
        catch { if case APIError.unauthorized = error { await handleUnauthorized() } }
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
        catch { if case APIError.unauthorized = error { await handleUnauthorized() } }
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
    /// The active (or just-finished) workout's kind, set from the band's `TA:` frame. "run" = the
    /// running tab (GPS, route, distance); "strength" = the heart-rate tab (lifting, no GPS). Default
    /// "run" preserves the historical behavior for an auto-started/unhinted workout. [[titan-strava-runs]]
    @Published var workoutKind = "run"
    /// A lift session: no GPS, no map/distance — the live screen + summary headline HR/zones/VO₂max.
    var isLift: Bool { workoutKind == "strength" || workoutKind == "lift" }
    @Published var runDistanceKm = 0.0
    @Published var runElapsedSec = 0
    @Published var runPaceSecPerKm = 0
    @Published var runLiveBpm: Int?
    @Published var runMaxBpm = 0
    @Published var runTrack: [CGPoint] = []        // streamed coords for the live trace (x=lon, y=lat)
    @Published var showLiveRunSheet = false        // drives the full-screen live tracker (app-wide)
    @Published var workoutSummary: WorkoutSummaryState?   // set on end → shows the post-workout summary sheet
    @Published var sleeping = false                       // watch Sleep face is running → live "Sleeping" state
    @Published var sleepStartedAt: Date?                  // when the night began → drives the live timer
    @Published var sleepSummary: SleepSummaryState?       // set on WAKE → shows the post-sleep summary sheet

    /// Enter the live "Sleeping" state (watch tapped START). Records the start so the app can show a live
    /// timer, like a workout in progress. Persisted so reopening the app mid-night still shows it running.
    func beginSleeping() {
        sleeping = true
        if sleepStartedAt == nil {
            let saved = UserDefaults.standard.object(forKey: "titan.sleepStartedAt") as? Date
            sleepStartedAt = saved ?? Date()
            UserDefaults.standard.set(sleepStartedAt, forKey: "titan.sleepStartedAt")
        }
    }

    /// Leave the live "Sleeping" state (woke up, however we learned it). Idempotent.
    private func clearLiveSleep() {
        sleeping = false
        sleepStartedAt = nil
        UserDefaults.standard.removeObject(forKey: "titan.sleepStartedAt")
    }
    private var runStartedAt: Date?
    private var runLastLat: Double?
    private var runLastLon: Double?
    private var runLastFixAt: Date?                // timestamp of the last accepted route fix (speed gate)
    private var runLastSignal: Date?
    private var runSawSport1 = false               // we've seen a sport-tagged frame this run (so a fall back to 0 = ended)
    private var runSportLostAt: Date?              // when the sport tag first fell to 0 this run (end debounce)
    private var lastSportWas1 = false              // the previous HR frame's sport tag — so we start on the RISING edge only
    private var suppressAutoStartUntil: Date?      // after an end, ignore the band's lingering sport tag this long
    private static let endRestartGraceSec: TimeInterval = 12   // how long to suppress auto-restart after an end
    private var runTicker: Task<Void, Never>?
    private var disconnectConfirmTask: Task<Void, Never>?   // durable-drop → end-the-run confirm (Fix B)
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

    /// The phone's own "steps today" (CoreMotion). Owned here (not per-view) so we can push it DOWN to
    /// the band — the band shows the phone's count too, so the watch face and the app never disagree.
    let stepTracker = StepTracker()

    /// Send the phone's step total to the band so its Steps face MAX-merges it (both show the same number).
    /// The band already streams its count up (T8); this closes the loop the other way. Called on connect
    /// and on every T8 we receive (a natural ~15 s cadence while connected). No-op if we have no count yet.
    func pushStepsToBand() {
        let phone = stepTracker.steps
        guard phone > 0 else { return }
        band?.sendSteps(phone, day: Self.localDayString())
    }

    /// Local YYYY-MM-DD, matching the band's T8 date so we only trust today's live count.
    static func localDayString() -> String {
        let f = DateFormatter(); f.dateFormat = "yyyy-MM-dd"; f.calendar = .current; f.timeZone = .current
        return f.string(from: Date())
    }

    /// May this T8 frame drive the LIVE "steps today" readout? DIRECTIONAL, not ±1: a timezone-skewed
    /// watch (a UTC-set band while the phone is in UTC-7 — the case a strict match broke, freezing steps
    /// at 0) runs AHEAD of the phone (diff -1), so ahead-of-today is tolerated. A frame dated BEHIND today
    /// (diff ≥ 1) is yesterday's data — a buffered total flushed on morning reconnect, or the band's banked
    /// end-of-day final. The old ±1 accepted those as "today": the app then showed (and MAX-pinned upstream
    /// consumers on) yesterday's 9,000 steps all morning while the watch honestly showed 200 — the classic
    /// watch-vs-app step desync. Uploads are unaffected either way: FrameRouter posts the frame's OWN date.
    static func bandDateIsCurrent(_ ymd: String) -> Bool {
        let f = DateFormatter(); f.dateFormat = "yyyy-MM-dd"; f.calendar = .current; f.timeZone = .current
        guard let d = f.date(from: ymd) else { return false }
        let cal = Calendar.current
        let diff = cal.dateComponents([.day], from: cal.startOfDay(for: d), to: cal.startOfDay(for: Date())).day ?? 99
        return diff <= 0 && diff >= -1                 // today or ahead-by-skew: live; behind: stale, upload-only
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
        // Only LIVE frames drive the run/lift state machine. On app open the band REPLAYS its offline
        // ring as ordinary "T5:" lines (flushLog / C3 sync), each carrying its original old timestamp.
        // Those buffered sport tags (sport==1 during a past workout, then 0 after) must NOT start/stop
        // the live session — replayed at flush speed they oscillate runActive and reopen a phantom run
        // long after the 12 s end-grace: that is the end-and-reopen loop. (T2/PPG already gets a separate
        // off-live-path builder for exactly this reason; T5 needs the same guard.) The 24/7 HR trend keeps
        // every reading separately in FrameRouter, so it's unaffected.
        let nowMs = UInt64(Date().timeIntervalSince1970 * 1000)
        if hr.t != 0, nowMs > hr.t, nowMs - hr.t > 60_000 { return }   // stale backlog frame → ignore
        if hr.sport == 1 {
            // Start ONLY on the rising edge (a workout just began). Starting on the level would re-open
            // the run on the very next frame after you end it — while the band is still mid-workout and
            // streaming sport==1 — which looped it back open forever. AND: after you tap End, the watch's
            // sport tag can flicker (end→restart, or a dropped C0) and produce a fresh 0→1 edge that
            // instantly re-popped the run — the "ending loop" where you had to force-quit. So during the
            // post-end grace window we ignore sport==1 ENTIRELY — including leaving lastSportWas1 untouched,
            // so a genuinely new workout started within the grace (interval training) is still seen as a
            // fresh rising edge once the grace expires (don't consume the edge).
            if !autoStartSuppressed {
                if !lastSportWas1 { startRunIfNeeded() }
                lastSportWas1 = true
            }
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

    /// True while we're in the post-end grace window — a recently-ended run must not be auto-restarted by
    /// the watch's still-flowing sport tag.
    private var autoStartSuppressed: Bool {
        if let until = suppressAutoStartUntil, Date() < until { return true }
        return false
    }

    /// The band told us this workout's kind (`TA:` frame). Switch the live experience: a lift drops GPS
    /// + any route/distance that leaked in before the kind arrived; a run (re)arms the phone locator.
    /// Robust to ordering — the first sport==1 frame may open the workout before `TA:` lands.
    func setWorkoutKind(_ k: String) {
        let norm = (k == "lift") ? "strength" : k
        guard norm != workoutKind else { return }
        workoutKind = norm
        if isLift {
            runTrack = []; runDistanceKm = 0; runPaceSecPerKm = 0   // a lift has no route/distance
        }
        updateLocator()   // start/stop phone GPS to match (no GPS for a lift)
    }

    /// The band's BLE link changed. A DURABLE drop while a run is live means the watch almost
    /// certainly finished (you racked the weight / walked off) and its TA:end / sport→0 frames never
    /// arrived — which used to leave the run hanging live forever: no summary, no seal. So we confirm
    /// the drop is durable (a transient blip auto-reconnects and cancels this) and then end for real.
    /// The workout window is kept open across the drop (router.deferWorkoutFlush) so this seals it
    /// with `ended`. Reproduced + verified in the watch simulator (firmware/sim, "drop-at-end").
    /// The raw CB link was restored (from `onLinkUp`, BEFORE the first data frame). Cancel the pending
    /// end-run confirm so a transient drop during a run doesn't end + seal it (H6). The data-driven LIVE
    /// path below also cancels the confirm, but only once frames resume; this fires earlier, on reconnect.
    private func handleLinkRestored() {
        disconnectConfirmTask?.cancel(); disconnectConfirmTask = nil
    }

    private func handleBandConnectionChange(_ up: Bool) {
        if up {
            disconnectConfirmTask?.cancel(); disconnectConfirmTask = nil   // reconnected → transient blip
            pushStepsToBand()   // top the watch back up to the phone's count the moment we connect
            return
        }
        guard runActive else { return }
        disconnectConfirmTask?.cancel()
        disconnectConfirmTask = Task { @MainActor [weak self] in
            try? await Task.sleep(nanoseconds: 8_000_000_000)
            guard let self, !Task.isCancelled else { return }
            if !self.bandConnected && self.runActive { self.endRun(notifyBand: false) }
        }
    }

    private func startRunIfNeeded() {
        guard !runActive else { return }
        runActive = true
        router?.deferWorkoutFlush = true      // keep the workout window open across a BLE blip / drop
        runStartedAt = Date(); runLastSignal = Date()
        runDistanceKm = 0; runElapsedSec = 0; runPaceSecPerKm = 0
        runLastLat = nil; runLastLon = nil; runLastFixAt = nil; runTrack = []; runMaxBpm = 0; runLiveBpm = nil
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
                self.band?.sendRunDistance(self.runDistanceKm * 1000)   // mirror distance to the watch Run face

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
        // Reset the rising-edge tracker. The sport==0 branch of ingestLiveHr resets this before it
        // ends a run, but the PRIMARY end signal — the watch's TA:{"k":"end"} frame → onWorkoutEnd →
        // endRun — bypasses that branch, so lastSportWas1 was left stuck TRUE. The NEXT workout's
        // sport==1 frames then never registered as a rising edge, so startRunIfNeeded never fired:
        // no live sheet, and on end `guard runActive` no-op'd → no summary AND no instant seal. This
        // one line is what made every workout after the first watch-ended one silently do nothing.
        lastSportWas1 = false
        router?.deferWorkoutFlush = false                 // run's over — normal disconnect flushing resumes
        disconnectConfirmTask?.cancel(); disconnectConfirmTask = nil
        // Block the band's lingering/flickering sport tag from instantly re-popping the run (the end loop).
        suppressAutoStartUntil = Date().addingTimeInterval(Self.endRestartGraceSec)
        runTicker?.cancel(); runTicker = nil
        showLiveRunSheet = false       // dismiss the live panel
        updateLocator()                // stop phone GPS unless a test is still using it
        // Seal the workout window NOW. Previously a workout only sealed on disconnect or after a 120s idle
        // gap, so finishing while the band stayed connected (app in foreground) saved NOTHING. Force it.
        router?.sealWorkout()
        if notifyBand { band?.endRunOnBand() }

        // Show the post-workout summary immediately from the live stats, then enrich it once the server
        // seals (Whoop-style instant seal makes that quick). Skip a non-session (e.g. a stray 0-second blip).
        if runElapsedSec >= 10 || runDistanceKm > 0.05 {
            // Preliminary duration from WALL-CLOCK (now − start), not the phone's live timer: for a
            // watch-driven workout the phone timer only accrues while foreground/connected and undercounts
            // the real session (a 33-min lift showed 11 — review). The sealed duration_min replaces this
            // within seconds.
            let prelimSec = runStartedAt.map { max(runElapsedSec, Int(Date().timeIntervalSince($0))) } ?? runElapsedSec
            workoutSummary = WorkoutSummaryState(
                kind: workoutKind, distanceKm: runDistanceKm, elapsedSec: prelimSec,
                maxBpm: runMaxBpm, startedAt: runStartedAt, hasGps: runHasGps)
            // Mark this workout seen so the catch-up path never re-pops the one we just showed live.
            if let s = runStartedAt { lastSeenWorkoutAt = max(lastSeenWorkoutAt, s) }
            fetchSealedSummary(startedAt: runStartedAt)
        }
        workoutKind = "run"   // reset to the default for the next workout (TA overrides on a real lift)
    }

    /// Poll the server for the just-sealed session and enrich the summary (zones, splits, VO₂max, sets).
    /// Thanks to the instant `ended`-flag seal this usually lands in a few seconds; we retry briefly and
    /// fall back to the live-stats-only summary if it doesn't (the seal still completes server-side).
    private func fetchSealedSummary(startedAt: Date?) {
        Task { @MainActor [weak self] in
            for attempt in 0..<8 {
                try? await Task.sleep(nanoseconds: attempt == 0 ? 2_000_000_000 : 3_000_000_000)
                guard let self, self.workoutSummary != nil else { return }   // dismissed
                guard let runs = try? await self.api.runs() else { continue }
                // Newest session at/after this run's start (within a few minutes) is ours. NO generic
                // newest-run fallback when we know our start time — before the seal lands that would
                // match some OLD run and present its splits/route as this workout's summary.
                let match = runs.first { r in
                    guard let started = startedAt, let iso = r.started_at,
                          let d = ISO8601DateFormatter().date(from: iso) else { return startedAt == nil }
                    return abs(d.timeIntervalSince(started)) < 600
                } ?? (startedAt == nil ? runs.first : nil)
                guard let match, let detail = try? await self.api.runDetail(match.id) else { continue }
                // Pin the catch-up watermark to the SERVER's exact start so this session can't later
                // re-pop as a catch-up (its server started_at may differ slightly from runStartedAt).
                if let iso = match.started_at, let d = ISO8601DateFormatter().date(from: iso) {
                    self.lastSeenWorkoutAt = max(self.lastSeenWorkoutAt, d)
                }
                if var s = self.workoutSummary { s.detail = detail; s.loading = false; self.workoutSummary = s }
                return
            }
            if var s = self?.workoutSummary { s.loading = false; s.failed = true; self?.workoutSummary = s }
        }
    }

    /// The watch reported WAKE (Sleep face). Leave the live "Sleeping" state and show the sleep summary
    /// immediately from the bed/wake markers, then enrich it once the server seals the night (stages,
    /// hypnogram, efficiency, performance). Mirrors `endRun` → `fetchSealedSummary` for workouts.
    func endSleep(bedSec: Int, wakeSec: Int) {
        clearLiveSleep()
        let bed = bedSec > 0 ? Date(timeIntervalSince1970: Double(bedSec)) : nil
        let wake = wakeSec > 0 ? Date(timeIntervalSince1970: Double(wakeSec)) : nil
        let inBed = max(0, wakeSec - bedSec)
        guard inBed >= 600 else { return }   // ignore a <10-min mis-tap; not a real night
        if bedSec > 0 { lastSeenSleepEpoch = max(lastSeenSleepEpoch, bedSec) }   // don't let the sync path re-pop it
        sleepSummary = SleepSummaryState(bedtime: bed, wake: wake, inBedSec: inBed)
        fetchSealedSleep(bedtimeEpoch: bedSec)
    }

    /// Poll the server for the just-sealed night and enrich the sleep summary (stages, hypnogram,
    /// efficiency, performance, respiratory rate). Night staging runs on the queue, so this can take a
    /// little longer than a workout seal; we retry patiently and fall back to the in-bed-only summary.
    /// Guards against surfacing the PREVIOUS night: before this night stages, `sleepDetail()` still
    /// returns yesterday's, so we only accept a detail whose start is near the bedtime we just ended.
    private func fetchSealedSleep(bedtimeEpoch: Int) {
        Task { @MainActor [weak self] in
            // Check quickly (the night may already be sealed) then every ~3s for ~195s — long enough to cover
            // the confirmed seal's WORST case: it can defer up to ~120s while raw windows finish processing,
            // THEN run whole-night staging. Short cadence so the metrics fill in near-immediately; the loading
            // card shows the whole time, and a timeout falls back to "saved — see Daily" rather than stranding.
            for attempt in 0..<65 {
                try? await Task.sleep(nanoseconds: attempt == 0 ? 1_500_000_000 : 3_000_000_000)
                guard let self, self.sleepSummary != nil else { return }   // dismissed
                // Progressive summary: the COMPUTING row already has a duration (from the watch envelope) but
                // no stages yet — keep the loading card until the night FINALIZES, don't stop on the placeholder.
                guard let resp = try? await self.api.sleepDetail(), let d = resp.detail,
                      (d.duration_min ?? 0) > 0, !resp.isComputing else { continue }
                // Is this the night we just ended? If the server stamps an epoch, require it within ~6h of
                // our bedtime; otherwise accept (best-effort). Keeps a stale prior night from masquerading.
                if bedtimeEpoch > 0, let e = d.epoch_sec, abs(e - bedtimeEpoch) > 6 * 3600 { continue }
                self.sleepDetail = resp   // refresh Daily/Recovery so they reflect the new night too
                if var s = self.sleepSummary { s.detail = d; s.assess = resp.assess; s.loading = false; self.sleepSummary = s }
                await self.refresh()      // cascade: recovery + strain-target now reflect the finalized night
                return
            }
            if var s = self?.sleepSummary { s.loading = false; s.failed = true; self?.sleepSummary = s }
        }
    }

    /// Worst horizontal accuracy (m) we'll trust INTO THE SAVED ROUTE. Coarse cell/Wi-Fi fixes at run
    /// start (often 65–1400 m) and urban-canyon outliers zigzag the route and inflate distance, so we keep
    /// them out of the sealed/server route. The LIVE dot is shown regardless (you must see where you are
    /// right away) — this only gates what gets persisted. 50 m is generous (real GNSS outdoors is ~5–15 m).
    private static let maxFixAccuracyM = 50.0
    /// Reject only an IMPLAUSIBLE-SPEED jump (a true GPS spike), scaled by elapsed time. A large jump over
    /// a large gap (tunnel, urban canyon, background coalescing) is REAL travel and must be accepted — the
    /// old fixed 200 m cap froze the run forever after one such gap (anchor never advanced). 20 m/s (72 km/h)
    /// is faster than any run/ride, so only genuine GPS errors (km-scale jumps in ~1 s) get rejected.
    private static let maxRunSpeedMps = 20.0
    /// Smallest move that counts as real progress. With continuous (~1 Hz) fixes, anything under this is
    /// GPS jitter while you're standing still — excluding it keeps the route clean and the distance honest.
    private static let minMoveM = 3.0

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
        // open); only an accurate fix that reflects REAL movement extends the route + distance. Now that
        // fixes stream continuously (~1 Hz), a min-move gate is what stops standing-still GPS jitter from
        // blobbing the track and inventing distance.
        guard runActive else { return }
        runLastSignal = Date()
        guard acc <= Self.maxFixAccuracyM else { return }
        if let la = runLastLat, let lo = runLastLon, let lastT = runLastFixAt {
            let d = Self.haversineM(la, lo, lat, lon)
            guard d.isFinite else { return }
            if d < Self.minMoveM { return }                          // stationary jitter — ignore, keep anchor
            let dt = max(0.5, loc.timestamp.timeIntervalSince(lastT))
            if d > Self.maxRunSpeedMps * dt {
                // Implausible speed = a true GPS spike. Don't trust its distance, but RE-ANCHOR to it so the
                // NEXT fix measures from here and tracking resumes. (The old code returned without advancing
                // the anchor → every later fix stayed >cap → run distance/route frozen for good.)
                runLastLat = lat; runLastLon = lon; runLastFixAt = loc.timestamp
                return
            }
            runDistanceKm += d / 1000
        }
        runLastLat = lat; runLastLon = lon; runLastFixAt = loc.timestamp
        runTrack.append(CGPoint(x: lon, y: lat))
        if runTrack.count > 3000 { runTrack.removeFirst(runTrack.count - 3000) }
        recomputePace()
        let t = UInt64(max(0, loc.timestamp.timeIntervalSince1970) * 1000)
        router?.ingestPhoneGps(GpsFix(t: t, sats: 0, speedKmh: max(0, loc.speed) * 3.6,
                                      alt: loc.altitude, lat: lat, lon: lon))   // seal pipeline → saved route
    }

    /// Phone GPS runs only while a run or a GPS test is active (battery).
    private func updateLocator() {
        // A lift never uses GPS — only a run or the GPS self-test powers the phone locator.
        if (runActive && !isLift) || gpsTestActive { locator.start() } else { locator.stop() }
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
