import SwiftUI

/// Band pairing + live connection status. This screen replaces the web "bridge" — there's no
/// Bluefy / Web Bluetooth; the in-app CoreBluetooth `BandManager` keeps the band synced in the
/// background. Pairing mints the one-time HMAC secret (stored in Keychain) and starts sync.
struct DevicesView: View {
    @EnvironmentObject var model: AppModel

    var body: some View {
        VStack(spacing: 16) {
            Card {
                HStack(spacing: 12) {
                    Circle().fill(model.bandConnected ? .green : .gray).frame(width: 12, height: 12)
                        .overlay(Circle().fill(.green).opacity(model.bandConnected ? 0.4 : 0).blur(radius: 6))
                    VStack(alignment: .leading) {
                        Text(model.bandConnected ? "Band connected" : (model.isBandPaired ? "Searching for band…" : "No band paired"))
                            .font(.headline)
                        Text(model.bandConnected
                             ? "Syncing in the background — even when the app is closed."
                             : "Keeps trying automatically once paired.")
                            .font(.caption).foregroundStyle(.secondary)
                    }
                    Spacer()
                    if let bpm = model.liveBpm, model.bandConnected {
                        VStack { Text("\(bpm)").font(.title2.weight(.bold)); Text("bpm").font(.caption2).foregroundStyle(.secondary) }
                    }
                }
            }

            if !model.isBandPaired {
                Card("Pair your band") {
                    Text("One tap pairs your Titan band and starts background sync. Make sure the band is awake (tap its button) and nearby.")
                        .font(.subheadline).foregroundStyle(.secondary)
                    Button {
                        Task { await model.pairBand() }
                    } label: {
                        Text("Pair Titan band").bold().frame(maxWidth: .infinity).padding(.vertical, 12)
                    }
                    .background(.indigo, in: RoundedRectangle(cornerRadius: 12)).foregroundStyle(.black)
                }
            } else {
                Card("How it works") {
                    Label("Wear it 24/7 — recovery, sleep, and workouts sync automatically.", systemImage: "moon.zzz.fill")
                    Label("Background sync keeps the band connected even when the app is closed.", systemImage: "antenna.radiowaves.left.and.right")
                    Label("Force-quitting the app stops sync until you reopen it once.", systemImage: "exclamationmark.triangle")
                        .foregroundStyle(.secondary)
                }
                .font(.subheadline)
            }

            if let err = model.error {
                Text(err).font(.footnote).foregroundStyle(.red)
            }
        }
        .screen("Band")
    }
}
