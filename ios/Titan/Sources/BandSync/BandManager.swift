import Foundation
import CoreBluetooth

/// Always-on background BLE connection to the Titan band (Bangle.js / Nordic UART Service).
/// Whoop-style pattern: `bluetooth-central` background mode + State Preservation & Restoration
/// (relaunches a terminated app) + no-timeout reconnect re-armed on every disconnect.
/// Design + citations: tasks/native-ios/01-background-ble.md.
///
/// Per-device binding: at pair time we lock onto the CLOSEST band (you hold yours to the phone)
/// and remember its peripheral identifier. Afterward the phone reconnects ONLY to that band — so
/// two people wearing Titan bands side by side never cross-connect.
public final class BandManager: NSObject {
    public static let NUS_SERVICE = CBUUID(string: "6E400001-B5A3-F393-E0A9-E50E24DCCA9E")
    public static let NUS_TX = CBUUID(string: "6E400003-B5A3-F393-E0A9-E50E24DCCA9E") // notify
    public static let NUS_RX = CBUUID(string: "6E400002-B5A3-F393-E0A9-E50E24DCCA9E") // write
    public static let BATTERY_SERVICE = CBUUID(string: "180F")   // standard BLE Battery Service
    public static let BATTERY_LEVEL = CBUUID(string: "2A19")     // u8 percent (0-100), Bangle exposes it by default
    private static let restoreId = "com.titan.band.central"
    private static let boundKey = "titan.band.peripheralUUID"

    private var central: CBCentralManager!
    private var band: CBPeripheral?
    private var rxChar: CBCharacteristic?
    private let router: FrameRouter

    /// The specific band this phone is bound to (nil until first pairing).
    private var boundId: UUID?
    /// True only during a fresh pair: collect nearby bands so the user picks theirs by code.
    private var pairing = false
    private var candidates: [UUID: (peripheral: CBPeripheral, rssi: Int, code: String)] = [:]
    private var pairTimeout: Timer?

    // MARK: data-staleness watchdog (the real "connected" signal)
    // iOS can report a peripheral as `.connected` while it's actually suspended or the link is wedged —
    // CB state lies. The honest signal is DATA: are frames still arriving? We stamp `lastFrameAt` on every
    // inbound value and a light timer decides LIVE (a frame within `liveWindow`) vs STALE (`.connected` but
    // silent). `onConnectionChange` reports LIVE — not the mere CB `didConnect`/`didDisconnect` — so the
    // app's "band connected" state matches reality (and the run's durable-drop end fires when data stops).
    private var lastFrameAt = Date.distantPast
    private var liveWatchdog: Timer?
    private var reportedLive = false
    private static let liveWindow: TimeInterval = 8   // a frame within this ⇒ LIVE; longer while connected ⇒ STALE

