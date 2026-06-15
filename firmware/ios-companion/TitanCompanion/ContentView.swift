import SwiftUI

struct ContentView: View {
    @EnvironmentObject var bangle: BangleManager
    @EnvironmentObject var settings: Settings

    var body: some View {
        NavigationStack {
            Form {
                Section("Status") {
                    HStack {
                        Circle().fill(bangle.connected ? .green : .gray).frame(width: 10, height: 10)
                        Text(bangle.status).font(.callout)
                        Spacer()
                    }
                    LabeledContent("Windows sent", value: "\(bangle.windowsSent)")
                    LabeledContent("PPG samples", value: "\(bangle.samples)")
                    if bangle.buffered > 0 {
                        LabeledContent("Buffered (offline)", value: "\(bangle.buffered)")
                    }
                    if let t = bangle.lastSync {
                        LabeledContent("Last sync", value: t.formatted(date: .omitted, time: .standard))
                    }
                }

                Section("Connection") {
                    Button(bangle.connected ? "Disconnect" : "Connect Bangle") {
                        bangle.connected ? bangle.stop() : bangle.start()
                    }
                    .disabled(!settings.isConfigured)
                    Text("Keep your iPhone near the band overnight. iOS reconnects automatically if the link drops or the app is evicted.")
                        .font(.footnote).foregroundStyle(.secondary)
                }

                Section("Titan") {
                    TextField("Ingest URL (e.g. https://titan.yourhost.com)", text: $settings.ingestURL)
                        .textInputAutocapitalization(.never).autocorrectionDisabled().keyboardType(.URL)
                }

                Section("Device credentials") {
                    TextField("Device ID (bangle_…)", text: $settings.deviceId)
                        .textInputAutocapitalization(.never).autocorrectionDisabled().font(.system(.body, design: .monospaced))
                    SecureField("Secret (one-time, from pairing)", text: $settings.secret)
                        .font(.system(.body, design: .monospaced))
                    Text("Pair a Bangle.js on Titan → Devices, then paste the device ID + secret here. The secret is stored in the iOS Keychain.")
                        .font(.footnote).foregroundStyle(.secondary)
                }
            }
            .navigationTitle("Titan")
        }
    }
}
