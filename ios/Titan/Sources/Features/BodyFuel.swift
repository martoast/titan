import SwiftUI
import Charts

// MARK: - Apple Health connect

/// The "Connect Apple Health" CTA (shown until connected). Turns any iPhone/Apple Watch into Titan's
/// data source — recovery, sleep, steps, weight all flow in, no band required.
struct HealthConnectCard: View {
    @EnvironmentObject var model: AppModel
    var body: some View {
        if !model.healthConnected {
            Button { Task { await model.connectAppleHealth() } } label: {
                GlassCard(padding: Theme.Space.l) {
                    HStack(spacing: Theme.Space.m) {
                        ZStack {
                            Circle().fill(Theme.Palette.pink.opacity(0.16)).frame(width: 48, height: 48)
                            Image(systemName: "heart.fill").font(.system(size: 20, weight: .semibold)).foregroundStyle(Theme.Palette.pink)
                        }
                        VStack(alignment: .leading, spacing: 2) {
                            Text("Connect Apple Health").font(Theme.Font.title).foregroundStyle(Theme.Palette.text)
                            Text("Sync steps, sleep & heart from your iPhone and Apple Watch — recovery, insights & trends, no band needed.")
                                .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                        }
                        Spacer(minLength: 0)
                        if model.healthSyncing { ProgressView().tint(Theme.Palette.pink) }
                        else { Image(systemName: "chevron.right").font(.caption).foregroundStyle(Theme.Palette.textFaint) }
                    }
                }
            }
            .buttonStyle(PressCard())
            .disabled(model.healthSyncing)
            .task { await model.loadHealthStatus() }
        }
    }
}

// MARK: - Live steps (phone-only, instant)

/// A live "steps today" ring from CoreMotion — works on any iPhone with no permission prompt.
struct LiveStepsCard: View {
    @StateObject private var tracker = StepTracker()
    private let goal = 10000

    var body: some View {
        Group {
            if tracker.available {
                GlassCard {
                    HStack(spacing: Theme.Space.l) {
                        FuelRing(pct: Double(tracker.steps) / Double(goal), color: Theme.Palette.mint, size: 92) {
                            VStack(spacing: 0) {
                                Text("\(tracker.steps)").font(Theme.Font.num(20)).foregroundStyle(.white).monospacedDigit()
                                    .contentTransition(.numericText())
                                Text("steps").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
                            }
                        }
                        VStack(alignment: .leading, spacing: Theme.Space.s) {
                            SectionHeader(title: "Move", trailing: "live")
                            stat(String(format: "%.1f km", tracker.distanceKm), "Distance", Theme.Palette.cyan)
                            stat("\(tracker.flights)", "Flights", Theme.Palette.amber)
                        }
                        Spacer(minLength: 0)
                    }
                    .animation(Theme.Motion.snappy, value: tracker.steps)
                }
            }
        }
        .task { tracker.start() }
        .onDisappear { tracker.stop() }
    }

    private func stat(_ value: String, _ label: String, _ color: Color) -> some View {
        HStack(spacing: 6) {
            Text(value).font(Theme.Font.num(15)).foregroundStyle(color).monospacedDigit()
            Text(label).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
        }
    }
}

// MARK: - Reusable gauge ring

/// A progress ring with arbitrary center content — used by hydration & fasting.
struct FuelRing<Center: View>: View {
    let pct: Double
    let color: Color
    var size: CGFloat = 96
    @ViewBuilder var center: () -> Center
    @State private var p: CGFloat = 0

    var body: some View {
        ZStack {
            Circle().stroke(Color.white.opacity(0.07), lineWidth: size * 0.09)
            Circle().trim(from: 0, to: p)
                .stroke(Theme.Grad.ring(color), style: StrokeStyle(lineWidth: size * 0.09, lineCap: .round))
                .rotationEffect(.degrees(-90)).shadow(color: color.opacity(0.5), radius: 6)
            center()
        }
        .frame(width: size, height: size)
        .onAppear { withAnimation(Theme.Motion.ring) { p = clampPct(pct) } }
        .onChange(of: pct) { _, v in withAnimation(Theme.Motion.ring) { p = clampPct(v) } }
    }

    private func clampPct(_ v: Double) -> CGFloat { CGFloat(min(1, max(0, v))) }
}

// MARK: - Hydration

struct HydrationCard: View {
    @EnvironmentObject var model: AppModel

