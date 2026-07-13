import SwiftUI

// Shared premium building blocks for a LIFTING session, so a lift looks identical whether it's the
// post-workout "wow" screen (WorkoutSummaryView) or the sealed detail opened from Daily (LiftDetailView).
// Both used to hand-roll their own hero / zones / sets, and drifted — the detail was a flat, greyed-out
// downgrade. These are the ONE source of truth; both screens render them.

// MARK: - Hero

/// The luminous lift hero: a dumbbell mark, the duration huge, a STRENGTH label, and peak-HR / red-zone pills.
struct LiftHero: View {
    let duration: String
    let maxHr: Int?
    let redMinutes: Int?

    var body: some View {
        ZStack {
            RoundedRectangle(cornerRadius: Theme.Radius.card, style: .continuous)
                .fill(LinearGradient(colors: [Theme.Palette.pink.opacity(0.28), Theme.Palette.violet.opacity(0.22), Theme.Palette.bg2],
                                     startPoint: .topLeading, endPoint: .bottomTrailing))
            Theme.Grad.glow(Theme.Palette.pink).opacity(0.5)

            VStack(spacing: Theme.Space.xs) {
                Image(systemName: "dumbbell.fill").font(.system(size: 26, weight: .bold)).foregroundStyle(Theme.Palette.pink)
                Text(duration).font(Theme.Font.num(60)).foregroundStyle(.white)
                Text("STRENGTH").font(Theme.Font.micro).tracking(2).foregroundStyle(.white.opacity(0.6))
                HStack(spacing: Theme.Space.s) {
                    if let hr = maxHr, hr > 0 { LiftHeroPill("heart.fill", String(localized: "\(hr) peak")) }
                    if let red = redMinutes, red >= 1 { LiftHeroPill("flame.fill", String(localized: "\(red)m in red")) }
                }
                .padding(.top, 2)
            }
            .padding(.vertical, Theme.Space.l)
        }
        .frame(height: 260)
        .overlay(RoundedRectangle(cornerRadius: Theme.Radius.card, style: .continuous).strokeBorder(Theme.Palette.cardStroke))
    }
}

private func LiftHeroPill(_ icon: String, _ text: String) -> some View {
    HStack(spacing: 5) {
        Image(systemName: icon).font(.system(size: 11, weight: .semibold))
        Text(text).font(Theme.Font.num(14, .semibold))
    }
    .foregroundStyle(.white)
    .padding(.horizontal, 11).padding(.vertical, 7)
    .background(.ultraThinMaterial, in: Capsule())
}

// MARK: - Stat grid

/// The premium lift stat grid — icon + colored-value StatTiles. Driven by the sealed detail, with a live
/// max-bpm / duration fallback so it's never empty before the seal lands.
struct LiftStatGrid: View {
    let detail: RunDetail?
    let fallbackMaxBpm: Int
    let fallbackDuration: String

    var body: some View {
        LazyVGrid(columns: [GridItem(.flexible(), spacing: Theme.Space.s),
                            GridItem(.flexible(), spacing: Theme.Space.s),
                            GridItem(.flexible(), spacing: Theme.Space.s)], spacing: Theme.Space.s) {
            ForEach(tiles, id: \.label) { t in
                StatTile(icon: t.icon, value: t.value, unit: t.unit, label: t.label, accent: t.accent)
            }
        }
    }

    private struct T { let icon, value: String; let unit: String?; let label: String; let accent: Color
        init(_ i: String, _ v: String, _ u: String?, _ l: String, _ a: Color) { icon = i; value = v; unit = u; label = l; accent = a } }

