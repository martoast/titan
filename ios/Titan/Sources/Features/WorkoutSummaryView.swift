import SwiftUI

/// The screen shown the INSTANT a workout ends — the "wow moment." It's built to feel premium: an
/// immersive hero (the route map for a run, a luminous gradient for a lift) with the headline number
/// huge and a proud verdict, then a crafted set of stat tiles, an HR-zone bar, and splits/elevation/
/// sets. Live stats render immediately; the server-sealed detail (map, zones, VO₂max, sets) fills in a
/// few seconds later and animates in. Bound to `model.workoutSummary`.
struct WorkoutSummaryView: View {
    let summary: WorkoutSummaryState
    @EnvironmentObject var model: AppModel
    @Environment(\.dismiss) private var dismiss
    @State private var appeared = false

    private var isLift: Bool { summary.isLift }
    private var detail: RunDetail? { summary.detail }
    private var imperial: Bool { (detail?.units ?? "metric") == "imperial" }
    private var accent: Color { isLift ? Theme.Palette.pink : Theme.Palette.cyan }

    var body: some View {
        NavigationStack {
            ZStack(alignment: .top) {
                Theme.Palette.bg.ignoresSafeArea()
                Theme.Grad.glow(accent).frame(height: 360).opacity(0.35).ignoresSafeArea(edges: .top)

                ScrollView {
                    VStack(spacing: Theme.Space.m) {
                        hero
                            .opacity(appeared ? 1 : 0)
                            .offset(y: appeared ? 0 : 18)

                        verdictRow.stagger(appeared, 0.05)

                        bigStats.stagger(appeared, 0.10)

                        if let z = detail?.hr_zones, zonesTotal(z) > 0 {
                            zonesCard(z).stagger(appeared, 0.15)
                        }

                        if isLift {
                            if let s = detail?.strength, let ex = s.exercises, !ex.isEmpty {
                                setsCard(s, ex).stagger(appeared, 0.2)
                            } else if summary.loading {
                                loadingNote("Analyzing your sets…").stagger(appeared, 0.2)
                            }
                        } else {
                            if let prof = detail?.elevation_profile, prof.count >= 2 {
                                elevationCard(prof).stagger(appeared, 0.2)
                            }
                            if let splits = splitsForUnit, !splits.isEmpty {
                                splitsCard(splits).stagger(appeared, 0.25)
                            }
                        }

                        shareRow.stagger(appeared, 0.3)
                        statusFooter
                        Color.clear.frame(height: 6)
                    }
                    .padding(Theme.Space.m)
                }
                .scrollIndicators(.hidden)
            }
            .navigationTitle(isLift ? "Lift" : "Run")
            .navigationBarTitleDisplayMode(.inline)
            .toolbarColorScheme(.dark, for: .navigationBar)
            .toolbar {
                ToolbarItem(placement: .confirmationAction) {
                    Button("Done") { dismiss() }.font(Theme.Font.label.weight(.bold)).foregroundStyle(accent)
                }
            }
            .onAppear {
                withAnimation(Theme.Motion.spring) { appeared = true }
                Haptic.success()
            }
        }
    }

    // MARK: - Hero

    @ViewBuilder private var hero: some View {
        if isLift { liftHero } else { runHero }
    }

    /// Run: the route map, full-bleed inside a tall rounded card, with the distance HUGE over a scrim.
    private var runHero: some View {
        ZStack(alignment: .bottomLeading) {
            // The map (or a shimmer while it seals).
            Group {
                if let url = detail?.map_url_large ?? summary.detail?.map_url_large, let u = URL(string: url) {
                    AsyncImage(url: u) { phase in
                        switch phase {
                        case .success(let img): img.resizable().scaledToFill()
                        default: heroFallback
                        }
                    }
                } else {
                    heroFallback
                }
            }
            .frame(maxWidth: .infinity)
            .frame(height: 340)
            .clipped()

            LinearGradient(colors: [.clear, .black.opacity(0.15), .black.opacity(0.82)],
                           startPoint: .center, endPoint: .bottom)

            VStack(alignment: .leading, spacing: Theme.Space.s) {
                kindChip
                HStack(alignment: .firstTextBaseline, spacing: 6) {
                    Text(distanceValue).font(Theme.Font.num(62)).foregroundStyle(.white)
                        .shadow(color: .black.opacity(0.5), radius: 8, y: 2)
                    Text(distanceUnit).font(Theme.Font.num(22, .semibold)).foregroundStyle(.white.opacity(0.85))
                }
                HStack(spacing: Theme.Space.s) {
                    heroPill("clock", durationText)
                    heroPill("speedometer", paceText + " " + (imperial ? "/mi" : "/km"))
                    if let e = detail?.elevation_gain_m, e > 0 { heroPill("mountain.2.fill", "\(elevText(e))") }
                }
            }
            .padding(Theme.Space.m)
        }
        .frame(height: 340)
        .clipShape(RoundedRectangle(cornerRadius: Theme.Radius.card, style: .continuous))
        .overlay(RoundedRectangle(cornerRadius: Theme.Radius.card, style: .continuous).strokeBorder(Theme.Palette.cardStroke))
    }

