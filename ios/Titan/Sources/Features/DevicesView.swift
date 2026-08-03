import SwiftUI

/// Band pairing + live status. Replaces the web bridge — the in-app CoreBluetooth manager keeps
/// the band synced in the background; no Bluefy, no manual connect.
struct DevicesView: View {
    @EnvironmentObject var model: AppModel
    @Environment(\.dismiss) private var dismiss
    @Environment(\.openURL) private var openURL

    private var btUnavailable: Bool { model.bluetooth == .off || model.bluetooth == .denied }

    var body: some View {
        VStack(spacing: Theme.Space.m) {
            hero.padding(.top, Theme.Space.s)

            if btUnavailable { bluetoothCard }

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
                }.disabled(model.bandSyncing || btUnavailable)
                if model.bandSyncFailed {
                    Text("Couldn't reach your band. Make sure it's on and nearby, then try again.")
                        .font(Theme.Font.micro).foregroundStyle(Theme.Palette.amber).multilineTextAlignment(.center)
                } else if let t = model.lastBandSyncAt {
                    Text("Last synced \(t.formatted(.relative(presentation: .named)))")
                        .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
                } else {
                    Text("Wear it overnight, then tap to pull your night in the morning.")
                        .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint).multilineTextAlignment(.center)
                }

                // Stuck on "Searching…"? Force a fresh BLE attempt without losing the pairing.
                if !model.bandConnected && !btUnavailable {
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
                        SectionHeader(title: String(localized: "Live signal"), trailing: model.liveHz > 0 ? "\(model.liveHz) Hz" : nil)
                        WaveformView(samples: model.waveform)
                        HStack(spacing: Theme.Space.m) {
                            liveStat("\(model.syncedSamples)", "samples", Theme.Palette.cyan)
                            divider
                            liveStat("\(model.windowsUploaded)", "uploaded", Theme.Palette.mint)
                            divider
                            liveStat(model.liveBpm.map { "\($0)" } ?? "—", "bpm", Theme.Palette.pink)
                        }
                        if model.bandStepsToday > 0 {
                            Divider().overlay(Theme.Palette.cardStroke)
                            HStack(spacing: Theme.Space.m) {
                                Image(systemName: "figure.walk").foregroundStyle(Theme.Palette.mint)
                                Text("\(model.bandStepsToday)").font(Theme.Font.num(22)).foregroundStyle(Theme.Palette.text).monospacedDigit().contentTransition(.numericText())
                                Text("steps today").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                                Spacer()
                                Text("LIVE").font(Theme.Font.micro).tracking(1).foregroundStyle(Theme.Palette.mint)
                            }
                        }
                    }
                }
            }

            // Phone GPS (band has none) — shown regardless of band connection.
            if model.isBandPaired { gpsTestCard }

            strapCard

            if !model.isBandPaired {
                if model.pairing {
                    GlassCard {
                        VStack(alignment: .leading, spacing: Theme.Space.m) {
                            SectionHeader(title: String(localized: "Pick your band"), trailing: String(localized: "code on screen"))
                            Text("Your band is showing a 4-character code. Tap the matching one below — this binds the app to YOUR band only.")
                                .font(Theme.Font.body).foregroundStyle(Theme.Palette.textDim)
                            if model.pairCandidates.isEmpty {
                                HStack(spacing: Theme.Space.s) {
                                    ProgressView().tint(Theme.Palette.indigo)
                                    Text("Searching… make sure the band shows a code (swipe to the Status face, then click the button).")
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
                            SectionHeader(title: String(localized: "Pair your band"))
                            VStack(alignment: .leading, spacing: Theme.Space.s) {
                                pairStep("1", "On the band, swipe to the Status face and click the button — it shows PAIR + a code.")
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
                                    .foregroundStyle(.white).opacity(btUnavailable ? 0.5 : 1)
                            }.disabled(btUnavailable)
                        }
                    }
                }
            } else {
                GlassCard {
                    VStack(alignment: .leading, spacing: Theme.Space.m) {
                        SectionHeader(title: String(localized: "Always-on sync"))
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
        .titanScreen(String(localized: "Band"), glow: model.bandConnected ? Theme.Palette.mint : Theme.Palette.indigo)
        // Stream the live trace only while this screen is up (heat), and make sure the Bluetooth card is right.
        .onAppear { model.liveSignalAppeared(); model.refreshBluetooth() }
        .onDisappear { model.liveSignalDisappeared() }
    }

    /// Shown when Bluetooth is off or the app's Bluetooth permission is denied — an actionable card
    /// instead of a forever-"Searching…" dead end (with the pair/reconnect/sync actions disabled above).
    private var bluetoothCard: some View {
        let denied = model.bluetooth == .denied
        return GlassCard {
            VStack(alignment: .leading, spacing: Theme.Space.s) {
                HStack(spacing: Theme.Space.s) {
                    Image(systemName: "antenna.radiowaves.left.and.right.slash")
                        .font(.system(size: 18, weight: .semibold)).foregroundStyle(Theme.Palette.amber)
                    Text(denied ? "Allow Bluetooth for Titan" : "Bluetooth is off")
                        .font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.text)
                }
                Text(denied
                     ? "Titan needs Bluetooth to reach your band. Turn it on for Titan in Settings."
                     : "Turn on Bluetooth to connect your band.")
                    .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                    .fixedSize(horizontal: false, vertical: true)
                if denied {
                    Button {
                        Haptic.tap()
                        if let u = URL(string: UIApplication.openSettingsURLString) { openURL(u) }
                    } label: {
                        Text("Open Settings").font(Theme.Font.micro.weight(.semibold)).foregroundStyle(Theme.Palette.cyan)
                    }
                }
            }
        }
    }

    // Run GPS test. The band has no GPS chip — runs are mapped by the PHONE — so this proves, on the spot,
    // that a real run will track: permission granted, Precise Location on, and a stream of run-grade fixes.
    private var gpsIcon: String {
        if model.gpsTestActive { return "location.magnifyingglass" }
        switch model.gpsReadiness {
        case .ready?:      return "checkmark.circle.fill"
        case .denied?:     return "location.slash.fill"
        case .preciseOff?: return "scope"
        case .weakSignal?: return "exclamationmark.triangle.fill"
        case nil:          return "location"
        }
    }
    private var gpsTint: Color {
        if model.gpsTestActive { return Theme.Palette.amber }
        switch model.gpsReadiness {
        case .ready?:                  return Theme.Palette.mint
        case .denied?, .preciseOff?:   return Theme.Palette.pink
        case .weakSignal?:             return Theme.Palette.amber
        case nil:                      return Theme.Palette.textFaint
        }
    }
    private var gpsTitle: String {
        if model.gpsTestActive { return String(localized: "Locating…") }
        switch model.gpsReadiness {
        case .ready?:      return String(localized: "Ready to run")
        case .denied?:     return String(localized: "Location off")
        case .preciseOff?: return String(localized: "Precise Location off")
        case .weakSignal?: return String(localized: "Weak signal")
        case nil:          return String(localized: "Not tested yet")
        }
    }
    private var gpsSubText: String {
        if model.gpsTestActive {
            return model.gpsAccuracyM.map { String(localized: "Finding you… ±\(Int($0)) m") } ?? String(localized: "Finding your location…")
        }
        switch model.gpsReadiness {
        case .ready(let acc)?: return String(localized: "Your runs will map. Locked to ±\(Int(acc)) m.")
        case .denied?:         return String(localized: "Allow location so runs can map.")
        case .preciseOff?:     return String(localized: "Turn on Precise Location for run mapping.")
        case .weakSignal(let best)?:
            return best.map { String(localized: "Best was ±\(Int($0)) m — too coarse. Try outside.") }
                ?? String(localized: "No fix — try outside with a clear view of the sky.")
        case nil: return String(localized: "Tap to confirm a run will track before you go.")
        }
    }
    /// What the user should DO when the test fails (shown below the status, color-matched to the verdict).
    private var gpsGuidance: (text: String, settings: Bool)? {
        if model.gpsTestActive { return nil }
        switch model.gpsReadiness {
        case .denied?:
            return (String(localized: "Enable Location for Titan in Settings → Privacy → Location Services, then test again."), true)
        case .preciseOff?:
            return (String(localized: "In Settings → Titan → Location, turn ON “Precise Location”. Without it, runs map to the wrong block."), true)
        case .weakSignal?:
            return (String(localized: "Step outside with a clear view of the sky and test again — walls and roofs block GPS."), false)
        default:
            return nil
        }
    }
    private var gpsTestCard: some View {
        GlassCard {
            VStack(alignment: .leading, spacing: Theme.Space.m) {
                SectionHeader(title: String(localized: "Run GPS (phone)"), trailing: model.gpsTestActive ? String(localized: "TESTING") : nil)

                HStack(spacing: Theme.Space.m) {
                    Image(systemName: gpsIcon)
                        .font(.title2).foregroundStyle(gpsTint)
                    VStack(alignment: .leading, spacing: 2) {
                        Text(gpsTitle)
                            .font(Theme.Font.body.weight(.bold))
                            .foregroundStyle(gpsTint == Theme.Palette.textFaint ? Theme.Palette.text : gpsTint)
                        Text(gpsSubText)
                            .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                    }
                    Spacer()
                    if model.gpsTestActive {
                        Text("\(model.gpsTestProgress)/3")
                            .font(Theme.Font.num(15)).foregroundStyle(Theme.Palette.amber).monospacedDigit()
                    }
                }

                // Proof it's your REAL, live location — the map centres on the actual fix (no placeholder),
                // and the readout (coords · accuracy · "updated Ns ago") visibly ticks as fixes stream in.
                // Only ever shown for a fix captured DURING this test (never stale run/demo data), so the
                // map can't mislead you with a location you're not actually at.
                if (model.gpsTestActive || model.gpsReadiness != nil),
                   model.gpsHasFix, let la = model.gpsLastLat, let lo = model.gpsLastLon {
                    LiveRouteMap(track: [CGPoint(x: lo, y: la)], interactive: false)
                        .frame(height: 180)
                        .clipShape(RoundedRectangle(cornerRadius: Theme.Radius.card))
                        .overlay(alignment: .topLeading) {
                            if model.gpsTestActive {
                                HStack(spacing: 5) {
                                    Circle().fill(Theme.Palette.mint).frame(width: 7, height: 7)
                                    Text("LIVE").font(Theme.Font.label.weight(.bold)).tracking(1).foregroundStyle(.white)
                                }
                                .padding(.horizontal, 8).padding(.vertical, 4)
                                .background(.black.opacity(0.55), in: Capsule()).padding(8)
                            }
                        }
                    TimelineView(.periodic(from: .now, by: 1)) { ctx in
                        HStack(spacing: 8) {
                            Text(String(format: "%.5f, %.5f", la, lo))
                                .font(Theme.Font.num(15)).foregroundStyle(Theme.Palette.cyan).monospacedDigit()
                            if let acc = model.gpsAccuracyM {
                                Text("±\(Int(acc)) m").font(Theme.Font.micro)
                                    .foregroundStyle(acc <= 50 ? Theme.Palette.mint : Theme.Palette.amber)
                            }
                            Spacer()
                            if let at = model.gpsLastFrameAt {
                                let age = Int(ctx.date.timeIntervalSince(at))
                                Text(age <= 1 ? "just now" : "\(age)s ago")
                                    .font(Theme.Font.micro)
                                    .foregroundStyle(age <= 3 ? Theme.Palette.textDim : Theme.Palette.amber)
                            }
                        }
                    }
                }

                if let g = gpsGuidance {
                    Text(g.text)
                        .font(Theme.Font.micro).foregroundStyle(gpsTint)
                    if g.settings {
                        Button { openURL(URL(string: UIApplication.openSettingsURLString)!) } label: {
                            Text("Open Settings")
                                .font(Theme.Font.body.weight(.semibold))
                                .frame(maxWidth: .infinity).padding(.vertical, 12)
                                .background(Theme.Palette.bg2, in: RoundedRectangle(cornerRadius: Theme.Radius.chip))
                                .overlay(RoundedRectangle(cornerRadius: Theme.Radius.chip).strokeBorder(Theme.Palette.cardStroke))
                                .foregroundStyle(Theme.Palette.text)
                        }
                    }
                }

                Button { model.startGpsTest() } label: {
                    HStack(spacing: 8) {
                        if model.gpsTestActive { ProgressView().tint(.white) }
                        else { Image(systemName: "location.viewfinder") }
                        Text(model.gpsTestActive ? "Locating… (\(model.gpsTestProgress)/3)"
                                                 : (model.gpsReadiness == nil ? "Test location" : "Test again"))
                    }
                    .font(Theme.Font.body.weight(.semibold))
                    .frame(maxWidth: .infinity).padding(.vertical, 12)
                    .background(Theme.Palette.bg2, in: RoundedRectangle(cornerRadius: Theme.Radius.chip))
                    .overlay(RoundedRectangle(cornerRadius: Theme.Radius.chip).strokeBorder(Theme.Palette.cardStroke))
                    .foregroundStyle(Theme.Palette.text)
                }.disabled(model.gpsTestActive)

                Text("Your band has no GPS, so runs are mapped by your iPhone. This checks the exact conditions a run needs — permission, Precise Location, and a steady signal — so you never waste a run.")
                    .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
            }
        }
    }

    // Chest-strap HR (Polar H10 etc.) — accurate running + lifting HR the wrist can't do. Independent
    // of the band; both run at once. The band stays your 24/7 rest/sleep/recovery sensor.
    @ViewBuilder private var strapCard: some View {
        GlassCard {
            VStack(alignment: .leading, spacing: Theme.Space.m) {
                if model.strapPairing {
                    SectionHeader(title: String(localized: "Pick your strap"))
                    Text("Put the strap on (wet the electrodes) so it powers up, then tap it below.")
                        .font(Theme.Font.body).foregroundStyle(Theme.Palette.textDim)
                    if model.strapCandidates.isEmpty {
                        HStack(spacing: Theme.Space.s) {
                            ProgressView().tint(Theme.Palette.indigo)
                            Text("Searching for heart-rate straps…")
                                .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
                        }.padding(.vertical, 6)
                    } else {
                        ForEach(model.strapCandidates) { cand in
                            Button { model.bindStrap(cand.id) } label: {
                                HStack(spacing: Theme.Space.m) {
                                    Image(systemName: "heart.circle.fill").foregroundStyle(Theme.Palette.pink)
                                    Text(cand.name).font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.text)
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
                    Button { model.cancelStrapPairing() } label: {
                        Text("Cancel").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                    }.frame(maxWidth: .infinity)
                } else if model.isStrapPaired {
                    SectionHeader(title: String(localized: "Heart-rate strap"),
                                  trailing: model.strapConnected ? String(localized: "CONNECTED") : String(localized: "searching"))
                    HStack(spacing: Theme.Space.m) {
                        Image(systemName: model.strapConnected ? "heart.fill" : "heart.slash")
                            .font(.title2)
                            .foregroundStyle(model.strapConnected ? Theme.Palette.pink : Theme.Palette.textFaint)
                            .symbolEffect(.pulse, options: model.strapConnected ? .repeating : .nonRepeating)
                        VStack(alignment: .leading, spacing: 2) {
                            Text(model.strapConnected ? "Connected" : "Waiting for strap")
                                .font(Theme.Font.body.weight(.bold))
                                .foregroundStyle(model.strapConnected ? Theme.Palette.pink : Theme.Palette.text)
                            Text(model.strapConnected
                                 ? "Used for workout HR. Wrist stays on recovery."
                                 : "Put it on (wet electrodes) — it connects automatically.")
                                .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                        }
                        Spacer()
                        if model.strapConnected, let bpm = model.strapBpm {
                            HStack(spacing: 5) {
                                Text("\(bpm)").font(Theme.Font.num(24)).contentTransition(.numericText())
                                Text("BPM").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                            }
                        }
                    }
                    if let batt = model.strapBattery {
                        HStack(spacing: 6) {
                            Image(systemName: batteryIcon(batt)).foregroundStyle(batteryColor(batt))
                            Text("\(batt)%").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim).monospacedDigit()
                            Text("strap").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
                        }
                    }
                    Button { model.forgetStrap() } label: {
                        Text("Forget strap").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                    }
                } else {
                    SectionHeader(title: String(localized: "Add a heart-rate strap"), trailing: String(localized: "optional"))
                    Text("Wrist HR is great for rest and sleep, but a chest strap (Polar H10, Garmin, Wahoo…) is far more accurate for running and lifting. Pair one and Titan uses it automatically during workouts.")
                        .font(Theme.Font.body).foregroundStyle(Theme.Palette.textDim)
                    Button {
                        Haptic.rigid()
                        model.startStrapPairing()
                    } label: {
                        HStack(spacing: 8) {
                            Image(systemName: "heart.text.square.fill")
                            Text("Pair a strap")
                        }
                        .font(Theme.Font.body.weight(.semibold))
                        .frame(maxWidth: .infinity).padding(.vertical, 13)
                        .background(Theme.Palette.bg2, in: RoundedRectangle(cornerRadius: Theme.Radius.chip))
                        .overlay(RoundedRectangle(cornerRadius: Theme.Radius.chip).strokeBorder(Theme.Palette.cardStroke))
                        .foregroundStyle(Theme.Palette.text)
                    }
                }
            }
        }
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
        if model.bandConnected { return String(localized: "Band connected") }
        if !model.isBandPaired { return String(localized: "No band yet") }
        return model.bandIdle ? String(localized: "Power-saving") : String(localized: "Searching…")
    }
    private var statusSub: String {
        if model.bandConnected { return String(localized: "Live — syncing in real time.") }
        if !model.isBandPaired { return String(localized: "Pair your Titan band to begin.") }
        return model.bandIdle
            ? String(localized: "Band's on its own, saving battery. It syncs in bursts and the moment you open the app.")
            : String(localized: "Reconnects automatically when it's near.")
    }

    private func pairStep(_ n: String, _ text: LocalizedStringKey) -> some View {
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

    private func liveStat(_ value: String, _ label: LocalizedStringKey, _ color: Color) -> some View {
        VStack(spacing: 3) {
            // No .contentTransition(.numericText()) here: the "samples" stat changes ~10×/sec while the
            // band streams, and an animated digit-morph at that rate is a continuous CPU/compositor cost.
            Text(value).font(Theme.Font.num(20)).foregroundStyle(color).monospacedDigit()
            Text(label).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim).textCase(.uppercase)
        }.frame(maxWidth: .infinity)
    }

    private func feature(_ icon: String, _ c: Color, _ title: LocalizedStringKey, _ sub: LocalizedStringKey) -> some View {
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
