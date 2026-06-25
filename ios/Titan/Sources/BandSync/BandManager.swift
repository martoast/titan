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
    /// Burst-sync policy: do we currently WANT a live link? Foreground / workout / an explicit sync →
    /// true (hold + auto-reconnect). Idle in the background → false (disconnect and stop re-arming, so
    /// the band drops to its low-power offline duty-cycle). Every auto-connect path is gated on this.
    /// Defaults true so first launch + a background relaunch connect; AppModel drives it from there.
    private var wantsConnection = true
    /// True only during a fresh pair: collect nearby bands so the user picks theirs by code.
    private var pairing = false
    private var candidates: [UUID: (peripheral: CBPeripheral, rssi: Int, code: String)] = [:]
    private var pairTimeout: Timer?

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
        if let b = band, b.state != .disconnected { central.cancelPeripheralConnection(b) }
        band = nil
    }

    public var isConnected: Bool { band?.state == .connected }

    /// Burst-sync control. `on=true` → hold/establish the live link (foreground, workout, a sync burst).
    /// `on=false` → drop it and stop re-arming, so the band falls into its low-power offline duty-cycle.
    /// The firmware auto-flushes its buffered trend on every reconnect, so each time we come back the
    /// day's data syncs itself — no held connection needed. This is what makes all-day wear practical.
    public func setDesiredConnection(_ on: Bool) {
        guard on != wantsConnection else { return }
        wantsConnection = on
        guard central.state == .poweredOn, let id = boundId else { return }
        if on {
            if band?.state != .connected {
                if let p = central.retrievePeripherals(withIdentifiers: [id]).first {
                    band = p; p.delegate = self; reconnect(p)
                }
                central.scanForPeripherals(withServices: nil)   // catch it the moment it advertises
            }
        } else {
            central.stopScan()
            if let b = band, b.state != .disconnected { central.cancelPeripheralConnection(b) }
        }
    }

    /// Manual "Sync now" (for free accounts with no background BLE): if the band is connected, ask it
    /// to flush its overnight ring buffer right now (C3); otherwise kick a connect to the bound band —
    /// the firmware auto-flushes on connect. Either way the whole night transfers on demand.
    public func syncNow() {
        wantsConnection = true   // an explicit sync overrides idle power-saving
        guard central.state == .poweredOn, let id = boundId else { return }
        if let p = band, p.state == .connected, let rx = rxChar {
            p.writeValue(Data("C3:\n".utf8), for: rx, type: .withoutResponse)
            return
        }
        if let p = central.retrievePeripherals(withIdentifiers: [id]).first {
            band = p; p.delegate = self; reconnect(p)
        }
        central.scanForPeripherals(withServices: nil)   // didDiscover only accepts our bound id
    }

    /// Force a fresh connection attempt when we're paired but stuck — advertised-but-never-connected,
    /// a half-open link, or a Bluetooth stack that's wedged. Tears down any existing connection to the
    /// bound band, drops the cached write char, then re-arms connect AND restarts a scan so we catch
    /// the band the instant it advertises again. This is the "Reconnect" button's muscle.
    public func reconnectKick() {
        wantsConnection = true   // the user asked to reconnect — override idle power-saving
        guard central.state == .poweredOn, let id = boundId else { return }
        if let b = band, b.state != .disconnected { central.cancelPeripheralConnection(b) }
        rxChar = nil
        if let p = central.retrievePeripherals(withIdentifiers: [id]).first {
            band = p; p.delegate = self; reconnect(p)
        }
        central.scanForPeripherals(withServices: nil)   // didDiscover only accepts our bound id
    }

    /// No-timeout connect (survives out-of-range + termination); system wakes us on events.
    private func reconnect(_ p: CBPeripheral) {
        central.connect(p, options: [
            CBConnectPeripheralOptionNotifyOnConnectionKey: true,
            CBConnectPeripheralOptionNotifyOnDisconnectionKey: true,
            CBConnectPeripheralOptionNotifyOnNotificationKey: true,
        ])
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
        if pairing { c.scanForPeripherals(withServices: nil); return }
        guard let id = boundId else { return }           // not paired yet — wait for startPairing()
        guard wantsConnection else { return }            // idle power-saving — don't auto-connect
        // Re-arm a pending connect to the known peripheral (works in the background, no scan).
        if let p = c.retrievePeripherals(withIdentifiers: [id]).first {
            band = p; p.delegate = self
            if p.state != .connected { reconnect(p) }
        }
        // Also scan (foreground) so we catch the band the moment it advertises; didDiscover only
        // accepts our bound identifier.
        c.scanForPeripherals(withServices: nil)
    }

    /// FIRST callback when iOS relaunches a terminated app for a BLE event.
    public func centralManager(_ c: CBCentralManager, willRestoreState dict: [String: Any]) {
        if let p = (dict[CBCentralManagerRestoredStatePeripheralsKey] as? [CBPeripheral])?
            .first(where: { boundId == nil || $0.identifier == boundId }) {
            band = p
            p.delegate = self
            if p.state != .connected && wantsConnection { reconnect(p) }
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
        guard p.identifier == boundId, wantsConnection else { return }   // our band, and only if we want a link
        band = p; p.delegate = self
        c.stopScan()
        reconnect(p)
    }

    public func centralManager(_ c: CBCentralManager, didConnect p: CBPeripheral) {
        onConnectionChange?(true)
        p.discoverServices([Self.NUS_SERVICE, Self.BATTERY_SERVICE])
    }

    public func centralManager(_ c: CBCentralManager, didDisconnectPeripheral p: CBPeripheral, error: Error?) {
        onConnectionChange?(false)
        router.flush(live: false)
        if p.identifier == boundId && wantsConnection { reconnect(p) }   // re-arm only while we want a link
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
        if ch.uuid == Self.BATTERY_LEVEL {
            if let pct = d.first { onBattery?(Int(pct)) }
            return
        }
        router.ingest(d)
    }
}