    /// Note any inbound BLE value (a data frame OR a battery notification) — proof the link is truly alive.
    /// Flips us to LIVE and re-arms the staleness clock.
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
        liveWatchdog?.invalidate()
        liveWatchdog = Timer.scheduledTimer(withTimeInterval: 2, repeats: true) { [weak self] _ in
            guard let self else { return }
            // Connected + a recent frame = LIVE; connected but silent past the window = STALE; not
            // connected at all = not live.
            let fresh = self.band?.state == .connected
                && Date().timeIntervalSince(self.lastFrameAt) <= Self.liveWindow
            self.setLive(fresh)
        }
    }

    private func stopLiveWatchdog() {
        liveWatchdog?.invalidate(); liveWatchdog = nil
    }

    /// A nearby band shown in the pairing picker. `code` matches what's on the band's screen.
    public struct PairCandidate: Identifiable, Equatable {
        public let id: UUID
        public let code: String   // e.g. "7C3F"
        public let rssi: Int
    }

    public var onConnectionChange: ((Bool) -> Void)?
    /// Band battery percent (0-100), from the standard BLE Battery Service — read on connect + on change.
    public var onBattery: ((Int) -> Void)?
    /// Pairing bound to a band (true) or timed out with no pick (false).
    public var onPaired: ((Bool) -> Void)?
    /// Live list of nearby bands during pairing (closest first) for the picker UI.
    public var onCandidates: (([PairCandidate]) -> Void)?

    public init(router: FrameRouter) {
        self.router = router
        super.init()
        if let s = UserDefaults.standard.string(forKey: Self.boundKey) { boundId = UUID(uuidString: s) }
        central = CBCentralManager(delegate: self, queue: nil,
            options: [CBCentralManagerOptionRestoreIdentifierKey: Self.restoreId])
    }

    public var isBound: Bool { boundId != nil }

    /// Start a fresh pairing: scan and surface nearby bands by code; the user taps theirs (the one
    /// whose code matches the band's screen). Times out after 2 minutes with no pick.
    public func startPairing() {
        pairing = true
        candidates = [:]
        boundId = nil
        UserDefaults.standard.removeObject(forKey: Self.boundKey)
        onCandidates?([])
        if central.state == .poweredOn { central.scanForPeripherals(withServices: nil) }
        pairTimeout?.invalidate()
        pairTimeout = Timer.scheduledTimer(withTimeInterval: 120, repeats: false) { [weak self] _ in
            guard let self, self.pairing else { return }
            self.pairing = false
            self.central.stopScan()
            self.onPaired?(false)
        }
    }

    /// User tapped a band in the picker → bind to that exact peripheral forever.
    public func bind(to id: UUID) {
        guard let c = candidates[id] else { return }
        pairing = false
        pairTimeout?.invalidate()
        boundId = id
        UserDefaults.standard.set(id.uuidString, forKey: Self.boundKey)
        band = c.peripheral
        c.peripheral.delegate = self
        central.stopScan()
        reconnect(c.peripheral)
        onPaired?(true)
    }

    public func cancelPairing() {
        pairing = false
        pairTimeout?.invalidate()
        central.stopScan()
    }

    /// Forget the bound band (used when re-pairing).
    public func unbind() {
        boundId = nil
        UserDefaults.standard.removeObject(forKey: Self.boundKey)
        stopLiveWatchdog()
        setLive(false)
        if let b = band, b.state != .disconnected { central.cancelPeripheralConnection(b) }
        band = nil
    }

    public var isConnected: Bool { band?.state == .connected }

    /// Whoop-style ALWAYS-ON link: the band is the only data pipeline, so a bound band is held connected
    /// 24/7 (foreground AND background) — there is no "release". This just NUDGES a connection attempt if
    /// we're not currently connected (e.g. the app foregrounded after the link briefly dropped); the
    /// always-on delegate paths (power-on, didDiscover, didDisconnect) do the rest. Idempotent + cheap.
    public func ensureConnected() {
        guard central.state == .poweredOn, let id = boundId else { return }
        if band?.state != .connected {
            if let p = central.retrievePeripherals(withIdentifiers: [id]).first {
                band = p; p.delegate = self; reconnect(p)
            }
            // Background scans return NOTHING unless the service UUID is explicit (iOS requirement), so
            // filter on the band's Nordic UART service — this is what re-finds a band that dropped while
            // we were backgrounded.
            central.scanForPeripherals(withServices: [Self.NUS_SERVICE])
        }
    }

    /// Manual "Sync now": if the band is connected, ask it to flush its overnight ring buffer right now
    /// (C3); otherwise kick a connect to the bound band — the firmware auto-flushes on connect. Either
    /// way the whole night transfers on demand.
    public func syncNow() {
        guard central.state == .poweredOn, let id = boundId else { return }
        if let p = band, p.state == .connected, let rx = rxChar {
            p.writeValue(Data("C3:\n".utf8), for: rx, type: .withoutResponse)
            return
        }
        if let p = central.retrievePeripherals(withIdentifiers: [id]).first {
            band = p; p.delegate = self; reconnect(p)
        }
        // Explicit service UUID so the scan works when backgrounded (nil scans return nothing there);
        // didDiscover only accepts our bound id anyway.
        central.scanForPeripherals(withServices: [Self.NUS_SERVICE])
    }

    /// Opening the app while ALREADY connected → force a flush (C3) so the latest steps + any data
    /// buffered since the last flush reach the phone (and the server) right now. The reconnect path
    /// already auto-flushes on connect, so this only covers the still-linked case. No-op if not connected.
    public func flushIfConnected() {
        guard let p = band, p.state == .connected, let rx = rxChar else { return }
        p.writeValue(Data("C3:\n".utf8), for: rx, type: .withoutResponse)
    }

    /// End the run on the band (C0): when you tap "End run" in the app, the band finishes too. No-op if
    /// not connected (then the band keeps recording until you finish on its RUN face).
    public func endRunOnBand() {
        guard let p = band, p.state == .connected, let rx = rxChar else { return }
        p.writeValue(Data("C0:\n".utf8), for: rx, type: .withoutResponse)
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
        pendingRunDistanceM = meters
        flushRunDistance()
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
        pendingSteps = (steps, day)
        flushSteps()
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
    /// the band the instant it advertises again. This is the "Reconnect" button's muscle.
    public func reconnectKick() {
        guard central.state == .poweredOn, let id = boundId else { return }
        if let b = band, b.state != .disconnected { central.cancelPeripheralConnection(b) }
        rxChar = nil
        if let p = central.retrievePeripherals(withIdentifiers: [id]).first {
            band = p; p.delegate = self; reconnect(p)
        }
        // Explicit service UUID so the scan works when backgrounded; didDiscover only accepts our bound id.
        central.scanForPeripherals(withServices: [Self.NUS_SERVICE])
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

    /// True if `adv`/peripheral looks like a Titan band.
    private func looksLikeBand(_ p: CBPeripheral, _ adv: [String: Any]) -> Bool {
        let name = p.name ?? (adv[CBAdvertisementDataLocalNameKey] as? String) ?? ""
        let uuids = adv[CBAdvertisementDataServiceUUIDsKey] as? [CBUUID] ?? []
        return name.hasPrefix("Bangle") || uuids.contains(Self.NUS_SERVICE)
    }
}

extension BandManager: CBCentralManagerDelegate {
    public func centralManagerDidUpdateState(_ c: CBCentralManager) {
        guard c.state == .poweredOn else { return }
        if pairing { c.scanForPeripherals(withServices: nil); return }   // pairing scan stays unfiltered (foreground)
        guard let id = boundId else { return }           // not paired yet — wait for startPairing()
        // ALWAYS-ON: a bound band is held connected 24/7 — no power-gating. Re-arm a pending connect to
        // the known peripheral (works in the background, no scan).
        if let p = c.retrievePeripherals(withIdentifiers: [id]).first {
            band = p; p.delegate = self
            if p.state != .connected { reconnect(p) }
        }
        // Also scan so we catch the band the moment it advertises. MUST filter on the service UUID — a
        // `nil` scan returns nothing while backgrounded (iOS rule); didDiscover only accepts our bound id.
        c.scanForPeripherals(withServices: [Self.NUS_SERVICE])
    }

    /// FIRST callback when iOS relaunches a terminated app for a BLE event.
    public func centralManager(_ c: CBCentralManager, willRestoreState dict: [String: Any]) {
        if let p = (dict[CBCentralManagerRestoredStatePeripheralsKey] as? [CBPeripheral])?
            .first(where: { boundId == nil || $0.identifier == boundId }) {
            band = p
            p.delegate = self
            if p.state != .connected { reconnect(p) }   // always-on: re-arm unconditionally
        }
    }

    public func centralManager(_ c: CBCentralManager, didDiscover p: CBPeripheral,
                               advertisementData: [String: Any], rssi: NSNumber) {
        guard looksLikeBand(p, advertisementData) else { return }
        if pairing {
            let name = p.name ?? (advertisementData[CBAdvertisementDataLocalNameKey] as? String) ?? ""
            let code = String(name.replacingOccurrences(of: " ", with: "").suffix(4)).uppercased()
            candidates[p.identifier] = (p, rssi.intValue, code)
            onCandidates?(candidates.values
                .map { PairCandidate(id: $0.peripheral.identifier, code: $0.code, rssi: $0.rssi) }
                .sorted { $0.rssi > $1.rssi })
            return
        }
        guard p.identifier == boundId else { return }   // our band — always (re)connect it
        band = p; p.delegate = self
        c.stopScan()
        reconnect(p)
    }

    public func centralManager(_ c: CBCentralManager, didConnect p: CBPeripheral) {
        c.stopScan()   // we're connected — stop the discovery scan (a connected band never re-advertises,
                       // so didDiscover can't stop it; without this a scan ran all session)
        // NOTE: we do NOT report LIVE here — CB `.connected` isn't proof of data. The staleness watchdog
        // flips us LIVE on the first real frame and STALE if a "connected" link goes silent.
        lastFrameAt = .distantPast
        startLiveWatchdog()
        p.discoverServices([Self.NUS_SERVICE, Self.BATTERY_SERVICE])
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
    /// always-on policy) unless the OS is already reconnecting for us.
    private func handleDisconnect(_ p: CBPeripheral, error: Error?, osIsReconnecting: Bool) {
        stopLiveWatchdog()
        setLive(false)
        router.flush(live: false)
        guard p.identifier == boundId else { return }
        // We deliberately cancelled (error == nil, e.g. unbind) → don't fight it. Otherwise re-arm, but
        // only if the OS isn't already handling the reconnect for us (iOS 17 auto-reconnect).
        let weCancelled = (error == nil)
        if !osIsReconnecting && !weCancelled { reconnect(p) }
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
        if rxChar != nil { syncTime() }   // push the phone's local time + timezone to the band
    }

    /// Send the phone's current UTC time + timezone offset so the band's clock is always correct
    /// Re-push the phone's clock to the band on demand (e.g. app foreground). A band whose clock drifted
    /// or was never synced (fresh reflash) otherwise stamps workouts with a wrong time — the "logged 11 h
    /// ago even though I just did it" bug. No-op if not connected.
    public func syncClockIfConnected() {
        guard let p = band, p.state == .connected, rxChar != nil else { return }
        syncTime()
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
    /// updates (the standard 0x2A19 char) split off to onBattery; everything else is a frame.
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
