import Foundation
import CoreBluetooth

/// Always-on background BLE connection to the user's band (Bangle.js / Nordic UART Service).
/// Whoop-style pattern: `bluetooth-central` background mode + State Preservation & Restoration
/// (relaunches a terminated app) + no-timeout reconnect re-armed on every disconnect.
/// Design + citations: tasks/native-ios/01-background-ble.md.
///
/// Per-device binding: at pair time we lock onto the CLOSEST band (you hold yours to the phone)
/// and remember its peripheral identifier. Afterward the phone reconnects ONLY to that band — so
/// two people wearing bands side by side never cross-connect.
///
/// Threading (H5): the central runs on a DEDICATED serial queue (`bleQueue`), so `didUpdateValueFor`
/// — up to ~50 Hz in a lift — never lands on the main thread. ALL of our own mutable state (band,
/// rxChar, boundId, staleness stamps, pending downlink writes, reconnect backoff) is touched ONLY on
/// `bleQueue`: every CB delegate callback already arrives there, and every public method hops onto it
/// before mutating. Timers are `DispatchSourceTimer`s scheduled on `bleQueue` (a plain DispatchQueue
/// has no run loop, so `Timer.scheduledTimer` would never fire there). The only things that cross back
/// to the main thread are the `on…` callbacks — their consumers (AppModel) already marshal onto
/// `@MainActor`, so we can invoke them straight from `bleQueue`.
public final class BandManager: NSObject {
    public static let NUS_SERVICE = CBUUID(string: "6E400001-B5A3-F393-E0A9-E50E24DCCA9E")
    public static let NUS_TX = CBUUID(string: "6E400003-B5A3-F393-E0A9-E50E24DCCA9E") // notify
    public static let NUS_RX = CBUUID(string: "6E400002-B5A3-F393-E0A9-E50E24DCCA9E") // write
    public static let BATTERY_SERVICE = CBUUID(string: "180F")   // standard BLE Battery Service
    public static let BATTERY_LEVEL = CBUUID(string: "2A19")     // u8 percent (0-100), Bangle exposes it by default
    private static let restoreId = "com.titan.band.central"
    private static let boundKey = "titan.band.peripheralUUID"
    private static let boundNameKey = "titan.band.peripheralName"

    /// Dedicated serial queue for the central + all of our state (see the class doc). Every CB
    /// delegate callback arrives here; every public method hops here before touching state.
    private let bleQueue = DispatchQueue(label: "com.titan.band.ble")

    private var central: CBCentralManager!
    private var band: CBPeripheral?
    private var rxChar: CBCharacteristic?
    private let router: FrameRouter

    /// The specific band this phone is bound to (nil until first pairing).
    private var boundId: UUID?
    /// The bound band's advertised name (if we captured it at pair time). Used to SAFELY re-bind after a
    /// BLE-address rotation (watch reboot/reflash) without cross-binding a stranger's band (H1).
    private var boundName: String?
    /// True only during a fresh pair: collect nearby bands so the user picks theirs by code.
    private var pairing = false
    // Carry the advertised `name` too: during pairing the phone only SCANS the band (never connects), so
    // `CBPeripheral.name` is still nil — the advertised name captured here is the only name we have to
    // persist as `boundName`, which is what stops a later address-rotation re-bind from adopting a
    // stranger's band (the anti-cross-connect guard in didDiscover).
    private var candidates: [UUID: (peripheral: CBPeripheral, rssi: Int, code: String, name: String)] = [:]
    private var pairTimeout: DispatchSourceTimer?
    private var scanStopTimer: DispatchSourceTimer?   // bounds the reconnect discovery scan (battery)

    // MARK: reconnect backoff (H4)
    // A flapping peer must not be hammered: after an unexpected drop / connect failure we re-arm with a
    // small exponential backoff (delays 0 → 1 → 2 → 4 → 8 s, capped ~8 s), reset to 0 on a successful
    // connect. USER-initiated kicks (reconnectKick / foreground ensureConnected / syncNow) bypass the
    // backoff and reconnect immediately (and reset it).
    private var reconnectBackoff: TimeInterval = 0
    private var reconnectWorkToken = 0   // bumping this cancels any pending scheduled reconnect

    // MARK: wedged-link self-heal (H2)
    private var wedgeRecovering = false          // guards the force-cancel so it fires once per wedge
    private var forceReconnectAfterCancel = false // our own cancel (nil error) that STILL wants a reconnect