    private var tiles: [T] {
        var t: [T] = []
        if let hr = detail?.avg_hr { t.append(.init("heart.fill", "\(hr)", "bpm", String(localized: "avg hr"), Theme.Palette.pink)) }
        t.append(.init("waveform.path.ecg", "\(detail?.max_hr ?? fallbackMaxBpm)", "bpm", String(localized: "peak hr"), Theme.Palette.pink))
        if let load = detail?.trimp { t.append(.init("bolt.fill", "\(Int(load.rounded()))", nil, String(localized: "load"), Theme.Palette.cyan)) }
        if let c = detail?.calories_kcal { t.append(.init("flame.fill", "\(c)", "kcal", String(localized: "calories"), Theme.Palette.amber)) }
        if let v = detail?.vo2max { t.append(.init("lungs.fill", String(format: "%.1f", v), nil, String(localized: "VO₂max"), Theme.Palette.mint)) }
        if let hrv = detail?.workout_hrv_ms { t.append(.init("heart.text.square.fill", "\(Int(hrv.rounded()))", "ms", String(localized: "HRV"), Theme.Palette.cyan)) }
        // Before the seal lands, show what we have so the grid is never empty.
        if t.count < 2 {
            t = [.init("waveform.path.ecg", "\(fallbackMaxBpm)", "bpm", String(localized: "peak hr"), Theme.Palette.pink),
                 .init("clock.fill", fallbackDuration, nil, String(localized: "time"), Theme.Palette.cyan)]
        }
        return t
    }
}

// MARK: - HR zones

/// Premium HR-zone card: a stacked proportional bar + a per-zone legend with percent and minutes.
struct HrZonesCard: View {
    let zones: HrZones

    var body: some View {
        let z: [(String, Double, Color)] = [
            ("Z1", zones.z1 ?? 0, Theme.Palette.cyan), ("Z2", zones.z2 ?? 0, Theme.Palette.mint),
            ("Z3", zones.z3 ?? 0, Theme.Palette.amber), ("Z4", zones.z4 ?? 0, Theme.Palette.pink),
            ("Z5", zones.z5 ?? 0, Theme.Palette.violet),
        ]
        let total = max(0.1, z.reduce(0) { $0 + $1.1 })
        LiftCard("Heart-rate zones", "Time at each intensity") {
            VStack(spacing: Theme.Space.m) {
                GeometryReader { geo in
                    HStack(spacing: 2) {
                        ForEach(z, id: \.0) { zone in
                            if zone.1 > 0 {
                                Capsule().fill(zone.2)
                                    .frame(width: max(3, geo.size.width * CGFloat(zone.1 / total)))
                            }
                        }
                    }
                }.frame(height: 16)
                VStack(spacing: Theme.Space.xs) {
                    ForEach(z.reversed(), id: \.0) { zone in
                        if zone.1 > 0 {
                            HStack(spacing: Theme.Space.s) {
                                Circle().fill(zone.2).frame(width: 8, height: 8)
                                Text(zoneName(zone.0)).font(Theme.Font.body).foregroundStyle(Theme.Palette.text)
                                Spacer()
                                Text("\(Int((zone.1 / total * 100).rounded()))%").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim).frame(width: 40, alignment: .trailing)
                                Text(minLabel(zone.1)).font(Theme.Font.num(14)).foregroundStyle(Theme.Palette.text).frame(width: 52, alignment: .trailing)
                            }
                        }
                    }
                }
            }
        }
    }

    private func zoneName(_ z: String) -> LocalizedStringKey {
        switch z { case "Z1": return "Z1 · Recovery"; case "Z2": return "Z2 · Easy"; case "Z3": return "Z3 · Aerobic"
        case "Z4": return "Z4 · Threshold"; default: return "Z5 · Max" }
    }
    private func minLabel(_ m: Double) -> String { m >= 60 ? String(format: "%d:%02d", Int(m) / 60, Int(m) % 60) : "\(Int(m.rounded()))m" }
}

/// True total minutes across all zones — callers use it to decide whether to show the card at all.
func hrZonesTotal(_ z: HrZones) -> Double { (z.z1 ?? 0) + (z.z2 ?? 0) + (z.z3 ?? 0) + (z.z4 ?? 0) + (z.z5 ?? 0) }

// MARK: - Sets

