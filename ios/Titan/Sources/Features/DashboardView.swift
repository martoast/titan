import SwiftUI

/// Today — the hero screen. A big recovery ring up top (Whoop-style), then recovery vitals,
/// last night's sleep with a stage bar, and activity. Fluid entrance + numeric count-ups.
struct DashboardView: View {
    @EnvironmentObject var model: AppModel
    @State private var appeared = false
    @State private var showJournal = false

    var body: some View {
        VStack(spacing: Theme.Space.m) {
            let d = model.dashboard

            // Warm, time-aware greeting — sets a human tone before the data.
            Text(greeting)
                .font(Theme.Font.body.weight(.medium)).foregroundStyle(Theme.Palette.textDim)
                .frame(maxWidth: .infinity, alignment: .leading)
                .opacity(appeared ? 1 : 0)

            // A failed cold sync is legible + recoverable here, rather than a ring stuck at "building baseline".
            if model.dashboardPhase == .failed && model.dashboard == nil {
                SyncErrorRow(message: "Couldn't sync today") { await model.refresh() }
            }

            // A run is streaming live from the band — see it tracking, right at the top.
            if model.runActive {
                LiveRunBanner { model.showLiveRunSheet = true }
            }

            // A sleep session is running on the watch — see it live (timer + sync status), like a workout.
            if model.sleeping {
                SleepingBanner(startedAt: model.sleepStartedAt,
                               connected: model.bandConnected,
                               lastData: model.bandStepsAt)
            }

            // Hero: the three Whoop rings — Sleep · Recovery · Strain — then the recovery headline.
            VStack(spacing: Theme.Space.m) {
                HStack(alignment: .top, spacing: Theme.Space.s) {
                    NavigationLink { SleepView() } label: {
                        StatRing(value: d?.rings?.sleep_performance.map(Double.init), max: 100,
                                 label: "Sleep", color: Theme.Palette.indigo, size: 92)
                    }.buttonStyle(PressCard())
                    NavigationLink { RecoveryView() } label: {
                        StatRing(value: d?.rings?.recovery.map(Double.init) ?? d?.readiness?.score.map(Double.init),
                                 max: 100, label: "Recovery", color: Theme.Palette.recovery(d?.readiness?.score), size: 116)
                    }.buttonStyle(PressCard())
                    NavigationLink { StrainView() } label: {
                        StatRing(value: d?.rings?.strain, max: 21, label: "Strain", color: Theme.Palette.cyan, size: 92)
                    }.buttonStyle(PressCard())
                }
                .padding(.top, Theme.Space.s)
                VStack(spacing: 6) {
                    Text(d?.readiness?.label ?? "Building your baseline")
                        .font(Theme.Font.title).foregroundStyle(Theme.Palette.text)
                    if let note = d?.readiness?.note {
                        Text(note).font(Theme.Font.body).foregroundStyle(Theme.Palette.textDim)
                            .multilineTextAlignment(.center).lineLimit(3)
                    }
                    if d?.readiness?.provisional == true {
                        Label("Still learning your baseline", systemImage: "hourglass")
                            .font(Theme.Font.micro).foregroundStyle(Theme.Palette.amber)
                            .padding(.horizontal, 10).padding(.vertical, 5)
                            .background(Theme.Palette.amber.opacity(0.12), in: Capsule())
                    }
                }
                .padding(.horizontal)
            }
            .frame(maxWidth: .infinity)
            .opacity(appeared ? 1 : 0).offset(y: appeared ? 0 : 16)

            // Trends — the Whoop-style Overview history (recovery / sleep / strain over week + month).
            NavigationLink { TrendsView() } label: {
                GlassCard {
                    HStack(spacing: Theme.Space.m) {
                        ZStack {
                            Circle().fill(Theme.Palette.cyan.opacity(0.16)).frame(width: 40, height: 40)
                            Image(systemName: "chart.xyaxis.line").foregroundStyle(Theme.Palette.cyan).font(.system(size: 17, weight: .semibold))
                        }
                        VStack(alignment: .leading, spacing: 2) {
                            Text("Trends").font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.text)
                            Text("Recovery, sleep & strain over time").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                        }
                        Spacer()
                        Image(systemName: "chevron.right").font(.caption).foregroundStyle(Theme.Palette.textFaint)
                    }
                }
            }.buttonStyle(PressCard())

            // Live steps from the iPhone (instant, no permission) + connect Apple Health for the rest
            LiveStepsCard()
            HealthConnectCard()

            // For You — the ranked insight feed (anomalies, goal progress, wins, behavior correlations)
            if !model.insights.isEmpty {
                VStack(spacing: Theme.Space.s) {
                    HStack { Text("FOR YOU").font(Theme.Font.label).tracking(1.2).foregroundStyle(Theme.Palette.textDim); Spacer() }
                    ForEach(model.insights) { InsightCard(insight: $0) }
                }
            }

            // Log your day → feeds the correlation engine
            Button { Haptic.tap(); showJournal = true } label: {
                GlassCard {
                    HStack(spacing: Theme.Space.m) {
                        ZStack {
                            Circle().fill(Theme.Palette.violet.opacity(0.16)).frame(width: 40, height: 40)
                            Image(systemName: "square.and.pencil").foregroundStyle(Theme.Palette.violet).font(.system(size: 17, weight: .semibold))
                        }
                        VStack(alignment: .leading, spacing: 2) {
                            Text("Log your day").font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.text)
                            Text(model.journalLogged.isEmpty
                                 ? "Alcohol, caffeine, stress… learn what moves your recovery"
                                 : "\(model.journalLogged.count) logged today")
                                .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                        }
                        Spacer()
                        Image(systemName: "chevron.right").font(.caption).foregroundStyle(Theme.Palette.textFaint)
                    }
                }
            }
            .buttonStyle(PressCard())
            .sheet(isPresented: $showJournal) { JournalSheet() }