    var body: some View {
        let h = model.hydration
        GlassCard {
            VStack(spacing: Theme.Space.m) {
                SectionHeader(title: "Hydration", trailing: h.map { "\($0.pct)%" })
                HStack(spacing: Theme.Space.l) {
                    FuelRing(pct: Double(h?.pct ?? 0) / 100, color: Theme.Palette.cyan, size: 100) {
                        VStack(spacing: 0) {
                            Text(String(format: "%.1f", h?.litres ?? 0)).font(Theme.Font.num(24)).foregroundStyle(.white).monospacedDigit()
                            Text("of \(String(format: "%.1f", h?.targetLitres ?? 2.5))L").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
                        }
                    }
                    VStack(spacing: Theme.Space.s) {
                        addButton("Glass", 250)
                        addButton("Bottle", 500)
                        addButton("Large", 750)
                    }
                }
            }
        }
        .task { await model.loadHydration() }
    }

    private func addButton(_ title: String, _ ml: Int) -> some View {
        Button { Task { await model.addWater(ml) } } label: {
            HStack(spacing: 8) {
                Image(systemName: "drop.fill").font(.system(size: 12)).foregroundStyle(Theme.Palette.cyan)
                Text(title).font(Theme.Font.micro.weight(.semibold)).foregroundStyle(Theme.Palette.text)
                Spacer()
                Text("+\(ml)").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim).monospacedDigit()
            }
            .padding(.horizontal, 12).padding(.vertical, 9).frame(maxWidth: .infinity)
            .background(Theme.Palette.card, in: Capsule())
            .overlay(Capsule().strokeBorder(Theme.Palette.cardStroke))
        }
        .buttonStyle(PressCard())
    }
}

// MARK: - Fasting

struct FastingCard: View {
    @EnvironmentObject var model: AppModel
    @State private var showStart = false

    var body: some View {
        GlassCard {
            if let f = model.fasting, f.active {
                active(f)
            } else {
                idle
            }
        }
        .task { await model.loadFasting() }
        .sheet(isPresented: $showStart) { FastingStartSheet() }
    }

    private func active(_ f: FastingStatus) -> some View {
        let start = f.started_at.flatMap(parseISO) ?? Date()
        let goalH = f.goal_h ?? 16
        return VStack(spacing: Theme.Space.m) {
            SectionHeader(title: "Fasting", trailing: "Goal \(Int(goalH))h")
            TimelineView(.periodic(from: .now, by: 30)) { ctx in
                let elapsed = max(0, ctx.date.timeIntervalSince(start)) / 3600
                HStack(spacing: Theme.Space.l) {
                    FuelRing(pct: elapsed / goalH, color: Theme.Palette.violet, size: 112) {
                        VStack(spacing: 0) {
                            Text(clock(elapsed)).font(Theme.Font.num(26)).foregroundStyle(.white).monospacedDigit()
                            Text("\(Int(elapsed))/\(Int(goalH))h").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
                        }
                    }
                    VStack(alignment: .leading, spacing: 6) {
                        Text(f.stage ?? "Fasting").font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.violet)
                        if let blurb = f.stage_blurb {
                            Text(blurb).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                                .fixedSize(horizontal: false, vertical: true)
                        }
                    }
                    Spacer(minLength: 0)
                }
            }
            Button { Task { await model.endFast() } } label: {
                Text("End fast").font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.text)
                    .frame(maxWidth: .infinity).padding(.vertical, 12)
                    .background(Theme.Palette.card, in: RoundedRectangle(cornerRadius: Theme.Radius.chip))
                    .overlay(RoundedRectangle(cornerRadius: Theme.Radius.chip).strokeBorder(Theme.Palette.cardStroke))
            }
        }
    }

    private var idle: some View {
        VStack(alignment: .leading, spacing: Theme.Space.m) {
            SectionHeader(title: "Fasting")
            HStack(spacing: Theme.Space.m) {
                ZStack {
                    Circle().fill(Theme.Palette.violet.opacity(0.16)).frame(width: 44, height: 44)
                    Image(systemName: "timer").font(.system(size: 19, weight: .semibold)).foregroundStyle(Theme.Palette.violet)
                }
                Text("Track a fasting window and watch your body shift into fat-burning.")
                    .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                Spacer(minLength: 0)
            }
            Button { Haptic.tap(); showStart = true } label: {
                Text("Start a fast").font(Theme.Font.body.weight(.semibold))
                    .frame(maxWidth: .infinity).padding(.vertical, 13)
                    .background(Theme.Grad.brand, in: RoundedRectangle(cornerRadius: Theme.Radius.chip))
                    .foregroundStyle(.white)
            }
        }
    }

    private func clock(_ hours: Double) -> String {
        let total = Int(hours * 60)
        return String(format: "%d:%02d", total / 60, total % 60)
    }
}