    /// Lift: a luminous gradient hero with the duration huge + the peak-HR story.
    private var liftHero: some View {
        ZStack {
            RoundedRectangle(cornerRadius: Theme.Radius.card, style: .continuous)
                .fill(LinearGradient(colors: [Theme.Palette.pink.opacity(0.28), Theme.Palette.violet.opacity(0.22), Theme.Palette.bg2],
                                     startPoint: .topLeading, endPoint: .bottomTrailing))
            Theme.Grad.glow(Theme.Palette.pink).opacity(0.5)

            VStack(spacing: Theme.Space.xs) {
                Image(systemName: "dumbbell.fill").font(.system(size: 26, weight: .bold)).foregroundStyle(Theme.Palette.pink)
                Text(durationText).font(Theme.Font.num(60)).foregroundStyle(.white)
                Text("STRENGTH").font(Theme.Font.micro).tracking(2).foregroundStyle(.white.opacity(0.6))
                HStack(spacing: Theme.Space.s) {
                    if let hr = detail?.max_hr ?? (summary.maxBpm > 0 ? summary.maxBpm : nil) {
                        heroPill("heart.fill", "\(hr) peak")
                    }
                    if let red = redMinutes, red >= 1 { heroPill("flame.fill", "\(red)m in red") }
                }
                .padding(.top, 2)
            }
            .padding(.vertical, Theme.Space.l)
        }
        .frame(height: 260)
        .overlay(RoundedRectangle(cornerRadius: Theme.Radius.card, style: .continuous).strokeBorder(Theme.Palette.cardStroke))
    }

    private var heroFallback: some View {
        ZStack {
            LinearGradient(colors: [Theme.Palette.cyan.opacity(0.18), Theme.Palette.bg2], startPoint: .top, endPoint: .bottom)
            if summary.loading {
                VStack(spacing: 8) {
                    ProgressView().tint(.white.opacity(0.7))
                    Text("Mapping your route…").font(Theme.Font.micro).foregroundStyle(.white.opacity(0.7))
                }
            } else {
                VStack(spacing: 6) {
                    Image(systemName: "mappin.slash").font(.system(size: 26)).foregroundStyle(.white.opacity(0.5))
                    Text("No GPS route").font(Theme.Font.micro).foregroundStyle(.white.opacity(0.6))
                }
            }
        }
    }

    private var kindChip: some View {
        HStack(spacing: 5) {
            Image(systemName: "figure.run").font(.system(size: 11, weight: .bold))
            Text("RUN").font(Theme.Font.micro).tracking(1.5)
        }
        .foregroundStyle(.white)
        .padding(.horizontal, 10).padding(.vertical, 5)
        .background(.ultraThinMaterial, in: Capsule())
    }

    private func heroPill(_ icon: String, _ text: String) -> some View {
        HStack(spacing: 5) {
            Image(systemName: icon).font(.system(size: 11, weight: .semibold))
            Text(text).font(Theme.Font.num(14, .semibold))
        }
        .foregroundStyle(.white)
        .padding(.horizontal, 11).padding(.vertical, 7)
        .background(.ultraThinMaterial, in: Capsule())
    }

    // MARK: - Verdict (a proud one-liner)