    // MARK: data-staleness watchdog (the real "connected" signal)
    // iOS can report a peripheral as `.connected` while it's actually suspended or the link is wedged —
    // CB state lies. The honest signal is DATA: are frames still arriving? We stamp `lastFrameAt` on every
    // inbound value and a light timer decides LIVE (a frame within `liveWindow`) vs STALE (`.connected` but
    // silent). `onConnectionChange` reports LIVE — not the mere CB `didConnect`/`didDisconnect` — so the
    // app's "band connected" state matches reality (and the run's durable-drop end fires when data stops).
    private var lastFrameAt = Date.distantPast
    private var liveWatchdog: DispatchSourceTimer?
    private var reportedLive = false
    // A firmware heartbeat lands every ~5 s. 13 s tolerates ≥2 dropped beats before we flip STALE, so a
    // single missed heartbeat at rest no longer flickers a false disconnect. (Firmware stays at 5 s.)
    private static let liveWindow: TimeInterval = 13  // a frame within this ⇒ LIVE; longer while connected ⇒ STALE

    /// Note any inbound BLE value (a data frame OR a battery notification) — proof the link is truly alive.
    /// Flips us to LIVE and re-arms the staleness clock. Stamped at the characteristic-value level (in
    /// `didUpdateValueFor`, before frame parsing), so the band's tiny `TB:` heartbeat — sent every ~5 s
    /// while connected — keeps us LIVE through the rest HRM duty-cycle gaps (HR frames only every ~30-180 s),
    /// without the router needing to understand the heartbeat at all.
    private func noteFrame() {
        lastFrameAt = Date()
        setLive(true)
    }

    /// Drive the user-facing connected state from DATA, not CB's optimistic `.connected`. Idempotent.
    private func setLive(_ live: Bool) {
        guard live != reportedLive else { return }
        reportedLive = live
        onConnectionChange?(live)
    }

    private func startLiveWatchdog() {
        liveWatchdog?.cancel()
        let t = DispatchSource.makeTimerSource(queue: bleQueue)
        t.schedule(deadline: .now() + 2, repeating: 2)
        t.setEventHandler { [weak self] in self?.liveTick() }
        liveWatchdog = t
        t.resume()
    }

    private func stopLiveWatchdog() {
        liveWatchdog?.cancel(); liveWatchdog = nil
    }

    // MARK: periodic clock re-sync (C2)
    // One C2 at connect is NOT enough. The firmware applies C2 only when IDLE — a session in progress
    // (workout/sleep, including one RESUMED right after a dead-battery reboot) defers it, and its C2
    // handler's contract is "the phone re-sends C2 on every connect + periodically, so a deferred
    // correction lands as soon as the session ends". The connect-time write is also .withoutResponse,
    // which CoreBluetooth may silently drop. Both together are how Tester B's band streamed 7+ hours on
    // a floored clock over a LIVE connection: the one C2 never landed and nothing ever retried. This
    // timer is the "periodically" half of that contract — a ~30-byte write every 5 min is noise next to
    // the inbound PPG stream, and a band that missed or deferred its sync is corrected within minutes
    // of going idle instead of never.
    private var clockResync: DispatchSourceTimer?
    private static let clockResyncInterval: TimeInterval = 300

    private func startClockResync() {
        clockResync?.cancel()
        let t = DispatchSource.makeTimerSource(queue: bleQueue)
        t.schedule(deadline: .now() + Self.clockResyncInterval, repeating: Self.clockResyncInterval)
        t.setEventHandler { [weak self] in
            guard let self, self.band?.state == .connected, self.rxChar != nil else { return }
            self.syncTime()
        }
        clockResync = t
        t.resume()
    }

    private func stopClockResync() {
        clockResync?.cancel(); clockResync = nil
    }

    /// One watchdog tick (on `bleQueue`). Connected + a recent frame = LIVE; connected but silent past the
    /// window = STALE; not connected at all = not live. When the link stays STALE past ~2× the window while
    /// CB still insists it's `.connected`, the link is WEDGED — proactively cancel it so the always-on
    /// reconnect path re-establishes a clean session (H2). Guarded to fire once per wedge.
    private func liveTick() {
        let connected = band?.state == .connected
        let sinceFrame = Date().timeIntervalSince(lastFrameAt)
        let fresh = connected && sinceFrame <= Self.liveWindow
        setLive(fresh)
        if connected && sinceFrame > Self.liveWindow * 2 {
            if !wedgeRecovering, let b = band {
                wedgeRecovering = true
                forceReconnectAfterCancel = true    // this cancel MUST be followed by a reconnect (not an unbind)
                central.cancelPeripheralConnection(b)
            }
        } else if fresh {
            wedgeRecovering = false                  // healthy again → re-arm the self-heal for next time
        }
    }

    /// A nearby band shown in the pairing picker. `code` matches what's on the band's screen.
    public struct PairCandidate: Identifiable, Equatable {
        public let id: UUID
        public let code: String   // e.g. "7C3F"
        public let rssi: Int
    }