private struct FastingStartSheet: View {
    @EnvironmentObject var model: AppModel
    @Environment(\.dismiss) private var dismiss
    private let presets: [(String, String, Double)] = [
        ("16:8", "Most popular — 16 h fast, 8 h eating", 16),
        ("18:6", "Leaner window — 18 h fast", 18),
        ("20:4", "Warrior — 20 h fast", 20),
        ("OMAD", "One meal a day — 24 h", 24),
    ]

    var body: some View {
        NavigationStack {
            ZStack {
                Theme.Palette.bg.ignoresSafeArea()
                ScrollView {
                    VStack(spacing: Theme.Space.m) {
                        ForEach(presets, id: \.0) { p in
                            Button {
                                Task { await model.startFast(p.2); dismiss() }
                            } label: {
                                GlassCard {
                                    HStack(spacing: Theme.Space.m) {
                                        Text(p.0).font(Theme.Font.num(22)).foregroundStyle(Theme.Palette.violet).frame(width: 64, alignment: .leading)
                                        Text(p.1).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                                        Spacer()
                                        Image(systemName: "chevron.right").font(.caption).foregroundStyle(Theme.Palette.textFaint)
                                    }
                                }
                            }.buttonStyle(PressCard())
                        }
                    }.padding(Theme.Space.m)
                }
            }
            .navigationTitle("Start a fast").navigationBarTitleDisplayMode(.inline)
            .toolbar { ToolbarItem(placement: .cancellationAction) { Button("Cancel") { dismiss() } } }
            .toolbarColorScheme(.dark, for: .navigationBar)
        }
    }
}

// MARK: - Weight

struct WeightSection: View {
    @EnvironmentObject var model: AppModel
    @State private var showLog = false

    var body: some View {
        GlassCard {
            VStack(alignment: .leading, spacing: Theme.Space.m) {
                SectionHeader(title: "Weight", trailing: rateText)
                if let w = model.weightCard, let trend = w.trend_kg {
                    HStack(alignment: .firstTextBaseline, spacing: 4) {
                        Text(String(format: "%.1f", trend)).font(Theme.Font.num(44)).foregroundStyle(.white).monospacedDigit()
                        Text("kg").font(Theme.Font.body).foregroundStyle(Theme.Palette.textDim)
                        Text("trend").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint).padding(.leading, 4)
                        Spacer()
                    }
                    chart(w)
                    if let g = w.goal { goalRow(g) }
                } else {
                    Text("Log a few weigh-ins and your smoothed “true weight” trend — and a projection to your goal — appears here.")
                        .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
                }
                Button { Haptic.tap(); showLog = true } label: {
                    Label("Log weigh-in", systemImage: "scalemass.fill").font(Theme.Font.body.weight(.semibold))
                        .frame(maxWidth: .infinity).padding(.vertical, 12)
                        .background(Theme.Palette.card, in: RoundedRectangle(cornerRadius: Theme.Radius.chip))
                        .overlay(RoundedRectangle(cornerRadius: Theme.Radius.chip).strokeBorder(Theme.Palette.cardStroke))
                        .foregroundStyle(Theme.Palette.text)
                }
            }
        }
        .task { await model.loadWeight() }
        .sheet(isPresented: $showLog) { WeightLogSheet() }
    }

    private var rateText: String? {
        guard let r = model.weightCard?.rate_kg_wk, abs(r) >= 0.02 else { return nil }
        return String(format: "%@%.1f kg/wk", r < 0 ? "▼ " : "▲ ", abs(r))
    }

    @ViewBuilder private func chart(_ w: WeightCard) -> some View {
        let pts = (w.series ?? []).compactMap { p -> (Date, Double, Double?)? in
            guard let d = parseYMD(p.date) else { return nil }
            return (d, p.trend, p.weight)
        }
        if pts.count > 1 {
            Chart {
                ForEach(Array(pts.enumerated()), id: \.offset) { _, pt in
                    if let raw = pt.2 {
                        PointMark(x: .value("d", pt.0), y: .value("kg", raw))
                            .foregroundStyle(Theme.Palette.textFaint.opacity(0.5)).symbolSize(10)
                    }
                }
                ForEach(Array(pts.enumerated()), id: \.offset) { _, pt in
                    LineMark(x: .value("d", pt.0), y: .value("kg", pt.1))
                        .interpolationMethod(.catmullRom)
                        .lineStyle(.init(lineWidth: 2.5, lineCap: .round))
                        .foregroundStyle(Theme.Palette.cyan)
                }
                if let goal = w.goal?.target_kg {
                    RuleMark(y: .value("goal", goal))
                        .lineStyle(.init(lineWidth: 1, dash: [4, 4]))
                        .foregroundStyle(Theme.Palette.mint.opacity(0.6))
                }
            }
            .chartXAxis(.hidden).chartYAxis { AxisMarks(position: .trailing) }
            .frame(height: 120)
        }
    }

    @ViewBuilder private func goalRow(_ g: WeightCard.WeightGoal) -> some View {
        let onTrack = g.on_track ?? false
        let color = onTrack ? Theme.Palette.mint : Theme.Palette.amber
        HStack(spacing: Theme.Space.s) {
            Image(systemName: "target").foregroundStyle(color).font(.system(size: 14))
            VStack(alignment: .leading, spacing: 1) {
                Text(g.target_kg.map { "Goal \(String(format: "%.0f", $0)) kg" } ?? "Goal").font(Theme.Font.micro.weight(.semibold)).foregroundStyle(Theme.Palette.text)
                Text(goalDetail(g)).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
            }
            Spacer()
        }
        .padding(.vertical, 8).padding(.horizontal, Theme.Space.m)
        .background(color.opacity(0.10), in: RoundedRectangle(cornerRadius: Theme.Radius.chip))
    }

    private func goalDetail(_ g: WeightCard.WeightGoal) -> String {
        guard let proj = g.projected_date, let d = parseYMD(proj) else {
            return "Keep logging — I’ll project your finish date."
        }
        let f = DateFormatter(); f.dateFormat = "MMM d"
        var s = "On track → \(f.string(from: d))"
        if let vs = g.vs_goal_days {
            if vs > 0 { s += " · \(vs)d early" } else if vs < 0 { s = "Behind → \(f.string(from: d)) · \(abs(vs))d late" }
        }
        return s
    }
}