    private var verdictRow: some View {
        HStack(spacing: Theme.Space.s) {
            Image(systemName: verdict.icon).font(.system(size: 16, weight: .bold)).foregroundStyle(accent)
            Text(verdict.text).font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.text)
            Spacer(minLength: 0)
        }
        .padding(Theme.Space.m)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(RoundedRectangle(cornerRadius: Theme.Radius.chip, style: .continuous).fill(accent.opacity(0.10)))
        .overlay(RoundedRectangle(cornerRadius: Theme.Radius.chip, style: .continuous).strokeBorder(accent.opacity(0.18)))
    }

    private var verdict: (icon: String, text: String) {
        if isLift {
            let red = redMinutes ?? 0
            if red >= 8 { return ("flame.fill", "Brutal session — you lived in the red zone.") }
            if red >= 2 { return ("bolt.fill", "Strong lift. Real time at high intensity.") }
            return ("checkmark.seal.fill", "Session logged. Consistency is how you build.")
        }
        let km = detail?.distance_km ?? summary.distanceKm
        let pace = detail?.avg_pace_s_per_km ?? 0
        if km >= 15 { return ("crown.fill", "Long one in the bank — huge aerobic work.") }
        if pace > 0, pace <= 300 { return ("bolt.fill", "Quick tempo run — that was moving.") }
        if km >= 8 { return ("flame.fill", "Solid distance. Great endurance day.") }
        return ("checkmark.seal.fill", "Nice run — every km counts. Well done.")
    }

    // MARK: - Big stats (premium tiles)

    private var bigStats: some View {
        LazyVGrid(columns: [GridItem(.flexible(), spacing: Theme.Space.s),
                            GridItem(.flexible(), spacing: Theme.Space.s),
                            GridItem(.flexible(), spacing: Theme.Space.s)], spacing: Theme.Space.s) {
            ForEach(tiles, id: \.label) { t in
                StatTile(icon: t.icon, value: t.value, unit: t.unit, label: t.label, accent: t.accent)
            }
        }
    }

    private var tiles: [Tile] {
        var t: [Tile] = []
        if isLift {
            if let hr = detail?.avg_hr { t.append(.init("heart.fill", "\(hr)", "bpm", "avg hr", Theme.Palette.pink)) }
            t.append(.init("waveform.path.ecg", "\(detail?.max_hr ?? summary.maxBpm)", "bpm", "peak hr", Theme.Palette.pink))
            if let load = detail?.trimp { t.append(.init("bolt.fill", "\(Int(load.rounded()))", nil, "load", Theme.Palette.cyan)) }
            if let c = detail?.calories_kcal { t.append(.init("flame.fill", "\(c)", "kcal", "calories", Theme.Palette.amber)) }
            if let v = detail?.vo2max { t.append(.init("lungs.fill", String(format: "%.1f", v), nil, "VO₂max", Theme.Palette.mint)) }
            if let hrv = detail?.workout_hrv_ms { t.append(.init("heart.text.square.fill", "\(Int(hrv.rounded()))", "ms", "HRV", Theme.Palette.cyan)) }
        } else {
            if let hr = detail?.avg_hr { t.append(.init("heart.fill", "\(hr)", "bpm", "avg hr", Theme.Palette.pink)) }
            t.append(.init("waveform.path.ecg", "\(detail?.max_hr ?? summary.maxBpm)", "bpm", "peak hr", Theme.Palette.pink))
            if let g = detail?.gap_s_per_km, g > 0 { t.append(.init("arrow.up.forward", paceString(g), imperial ? "/mi" : "/km", "GAP", Theme.Palette.mint)) }
            if let c = detail?.calories_kcal { t.append(.init("flame.fill", "\(c)", "kcal", "calories", Theme.Palette.amber)) }
            if let v = detail?.vo2max { t.append(.init("lungs.fill", String(format: "%.1f", v), nil, "VO₂max", Theme.Palette.mint)) }
            if let re = detail?.relative_effort, re > 0 { t.append(.init("chart.bar.fill", "\(re)", nil, "effort", Theme.Palette.violet)) }
        }
        // Before the seal lands, show what we have from live stats so the grid is never empty.
        if t.count < 2 {
            t = [.init("waveform.path.ecg", "\(summary.maxBpm)", "bpm", "peak hr", Theme.Palette.pink)]
            if isLift { t.append(.init("clock.fill", durationText, nil, "time", Theme.Palette.cyan)) }
            else { t.append(.init("figure.run", distanceValue, distanceUnit, "distance", Theme.Palette.cyan)) }
        }
        return t
    }

    private struct Tile { let icon, value: String; let unit: String?; let label: String; let accent: Color
        init(_ i: String, _ v: String, _ u: String?, _ l: String, _ a: Color) { icon = i; value = v; unit = u; label = l; accent = a } }

    // MARK: - HR zones (a premium stacked bar + legend)

    private func zonesCard(_ z: HrZones) -> some View {
        let zones: [(String, Double, Color)] = [
            ("Z1", z.z1 ?? 0, Theme.Palette.cyan), ("Z2", z.z2 ?? 0, Theme.Palette.mint),
            ("Z3", z.z3 ?? 0, Theme.Palette.amber), ("Z4", z.z4 ?? 0, Theme.Palette.pink),
            ("Z5", z.z5 ?? 0, Theme.Palette.violet),
        ]
        let total = max(0.1, zones.reduce(0) { $0 + $1.1 })
        return card("Heart-rate zones", "Time at each intensity") {
            VStack(spacing: Theme.Space.m) {
                GeometryReader { geo in
                    HStack(spacing: 2) {
                        ForEach(zones, id: \.0) { z in
                            if z.1 > 0 {
                                Capsule().fill(z.2)
                                    .frame(width: max(3, geo.size.width * CGFloat(z.1 / total)))
                            }
                        }
                    }
                }.frame(height: 16)
                VStack(spacing: Theme.Space.xs) {
                    ForEach(zones.reversed(), id: \.0) { z in
                        if z.1 > 0 {
                            HStack(spacing: Theme.Space.s) {
                                Circle().fill(z.2).frame(width: 8, height: 8)
                                Text(zoneName(z.0)).font(Theme.Font.body).foregroundStyle(Theme.Palette.text)
                                Spacer()
                                Text("\(Int((z.1 / total * 100).rounded()))%").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim).frame(width: 40, alignment: .trailing)
                                Text(minLabel(z.1)).font(Theme.Font.num(14)).foregroundStyle(Theme.Palette.text).frame(width: 52, alignment: .trailing)
                            }
                        }
                    }
                }
            }
        }
    }

    private func zoneName(_ z: String) -> String {
        switch z { case "Z1": return "Z1 · Recovery"; case "Z2": return "Z2 · Easy"; case "Z3": return "Z3 · Aerobic"
        case "Z4": return "Z4 · Threshold"; default: return "Z5 · Max" }
    }

    // MARK: - Sets

    private func setsCard(_ s: StrengthDetail, _ exercises: [StrengthExercise]) -> some View {
        card("Sets", "\(s.total_sets ?? 0) sets · \(s.total_reps ?? 0) reps") {
            VStack(spacing: Theme.Space.s) {
                ForEach(exercises) { ex in
                    HStack {
                        VStack(alignment: .leading, spacing: 1) {
                            Text(ex.name).font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.text)
                            if let mg = ex.muscle_group { Text(mg.capitalized).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint) }
                        }
                        Spacer()
                        Text((ex.sets ?? []).map { "\($0.reps ?? 0)" }.joined(separator: " · "))
                            .font(Theme.Font.num(15)).foregroundStyle(Theme.Palette.textDim)
                    }
                    if ex.id != exercises.last?.id { Divider().overlay(Theme.Palette.cardStroke) }
                }
            }
        }
    }

    // MARK: - Splits + Elevation

    private var splitsForUnit: [RunSplit]? { imperial ? detail?.splits?.mi : detail?.splits?.km }

    private func splitsCard(_ splits: [RunSplit]) -> some View {
        let paces = splits.compactMap { $0.pace_s_per_unit }.filter { $0 > 0 }
        let pMin = paces.min() ?? 1, pMax = paces.max() ?? 1
        return card("Splits", "per \(imperial ? "mile" : "km")") {
            VStack(spacing: Theme.Space.xs) {
                ForEach(splits) { sp in
                    let p = sp.pace_s_per_unit ?? 0
                    let frac = pMax > pMin ? 0.3 + 0.7 * ((pMax - p) / (pMax - pMin)) : 1
                    HStack(spacing: Theme.Space.s) {
                        Text("\(sp.index)").font(Theme.Font.micro.monospacedDigit()).foregroundStyle(Theme.Palette.textDim).frame(width: 16, alignment: .leading)
                        GeometryReader { g in
                            RoundedRectangle(cornerRadius: 5)
                                .fill(LinearGradient(colors: [Theme.Palette.cyan.opacity(0.85), Theme.Palette.mint.opacity(0.5)], startPoint: .leading, endPoint: .trailing))
                                .frame(width: max(6, g.size.width * frac), height: 16)
                        }.frame(height: 16)
                        Text(RunFmt.pace(p)).font(Theme.Font.num(14)).foregroundStyle(Theme.Palette.text).frame(width: 50, alignment: .trailing)
                    }
                }
            }
        }
    }

    private func elevationCard(_ prof: [ElevationPoint]) -> some View {
        let alts = prof.map(\.alt_m)
        let lo = alts.min() ?? 0, hi = alts.max() ?? 1
        let span = max(1, hi - lo)
        return card("Elevation", detail?.elevation_gain_m.map { "\(elevText($0)) gain" } ?? "") {
            GeometryReader { geo in
                let pts = prof.enumerated().map { i, pt in
                    CGPoint(x: geo.size.width * CGFloat(i) / CGFloat(max(1, prof.count - 1)),
                            y: geo.size.height * (1 - CGFloat((pt.alt_m - lo) / span)))
                }
                ZStack {
                    Path { p in
                        guard let f = pts.first else { return }
                        p.move(to: CGPoint(x: f.x, y: geo.size.height)); p.addLine(to: f)
                        pts.forEach { p.addLine(to: $0) }
                        if let l = pts.last { p.addLine(to: CGPoint(x: l.x, y: geo.size.height)) }
                    }.fill(LinearGradient(colors: [Theme.Palette.amber.opacity(0.30), .clear], startPoint: .top, endPoint: .bottom))
                    Path { p in
                        guard let f = pts.first else { return }
                        p.move(to: f); pts.forEach { p.addLine(to: $0) }
                    }.stroke(Theme.Palette.amber, style: StrokeStyle(lineWidth: 2, lineJoin: .round))
                }
            }
            .frame(height: 70)
        }
    }

    // MARK: - Share + footer

    private var shareRow: some View {
        Group {
            if let urlStr = detail?.map_url_large, let url = URL(string: urlStr) {
                ShareLink(item: url, subject: Text(shareCaption), message: Text(shareCaption)) {
                    HStack(spacing: Theme.Space.s) {
                        Image(systemName: "square.and.arrow.up.fill")
                        Text("Share this \(isLift ? "lift" : "run")").font(Theme.Font.body.weight(.semibold))
                    }
                    .foregroundStyle(.white)
                    .frame(maxWidth: .infinity).padding(.vertical, Theme.Space.m)
                    .background(RoundedRectangle(cornerRadius: Theme.Radius.chip, style: .continuous).fill(accent.opacity(0.9)))
                }
            }
        }
    }

    private var shareCaption: String {
        if isLift { return "Strength session — logged on Titan 💪" }
        let d = distanceValue + " " + distanceUnit
        let p = paceText == "—" ? "" : " at \(paceText)\(imperial ? "/mi" : "/km")"
        return "Ran \(d)\(p) — tracked on Titan 🏃"
    }

    private var statusFooter: some View {
        Group {
            if summary.loading { Label("Sealing the full breakdown…", systemImage: "sparkles") }
            else if summary.failed { Label("Saved — the full breakdown appears in Daily shortly.", systemImage: "checkmark.circle") }
            else { Label("Sealed from your band", systemImage: "checkmark.seal.fill") }
        }
        .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
        .frame(maxWidth: .infinity).padding(.top, 2)
    }

    // MARK: - Card shell + helpers

    private func card<Content: View>(_ title: String, _ subtitle: String = "", @ViewBuilder _ content: () -> Content) -> some View {
        VStack(alignment: .leading, spacing: Theme.Space.m) {
            HStack(alignment: .firstTextBaseline) {
                Text(title.uppercased()).font(Theme.Font.label).tracking(0.8).foregroundStyle(Theme.Palette.textDim)
                Spacer()
                if !subtitle.isEmpty { Text(subtitle).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint) }
            }
            content()
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(Theme.Space.m)
        .background(RoundedRectangle(cornerRadius: Theme.Radius.card, style: .continuous).fill(Theme.Palette.card))
        .overlay(RoundedRectangle(cornerRadius: Theme.Radius.card, style: .continuous).strokeBorder(Theme.Palette.cardStroke))
    }

    private func loadingNote(_ text: String) -> some View {
        HStack(spacing: Theme.Space.s) { ProgressView().tint(Theme.Palette.textFaint); Text(text).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint) }
            .frame(maxWidth: .infinity).padding(.vertical, Theme.Space.m)
            .background(RoundedRectangle(cornerRadius: Theme.Radius.card).fill(Theme.Palette.card))
    }

    private var redMinutes: Int? {
        guard let z = detail?.hr_zones else { return nil }
        let r = (z.z4 ?? 0) + (z.z5 ?? 0)
        return r > 0 ? Int(r.rounded()) : nil
    }
    private func zonesTotal(_ z: HrZones) -> Double { (z.z1 ?? 0) + (z.z2 ?? 0) + (z.z3 ?? 0) + (z.z4 ?? 0) + (z.z5 ?? 0) }
    private func minLabel(_ m: Double) -> String { m >= 60 ? String(format: "%d:%02d", Int(m) / 60, Int(m) % 60) : "\(Int(m.rounded()))m" }
    private func elevText(_ m: Int) -> String { imperial ? "\(Int(Double(m) * 3.28084)) ft" : "\(m) m" }

    private var durationText: String {
        let s = detail?.moving_time_s ?? detail?.duration_min.map { $0 * 60 } ?? summary.elapsedSec
        let h = s / 3600, m = (s % 3600) / 60, sec = s % 60
        return h > 0 ? String(format: "%d:%02d:%02d", h, m, sec) : String(format: "%d:%02d", m, sec)
    }
    private var distanceValue: String {
        let km = detail?.distance_km ?? summary.distanceKm
        return imperial ? String(format: "%.2f", km * 0.621371) : String(format: "%.2f", km)
    }
    private var distanceUnit: String { imperial ? "mi" : "km" }
    private var paceText: String {
        guard let p = detail?.avg_pace_s_per_km, p > 0 else { return "—" }
        return paceString(p)
    }
    private func paceString(_ secPerKm: Int) -> String {
        let s = imperial ? Int(Double(secPerKm) / 0.621371) : secPerKm
        return String(format: "%d:%02d", s / 60, s % 60)
    }
}

