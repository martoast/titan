import SwiftUI

/// The screen shown the INSTANT a workout ends — the thing you want to see right after you stop. It
/// branches on the workout kind the watch chose:
///   • Run (running tab, GPS) → the Strava-style summary: map, distance, pace, splits, elevation.
///   • Lift (heart-rate tab)  → the strength summary: HR zones, peak HR, VO₂max, calories, sets/reps.
/// Live stats render immediately; the rich server-sealed detail fills in a few seconds later (the
/// `ended`-flag instant seal makes that quick). Presented as a sheet bound to `model.workoutSummary`.
struct WorkoutSummaryView: View {
    let summary: WorkoutSummaryState
    @EnvironmentObject var model: AppModel
    @Environment(\.dismiss) private var dismiss

    private var isLift: Bool { summary.isLift }
    private var detail: RunDetail? { summary.detail }
    private var imperial: Bool { (detail?.units ?? "metric") == "imperial" }
    private var accent: Color { isLift ? Theme.Palette.pink : Theme.Palette.cyan }

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(spacing: Theme.Space.m) {
                    hero
                    if isLift { liftBody } else { runBody }
                    statusFooter
                }
                .padding(Theme.Space.m)
            }
            .background(Theme.Palette.bg.ignoresSafeArea())
            .navigationTitle(detail?.title ?? (isLift ? "Lift" : "Run"))
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .confirmationAction) {
                    Button("Done") { dismiss() }.font(Theme.Font.label).foregroundStyle(accent)
                }
            }
        }
    }

    // MARK: hero — the headline number(s), available from live stats immediately.

    private var hero: some View {
        VStack(spacing: Theme.Space.s) {
            Image(systemName: isLift ? "dumbbell.fill" : "figure.run")
                .font(.system(size: 30, weight: .bold)).foregroundStyle(accent)
            if isLift {
                Text(durationText).font(Theme.Font.num(46))
                Text("time").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
            } else {
                Text(distanceText).font(Theme.Font.num(46))
                HStack(spacing: Theme.Space.l) {
                    heroStat(durationText, "time")
                    heroStat(paceText, imperial ? "/mi" : "/km")
                }
                .padding(.top, 2)
            }
        }
        .frame(maxWidth: .infinity)
        .padding(.vertical, Theme.Space.l)
        .background(RoundedRectangle(cornerRadius: Theme.Radius.card).fill(Theme.Palette.card))
        .overlay(RoundedRectangle(cornerRadius: Theme.Radius.card).stroke(Theme.Palette.cardStroke))
    }

    private func heroStat(_ v: String, _ l: String) -> some View {
        VStack(spacing: 1) {
            Text(v).font(Theme.Font.num(20))
            Text(l).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
        }
    }

    // MARK: run body — map + the metric grid + splits/elevation (when sealed).

    private var runBody: some View {
        VStack(spacing: Theme.Space.m) {
            if let url = detail?.map_url_large, let u = URL(string: url) {
                AsyncImage(url: u) { img in
                    img.resizable().aspectRatio(contentMode: .fill)
                } placeholder: { mapPlaceholder }
                .frame(height: 200).clipShape(RoundedRectangle(cornerRadius: Theme.Radius.card))
            } else if summary.loading && summary.hasGps {
                mapPlaceholder.frame(height: 200).clipShape(RoundedRectangle(cornerRadius: Theme.Radius.card))
            }
            metricGrid(runMetrics)
            if let prof = detail?.elevation_profile, prof.count >= 2 { elevationCard(prof) }
        }
    }

    private var mapPlaceholder: some View {
        ZStack {
            Theme.Palette.bg2
            ProgressView().tint(Theme.Palette.textFaint)
        }
    }

    // MARK: lift body — the HR-zone story + the metric grid + detected sets.

    private var liftBody: some View {
        VStack(spacing: Theme.Space.m) {
            if let z = detail?.hr_zones, zonesTotal(z) > 0 { zonesCard(z) }
            metricGrid(liftMetrics)
            if let s = detail?.strength, let ex = s.exercises, !ex.isEmpty { setsCard(s, ex) }
            else if summary.loading { loadingNote("Analyzing your sets…") }
        }
    }

    // MARK: shared metric grid

    private func metricGrid(_ items: [(String, String)]) -> some View {
        LazyVGrid(columns: [GridItem(.flexible()), GridItem(.flexible()), GridItem(.flexible())], spacing: Theme.Space.s) {
            ForEach(items, id: \.0) { item in
                VStack(spacing: 2) {
                    Text(item.1).font(Theme.Font.num(19))
                    Text(item.0).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
                        .multilineTextAlignment(.center)
                }
                .frame(maxWidth: .infinity).padding(.vertical, Theme.Space.s)
                .background(RoundedRectangle(cornerRadius: Theme.Radius.chip).fill(Theme.Palette.card))
            }
        }
    }

    private var runMetrics: [(String, String)] {
        var m: [(String, String)] = []
        if let d = detail {
            if let avg = d.avg_hr { m.append(("avg hr", "\(avg)")) }
            m.append(("max hr", "\(d.max_hr ?? summary.maxBpm)"))
            if let g = d.gap_s_per_km { m.append(("GAP", paceString(g))) }
            if let e = d.elevation_gain_m { m.append(("elev gain", "\(e) m")) }
            if let c = d.calories_kcal { m.append(("calories", "\(c)")) }
            if let v = d.vo2max { m.append(("VO₂max", String(format: "%.1f", v))) }
            if let re = d.relative_effort { m.append(("effort", "\(re)")) }
        } else {
            m.append(("max hr", "\(summary.maxBpm)"))
            m.append(("distance", distanceText))
        }
        return m
    }

    private var liftMetrics: [(String, String)] {
        var m: [(String, String)] = []
        if let d = detail {
            if let avg = d.avg_hr { m.append(("avg hr", "\(avg)")) }
            m.append(("max hr", "\(d.max_hr ?? summary.maxBpm)"))
            if let v = d.vo2max { m.append(("VO₂max", String(format: "%.1f", v))) }
            if let c = d.calories_kcal { m.append(("calories", "\(c)")) }
            if let t = d.trimp { m.append(("load", "\(Int(t.rounded()))")) }
            if let hrv = d.workout_hrv_ms { m.append(("HRV", "\(Int(hrv.rounded()))")) }
        } else {
            m.append(("max hr", "\(summary.maxBpm)"))
            m.append(("time", durationText))
        }
        return m
    }

    // MARK: cards

    private func zonesCard(_ z: HrZones) -> some View {
        let zones: [(String, Int, Color)] = [
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
                                Capsule().fill(zone.2)
                                    .frame(width: max(4, geo.size.width * CGFloat(zone.1) / CGFloat(maxMin)))
                            }
                        }
                        .frame(height: 12)
                        Text("\(zone.1)m").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim).frame(width: 36, alignment: .trailing)
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

    private func elevationCard(_ prof: [ElevationPoint]) -> some View {
        let alts = prof.map(\.alt_m)
        let lo = alts.min() ?? 0, hi = alts.max() ?? 1
        let span = max(1, hi - lo)
        return card("Elevation") {
            GeometryReader { geo in
                Path { p in
                    for (i, pt) in prof.enumerated() {
                        let x = geo.size.width * CGFloat(i) / CGFloat(max(1, prof.count - 1))
                        let y = geo.size.height * (1 - CGFloat((pt.alt_m - lo) / span))
                        if i == 0 { p.move(to: CGPoint(x: x, y: y)) } else { p.addLine(to: CGPoint(x: x, y: y)) }
                    }
                }
                .stroke(Theme.Palette.mint, style: StrokeStyle(lineWidth: 2, lineJoin: .round))
            }
            .frame(height: 70)
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

    private var statusFooter: some View {
        Group {
            if summary.failed {
                Text("Saved to your band. The full breakdown will appear in Daily shortly.")
            } else if summary.loading {
                Text("Saving your \(isLift ? "lift" : "run")…")
            } else {
                Text("Sealed from your band — nice work.")
            }
        }
        .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
        .frame(maxWidth: .infinity).multilineTextAlignment(.center).padding(.top, 4)
    }

    // MARK: formatting

    private func zonesTotal(_ z: HrZones) -> Int { (z.z1 ?? 0) + (z.z2 ?? 0) + (z.z3 ?? 0) + (z.z4 ?? 0) + (z.z5 ?? 0) }

    private var durationText: String {
        let s = detail?.duration_min.map { $0 * 60 } ?? summary.elapsedSec
        let h = s / 3600, m = (s % 3600) / 60, sec = s % 60
        return h > 0 ? String(format: "%d:%02d:%02d", h, m, sec) : String(format: "%d:%02d", m, sec)
    }

    private var distanceText: String {
        let km = detail?.distance_km ?? summary.distanceKm
        return imperial ? String(format: "%.2f mi", km * 0.621371) : String(format: "%.2f km", km)
    }

    private var paceText: String {
        guard let p = detail?.avg_pace_s_per_km, p > 0 else { return "—" }
        return paceString(p)
    }

    private func paceString(_ secPerKm: Int) -> String {
        let s = imperial ? Int(Double(secPerKm) / 0.621371) : secPerKm
        return String(format: "%d:%02d", s / 60, s % 60)
    }
}
