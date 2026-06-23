# 01 — Background BLE design (the core of Whoop-parity)

**Verdict: FEASIBLE.** Native iOS fully supports continuous background BLE streaming from the
Bangle.js NUS peripheral — including relaunching a *terminated* app to deliver
notifications/connections. This is the documented mechanism Whoop/Oura/Polar use. Research:
[research A report] (Apple archive "Core Bluetooth Background Processing" + Punch Through +
Apple forums; see Sources at bottom).

## The four pillars

1. **Background mode** — `UIBackgroundModes: [bluetooth-central]` in Info.plist. While
   backgrounded the app can connect, subscribe, and **receive characteristic notifications**.
   Only *scanning* is throttled (coalesced discoveries, longer intervals, and you MUST scan
   with explicit service UUIDs — a nil-services background scan delivers nothing). Once
   **connected**, the NUS TX notification stream flows unthrottled.

2. **State Preservation & Restoration** — the "app killed, still syncing" piece. Init the
   manager with `CBCentralManagerOptionRestoreIdentifierKey` (same string every launch). iOS
   preserves the peripherals being connected and the characteristics subscribed; when a BLE
   event fires after termination it **relaunches the app into the background** and calls
   `centralManager(_:willRestoreState:)` FIRST. Recover the peripheral from
   `CBCentralManagerRestoredStatePeripheralsKey`, re-set its delegate, retain it. Events that
   relaunch: peripheral discovery, connection, and notifications from a subscribed char. You
   get ~10s per wake — drain buffer + persist fast, then yield.

3. **No-timeout reconnect (the Whoop pattern)** — Apple: *"because connection requests do not
   time out, the iOS device will reconnect when the user returns home."* Call
   `connect(peripheral, options:)` once (no timeout exists), and on
   `didDisconnectPeripheral` immediately call `connect()` again to re-arm forever. Pass
   `CBConnectPeripheralOptionNotifyOnConnection/Disconnection/NotificationKey` so the system
   wakes the app around these while suspended.

4. **NUS over CoreBluetooth** — identical semantics to Web Bluetooth:
   - Service `6E400001-B5A3-F393-E0A9-E50E24DCCA9E`
   - TX (notify, device→app) `6E400003-…` → `setNotifyValue(true)`, bytes in `didUpdateValueFor`
   - RX (write, app→device) `6E400002-…` → `writeValue(.withoutResponse)`
   - MTU is auto-negotiated (~185 B, iOS 10+); you can't request it. Read effective size via
     `maximumWriteValueLength(for:)` and chunk writes on it.

## The hard limit (same as Whoop)