private struct WeightLogSheet: View {
    @EnvironmentObject var model: AppModel
    @Environment(\.dismiss) private var dismiss
    @State private var kg = ""

    var body: some View {
        NavigationStack {
            ZStack {
                Theme.Palette.bg.ignoresSafeArea()
                VStack(spacing: Theme.Space.l) {
                    Text("Weigh in at the same time each day (mornings are best) — the trend smooths out the daily noise.")
                        .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim).multilineTextAlignment(.center)
                    HStack(alignment: .firstTextBaseline, spacing: 6) {
                        TextField("0.0", text: $kg).keyboardType(.decimalPad)
                            .font(Theme.Font.num(44)).foregroundStyle(Theme.Palette.text)
                            .multilineTextAlignment(.trailing).frame(width: 140)
                        Text("kg").font(Theme.Font.title).foregroundStyle(Theme.Palette.textDim)
                    }
                    Button {
                        Haptic.success()
                        if let v = Double(kg.replacingOccurrences(of: ",", with: ".")) {
                            Task { await model.logWeight(v); dismiss() }
                        }
                    } label: {
                        Text("Save").font(Theme.Font.body.weight(.semibold))
                            .frame(maxWidth: .infinity).padding(.vertical, 14)
                            .background(Theme.Grad.brand, in: RoundedRectangle(cornerRadius: Theme.Radius.chip))
                            .foregroundStyle(.white)
                    }.padding(.horizontal, Theme.Space.xl)
                    Spacer()
                }.padding(.top, Theme.Space.xl).padding(Theme.Space.m)
            }
            .navigationTitle("Log weigh-in").navigationBarTitleDisplayMode(.inline)
            .toolbar { ToolbarItem(placement: .cancellationAction) { Button("Cancel") { dismiss() } } }
            .toolbarColorScheme(.dark, for: .navigationBar)
        }
    }
}

// MARK: - date helpers

private func parseISO(_ s: String) -> Date? {
    let f = ISO8601DateFormatter()
    f.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
    return f.date(from: s) ?? ISO8601DateFormatter().date(from: s)
}

private func parseYMD(_ s: String) -> Date? {
    let f = DateFormatter(); f.dateFormat = "yyyy-MM-dd"; f.timeZone = .current
    return f.date(from: s)
}