/// A premium stat tile: an accent icon, the value big, a small unit, and a quiet label.
struct StatTile: View {
    let icon: String; let value: String; let unit: String?; let label: String; let accent: Color
    var body: some View {
        VStack(alignment: .leading, spacing: 3) {
            Image(systemName: icon).font(.system(size: 13, weight: .semibold)).foregroundStyle(accent)
            Spacer(minLength: 2)
            HStack(alignment: .firstTextBaseline, spacing: 2) {
                Text(value).font(Theme.Font.num(23)).foregroundStyle(Theme.Palette.text).lineLimit(1).minimumScaleFactor(0.6)
                if let unit { Text(unit).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint) }
            }
            Text(label.uppercased()).font(Theme.Font.micro).tracking(0.4).foregroundStyle(Theme.Palette.textDim)
        }
        .frame(maxWidth: .infinity, minHeight: 78, alignment: .leading)
        .padding(Theme.Space.s)
        .background(RoundedRectangle(cornerRadius: Theme.Radius.chip, style: .continuous).fill(Theme.Palette.card))
        .overlay(RoundedRectangle(cornerRadius: Theme.Radius.chip, style: .continuous).strokeBorder(accent.opacity(0.14)))
    }
}

/// Staggered entrance: fade + slide in, offset by a small per-element delay for a choreographed reveal.
private extension View {
    func stagger(_ appeared: Bool, _ delay: Double) -> some View {
        self.opacity(appeared ? 1 : 0)
            .offset(y: appeared ? 0 : 14)
            .animation(Theme.Motion.spring.delay(delay), value: appeared)
    }
}