iOS will **not** relaunch the app after: **user force-quit** (swipe-away in App Switcher),
**device reboot**, or **Bluetooth turned off**. No API defeats this. After any of those, the
user must open the app once. Standard mitigation used by serious BLE wearables: pair CB
restoration with **Core Location region monitoring** (or significant-location-change /
iBeacon) — region enter/exit relaunches even a force-quit app and gives ~10s to re-arm BLE.
**Decision:** ship v1 with CB restoration; add Core Location region monitoring in a fast
follow to recover the force-quit/reboot case. (Requires `NSLocationWhenInUseUsageDescription`
+ possibly Always; note App Store review will ask why — "re-establish band sync after
force-quit," which is legitimate.)

## Firmware implication (important for the port)

Our firmware streams newline-delimited base64 frames via `Bluetooth.println` over NUS. A T1
frame is ~216 base64 chars — larger than one ~185-byte BLE packet. Espruino's NUS splits
output across packets automatically; **the iOS receiver must buffer incoming bytes and split
on `\n`** (exactly like the web bridge's `this._rx` accumulator). Do NOT assume one
notification == one frame. No firmware change needed — iOS accepts the negotiated MTU and the
existing line protocol works as-is.

## Required Info.plist (BLE only)

```xml
<key>NSBluetoothAlwaysUsageDescription</key>
<string>Titan connects to your recovery band to sync biosignals in the background.</string>
<key>UIBackgroundModes</key>
<array><string>bluetooth-central</string></array>
```
No entitlement / provisioning capability needed for background BLE central (unlike HealthKit).
State restoration needs only the RestoreIdentifier passed in code.

## Reference Swift skeleton (basis for `BandManager.swift`)

```swift
import CoreBluetooth

let NUS_SERVICE = CBUUID(string: "6E400001-B5A3-F393-E0A9-E50E24DCCA9E")
let NUS_TX      = CBUUID(string: "6E400003-B5A3-F393-E0A9-E50E24DCCA9E") // notify
let NUS_RX      = CBUUID(string: "6E400002-B5A3-F393-E0A9-E50E24DCCA9E") // write

final class BandManager: NSObject, CBCentralManagerDelegate, CBPeripheralDelegate {
    var central: CBCentralManager!
    var band: CBPeripheral?
    var rxChar: CBCharacteristic?
    private var rx = Data()   // newline-accumulator, like the bridge's this._rx

    override init() {
        super.init()
        central = CBCentralManager(delegate: self, queue: nil,
            options: [CBCentralManagerOptionRestoreIdentifierKey: "com.titan.band.central"])
    }
    func centralManagerDidUpdateState(_ c: CBCentralManager) {
        guard c.state == .poweredOn else { return }
        if band == nil { c.scanForPeripherals(withServices: [NUS_SERVICE]) } // explicit UUID!
    }
    func centralManager(_ c: CBCentralManager, willRestoreState d: [String: Any]) {
        if let p = (d[CBCentralManagerRestoredStatePeripheralsKey] as? [CBPeripheral])?.first {
            band = p; p.delegate = self
            if p.state != .connected { reconnect(p) }
        }
    }
    func centralManager(_ c: CBCentralManager, didDiscover p: CBPeripheral,
                        advertisementData: [String: Any], rssi: NSNumber) {
        band = p; p.delegate = self; c.stopScan(); reconnect(p)
    }
    func reconnect(_ p: CBPeripheral) {
        central.connect(p, options: [
            CBConnectPeripheralOptionNotifyOnConnectionKey: true,
            CBConnectPeripheralOptionNotifyOnDisconnectionKey: true,
            CBConnectPeripheralOptionNotifyOnNotificationKey: true])
    }
    func centralManager(_ c: CBCentralManager, didConnect p: CBPeripheral) {
        p.discoverServices([NUS_SERVICE])
    }
    func centralManager(_ c: CBCentralManager, didDisconnectPeripheral p: CBPeripheral,
                        error: Error?) { reconnect(p) }   // re-arm forever
    func peripheral(_ p: CBPeripheral, didDiscoverServices e: Error?) {
        p.services?.forEach { p.discoverCharacteristics([NUS_TX, NUS_RX], for: $0) }
    }
    func peripheral(_ p: CBPeripheral, didDiscoverCharacteristicsFor s: CBService, e: Error?) {
        for ch in s.characteristics ?? [] {
            if ch.uuid == NUS_TX { p.setNotifyValue(true, for: ch) }
            if ch.uuid == NUS_RX { rxChar = ch }
        }
    }
    func peripheral(_ p: CBPeripheral, didUpdateValueFor ch: CBCharacteristic, e: Error?) {
        guard let d = ch.value else { return }
        rx.append(d)
        while let nl = rx.firstIndex(of: 0x0A) {           // split on '\n'
            let line = rx.subdata(in: rx.startIndex..<nl)
            rx.removeSubrange(rx.startIndex...nl)
            FrameRouter.handle(line)                       // → T1..T7 decode (see 05)
        }
    }
}
```

## Persistence note
For reconnect after a clean relaunch (not via restoration), persist the peripheral's
`identifier` (UUID) and use `retrievePeripherals(withIdentifiers:)` instead of re-scanning.

## Sources
- Apple — Core Bluetooth Background Processing for iOS Apps (archive):
  https://developer.apple.com/library/archive/documentation/NetworkingInternetWeb/Conceptual/CoreBluetooth_concepts/CoreBluetoothBackgroundProcessingForIOSApps/PerformingTasksWhileYourAppIsInTheBackground.html
- Apple — Core Bluetooth framework: https://developer.apple.com/documentation/corebluetooth/
- Punch Through — Background Bluetooth UX: https://punchthrough.com/leveraging-background-bluetooth-for-a-great-user-experience/
- Punch Through — Core Bluetooth Ultimate Guide: https://punchthrough.com/core-bluetooth-guide/
- Uy Nguyen — BLE in background (state preservation & restoration): https://uynguyen.github.io/2018/07/23/Best-practice-How-to-deal-with-Bluetooth-Low-Energy-in-background/
- Apple Forums — ATT MTU in CoreBluetooth: https://developer.apple.com/forums/thread/73871