            // Biological Age — the hero "how old is your body" stat
            if let b = d?.bio_age, let age = b.biological_age {
                GlassCard {
                    VStack(alignment: .leading, spacing: Theme.Space.s) {
                        SectionHeader(title: "Biological Age", trailing: b.confidence.map { "\($0.capitalized) confidence" })
                        HStack(alignment: .firstTextBaseline, spacing: Theme.Space.m) {
                            Text(String(format: "%.0f", age))
                                .font(Theme.Font.num(68))
                                .foregroundStyle(Theme.Grad.brand)
                            VStack(alignment: .leading, spacing: 6) {
                                if let delta = b.delta { deltaBadge(delta) }
                                if let chrono = b.chronological_age {
                                    Text("Your real age is \(Int(chrono.rounded()))")
                                        .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                                }
                            }
                            Spacer()
                        }
                        if let fit = b.fitness_age {
                            Label("Fitness age \(Int(fit.rounded())) · from VO₂max", systemImage: "figure.run")
                                .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
                        }
                    }
                }
            }

            // Recovery vitals
            NavigationLink { RecoveryView() } label: {
                GlassCard {
                    VStack(alignment: .leading, spacing: Theme.Space.m) {
                        SectionHeader(title: "Recovery", trailing: chevron)
                        HStack(spacing: Theme.Space.m) {
                            Metric(value: int(d?.recovery?.hrv_ms), unit: "ms", label: "HRV", color: Theme.Palette.cyan, icon: "waveform.path.ecg")
                            divider
                            Metric(value: int(d?.recovery?.resting_hr), unit: "bpm", label: "Resting HR", color: Theme.Palette.pink, icon: "heart.fill")
                            divider
                            Metric(value: dec(d?.recovery?.resp_rate), unit: "br/m", label: "Respiration", color: Theme.Palette.violet, icon: "lungs.fill")
                        }
                    }
                }
            }.buttonStyle(PressCard())

            // Sleep
            NavigationLink { SleepView() } label: {
                GlassCard {
                    VStack(alignment: .leading, spacing: Theme.Space.m) {
                        SectionHeader(title: "Last night", trailing: chevron)
                        HStack(alignment: .firstTextBaseline) {
                            Text(minToHrs(d?.sleep?.duration_min)).font(Theme.Font.num(30)).foregroundStyle(Theme.Palette.text)
                            Spacer()
                            if let q = d?.sleep?.quality { Metric(value: "\(q)", unit: nil, label: "Quality", color: Theme.Palette.indigo) }
                        }
                        StageBars(stages: [
                            ("Deep", d?.sleep?.deep_min ?? 0, Theme.Palette.indigo),
                            ("REM", d?.sleep?.rem_min ?? 0, Theme.Palette.violet),
                            ("Light", d?.sleep?.light_min ?? 0, Theme.Palette.cyan.opacity(0.6)),
                            ("Awake", d?.sleep?.awake_min ?? 0, Theme.Palette.textFaint),
                        ])
                    }
                }
            }.buttonStyle(PressCard())

            // Activity
            if let a = d?.activity {
                GlassCard {
                    VStack(alignment: .leading, spacing: Theme.Space.m) {
                        SectionHeader(title: "Activity")
                        HStack(spacing: Theme.Space.m) {
                            Metric(value: int(a.steps.map(Double.init)), unit: nil, label: "Steps", color: Theme.Palette.mint, icon: "figure.walk")
                            divider
                            Metric(value: int(a.active_kcal.map(Double.init)), unit: "kcal", label: "Active", color: Theme.Palette.amber, icon: "flame.fill")
                            divider
                            Metric(value: int(a.floors.map(Double.init)), unit: nil, label: "Floors", color: Theme.Palette.cyan, icon: "stairs")
                        }
                    }
                }
            }

