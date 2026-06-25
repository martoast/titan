import SwiftUI

/// Band pairing + live status. Replaces the web bridge — the in-app CoreBluetooth manager keeps
/// the band synced in the background; no Bluefy, no manual connect.
struct DevicesView: View {
    @EnvironmentObject var model: AppModel
    @Environment(\.dismiss) private var dismiss

    var body: some View {
        VStack(spacing: Theme.Space.m) {
            hero.padding(.top, Theme.Space.s)

            if model.isBandPaired {
                Button { Haptic.rigid(); model.syncBand() } label: {
                    HStack(spacing: 8) {
                        if model.bandSyncing { ProgressView().tint(.white) }
                        else { Image(systemName: "arrow.triangle.2.circlepath") }
                        Text(model.bandSyncing ? "Syncing your band…" : "Sync now")
                    }
                    .font(Theme.Font.body.weight(.semibold))
                    .frame(maxWidth: .infinity).padding(.vertical, 14)
                    .background(Theme.Grad.brand, in: RoundedRectangle(cornerRadius: Theme.Radius.chip))
                    .foregroundStyle(.white)
                }.disabled(model.bandSyncing)
                if let t = model.lastBandSyncAt {
                    Text("Last synced \(t.formatted(.relative(presentation: .named)))")
                        .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
                } else {
                    Text("Wear it overnight, then tap to pull your night in the morning.")
                        .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint).multilineTextAlignment(.center)
                }

                // Stuck on "Searching…"? Force a fresh BLE attempt without losing the pairing.
                if !model.bandConnected {
                    Button { Haptic.rigid(); model.reconnectBand() } label: {
                        HStack(spacing: 8) {
                            Image(systemName: "arrow.clockwise")
                            Text("Reconnect")
                        }
                        .font(Theme.Font.body.weight(.semibold))
                        .frame(maxWidth: .infinity).padding(.vertical, 12)
                        .background(Theme.Palette.bg2, in: RoundedRectangle(cornerRadius: Theme.Radius.chip))
                        .overlay(RoundedRectangle(cornerRadius: Theme.Radius.chip).strokeBorder(Theme.Palette.cardStroke))
                        .foregroundStyle(Theme.Palette.text)
                    }
                }

                // Last resort: drop the binding and pick the band again from scratch.
                Button { Haptic.rigid(); Task { await model.repairBand() } } label: {
                    Text("Forget band & re-pair")
                        .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim).underline()
                }.frame(maxWidth: .infinity).padding(.top, 2)
            }

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
                if model.pairing {
                    GlassCard {
                        VStack(alignment: .leading, spacing: Theme.Space.m) {
                            SectionHeader(title: "Pick your band", trailing: "code on screen")
                            Text("Your band is showing a 4-character code. Tap the matching one below — this binds the app to YOUR band only.")
                                .font(Theme.Font.body).foregroundStyle(Theme.Palette.textDim)
                            if model.pairCandidates.isEmpty {
                                HStack(spacing: Theme.Space.s) {
                                    ProgressView().tint(Theme.Palette.indigo)
                                    Text("Searching… make sure the band shows a code (hold its button 3s).")
                                        .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
                                }.padding(.vertical, 6)
                            } else {
                                ForEach(model.pairCandidates) { cand in
                                    Button { model.bindBand(cand.id) } label: {
                                        HStack(spacing: Theme.Space.m) {
                                            Text(cand.code).font(Theme.Font.num(22)).foregroundStyle(Theme.Palette.cyan).monospaced()
                                            Spacer()
                                            SignalBars(rssi: cand.rssi)
                                            Image(systemName: "chevron.right").font(.caption).foregroundStyle(Theme.Palette.textFaint)
                                        }
                                        .padding(.vertical, 11).padding(.horizontal, Theme.Space.m)
                                        .background(Theme.Palette.bg2, in: RoundedRectangle(cornerRadius: Theme.Radius.chip))
                                        .overlay(RoundedRectangle(cornerRadius: Theme.Radius.chip).strokeBorder(Theme.Palette.cardStroke))
                                    }.buttonStyle(PressCard())
                                }
                            }
                            Button { model.cancelPairing() } label: {
                                Text("Cancel").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                            }.frame(maxWidth: .infinity)
                        }
                    }
                } else {
                    GlassCard {
                        VStack(alignment: .leading, spacing: Theme.Space.m) {
                            SectionHeader(title: "Pair your band")
                            VStack(alignment: .leading, spacing: Theme.Space.s) {
                                pairStep("1", "On the band, triple-tap the button until it shows PAIR + a code.")
                                pairStep("2", "Tap Pair below, then pick that code in the app.")
                            }
                            Text("We bind to the exact band you pick — so two bands side by side never cross-connect.")
                                .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
                            Button {
                                Haptic.rigid()
                                Task { await model.pairBand() }
                            } label: {
                                Text("Pair Titan band").font(Theme.Font.body.weight(.semibold))
                                    .frame(maxWidth: .infinity).padding(.vertical, 14)
                                    .background(Theme.Grad.brand, in: RoundedRectangle(cornerRadius: Theme.Radius.chip))
                                    .foregroundStyle(.white)
                            }
                        }
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
        .toolbar { ToolbarItem(placement: .confirmationAction) { Button("Done") { dismiss() } } }
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
                if let batt = model.bandBattery {
                    HStack(spacing: 6) {
                        Image(systemName: batteryIcon(batt)).foregroundStyle(batteryColor(batt))
                        Text("\(batt)%").font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.text).monospacedDigit()
                        Text("band").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                    }
                    .padding(.horizontal, Theme.Space.m).padding(.vertical, 7)
                    .background(batteryColor(batt).opacity(0.12), in: Capsule())
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
        if model.bandConnected { return "Band connected" }
        if !model.isBandPaired { return "No band yet" }
        return model.bandIdle ? "Power-saving" : "Searching…"
    }
    private var statusSub: String {
        if model.bandConnected { return "Live — syncing in real time." }
        if !model.isBandPaired { return "Pair your Titan band to begin." }
        return model.bandIdle
            ? "Band's on its own, saving battery. It syncs in bursts and the moment you open the app."
            : "Reconnects automatically when it's near."
    }

    private func pairStep(_ n: String, _ text: String) -> some View {
        HStack(alignment: .top, spacing: Theme.Space.s) {
            Text(n).font(Theme.Font.micro).foregroundStyle(.white)
                .frame(width: 20, height: 20).background(Theme.Palette.indigo, in: Circle())
            Text(text).font(Theme.Font.body).foregroundStyle(Theme.Palette.textDim)
        }
    }

    private func batteryColor(_ p: Int) -> Color {
        p < 15 ? Theme.Palette.pink : (p < 35 ? Theme.Palette.amber : Theme.Palette.mint)
    }
    private func batteryIcon(_ p: Int) -> String {
        if p >= 88 { return "battery.100" }
        if p >= 60 { return "battery.75" }
        if p >= 35 { return "battery.50" }
        if p >= 12 { return "battery.25" }
        return "battery.0"
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