    public var onConnectionChange: ((Bool) -> Void)?
    /// The CB link came up (`didConnect`, or restored already-connected) — fired BEFORE any data. Distinct
    /// from `onConnectionChange` (which is data-driven LIVE): lets AppModel cancel a pending end-run confirm
    /// the instant the link is restored, so a transient drop during a run can't wrongly end + seal it (H6).
    public var onLinkUp: (() -> Void)?
    /// Band battery percent (0-100), from the standard BLE Battery Service — read on connect + on change.
    public var onBattery: ((Int) -> Void)?
    /// Pairing bound to a band (true) or timed out with no pick (false).
    public var onPaired: ((Bool) -> Void)?
    /// Live list of nearby bands during pairing (closest first) for the picker UI.
    public var onCandidates: (([PairCandidate]) -> Void)?
    /// Bluetooth radio/permission availability → an actionable card instead of a forever-"Searching…".
    public var onState: ((BluetoothAvailability) -> Void)?

    private static func availability(_ s: CBManagerState) -> BluetoothAvailability {
        switch s {
        case .poweredOn: return .ok
        case .poweredOff: return .off
        case .unauthorized: return .denied
        case .unsupported: return .unsupported
        default: return .unknown   // .resetting / .unknown — transient, treat as "still figuring it out"
        }
    }

    /// Re-report the current Bluetooth state (e.g. when the connection screen opens) so the UI is correct
    /// even if the one-shot `didUpdateState` fired before the callback was wired.
    public func refreshState() {
        bleQueue.async { [weak self] in guard let self else { return }; self.onState?(Self.availability(self.central.state)) }
    }

    public init(router: FrameRouter) {
        self.router = router
        super.init()
        if let s = UserDefaults.standard.string(forKey: Self.boundKey) { boundId = UUID(uuidString: s) }
        boundName = UserDefaults.standard.string(forKey: Self.boundNameKey)
        // Dedicated serial queue (H5): keeps 50 Hz decode off the main thread AND makes State Restoration
        // reliable — the central (with the restore id) is constructed here, synchronously, at launch by the
        // AppDelegate BEFORE any await/network, so it exists inside iOS's restoration window on a cold,
        // BLE-triggered background relaunch.
        central = CBCentralManager(delegate: self, queue: bleQueue,
            options: [CBCentralManagerOptionRestoreIdentifierKey: Self.restoreId])
    }

    public var isBound: Bool { boundId != nil }

    /// Start a fresh pairing: scan and surface nearby bands by code; the user taps theirs (the one
    /// whose code matches the band's screen). Times out after 2 minutes with no pick.
    public func startPairing() {
        bleQueue.async { [weak self] in
            guard let self else { return }
            self.pairing = true
            self.candidates = [:]
            self.boundId = nil
            self.boundName = nil
            UserDefaults.standard.removeObject(forKey: Self.boundKey)
            UserDefaults.standard.removeObject(forKey: Self.boundNameKey)
            self.onCandidates?([])
            if self.central.state == .poweredOn { self.central.scanForPeripherals(withServices: nil) }
            self.pairTimeout?.cancel()
            let t = DispatchSource.makeTimerSource(queue: self.bleQueue)
            t.schedule(deadline: .now() + 120)
            t.setEventHandler { [weak self] in
                guard let self, self.pairing else { return }
                self.pairing = false
                self.central.stopScan()
                self.onPaired?(false)
            }
            self.pairTimeout = t
            t.resume()
        }
    }

    /// User tapped a band in the picker → bind to that exact peripheral forever.
    public func bind(to id: UUID) {
        bleQueue.async { [weak self] in
            guard let self, let c = self.candidates[id] else { return }
            self.pairing = false
            self.pairTimeout?.cancel(); self.pairTimeout = nil
            self.boundId = id
            // Prefer the advertised name captured at discovery — c.peripheral.name is still nil here
            // (we only scanned, never connected), which is exactly why boundName used to persist as nil.
            self.boundName = c.name.isEmpty ? c.peripheral.name : c.name
            UserDefaults.standard.set(id.uuidString, forKey: Self.boundKey)
            if let n = self.boundName { UserDefaults.standard.set(n, forKey: Self.boundNameKey) }
            else { UserDefaults.standard.removeObject(forKey: Self.boundNameKey) }
            self.band = c.peripheral
            c.peripheral.delegate = self
            self.central.stopScan()
            self.reconnectBackoff = 0
            self.reconnect(c.peripheral)
            self.onPaired?(true)
        }
    }

    public func cancelPairing() {
        bleQueue.async { [weak self] in
            guard let self else { return }
            self.pairing = false
            self.pairTimeout?.cancel(); self.pairTimeout = nil
            self.central.stopScan()
        }
    }

