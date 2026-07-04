import SwiftUI

/// The detail screen for a sealed LIFTING session, opened from the Daily → Train list. A lift has no
/// route/distance, so this headlines the strength story: time, HR zones, peak HR, VO₂max, calories,
/// training load, and the detected sets/reps. Mirrors the lift half of WorkoutSummaryView, driven
/// purely by the server-sealed RunDetail (fetched by id, like RunDetailView).
struct LiftDetailView: View {
    let runId: Int
    let fallback: RunSummary
    @EnvironmentObject var model: AppModel
    @Environment(\.dismiss) private var dismiss
    @State private var detail: RunDetail?
    @State private var loading = true
    @State private var failed = false

    private var accent: Color { Theme.Palette.pink }

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(spacing: Theme.Space.m) {
                    hero
                    if let z = detail?.hr_zones, zonesTotal(z) > 0 { zonesCard(z) }
                    metricGrid
                    if let s = detail?.strength, let ex = s.exercises, !ex.isEmpty {
                        setsCard(s, ex)
                    } else if loading {
                        loadingNote("Loading your lift…")
                    }
                    if failed {
                        Text("Couldn't load this workout. It's saved — pull back and reopen.")
                            .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
                            .frame(maxWidth: .infinity).multilineTextAlignment(.center)
                    }
                }
                .padding(Theme.Space.m)
            }
            .background(Theme.Palette.bg.ignoresSafeArea())
            .navigationTitle(detail?.title ?? fallback.title)
            .navigationBarTitleDisplayMode(.inline)
            .toolbar { ToolbarItem(placement: .topBarLeading) { Button("Done") { dismiss() } } }
            .task { await load() }
        }
    }

    private func load() async {
        loading = true; failed = false
        do { detail = try await model.api.runDetail(runId) } catch { failed = true }
        loading = false
    }

    // MARK: hero

    private var hero: some View {
        VStack(spacing: Theme.Space.s) {
            Image(systemName: "dumbbell.fill").font(.system(size: 30, weight: .bold)).foregroundStyle(accent)
            Text(durationText).font(Theme.Font.num(46))
            Text("time").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
        }
        .frame(maxWidth: .infinity)
        .padding(.vertical, Theme.Space.l)
        .background(RoundedRectangle(cornerRadius: Theme.Radius.card).fill(Theme.Palette.card))
        .overlay(RoundedRectangle(cornerRadius: Theme.Radius.card).stroke(Theme.Palette.cardStroke))
    }

    // MARK: metric grid

    private var metricGrid: some View {
        let items = liftMetrics
        return LazyVGrid(columns: [GridItem(.flexible()), GridItem(.flexible()), GridItem(.flexible())], spacing: Theme.Space.s) {
            ForEach(items, id: \.0) { item in
                VStack(spacing: 2) {
                    Text(item.1).font(Theme.Font.num(19))
                    Text(item.0).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint).multilineTextAlignment(.center)
                }
                .frame(maxWidth: .infinity).padding(.vertical, Theme.Space.s)
                .background(RoundedRectangle(cornerRadius: Theme.Radius.chip).fill(Theme.Palette.card))
            }
        }
    }

    private var liftMetrics: [(String, String)] {
        guard let d = detail else { return [("time", durationText)] }
        var m: [(String, String)] = []
        if let avg = d.avg_hr { m.append(("avg hr", "\(avg)")) }
        if let mx = d.max_hr { m.append(("max hr", "\(mx)")) }
        if let v = d.vo2max { m.append(("VO₂max", String(format: "%.1f", v))) }
        if let c = d.calories_kcal { m.append(("calories", "\(c)")) }
        if let t = d.trimp { m.append(("load", "\(Int(t.rounded()))")) }
        if let hrv = d.workout_hrv_ms { m.append(("HRV", "\(Int(hrv.rounded()))")) }
        return m.isEmpty ? [("time", durationText)] : m
    }

    // MARK: cards

    private func zonesCard(_ z: HrZones) -> some View {
        let zones: [(String, Double, Color)] = [
            ("Z1", z.z1 ?? 0, Theme.Palette.cyan), ("Z2", z.z2 ?? 0, Theme.Palette.mint),
            ("Z3", z.z3 ?? 0, Theme.Palette.amber), ("Z4", z.z4 ?? 0, Theme.Palette.pink),
            ("Z5", z.z5 ?? 0, Theme.Palette.violet),
        ]
        let maxMin = max(1, zones.map(\.1).max() ?? 1)
        return card("Heart-rate zones") {
            VStack(spacing: Theme.Space.s) {
                ForEach(zones, id: \.0) { zone in
                    HStack(spacing: Theme.Space.s) {
                        Text(zone.0).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim).frame(width: 26, alignment: .leading)
                        GeometryReader { geo in
                            ZStack(alignment: .leading) {
                                Capsule().fill(Theme.Palette.bg2)
                                Capsule().fill(zone.2).frame(width: max(4, geo.size.width * CGFloat(zone.1) / CGFloat(maxMin)))
                            }
                        }
                        .frame(height: 12)
                        Text("\(Int(zone.1.rounded()))m").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim).frame(width: 36, alignment: .trailing)
                    }
                }
            }
        }
    }

    private func setsCard(_ s: StrengthDetail, _ exercises: [StrengthExercise]) -> some View {
        card("Sets · \(s.total_sets ?? 0) sets, \(s.total_reps ?? 0) reps") {
            VStack(spacing: Theme.Space.s) {
                ForEach(exercises) { ex in
                    HStack {
                        VStack(alignment: .leading, spacing: 1) {
                            Text(ex.name).font(Theme.Font.body).foregroundStyle(Theme.Palette.text)
                            if let mg = ex.muscle_group { Text(mg).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint) }
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

    private func card<Content: View>(_ title: String, @ViewBuilder _ content: () -> Content) -> some View {
        VStack(alignment: .leading, spacing: Theme.Space.s) {
            Text(title).font(Theme.Font.label).foregroundStyle(Theme.Palette.textDim)
            content()
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(Theme.Space.m)
        .background(RoundedRectangle(cornerRadius: Theme.Radius.card).fill(Theme.Palette.card))
        .overlay(RoundedRectangle(cornerRadius: Theme.Radius.card).stroke(Theme.Palette.cardStroke))
    }

    private func loadingNote(_ text: String) -> some View {
        HStack(spacing: Theme.Space.s) {
            ProgressView().tint(Theme.Palette.textFaint)
            Text(text).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
        }
        .frame(maxWidth: .infinity).padding(.vertical, Theme.Space.s)
    }

    // MARK: formatting

    private func zonesTotal(_ z: HrZones) -> Double { (z.z1 ?? 0) + (z.z2 ?? 0) + (z.z3 ?? 0) + (z.z4 ?? 0) + (z.z5 ?? 0) }

    private var durationText: String {
        let s = (detail?.duration_min ?? fallback.duration_min ?? 0) * 60
        let h = s / 3600, m = (s % 3600) / 60, sec = s % 60
        return h > 0 ? String(format: "%d:%02d:%02d", h, m, sec) : String(format: "%d:%02d", m, sec)
    }
}
