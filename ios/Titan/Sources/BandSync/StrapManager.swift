import Foundation
import CoreBluetooth

/// BLE connection to a standard heart-rate chest strap (Polar H10, Garmin HRM, Wahoo TICKR…).
///
/// Wrist PPG is reference-grade at rest/sleep but unreliable during running and unusable during
/// lifting (grip + isometric occlusion). A chest strap reads the heart's electrical signal and is
/// immune to that — so during workouts we prefer it. This is a SECOND `CBCentralManager` (its own
/// restore id) running alongside `BandManager`: the band keeps doing 24/7 PPG/steps/sleep while the
/// strap supplies workout HR. Both can be connected at once.
///
/// It speaks the generic BLE Heart Rate Service (0x180D / 0x2A37), so it works with ANY standard
/// strap — no vendor app, login, or SDK. Pairs once (closest strap, remembered by identifier) and
/// auto-reconnects, mirroring BandManager.
public final class StrapManager: NSObject {
    public static let HR_SERVICE = CBUUID(string: "180D")        // standard Heart Rate Service
    public static let HR_MEASUREMENT = CBUUID(string: "2A37")    // Heart Rate Measurement (notify)
    public static let BATTERY_SERVICE = CBUUID(string: "180F")
    public static let BATTERY_LEVEL = CBUUID(string: "2A19")
    private static let restoreId = "com.titan.strap.central"
    private static let boundKey = "titan.strap.peripheralUUID"

    private var central: CBCentralManager!
    private var strap: CBPeripheral?
    private var boundId: UUID?
    private var pairing = false
    private var candidates: [UUID: (peripheral: CBPeripheral, rssi: Int, name: String)] = [:]
    private var pairTimeout: Timer?

    /// A nearby strap shown in the pairing picker.
    public struct StrapCandidate: Identifiable, Equatable {
        public let id: UUID
        public let name: String     // e.g. "Polar H10 ABC123"
        public let rssi: Int
    }

    /// A heart-rate reading (bpm) with the phone-clock timestamp it arrived (ms since epoch, the same
    /// base the band syncs to, so it lines up in the workout assembler).
    public var onHr: ((UInt8, UInt64) -> Void)?
    public var onConnectionChange: ((Bool) -> Void)?
    public var onBattery: ((Int) -> Void)?
    public var onPaired: ((Bool) -> Void)?
    public var onCandidates: (([StrapCandidate]) -> Void)?

    public override init() {
        super.init()
        if let s = UserDefaults.standard.string(forKey: Self.boundKey) { boundId = UUID(uuidString: s) }
        central = CBCentralManager(delegate: self, queue: nil,
            options: [CBCentralManagerOptionRestoreIdentifierKey: Self.restoreId])
    }

    public var isBound: Bool { boundId != nil }
    public var isConnected: Bool { strap?.state == .connected }

    /// Start a fresh pairing: scan for HR straps and surface them by name; the user taps theirs.
    public func startPairing() {
        pairing = true
        candidates = [:]
        onCandidates?([])
        if central.state == .poweredOn { central.scanForPeripherals(withServices: [Self.HR_SERVICE]) }
        pairTimeout?.invalidate()
        pairTimeout = Timer.scheduledTimer(withTimeInterval: 120, repeats: false) { [weak self] _ in
            guard let self, self.pairing else { return }
            self.pairing = false
            self.central.stopScan()
            self.onPaired?(false)
        }
    }

