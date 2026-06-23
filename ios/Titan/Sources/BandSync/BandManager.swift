import Foundation
import CoreBluetooth

/// Always-on background BLE connection to the Titan band (Bangle.js / Nordic UART Service).
/// Implements the Whoop-style pattern verified in research: `bluetooth-central` background mode +
/// State Preservation & Restoration (relaunches a terminated app) + no-timeout reconnect
/// re-armed on every disconnect. Decoded data is handed to `FrameRouter`.
/// Design + citations: tasks/native-ios/01-background-ble.md.
///
/// Requires (Xcode): UIBackgroundModes `bluetooth-central`, `NSBluetoothAlwaysUsageDescription`.
public final class BandManager: NSObject {
    public static let NUS_SERVICE = CBUUID(string: "6E400001-B5A3-F393-E0A9-E50E24DCCA9E")
    public static let NUS_TX = CBUUID(string: "6E400003-B5A3-F393-E0A9-E50E24DCCA9E") // notify
    public static let NUS_RX = CBUUID(string: "6E400002-B5A3-F393-E0A9-E50E24DCCA9E") // write
    private static let restoreId = "com.titan.band.central"

    private var central: CBCentralManager!
    private var band: CBPeripheral?
    private var rxChar: CBCharacteristic?
    private let router: FrameRouter

    public var onConnectionChange: ((Bool) -> Void)?

    public init(router: FrameRouter) {
        self.router = router
        super.init()
        central = CBCentralManager(delegate: self, queue: nil,
            options: [CBCentralManagerOptionRestoreIdentifierKey: Self.restoreId])
    }

    /// Re-arm a forever-pending connect (survives out-of-range + termination).
    private func reconnect(_ p: CBPeripheral) {
        central.connect(p, options: [
            CBConnectPeripheralOptionNotifyOnConnectionKey: true,
            CBConnectPeripheralOptionNotifyOnDisconnectionKey: true,
            CBConnectPeripheralOptionNotifyOnNotificationKey: true,
        ])
    }
}

extension BandManager: CBCentralManagerDelegate {
    public func centralManagerDidUpdateState(_ c: CBCentralManager) {
        guard c.state == .poweredOn else { return }
        if band == nil {
            // Background scan REQUIRES an explicit service UUID.
            c.scanForPeripherals(withServices: [Self.NUS_SERVICE])
        } else if band?.state != .connected {
            reconnect(band!)
        }
    }

    /// FIRST callback when iOS relaunches a terminated app for a BLE event.
    public func centralManager(_ c: CBCentralManager, willRestoreState dict: [String: Any]) {
        if let p = (dict[CBCentralManagerRestoredStatePeripheralsKey] as? [CBPeripheral])?.first {
            band = p
            p.delegate = self
            if p.state != .connected { reconnect(p) }
        }
    }

    public func centralManager(_ c: CBCentralManager, didDiscover p: CBPeripheral,
                               advertisementData: [String: Any], rssi: NSNumber) {
        band = p; p.delegate = self
        c.stopScan()
        reconnect(p)
    }

    public func centralManager(_ c: CBCentralManager, didConnect p: CBPeripheral) {
        onConnectionChange?(true)
        p.discoverServices([Self.NUS_SERVICE])
    }

    public func centralManager(_ c: CBCentralManager, didDisconnectPeripheral p: CBPeripheral, error: Error?) {
        onConnectionChange?(false)
        router.flush(live: false)
        reconnect(p)  // re-arm forever
    }
}

extension BandManager: CBPeripheralDelegate {
    public func peripheral(_ p: CBPeripheral, didDiscoverServices error: Error?) {
        p.services?.forEach { p.discoverCharacteristics([Self.NUS_TX, Self.NUS_RX], for: $0) }
    }

    public func peripheral(_ p: CBPeripheral, didDiscoverCharacteristicsFor s: CBService, error: Error?) {
        for ch in s.characteristics ?? [] {
            if ch.uuid == Self.NUS_TX { p.setNotifyValue(true, for: ch) }
            if ch.uuid == Self.NUS_RX { rxChar = ch }
        }
    }

    /// NUS TX stream — fires in the background and wakes a terminated app. Drain fast.
    public func peripheral(_ p: CBPeripheral, didUpdateValueFor ch: CBCharacteristic, error: Error?) {
        guard let d = ch.value else { return }
        router.ingest(d)
    }
}