/// Premium sets card: each exercise with its per-set weight×reps and a total-volume figure — not reps-only.
struct LiftSetsCard: View {
    let strength: StrengthDetail
    let exercises: [StrengthExercise]
    var imperial: Bool = false

    var body: some View {
        LiftCard("Sets", "\(strength.total_sets ?? 0) sets · \(strength.total_reps ?? 0) reps") {
            VStack(spacing: Theme.Space.m) {
                ForEach(exercises) { ex in
                    VStack(alignment: .leading, spacing: 5) {
                        HStack(alignment: .firstTextBaseline) {
                            VStack(alignment: .leading, spacing: 1) {
                                Text(ex.name).font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.text)
                                if let mg = ex.muscle_group { Text(mg.capitalized).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint) }
                            }
                            Spacer()
                            if let vol = volume(ex) {
                                Text(vol).font(Theme.Font.num(14)).foregroundStyle(Theme.Palette.pink)
                            }
                        }
                        Text(setSummary(ex)).font(Theme.Font.num(15)).foregroundStyle(Theme.Palette.textDim)
                            .frame(maxWidth: .infinity, alignment: .leading)
                    }
                    if ex.id != exercises.last?.id { Divider().overlay(Theme.Palette.cardStroke) }
                }
            }
        }
    }

    private var unit: String { imperial ? "lb" : "kg" }
    private func conv(_ kg: Double) -> Double { imperial ? kg * 2.20462 : kg }
    private func fmtW(_ w: Double) -> String { w == w.rounded() ? "\(Int(w))" : String(format: "%.1f", w) }

    /// "8×60 · 8×60 · 6×65 kg" when weight is present, else "8 · 8 · 6 reps".
    private func setSummary(_ ex: StrengthExercise) -> String {
        let sets = ex.sets ?? []
        let anyWeight = sets.contains { ($0.weight_kg ?? 0) > 0 }
        let parts = sets.map { s -> String in
            let r = s.reps ?? 0
            if anyWeight, let w = s.weight_kg, w > 0 { return "\(r)×\(fmtW(conv(w)))" }
            return "\(r)"
        }
        return parts.joined(separator: " · ") + (anyWeight ? " \(unit)" : String(localized: " reps"))
    }

    /// Total volume moved for the exercise (Σ reps × weight), e.g. "1,240 kg". Nil when no weight logged.
    private func volume(_ ex: StrengthExercise) -> String? {
        let kg = (ex.sets ?? []).reduce(0.0) { acc, s in acc + Double(s.reps ?? 0) * (s.weight_kg ?? 0) }
        guard kg > 0 else { return nil }
        let v = conv(kg)
        let f = NumberFormatter(); f.numberStyle = .decimal; f.maximumFractionDigits = 0
        let num = f.string(from: NSNumber(value: v)) ?? "\(Int(v))"
        return "\(num) \(unit)"
    }
}

// MARK: - Card shell

/// The premium card shell shared by the lift components: an uppercase title, an optional subtitle, content.
struct LiftCard<Content: View>: View {
    let title: LocalizedStringKey
    let subtitle: LocalizedStringKey?
    @ViewBuilder let content: () -> Content

    init(_ title: LocalizedStringKey, _ subtitle: LocalizedStringKey? = nil, @ViewBuilder content: @escaping () -> Content) {
        self.title = title; self.subtitle = subtitle; self.content = content
    }

    var body: some View {
        VStack(alignment: .leading, spacing: Theme.Space.m) {
            HStack(alignment: .firstTextBaseline) {
                Text(title).font(Theme.Font.label).tracking(0.8).foregroundStyle(Theme.Palette.textDim).textCase(.uppercase)
                Spacer()
                if let subtitle { Text(subtitle).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint) }
            }
            content()
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(Theme.Space.m)
        .background(RoundedRectangle(cornerRadius: Theme.Radius.card, style: .continuous).fill(Theme.Palette.card))
        .overlay(RoundedRectangle(cornerRadius: Theme.Radius.card, style: .continuous).strokeBorder(Theme.Palette.cardStroke))
    }
}