    public func bind(to id: UUID) {
        guard let c = candidates[id] else { return }
        pairing = false
        pairTimeout?.invalidate()
        boundId = id
        UserDefaults.standard.set(id.uuidString, forKey: Self.boundKey)
        strap = c.peripheral
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

    /// Forget the bound strap.
    public func unbind() {
        boundId = nil
        UserDefaults.standard.removeObject(forKey: Self.boundKey)
        if let s = strap, s.state != .disconnected { central.cancelPeripheralConnection(s) }
        strap = nil
        onConnectionChange?(false)
    }

    /// No-timeout connect (survives out-of-range + app termination; the system wakes us on events).
    private func reconnect(_ p: CBPeripheral) {
        central.connect(p, options: [
            CBConnectPeripheralOptionNotifyOnConnectionKey: true,
            CBConnectPeripheralOptionNotifyOnDisconnectionKey: true,
            CBConnectPeripheralOptionNotifyOnNotificationKey: true,
        ])
    }

    /// Parse a Heart Rate Measurement (0x2A37): flags byte, then 8- or 16-bit HR. (RR intervals, if
    /// present, follow — reserved for a future in-workout-HRV pass; we read bpm here.)
    private func parseHr(_ d: Data) -> UInt8? {
        guard d.count >= 2 else { return nil }
        let flags = d[0]
        if flags & 0x01 == 0 {
            return d[1]                                   // uint8 bpm
        }
        guard d.count >= 3 else { return nil }
        let bpm16 = UInt16(d[1]) | (UInt16(d[2]) << 8)    // uint16 bpm
        return UInt8(min(bpm16, 255))
    }
}

extension StrapManager: CBCentralManagerDelegate {
    public func centralManagerDidUpdateState(_ c: CBCentralManager) {
        guard c.state == .poweredOn else { return }
        if pairing { c.scanForPeripherals(withServices: [Self.HR_SERVICE]); return }
        guard let id = boundId else { return }
        if let p = c.retrievePeripherals(withIdentifiers: [id]).first {
            strap = p; p.delegate = self
            if p.state != .connected { reconnect(p) }
        }
        c.scanForPeripherals(withServices: [Self.HR_SERVICE])
    }

    public func centralManager(_ c: CBCentralManager, willRestoreState dict: [String: Any]) {
        if let p = (dict[CBCentralManagerRestoredStatePeripheralsKey] as? [CBPeripheral])?
            .first(where: { boundId == nil || $0.identifier == boundId }) {
            strap = p
            p.delegate = self
            if p.state != .connected { reconnect(p) }
        }
    }

    public func centralManager(_ c: CBCentralManager, didDiscover p: CBPeripheral,
                               advertisementData: [String: Any], rssi: NSNumber) {
        if pairing {
            let name = p.name ?? (advertisementData[CBAdvertisementDataLocalNameKey] as? String) ?? "Heart-rate strap"
            candidates[p.identifier] = (p, rssi.intValue, name)
            onCandidates?(candidates.values
                .map { StrapCandidate(id: $0.peripheral.identifier, name: $0.name, rssi: $0.rssi) }
                .sorted { $0.rssi > $1.rssi })
            return
        }
        guard p.identifier == boundId else { return }
        strap = p; p.delegate = self
        c.stopScan()
        reconnect(p)
    }

    public func centralManager(_ c: CBCentralManager, didConnect p: CBPeripheral) {
        onConnectionChange?(true)
        p.discoverServices([Self.HR_SERVICE, Self.BATTERY_SERVICE])
    }

    public func centralManager(_ c: CBCentralManager, didDisconnectPeripheral p: CBPeripheral, error: Error?) {
        onConnectionChange?(false)
        if p.identifier == boundId { reconnect(p) }   // straps drop between workouts; re-arm
    }
}

extension StrapManager: CBPeripheralDelegate {
    public func peripheral(_ p: CBPeripheral, didDiscoverServices error: Error?) {
        for s in p.services ?? [] {
            if s.uuid == Self.BATTERY_SERVICE { p.discoverCharacteristics([Self.BATTERY_LEVEL], for: s) }
            else { p.discoverCharacteristics([Self.HR_MEASUREMENT], for: s) }
        }
    }

    public func peripheral(_ p: CBPeripheral, didDiscoverCharacteristicsFor s: CBService, error: Error?) {
        for ch in s.characteristics ?? [] {
            if ch.uuid == Self.HR_MEASUREMENT { p.setNotifyValue(true, for: ch) }
            if ch.uuid == Self.BATTERY_LEVEL { p.readValue(for: ch); p.setNotifyValue(true, for: ch) }
        }
    }

    public func peripheral(_ p: CBPeripheral, didUpdateValueFor ch: CBCharacteristic, error: Error?) {
        guard let d = ch.value else { return }
        if ch.uuid == Self.BATTERY_LEVEL {
            if let pct = d.first { onBattery?(Int(pct)) }
            return
        }
        if ch.uuid == Self.HR_MEASUREMENT, let bpm = parseHr(d), bpm > 0 {
            onHr?(bpm, UInt64(Date().timeIntervalSince1970 * 1000))
        }
    }
}