    /// Forget the bound band (used when re-pairing).
    public func unbind() {
        bleQueue.async { [weak self] in
            guard let self else { return }
            self.boundId = nil
            self.boundName = nil
            UserDefaults.standard.removeObject(forKey: Self.boundKey)
            UserDefaults.standard.removeObject(forKey: Self.boundNameKey)
            self.reconnectWorkToken += 1          // cancel any pending scheduled reconnect
            self.stopLiveWatchdog()
            self.stopClockResync()
            self.setLive(false)
            if let b = self.band, b.state != .disconnected { self.central.cancelPeripheralConnection(b) }
            self.band = nil
        }
    }

    public var isConnected: Bool { band?.state == .connected }

    /// Whoop-style ALWAYS-ON link: the band is the only data pipeline, so a bound band is held connected
    /// 24/7 (foreground AND background) — there is no "release". This just NUDGES a connection attempt if
    /// we're not currently connected (e.g. the app foregrounded after the link briefly dropped); the
    /// always-on delegate paths (power-on, didDiscover, didDisconnect) do the rest. Idempotent + cheap.
    /// User/foreground-initiated → immediate (no backoff), and it clears any pending backoff.
    public func ensureConnected() {
        bleQueue.async { [weak self] in
            guard let self, self.central.state == .poweredOn, let id = self.boundId else { return }
            if self.band?.state != .connected {
                self.reconnectBackoff = 0
                self.reconnectWorkToken += 1
                if let p = self.central.retrievePeripherals(withIdentifiers: [id]).first {
                    self.band = p; p.delegate = self; self.reconnect(p)
                }
                // A bounded scan catches an address rotation; the standing connect() above handles the
                // ordinary out-of-range case (no all-day scan). See startBoundedScan.
                self.startBoundedScan()
            }
        }
    }

    /// Manual "Sync now": if the band is connected, ask it to flush its overnight ring buffer right now
    /// (C3); otherwise kick a connect to the bound band — the firmware auto-flushes on connect. Either
    /// way the whole night transfers on demand.
    public func syncNow() {
        bleQueue.async { [weak self] in
            guard let self, self.central.state == .poweredOn, let id = self.boundId else { return }
            if let p = self.band, p.state == .connected, let rx = self.rxChar {
                p.writeValue(Data("C3:\n".utf8), for: rx, type: .withoutResponse)
                return
            }
            self.reconnectBackoff = 0
            self.reconnectWorkToken += 1
            if let p = self.central.retrievePeripherals(withIdentifiers: [id]).first {
                self.band = p; p.delegate = self; self.reconnect(p)
            }
            self.startBoundedScan()   // bounded — connect() above covers the common reconnect case
        }
    }

    /// Opening the app while ALREADY connected → force a flush (C3) so the latest steps + any data
    /// buffered since the last flush reach the phone (and the server) right now. The reconnect path
    /// already auto-flushes on connect, so this only covers the still-linked case. No-op if not connected.
    public func flushIfConnected() {
        bleQueue.async { [weak self] in
            guard let self, let p = self.band, p.state == .connected, let rx = self.rxChar else { return }
            p.writeValue(Data("C3:\n".utf8), for: rx, type: .withoutResponse)
        }
    }

    /// End the run on the band (C0): when you tap "End run" in the app, the band finishes too. No-op if
    /// not connected (then the band keeps recording until you finish on its RUN face).
    public func endRunOnBand() {
        bleQueue.async { [weak self] in
            guard let self, let p = self.band, p.state == .connected, let rx = self.rxChar else { return }
            p.writeValue(Data("C0:\n".utf8), for: rx, type: .withoutResponse)
        }
    }

    /// Push the live run distance (metres) to the band's Run face (C5). The band has no GPS — the phone
    /// owns the route + distance — so without this the watch shows time but a frozen 0.00 km. No-op if
    /// not connected. Sent ~1 Hz while a run is live.
    ///
    /// Flow-controlled: a write-without-response is silently DROPPED by iOS when the link buffer is full,
    /// and during a connected run the band floods us with inbound PPG frames — so a plain 1 Hz write
    /// often got starved and the watch froze at 0.00 while the phone tracked fine. We instead keep only
    /// the LATEST distance and flush it the moment CoreBluetooth says the peripheral can accept a write
    /// (peripheralIsReady), so the newest value always lands.
    private var pendingRunDistanceM: Double?
    public func sendRunDistance(_ meters: Double) {
        bleQueue.async { [weak self] in
            guard let self else { return }
            self.pendingRunDistanceM = meters
            self.flushRunDistance()
        }
    }

    private func flushRunDistance() {
        guard let m = pendingRunDistanceM,
              let p = band, p.state == .connected, let rx = rxChar,
              p.canSendWriteWithoutResponse else { return }
        pendingRunDistanceM = nil
        p.writeValue(Data("C5:{\"d\":\(Int(m.rounded()))}\n".utf8), for: rx, type: .withoutResponse)
    }