            Color.clear.frame(height: 8)
        }
        .animation(Theme.Motion.spring, value: appeared)
        .animation(Theme.Motion.snappy, value: model.dashboardPhase)
        .titanScreen("Today", glow: Theme.Palette.recovery(model.dashboard?.readiness?.score))
        .refreshable { Haptic.soft(); await model.refresh(); await model.loadInsights(); await model.loadJournal() }
        .task {
            appeared = true          // let the screen animate in immediately, not after the network
            await model.refresh()
            await model.loadInsights()
            await model.loadJournal()
        }
    }

    /// "X.X years younger / older / on pace" — green when younger, amber when older.
    @ViewBuilder private func deltaBadge(_ delta: Double) -> some View {
        let younger = delta < -0.4, older = delta > 0.4
        let color = younger ? Theme.Palette.mint : (older ? Theme.Palette.amber : Theme.Palette.textDim)
        let text = younger ? String(format: "%.1f yrs younger", -delta)
                 : (older ? String(format: "%.1f yrs older", delta) : "Right on pace")
        Label(text, systemImage: younger ? "arrow.down.right" : (older ? "arrow.up.right" : "equal"))
            .font(Theme.Font.label).foregroundStyle(color)
            .padding(.horizontal, 10).padding(.vertical, 5)
            .background(color.opacity(0.14), in: Capsule())
    }

    private var greeting: String {
        let hour = Calendar.current.component(.hour, from: Date())
        let part = hour < 12 ? "Good morning" : (hour < 18 ? "Good afternoon" : "Good evening")
        if let first = model.user?.name?.split(separator: " ").first { return "\(part), \(first)." }
        return part + "."
    }

    private var chevron: String { "›" }
    private var divider: some View { Rectangle().fill(Theme.Palette.cardStroke).frame(width: 1, height: 34) }
    private func int(_ v: Double?) -> String { v.map { String(Int($0.rounded())) } ?? "—" }
    private func dec(_ v: Double?) -> String { v.map { String(format: "%.1f", $0) } ?? "—" }
}

/// Subtle press-scale for tappable cards.
struct PressCard: ButtonStyle {
    func makeBody(configuration: Configuration) -> some View {
        configuration.label
            .scaleEffect(configuration.isPressed ? 0.97 : 1)
            .animation(Theme.Motion.snappy, value: configuration.isPressed)
    }
}

/// A sleep session running on the watch, shown live in the app (like a workout in progress) — a night
/// timer that ticks, plus whether it's streaming live or waiting to sync from the band in the morning.
struct SleepingBanner: View {
    let startedAt: Date?
    let connected: Bool
    let lastData: Date?
    @State private var pulse = false

    var body: some View {
        HStack(spacing: Theme.Space.m) {
            ZStack {
                Circle().fill(Theme.Palette.indigo.opacity(0.18)).frame(width: 44, height: 44)
                Image(systemName: "moon.stars.fill").font(.title3).foregroundStyle(Theme.Palette.indigo)
            }
            VStack(alignment: .leading, spacing: 2) {
                HStack(spacing: 6) {
                    Circle().fill(Theme.Palette.violet).frame(width: 7, height: 7).opacity(pulse ? 0.3 : 1)
                    Text("SLEEPING").font(Theme.Font.label.weight(.bold)).tracking(1).foregroundStyle(Theme.Palette.violet)
                }
                TimelineView(.periodic(from: .now, by: 1)) { _ in
                    Text(elapsed).font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.text).monospacedDigit()
                }
                Text(statusLine).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
            }
            Spacer()
            HStack(spacing: 4) {
                Circle().fill(connected ? Theme.Palette.mint : Theme.Palette.textFaint).frame(width: 7, height: 7)
                Text(connected ? "Live" : "Offline").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
            }
        }
        .padding(Theme.Space.m)
        .background(LinearGradient(colors: [Theme.Palette.indigo.opacity(0.16), Theme.Palette.card],
                                   startPoint: .leading, endPoint: .trailing),
                    in: RoundedRectangle(cornerRadius: Theme.Radius.card))
        .overlay(RoundedRectangle(cornerRadius: Theme.Radius.card).stroke(Theme.Palette.indigo.opacity(0.4), lineWidth: 1))
        .onAppear { withAnimation(.easeInOut(duration: 1.4).repeatForever(autoreverses: true)) { pulse = true } }
    }

    private var elapsed: String {
        guard let s = startedAt else { return "Tracking your night" }
        let sec = max(0, Int(Date().timeIntervalSince(s)))
        let h = sec / 3600, m = (sec % 3600) / 60
        return h > 0 ? "\(h)h \(m)m asleep" : "\(m)m asleep"
    }
    private var statusLine: String {
        if connected { return "Tracking your night · streaming live" }
        if let l = lastData { return "Saved on your band · last synced \(rel(l))" }
        return "Saved on your band · syncs when you reconnect"
    }
    private func rel(_ d: Date) -> String {
        let f = RelativeDateTimeFormatter(); f.unitsStyle = .abbreviated
        return f.localizedString(for: d, relativeTo: Date())
    }
}
