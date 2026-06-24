import SwiftUI

/// Band pairing + live status. Replaces the web bridge — the in-app CoreBluetooth manager keeps
/// the band synced in the background; no Bluefy, no manual connect.
struct DevicesView: View {
    @EnvironmentObject var model: AppModel

    var body: some View {
        VStack(spacing: Theme.Space.m) {
            hero.padding(.top, Theme.Space.s)

            if model.bandConnected {
                GlassCard {
                    VStack(alignment: .leading, spacing: Theme.Space.m) {
                        SectionHeader(title: "Live signal", trailing: model.liveHz > 0 ? "\(model.liveHz) Hz" : nil)
                        WaveformView(samples: model.waveform)
                        HStack(spacing: Theme.Space.m) {
                            liveStat("\(model.syncedSamples)", "samples", Theme.Palette.cyan)
                            divider
                            liveStat("\(model.windowsUploaded)", "uploaded", Theme.Palette.mint)
                            divider
                            liveStat(model.liveBpm.map { "\($0)" } ?? "—", "bpm", Theme.Palette.pink)
                        }
                    }
                }
            }

            if !model.isBandPaired {
                GlassCard {
                    VStack(alignment: .leading, spacing: Theme.Space.m) {
                        SectionHeader(title: "Pair your band")
                        Text(model.pairing
                             ? "Hold YOUR band right against the phone so we bind to the correct one — then it'll only ever connect to this band."
                             : "Wake your band (tap its button), hold it against the phone, then pair. We lock onto the nearest band so it never grabs someone else's nearby.")
                            .font(Theme.Font.body).foregroundStyle(Theme.Palette.textDim)
                        Button {
                            Haptic.rigid()
                            Task { await model.pairBand() }
                        } label: {
                            HStack {
                                if model.pairing { ProgressView().tint(.white) }
                                Text(model.pairing ? "Hold band close…" : "Pair Titan band").font(Theme.Font.body.weight(.semibold))
                            }
                            .frame(maxWidth: .infinity).padding(.vertical, 14)
                            .background(Theme.Grad.brand, in: RoundedRectangle(cornerRadius: Theme.Radius.chip))
                            .foregroundStyle(.white)
                        }.disabled(model.pairing)
                    }
                }
            } else {
                GlassCard {
                    VStack(alignment: .leading, spacing: Theme.Space.m) {
                        SectionHeader(title: "Always-on sync")
                        feature("moon.zzz.fill", Theme.Palette.indigo, "Wear it 24/7", "Recovery, sleep, and workouts sync on their own.")
                        feature("antenna.radiowaves.left.and.right", Theme.Palette.mint, "Background connection", "Stays synced even when the app is closed.")
                        feature("bolt.fill", Theme.Palette.amber, "Morning sync", "Your whole night uploads in seconds when you wake.")
                    }
                }
            }

            if let err = model.error {
                Text(err).font(Theme.Font.micro).foregroundStyle(Theme.Palette.pink)
            }
            Color.clear.frame(height: 8)
        }
        .titanScreen("Band", glow: model.bandConnected ? Theme.Palette.mint : Theme.Palette.indigo)
    }

    private var hero: some View {
        GlassCard(padding: Theme.Space.l) {
            VStack(spacing: Theme.Space.m) {
                Radar(active: model.bandConnected)
                VStack(spacing: 4) {
                    Text(statusTitle).font(Theme.Font.title).foregroundStyle(Theme.Palette.text)
                    Text(statusSub).font(Theme.Font.body).foregroundStyle(Theme.Palette.textDim)
                        .multilineTextAlignment(.center)
                }
                if model.bandConnected, let bpm = model.liveBpm {
                    HStack(spacing: 8) {
                        Image(systemName: "heart.fill").foregroundStyle(Theme.Palette.pink)
                            .symbolEffect(.pulse, options: .repeating)
                        Text("\(bpm)").font(Theme.Font.num(28)).contentTransition(.numericText())
                        Text("BPM").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                    }
                    .padding(.horizontal, Theme.Space.m).padding(.vertical, 8)
                    .background(Theme.Palette.pink.opacity(0.12), in: Capsule())
                }
            }
            .frame(maxWidth: .infinity)
        }
    }

    private var statusTitle: String {
        model.bandConnected ? "Band connected" : (model.isBandPaired ? "Searching…" : "No band yet")
    }
    private var statusSub: String {
        model.bandConnected ? "Syncing in the background." : (model.isBandPaired ? "Reconnects automatically when it's near." : "Pair your Titan band to begin.")
    }

    private var divider: some View { Rectangle().fill(Theme.Palette.cardStroke).frame(width: 1, height: 30) }

    private func liveStat(_ value: String, _ label: String, _ color: Color) -> some View {
        VStack(spacing: 3) {
            Text(value).font(Theme.Font.num(20)).foregroundStyle(color).monospacedDigit().contentTransition(.numericText())
            Text(label.uppercased()).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
        }.frame(maxWidth: .infinity)
    }

    private func feature(_ icon: String, _ c: Color, _ title: String, _ sub: String) -> some View {
        HStack(alignment: .top, spacing: Theme.Space.m) {
            Image(systemName: icon).font(.system(size: 17)).foregroundStyle(c).frame(width: 26)
            VStack(alignment: .leading, spacing: 2) {
                Text(title).font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.text)
                Text(sub).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
            }
        }
    }
}

/// Concentric radar that breathes when connected.
private struct Radar: View {
    let active: Bool
    @State private var anim = false
    var body: some View {
        ZStack {
            ForEach(0..<3) { i in
                Circle().stroke(color.opacity(0.25 - Double(i) * 0.07), lineWidth: 1.5)
                    .frame(width: 70 + CGFloat(i) * 38, height: 70 + CGFloat(i) * 38)
                    .scaleEffect(anim && active ? 1.08 : 1)
                    .animation(.easeInOut(duration: 1.8).repeatForever(autoreverses: true).delay(Double(i) * 0.2), value: anim)
            }
            Circle().fill(color.opacity(0.16)).frame(width: 64, height: 64)
            Image(systemName: active ? "applewatch.radiowaves.left.and.right" : "applewatch.slash")
                .font(.system(size: 26, weight: .medium)).foregroundStyle(color)
                .symbolEffect(.pulse, options: active ? .repeating : .nonRepeating)
        }
        .frame(height: 160)
        .onAppear { anim = true }
    }
    private var color: Color { active ? Theme.Palette.mint : Theme.Palette.textDim }
}