    /// Push the phone's step total for today to the band (C6) so the watch's Steps face and the app show
    /// the SAME number. The band streams its own count up (T8); this closes the loop the other way — the
    /// watch MAX-merges the phone's count (it never goes backwards, and it's a MAX so it's never double-
    /// counted). Same flow-control as the run distance: keep only the latest and flush when the link's ready.
    private var pendingSteps: (steps: Int, day: String)?
    public func sendSteps(_ steps: Int, day: String) {
        bleQueue.async { [weak self] in
            guard let self else { return }
            self.pendingSteps = (steps, day)
            self.flushSteps()
        }
    }

    private func flushSteps() {
        guard let s = pendingSteps,
              let p = band, p.state == .connected, let rx = rxChar,
              p.canSendWriteWithoutResponse else { return }
        pendingSteps = nil
        p.writeValue(Data("C6:{\"s\":\(s.steps),\"d\":\"\(s.day)\"}\n".utf8), for: rx, type: .withoutResponse)
    }

    /// Force a fresh connection attempt when we're paired but stuck — advertised-but-never-connected,
    /// a half-open link, or a Bluetooth stack that's wedged. Tears down any existing connection to the
    /// bound band, drops the cached write char, then re-arms connect AND restarts a scan so we catch
    /// the band the instant it advertises again. This is the "Reconnect" button's muscle. User-initiated
    /// → immediate (no backoff).
    public func reconnectKick() {
        bleQueue.async { [weak self] in
            guard let self, self.central.state == .poweredOn, let id = self.boundId else { return }
            self.reconnectBackoff = 0
            self.reconnectWorkToken += 1
            if let b = self.band, b.state != .disconnected { self.central.cancelPeripheralConnection(b) }
            self.rxChar = nil
            if let p = self.central.retrievePeripherals(withIdentifiers: [id]).first {
                self.band = p; p.delegate = self; self.reconnect(p)
            }
            self.startBoundedScan()   // user "Reconnect" → a fresh bounded discovery scan
        }
    }

    /// No-timeout connect (survives out-of-range + termination); system wakes us on events. On iOS 17+ we
    /// also opt into the OS's own auto-reconnect (`EnableAutoReconnect`): CoreBluetooth transparently
    /// re-establishes the link after an unexpected drop, which needs the `isReconnecting` disconnect
    /// callback below to be implemented (else the connect fails with "invalid parameters").
    private func reconnect(_ p: CBPeripheral) {
        var opts: [String: Any] = [
            CBConnectPeripheralOptionNotifyOnConnectionKey: true,
            CBConnectPeripheralOptionNotifyOnDisconnectionKey: true,
            CBConnectPeripheralOptionNotifyOnNotificationKey: true,
        ]
        if #available(iOS 17, *) { opts[CBConnectPeripheralOptionEnableAutoReconnect] = true }
        central.connect(p, options: opts)
    }

    /// Start a BOUNDED discovery scan, then stop it after `seconds`. The standing no-timeout `connect()`
    /// already re-establishes the link the instant the band comes back in range, so a scan adds nothing for
    /// the ordinary out-of-range case — its only real value is catching a BLE-ADDRESS ROTATION after a
    /// watch reboot/reflash (where `retrievePeripherals(boundId)` is a dead handle and only a fresh advert
    /// can recover). Running a service-filtered scan 24/7 for every out-of-range period to catch that rare
    /// event is a steady background battery drain; bounding it keeps the payoff without the all-day cost
    /// (foreground/ensureConnected + the Reconnect button restart it, so a rotation still recovers). Must be
    /// filtered on the service UUID (a background scan returns nothing otherwise); pairing uses its own scan.
    private func startBoundedScan(_ seconds: TimeInterval = 30) {
        central.scanForPeripherals(withServices: [Self.NUS_SERVICE])
        scanStopTimer?.cancel()
        let t = DispatchSource.makeTimerSource(queue: bleQueue)
        t.schedule(deadline: .now() + seconds)
        t.setEventHandler { [weak self] in
            guard let self, !self.pairing, self.band?.state != .connected else { return }
            self.central.stopScan()
        }
        scanStopTimer = t
        t.resume()
    }

