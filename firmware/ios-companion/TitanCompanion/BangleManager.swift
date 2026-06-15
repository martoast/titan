import Foundation
import CoreBluetooth

/// Owns the BLE link to the Bangle.js and drives decode → window → upload.
///
/// iOS overnight strategy (the whole reason this native app exists — Web Bluetooth
/// can't do this):
///   • `bluetooth-central` background mode + a CBCentralManager restore identifier, so
///     iOS relaunches us into the background and hands back the live connection if the
///     app is evicted overnight (`willRestoreState`).
///   • A *pending* `connect()` (no timeout) auto-reconnects when the band comes back in
///     range — works while suspended, no scanning needed once paired.
///   • Subscribed-characteristic notifications wake the app briefly in the background to
///     decode + (every ~2 min) upload a window.
final class BangleManager: NSObject, ObservableObject {
    static let nusService = CBUUID(string: "6e400001-b5a3-f393-e0a9-e50e24dcca9e")
    static let nusTX = CBUUID(string: "6e400003-b5a3-f393-e0a9-e50e24dcca9e")
    private let knownKey = "titan.peripheralUUID"

    @Published var status = "Idle"
    @Published var connected = false
    @Published var windowsSent = 0
    @Published var samples = 0
    @Published var buffered = 0
    @Published var lastSync: Date?

    let settings: Settings
    private var central: CBCentralManager!
    private var peripheral: CBPeripheral?
    private let decoder = FrameDecoder()
    private let assembler = WindowAssembler()
    private let uploader: IngestUploader

    init(settings: Settings) {
        self.settings = settings
        self.uploader = IngestUploader(settings: settings)
        super.init()
        uploader.onResult = { [weak self] ok, msg, n in
            self?.status = msg
            self?.buffered = n
            if ok { self?.lastSync = Date() }
        }
        // queue: nil → callbacks on the main queue, so @Published writes are safe.
        central = CBCentralManager(delegate: self, queue: nil,
            options: [CBCentralManagerOptionRestoreIdentifierKey: "titan.central"])
    }

    func start() {
        guard central.state == .poweredOn else { status = "Waiting for Bluetooth…"; return }
        connectKnownOrScan()
    }

    func stop() {
        if let p = peripheral { central.cancelPeripheralConnection(p) }
        central.stopScan()
        connected = false
        status = "Stopped"
    }

    private func connectKnownOrScan() {
        if let s = UserDefaults.standard.string(forKey: knownKey),
           let uuid = UUID(uuidString: s),
           let p = central.retrievePeripherals(withIdentifiers: [uuid]).first {
            peripheral = p
            p.delegate = self
            status = "Reconnecting…"
            central.connect(p, options: nil)   // pending connect; fires in background when in range
        } else {
            status = "Scanning for Bangle…"
            central.scanForPeripherals(withServices: [Self.nusService], options: nil)
        }
    }
}

// MARK: - CBCentralManagerDelegate

extension BangleManager: CBCentralManagerDelegate {
    func centralManagerDidUpdateState(_ central: CBCentralManager) {
        switch central.state {
        case .poweredOn:
            uploader.flushBuffer()
            if settings.isConfigured { connectKnownOrScan() } else { status = "Add device credentials" }
        case .poweredOff: status = "Bluetooth is off"; connected = false
        case .unauthorized: status = "Bluetooth permission needed"
        default: status = "Bluetooth unavailable"
        }
    }

    /// iOS relaunched us into the background — recover the connection it preserved.
    func centralManager(_ central: CBCentralManager, willRestoreState dict: [String: Any]) {
        if let ps = dict[CBCentralManagerRestoredStatePeripheralsKey] as? [CBPeripheral], let p = ps.first {
            peripheral = p
            p.delegate = self
            status = "Restored connection"
            if p.state == .connected { p.discoverServices([Self.nusService]) }
            else { central.connect(p, options: nil) }
        }
    }

    func centralManager(_ central: CBCentralManager, didDiscover peripheral: CBPeripheral,
                        advertisementData: [String: Any], rssi RSSI: NSNumber) {
        central.stopScan()
        self.peripheral = peripheral
        peripheral.delegate = self
        status = "Connecting…"
        central.connect(peripheral, options: nil)
    }

    func centralManager(_ central: CBCentralManager, didConnect peripheral: CBPeripheral) {
        UserDefaults.standard.set(peripheral.identifier.uuidString, forKey: knownKey)
        status = "Discovering…"
        peripheral.discoverServices([Self.nusService])
    }

    func centralManager(_ central: CBCentralManager, didDisconnectPeripheral peripheral: CBPeripheral, error: Error?) {
        connected = false
        status = "Disconnected — will reconnect"
        if let w = assembler.flush() { send(w) }   // don't lose the partial window
        central.connect(peripheral, options: nil)  // pending reconnect
    }

    func centralManager(_ central: CBCentralManager, didFailToConnect peripheral: CBPeripheral, error: Error?) {
        central.connect(peripheral, options: nil)  // keep retrying
    }
}

// MARK: - CBPeripheralDelegate

extension BangleManager: CBPeripheralDelegate {
    func peripheral(_ peripheral: CBPeripheral, didDiscoverServices error: Error?) {
        peripheral.services?.filter { $0.uuid == Self.nusService }
            .forEach { peripheral.discoverCharacteristics([Self.nusTX], for: $0) }
    }

    func peripheral(_ peripheral: CBPeripheral, didDiscoverCharacteristicsFor service: CBService, error: Error?) {
        service.characteristics?.filter { $0.uuid == Self.nusTX }
            .forEach { peripheral.setNotifyValue(true, for: $0) }
    }

    func peripheral(_ peripheral: CBPeripheral, didUpdateNotificationStateFor characteristic: CBCharacteristic, error: Error?) {
        connected = characteristic.isNotifying
        if characteristic.isNotifying { status = "Streaming" }
    }

    func peripheral(_ peripheral: CBPeripheral, didUpdateValueFor characteristic: CBCharacteristic, error: Error?) {
        guard let data = characteristic.value else { return }
        let decoded = decoder.feed(data)
        if decoded.isEmpty { return }
        samples += decoded.count
        if let window = assembler.add(decoded) { send(window) }
    }

    private func send(_ window: PPGWindow) {
        windowsSent += 1
        uploader.send(window)
    }
}