    /// Re-arm a reconnect for the UNSOLICITED failure/disconnect paths (didFailToConnect, an unexpected
    /// drop where the OS isn't auto-reconnecting for us).
    ///
    /// CRITICAL for true always-on: we issue the no-timeout `connect()` IMMEDIATELY. A pending `connect()`
    /// is the CoreBluetooth-level primitive that lets iOS relaunch a TERMINATED app the instant the band
    /// reappears. Relying on the bleQueue backoff timer alone (as this used to) is NOT enough — that
    /// closure is frozen when the app suspends and lost when it's jetsammed, so during the backoff window
    /// nothing is armed at the CB level and the band would silently stop syncing until a manual reopen.
    /// `connect()` is no-timeout, so arming it now never "hammers" a peer — it just waits.
    ///
    /// The exponential backoff (0 → 1 → 2 → 4 → 8 s, reset on connect) now only PACES an additional
    /// re-issue as insurance; re-issuing a still-pending connect is a cheap no-op (CoreBluetooth dedups).
    private func scheduleReconnect(_ p: CBPeripheral) {
        reconnect(p)   // arm the standing pending connection NOW (survives suspend + termination)
        let delay = reconnectBackoff
        reconnectBackoff = reconnectBackoff == 0 ? 1 : min(reconnectBackoff * 2, 8)
        guard delay > 0 else { return }   // first drop: the immediate connect() above is the arming
        reconnectWorkToken += 1
        let token = reconnectWorkToken
        bleQueue.asyncAfter(deadline: .now() + delay) { [weak self] in
            guard let self, token == self.reconnectWorkToken else { return }
            guard self.boundId == p.identifier, self.band?.state != .connected else { return }
            self.reconnect(p)
        }
    }

    /// True if `adv`/peripheral looks like a supported band.
    private func looksLikeBand(_ p: CBPeripheral, _ adv: [String: Any]) -> Bool {
        let name = p.name ?? (adv[CBAdvertisementDataLocalNameKey] as? String) ?? ""
        let uuids = adv[CBAdvertisementDataServiceUUIDsKey] as? [CBUUID] ?? []
        return name.hasPrefix("Bangle") || uuids.contains(Self.NUS_SERVICE)
    }

    /// Shared "the CB link is up" path (H5/B2): stop the discovery scan, reset the backoff + self-heal
    /// guards, arm the staleness watchdog, tell AppModel the link is up (pre-data, for the end-run confirm
    /// cancel), then discover services so `rxChar` is (re)cached and downlink writes work. Called from
    /// `didConnect` AND from `willRestoreState` when the peripheral is restored ALREADY `.connected`
    /// (otherwise discovery would be skipped and `rxChar` would stay nil forever → every C-command no-ops).
    private func handleConnected(_ p: CBPeripheral) {
        central.stopScan()   // we're connected — stop the discovery scan (a connected band never re-advertises,
                             // so didDiscover can't stop it; without this a scan ran all session)
        scanStopTimer?.cancel(); scanStopTimer = nil   // connected before the bounded-scan window elapsed
        // Back-fill a missing bound name now that we're connected (p.name resolves over GATT). Repairs any
        // binding made before we captured the advertised name, so the address-rotation guard has a real
        // name to match instead of falling through to the unsafe service-only path.
        if boundName == nil, let n = p.name, !n.isEmpty {
            boundName = n
            UserDefaults.standard.set(n, forKey: Self.boundNameKey)
        }
        reconnectBackoff = 0
        reconnectWorkToken += 1     // supersede any pending scheduled reconnect
        wedgeRecovering = false
        // NOTE: we do NOT report LIVE here — CB `.connected` isn't proof of data. The staleness watchdog
        // flips us LIVE on the first real frame and STALE if a "connected" link goes silent.
        lastFrameAt = .distantPast
        startLiveWatchdog()
        onLinkUp?()                 // link restored (pre-data) → cancel a pending end-run confirm (H6)
        p.discoverServices([Self.NUS_SERVICE, Self.BATTERY_SERVICE])
    }
}

extension BandManager: CBCentralManagerDelegate {
    public func centralManagerDidUpdateState(_ c: CBCentralManager) {
        onState?(Self.availability(c.state))   // surface off/denied/unsupported to the UI
        guard c.state == .poweredOn else { return }
        if pairing { c.scanForPeripherals(withServices: nil); return }   // pairing scan stays unfiltered (foreground)
        guard let id = boundId else { return }           // not paired yet — wait for startPairing()
        // ALWAYS-ON: a bound band is held connected 24/7 — no power-gating. Re-arm a pending connect to
        // the known peripheral (works in the background, no scan).
        if let p = c.retrievePeripherals(withIdentifiers: [id]).first {
            band = p; p.delegate = self
            // Already connected (e.g. restored live by willRestoreState) → do NOT scan. A connected band
            // never re-advertises, so didDiscover would never fire to stopScan() and the scan would run
            // 24/7 alongside a healthy link — a real all-day battery drain (StrapManager guards this too).
            if p.state == .connected { return }
            reconnect(p)
        }
        // Not connected → a BOUNDED discovery scan (catches an address rotation); the always-on connect()
        // re-armed above handles the ordinary out-of-range reconnect without an all-day scan.
        startBoundedScan()
    }

    /// FIRST callback when iOS relaunches a terminated app for a BLE event.
    public func centralManager(_ c: CBCentralManager, willRestoreState dict: [String: Any]) {
        if let p = (dict[CBCentralManagerRestoredStatePeripheralsKey] as? [CBPeripheral])?
            .first(where: { boundId == nil || $0.identifier == boundId }) {
            band = p
            p.delegate = self
            // B2: a peripheral restored ALREADY `.connected` must still discover services — otherwise
            // `rxChar` stays nil and every downlink write (C0/C2/C3/C5/C6) silently no-ops forever. Mirror
            // the didConnect path. When restored NOT connected, re-arm the always-on connect unconditionally.
            if p.state == .connected { handleConnected(p) }
            else { reconnect(p) }
        }
    }

    public func centralManager(_ c: CBCentralManager, didDiscover p: CBPeripheral,
                               advertisementData: [String: Any], rssi: NSNumber) {
        guard looksLikeBand(p, advertisementData) else { return }
        if pairing {
            let name = p.name ?? (advertisementData[CBAdvertisementDataLocalNameKey] as? String) ?? ""
            let code = String(name.replacingOccurrences(of: " ", with: "").suffix(4)).uppercased()
            candidates[p.identifier] = (p, rssi.intValue, code, name)
            onCandidates?(candidates.values
                .map { PairCandidate(id: $0.peripheral.identifier, code: $0.code, rssi: $0.rssi) }
                .sorted { $0.rssi > $1.rssi })
            return
        }
        guard let bid = boundId else { return }           // not paired — ignore all adverts
        if p.identifier != bid {
            // Possible BLE-address ROTATION (H1): a watch reboot/reflash can rotate the peripheral id, so
            // `retrievePeripherals(boundId)` returns nothing and we could never reconnect without a manual
            // re-pair. Allow a re-bind, but ONLY when (a) the old id genuinely can't be retrieved AND (b)
            // this advert plausibly IS our band. We prefer an exact advertised-NAME match (a stranger's
            // band has a different name — this is what stops cross-binding); we fall back to a bare
            // NUS_SERVICE match only for legacy binds that never captured a name. Never during pairing
            // (handled above), so a fresh pair can't be hijacked by this path.
            guard central.retrievePeripherals(withIdentifiers: [bid]).isEmpty else { return }
            let advName = p.name ?? (advertisementData[CBAdvertisementDataLocalNameKey] as? String)
            let nameMatch = boundName != nil && advName != nil && advName == boundName
            let serviceMatch = (advertisementData[CBAdvertisementDataServiceUUIDsKey] as? [CBUUID])?
                .contains(Self.NUS_SERVICE) ?? false
            guard nameMatch || (boundName == nil && serviceMatch) else { return }
            boundId = p.identifier
            UserDefaults.standard.set(p.identifier.uuidString, forKey: Self.boundKey)
            if let advName { boundName = advName; UserDefaults.standard.set(advName, forKey: Self.boundNameKey) }
        }
        band = p; p.delegate = self
        c.stopScan()
        reconnect(p)
    }

    public func centralManager(_ c: CBCentralManager, didConnect p: CBPeripheral) {
        handleConnected(p)
    }

    /// The OS gave up on a connect attempt (H4). Re-arm with backoff so a peer that's advertising but
    /// refusing (or momentarily gone) isn't hammered.
    public func centralManager(_ c: CBCentralManager, didFailToConnect p: CBPeripheral, error: Error?) {
        guard p.identifier == boundId else { return }   // a stale/other peripheral must not touch live state
        stopLiveWatchdog()
        stopClockResync()
        setLive(false)
        scheduleReconnect(p)
    }

    /// Legacy disconnect callback (iOS < 17). On iOS 17+ the `isReconnecting` variant below is called
    /// instead (never both), so this only runs where auto-reconnect isn't available.
    public func centralManager(_ c: CBCentralManager, didDisconnectPeripheral p: CBPeripheral, error: Error?) {
        handleDisconnect(p, error: error, osIsReconnecting: false)
    }

    /// iOS 17+ disconnect callback — REQUIRED once `CBConnectPeripheralOptionEnableAutoReconnect` is used
    /// (otherwise `connect` fails "invalid parameters"). When `isReconnecting` is true the OS is already
    /// re-establishing the link, so we must NOT also issue our own `connect` (that races the OS); we just
    /// drop to not-live and wait. When false, we re-arm ourselves exactly as the legacy path does.
    @available(iOS 17, *)
    public func centralManager(_ c: CBCentralManager, didDisconnectPeripheral p: CBPeripheral,
                               timestamp: CFAbsoluteTime, isReconnecting: Bool, error: Error?) {
        handleDisconnect(p, error: error, osIsReconnecting: isReconnecting)
    }

    /// Shared disconnect handling. Data has stopped, so the link is not LIVE. We inspect `error` to be
    /// honest about WHY: nil = we cancelled deliberately; `.connectionTimeout`(6) = out of range / band
    /// died; `.peripheralDisconnected`(7) = the peer ended the link. We always re-arm a reconnect (the
    /// always-on policy, now with backoff) unless the OS is already reconnecting for us, OR we cancelled
    /// on purpose (unbind). The one exception: a SELF-HEAL cancel (H2) reports nil error but MUST still
    /// reconnect — `forceReconnectAfterCancel` distinguishes it from a deliberate unbind.
    private func handleDisconnect(_ p: CBPeripheral, error: Error?, osIsReconnecting: Bool) {
        // Identity FIRST: an old/stale peripheral disconnecting (e.g. during a re-pair, while the new bound
        // band is already streaming) must not flip us not-live, flush the live band's window, or consume the
        // self-heal flag. All of that belongs only to the bound band's own disconnect.
        guard p.identifier == boundId else { return }
        stopLiveWatchdog()
        stopClockResync()
        setLive(false)
        router.flush(live: false)
        wedgeRecovering = false
        let forced = forceReconnectAfterCancel
        forceReconnectAfterCancel = false
        // We deliberately cancelled (error == nil, e.g. unbind) → don't fight it — UNLESS this was our
        // wedge self-heal cancel, which wants a reconnect. Otherwise re-arm (with backoff), but only if
        // the OS isn't already handling the reconnect for us (iOS 17 auto-reconnect).
        let weCancelled = (error == nil) && !forced
        if !osIsReconnecting && !weCancelled { scheduleReconnect(p) }
    }
}

extension BandManager: CBPeripheralDelegate {
    public func peripheral(_ p: CBPeripheral, didDiscoverServices error: Error?) {
        for s in p.services ?? [] {
            if s.uuid == Self.BATTERY_SERVICE { p.discoverCharacteristics([Self.BATTERY_LEVEL], for: s) }
            else { p.discoverCharacteristics([Self.NUS_TX, Self.NUS_RX], for: s) }
        }
    }

    public func peripheral(_ p: CBPeripheral, didDiscoverCharacteristicsFor s: CBService, error: Error?) {
        for ch in s.characteristics ?? [] {
            if ch.uuid == Self.NUS_TX { p.setNotifyValue(true, for: ch) }
            if ch.uuid == Self.NUS_RX { rxChar = ch }
            if ch.uuid == Self.BATTERY_LEVEL { p.readValue(for: ch); p.setNotifyValue(true, for: ch) }
        }
        if rxChar != nil {
            syncTime()          // push the phone's local time + timezone to the band
            startClockResync()  // …and keep re-pushing: a busy band defers C2, a dropped write loses it
        }
    }

    /// Send the phone's current UTC time + timezone offset so the band's clock is always correct
    /// Re-push the phone's clock to the band on demand (e.g. app foreground). A band whose clock drifted
    /// or was never synced (fresh reflash) otherwise stamps workouts with a wrong time — the "logged 11 h
    /// ago even though I just did it" bug. No-op if not connected.
    public func syncClockIfConnected() {
        bleQueue.async { [weak self] in
            guard let self, let p = self.band, p.state == .connected, self.rxChar != nil else { return }
            self.syncTime()
        }
    }

    /// (the phone always knows the right zone — more reliable than GPS, which can't derive tz).
    private func syncTime() {
        guard let p = band, let rx = rxChar else { return }
        let t = Int(Date().timeIntervalSince1970)
        let tz = Double(TimeZone.current.secondsFromGMT()) / 3600.0   // e.g. PDT = -7.0
        let cmd = "C2:{\"t\":\(t),\"tz\":\(tz)}\n"
        p.writeValue(Data(cmd.utf8), for: rx, type: .withoutResponse)
    }

    /// NUS TX stream — fires in the background and wakes a terminated app. Drain fast. Battery-level
    /// updates (the standard 0x2A19 char) split off to onBattery; everything else is a frame. Runs on
    /// `bleQueue` (H5), so the router decode never touches the main thread.
    public func peripheral(_ p: CBPeripheral, didUpdateValueFor ch: CBCharacteristic, error: Error?) {
        guard let d = ch.value else { return }
        noteFrame()   // ANY inbound value = the link is truly alive → LIVE + re-arm the staleness clock
        if ch.uuid == Self.BATTERY_LEVEL {
            if let pct = d.first { onBattery?(Int(pct)) }
            return
        }
        router.ingest(d)
    }

    /// The link can accept another write-without-response → flush the latest pending run distance so the
    /// watch's Run face stays in step even while inbound PPG frames are saturating the connection.
    public func peripheralIsReady(toSendWriteWithoutResponse p: CBPeripheral) {
        flushRunDistance()
        flushSteps()
    }
}
