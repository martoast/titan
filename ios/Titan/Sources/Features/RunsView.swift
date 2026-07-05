import SwiftUI

// MARK: - Formatting helpers

enum RunFmt {
    /// pace seconds → "m:ss"
    static func pace(_ s: Double?) -> String {
        guard let s, s > 0 else { return "—" }
        let t = Int(s.rounded()); return String(format: "%d:%02d", t / 60, t % 60)
    }
    /// seconds → "h:mm:ss" or "m:ss"
    static func dur(_ s: Int?) -> String {
        guard let s, s > 0 else { return "—" }
        let h = s / 3600, m = (s % 3600) / 60, sec = s % 60
        return h > 0 ? String(format: "%d:%02d:%02d", h, m, sec) : String(format: "%d:%02d", m, sec)
    }
    static func dateLabel(_ iso: String?) -> String {
        guard let iso, let d = ISO8601DateFormatter().date(from: iso) else { return "" }
        let f = DateFormatter(); f.dateFormat = "EEE, MMM d · h:mm a"; return f.string(from: d)
    }
    static func dayLabel(_ iso: String?) -> String {
        guard let iso, let d = ISO8601DateFormatter().date(from: iso) else { return "" }
        let f = RelativeDateTimeFormatter(); f.unitsStyle = .abbreviated
        return f.localizedString(for: d, relativeTo: Date())
    }
}

// MARK: - Runs list (embedded in the Train section)

/// The window over the workout list — the streak hero always shows the true numbers; this only
/// narrows the rows below it.
enum WorkoutRange: String, CaseIterable, Identifiable {
    case week = "Week", month = "Month", all = "All"
    var id: String { rawValue }
}

struct RunsSection: View {
    @EnvironmentObject var model: AppModel
    @State private var runs: [RunSummary] = []
    @State private var streak: WorkoutStreakInfo?
    @State private var activeDays: [String] = []
    @State private var range: WorkoutRange = .week
    @State private var loading = true
    @State private var selected: RunSummary?

    private var filtered: [RunSummary] { runs.filter(inRange) }

    var body: some View {
        VStack(spacing: Theme.Space.m) {
            // The headline: the consecutive-day streak + calendar strip.
            if loading && streak == nil {
                Shimmer().frame(height: 210).clipShape(RoundedRectangle(cornerRadius: Theme.Radius.card))
            } else if let s = streak {
                StreakHero(streak: s, activeDays: activeDays)
            }

            GlassCard {
                VStack(alignment: .leading, spacing: Theme.Space.m) {
                    HStack {
                        SectionHeader(title: "Workouts")
                        if !runs.isEmpty { Text("\(filtered.count)").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint).monospacedDigit() }
                    }
                    if !runs.isEmpty { filterBar }

                    if loading && runs.isEmpty {
                        VStack(spacing: 8) { Shimmer().frame(height: 60).clipShape(RoundedRectangle(cornerRadius: Theme.Radius.chip)); Shimmer().frame(height: 60).clipShape(RoundedRectangle(cornerRadius: Theme.Radius.chip)) }
                    } else if runs.isEmpty {
                        VStack(spacing: 6) {
                            Image(systemName: "figure.run").font(.system(size: 30)).foregroundStyle(Theme.Palette.textFaint)
                            Text("No workouts yet").font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.text)
                            Text("Tap the Run or Lift face on your band — runs land here with your route + splits, lifts with your HR zones + sets.")
                                .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim).multilineTextAlignment(.center)
                        }
                        .frame(maxWidth: .infinity).padding(.vertical, Theme.Space.s)
                    } else if filtered.isEmpty {
                        Text(range == .week ? "Nothing logged this week yet — get after it." : "Nothing logged this month yet.")
                            .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                            .frame(maxWidth: .infinity).multilineTextAlignment(.center).padding(.vertical, Theme.Space.s)
                    } else {
                        VStack(spacing: 8) {
                            ForEach(filtered) { run in
                                Button { Haptic.tap(); selected = run } label: { RunRow(run: run) }
                                    .buttonStyle(PressCard())
                            }
                        }
                    }
                }
            }
        }
        .task { await load() }
        .sheet(item: $selected) { run in
            // A lift gets the strength summary (HR zones / VO₂max / sets); a run gets the Strava detail.
            if run.isLift {
                LiftDetailView(runId: run.id, fallback: run).environmentObject(model)
            } else {
                RunDetailView(runId: run.id, fallback: run).environmentObject(model)
            }
        }
    }

    private var filterBar: some View {
        HStack(spacing: 6) {
            ForEach(WorkoutRange.allCases) { r in
                let on = range == r
                Button { Haptic.tap(); withAnimation(Theme.Motion.snappy) { range = r } } label: {
                    Text(r.rawValue).font(Theme.Font.micro)
                        .foregroundStyle(on ? Theme.Palette.bg : Theme.Palette.textDim)
                        .padding(.horizontal, 14).padding(.vertical, 7)
                        .background { if on { Capsule().fill(Theme.Palette.text) } }
                }.buttonStyle(.plain)
            }
            Spacer()
        }
    }

    // Match the server's Monday-start week so the filter agrees with the hero's "this week" count.
    private func inRange(_ run: RunSummary) -> Bool {
        guard range != .all else { return true }
        guard let iso = run.started_at, let d = ISO8601DateFormatter().date(from: iso) else { return true }
        var cal = Calendar.current; cal.firstWeekday = 2
        switch range {
        case .week: return cal.isDate(d, equalTo: Date(), toGranularity: .weekOfYear)
        case .month: return cal.isDate(d, equalTo: Date(), toGranularity: .month)
        case .all: return true
        }
    }

    private func load() async {
        if let r = try? await model.api.workouts() {
            runs = r.runs
            streak = r.streak
            activeDays = r.active_days ?? []
        }
        loading = false
    }
}

private struct RunRow: View {
    let run: RunSummary
    private var tint: Color { run.isLift ? Theme.Palette.amber : Theme.Palette.mint }
    var body: some View {
        HStack(spacing: Theme.Space.m) {
            ZStack {
                RoundedRectangle(cornerRadius: Theme.Radius.chip).fill(tint.opacity(0.12)).frame(width: 44, height: 44)
                Image(systemName: icon).foregroundStyle(tint).font(.system(size: 18, weight: .semibold))
            }
            VStack(alignment: .leading, spacing: 3) {
                HStack(alignment: .firstTextBaseline, spacing: 6) {
                    Text(headline).font(Theme.Font.num(20)).foregroundStyle(Theme.Palette.text)
                    Text(run.title).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                }
                HStack(spacing: 8) {
                    // A run shows pace; a lift shows duration (it has no distance/pace).
                    if run.isLift {
                        if let m = run.duration_min { Text(RunFmt.dur(m * 60)).foregroundStyle(Theme.Palette.textDim) }
                    } else if let p = run.avg_pace_s_per_km {
                        Text("\(RunFmt.pace(Double(p))) /km").foregroundStyle(Theme.Palette.textDim)
                    }
                    Text(RunFmt.dayLabel(run.started_at)).foregroundStyle(Theme.Palette.textFaint)
                }.font(Theme.Font.micro)
            }
            Spacer()
            Image(systemName: "chevron.right").font(.caption.weight(.semibold)).foregroundStyle(Theme.Palette.textFaint)
        }
        .padding(Theme.Space.s)
        .background(Theme.Palette.card, in: RoundedRectangle(cornerRadius: Theme.Radius.chip, style: .continuous))
    }
    // Lift: the title carries the type ("Strength") so lead with duration; run: the distance.
    private var headline: String {
        if run.isLift { return run.duration_min.map { RunFmt.dur($0 * 60) } ?? "Lift" }
        return run.distance_km.map { String(format: "%.2f km", $0) } ?? "Run"
    }
    private var icon: String {
        switch run.activity_type {
        case "strength": return "dumbbell.fill"
        case "cycle": return "bicycle"
        case "walk": return "figure.walk"
        default: return "figure.run"
        }
    }
}

// MARK: - Run detail (the end-of-run summary)

struct RunDetailView: View {
    let runId: Int
    let fallback: RunSummary
    @EnvironmentObject var model: AppModel
    @Environment(\.dismiss) private var dismiss
    @State private var detail: RunDetail?
    @State private var loading = true
    @State private var failed = false

    private var imperial: Bool { (detail?.units ?? "metric") == "imperial" }

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(spacing: Theme.Space.m) {
                    mapHero
                    secondaryGrid
                    if let prof = detail?.elevation_profile, prof.count >= 2 { elevationCard(prof) }
                    if let splits = splitsForUnit, !splits.isEmpty { splitsCard(splits) }
                    if let efforts = sortedEfforts, !efforts.isEmpty { effortsCard(efforts) }
                    Text("Nice work. Sealed from your band — GPS-grade estimates.")
                        .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
                        .frame(maxWidth: .infinity).multilineTextAlignment(.center).padding(.top, 4)
                }
                .padding(Theme.Space.m)
            }
            .background(Theme.Palette.bg.ignoresSafeArea())
            .navigationTitle(detail?.title ?? fallback.title)
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .topBarLeading) { Button("Done") { dismiss() } }
                ToolbarItem(placement: .topBarTrailing) { shareButton }
            }
            .task { await load() }
        }
    }

    // — share: the run as a poster (map link + a proud caption) —
    @ViewBuilder private var shareButton: some View {
        if let urlStr = detail?.map_url_large, let url = URL(string: urlStr) {
            ShareLink(item: url, subject: Text(shareCaption), message: Text(shareCaption)) {
                Image(systemName: "square.and.arrow.up")
            }
        }
    }
    private var shareCaption: String {
        let km = detail?.distance_km ?? fallback.distance_km
        let d = km.map { imperial ? String(format: "%.2f mi", $0 * 0.621371) : String(format: "%.2f km", $0) } ?? "a run"
        let p = paceLabel(detail?.avg_pace_s_per_km ?? fallback.avg_pace_s_per_km)
        return "Ran \(d)\(p == "—" ? "" : " at \(p)") — tracked on Titan 🏃"
    }

    private var hasMap: Bool { (detail?.map_url_large ?? fallback.map_thumb_url) != nil }

    // — the hero. With GPS: the route poster (BIG distance + pace overlaid). Without GPS (indoor/no
    //   Mapbox): a clean stats hero so you STILL get distance + pace + the "estimated from steps" note. —
    @ViewBuilder private var mapHero: some View {
        if hasMap {
            ZStack(alignment: .bottomLeading) {
                RoundedRectangle(cornerRadius: Theme.Radius.card, style: .continuous).fill(Theme.Palette.card)
                // The map MUST be width-constrained + clipped: a scaledToFill AsyncImage otherwise reports
                // the source Mapbox image's full intrinsic width, ballooning this ZStack far past the screen
                // and dragging the whole scroll content wide with it (the "zoomed-in, left-cut-off" bug).
                Group {
                    if let urlStr = detail?.map_url_large ?? fallback.map_thumb_url, let url = URL(string: urlStr) {
                        AsyncImage(url: url) { phase in
                            switch phase {
                            case .success(let img): img.resizable().scaledToFill()
                            case .failure: mapPlaceholder
                            default: Shimmer()
                            }
                        }
                    }
                }
                .frame(maxWidth: .infinity)
                .frame(height: 260)
                .clipped()
                LinearGradient(colors: [.clear, .black.opacity(0.65)], startPoint: .center, endPoint: .bottom)
                heroStats(onMap: true).padding(Theme.Space.m)
            }
            .frame(maxWidth: .infinity)
            .frame(height: 260).clipShape(RoundedRectangle(cornerRadius: Theme.Radius.card, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: Theme.Radius.card, style: .continuous).strokeBorder(Theme.Palette.cardStroke))
        } else if loading {
            Shimmer().frame(height: 150).clipShape(RoundedRectangle(cornerRadius: Theme.Radius.card, style: .continuous))
        } else if failed {
            GlassCard { mapPlaceholder.frame(height: 110) }
        } else {
            GlassCard(padding: Theme.Space.l) {
                VStack(alignment: .leading, spacing: Theme.Space.s) {
                    heroStats(onMap: false)
                    if (detail?.distance_source) == "steps" {
                        Label("Estimated from your steps — no GPS lock on this one", systemImage: "figure.run")
                            .font(Theme.Font.micro).foregroundStyle(Theme.Palette.amber)
                            .padding(.horizontal, 10).padding(.vertical, 5)
                            .background(Theme.Palette.amber.opacity(0.12), in: Capsule())
                    } else {
                        Label("No GPS route on this one", systemImage: "mappin.slash")
                            .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
                    }
                }
            }
        }
    }

    private func heroStats(onMap: Bool) -> some View {
        let fg: Color = onMap ? .white : Theme.Palette.text
        return VStack(alignment: .leading, spacing: 2) {
            HStack(alignment: .firstTextBaseline, spacing: 6) {
                Text(heroDistanceValue).font(Theme.Font.num(48)).foregroundStyle(fg)
                Text(heroDistanceUnit).font(Theme.Font.body.weight(.semibold)).foregroundStyle(fg.opacity(0.8))
            }
            HStack(spacing: 10) {
                label("clock", RunFmt.dur(detail?.moving_time_s ?? fallback.duration_min.map { $0 * 60 }), onMap: onMap)
                label("speedometer", paceLabel(detail?.avg_pace_s_per_km ?? fallback.avg_pace_s_per_km), onMap: onMap)
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
    }

    private func label(_ icon: String, _ text: String, onMap: Bool = true) -> some View {
        let tint: Color = onMap ? .white.opacity(0.7) : Theme.Palette.textDim
        let fg: Color = onMap ? .white.opacity(0.92) : Theme.Palette.text
        return HStack(spacing: 4) {
            Image(systemName: icon).font(.system(size: 11)).foregroundStyle(tint)
            Text(text).font(Theme.Font.num(15, .semibold)).foregroundStyle(fg)
        }
    }

    @ViewBuilder private var mapPlaceholder: some View {
        if failed {
            Button { Task { await load() } } label: {
                VStack(spacing: 6) {
                    Image(systemName: "arrow.clockwise").font(.system(size: 24)).foregroundStyle(Theme.Palette.textDim)
                    Text("Couldn't load this run").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                    Text("Tap to retry").font(Theme.Font.micro).foregroundStyle(Theme.Palette.mint)
                }
            }.buttonStyle(.plain).frame(maxWidth: .infinity, maxHeight: .infinity)
        } else if loading {
            Shimmer()
        } else {
            VStack(spacing: 6) {
                Image(systemName: "map").font(.system(size: 26)).foregroundStyle(Theme.Palette.textFaint)
                Text("No GPS route").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
            }.frame(maxWidth: .infinity, maxHeight: .infinity)
        }
    }

    private var heroDistanceValue: String {
        guard let km = detail?.distance_km ?? fallback.distance_km else { return "—" }
        return imperial ? String(format: "%.2f", km * 0.621371) : String(format: "%.2f", km)
    }
    private var heroDistanceUnit: String { imperial ? "mi" : "km" }

    // — secondary stats (calm: color reserved for pace/effort; the rest read in plain text) —
    private var secondaryGrid: some View {
        let d = detail
        return LazyVGrid(columns: Array(repeating: GridItem(.flexible(), spacing: 10), count: 3), spacing: 10) {
            if let gap = d?.gap_s_per_km { tile(paceLabel(gap), "GAP", Theme.Palette.mint) }
            if let gain = d?.elevation_gain_m { tile(imperial ? "\(Int(Double(gain) * 3.28084))" : "\(gain)", "Elev gain", Theme.Palette.text, imperial ? "ft" : "m") }
            if let hr = d?.avg_hr { tile("\(hr)", "Avg HR", Theme.Palette.text, "bpm") }
            if let hr = d?.max_hr { tile("\(hr)", "Max HR", Theme.Palette.text, "bpm") }
            if let hrv = d?.workout_hrv_ms { tile("\(Int(hrv.rounded()))", "HRV", Theme.Palette.cyan, "ms") }
            if let re = d?.relative_effort { tile("\(re)", "Effort", Theme.Palette.pink) }
            if let c = d?.calories_kcal { tile("\(c)", "Calories", Theme.Palette.text, "kcal") }
            if let v = d?.vo2max { tile(String(format: "%.1f", v), "VO₂max", Theme.Palette.mint) }
        }
    }

    private func tile(_ value: String, _ label: String, _ color: Color, _ unit: String? = nil) -> some View {
        Metric(value: value, unit: unit, label: label, color: color)
            .padding(.vertical, 10).padding(.horizontal, 12)
            .background(Theme.Palette.card, in: RoundedRectangle(cornerRadius: Theme.Radius.chip, style: .continuous))
    }

    private func paceLabel(_ secPerKm: Int?) -> String {
        guard let s = secPerKm, s > 0 else { return "—" }
        let v = imperial ? Int(Double(s) * 1.609344) : s
        return "\(RunFmt.pace(Double(v)))\(imperial ? "/mi" : "/km")"
    }

    // — elevation —
    private func elevationCard(_ prof: [ElevationPoint]) -> some View {
        GlassCard {
            VStack(alignment: .leading, spacing: 8) {
                SectionHeader(title: "Elevation")
                GeometryReader { geo in
                    let alts = prof.map(\.alt_m), aMin = alts.min() ?? 0, aMax = alts.max() ?? 1
                    let aRange = max(0.1, aMax - aMin), dMax = prof.map(\.d_km).max() ?? 1
                    let pts = prof.map { p in CGPoint(
                        x: CGFloat(p.d_km / max(dMax, 0.001)) * geo.size.width,
                        y: geo.size.height - CGFloat((p.alt_m - aMin) / aRange) * (geo.size.height - 6) - 3) }
                    ZStack {
                        Path { path in
                            guard let first = pts.first else { return }
                            path.move(to: CGPoint(x: 0, y: geo.size.height))
                            path.addLine(to: first); pts.forEach { path.addLine(to: $0) }
                            path.addLine(to: CGPoint(x: geo.size.width, y: geo.size.height)); path.closeSubpath()
                        }.fill(Theme.Palette.amber.opacity(0.14))
                        Path { path in
                            guard let first = pts.first else { return }
                            path.move(to: first); pts.forEach { path.addLine(to: $0) }
                        }.stroke(Theme.Palette.amber.opacity(0.85), lineWidth: 1.5)
                    }
                }.frame(height: 60)
            }
        }
    }

    // — splits —
    private var splitsForUnit: [RunSplit]? { imperial ? detail?.splits?.mi : detail?.splits?.km }

    private func splitsCard(_ splits: [RunSplit]) -> some View {
        let paces = splits.compactMap { $0.pace_s_per_unit }.filter { $0 > 0 }
        let pMin = paces.min() ?? 1, pMax = paces.max() ?? 1
        return GlassCard {
            VStack(alignment: .leading, spacing: 8) {
                SectionHeader(title: "Splits · per \(imperial ? "mi" : "km")")
                VStack(spacing: 7) {
                    ForEach(splits) { sp in
                        let p = sp.pace_s_per_unit ?? 0
                        let frac = pMax > pMin ? 0.28 + 0.72 * ((pMax - p) / (pMax - pMin)) : 1
                        HStack(spacing: 10) {
                            Text("\(sp.index)").font(Theme.Font.micro.monospacedDigit())
                                .foregroundStyle(Theme.Palette.textDim).frame(width: 18, alignment: .leading)
                            GeometryReader { g in
                                RoundedRectangle(cornerRadius: 6)
                                    .fill(LinearGradient(colors: [Theme.Palette.mint.opacity(0.75), Theme.Palette.mint.opacity(0.35)], startPoint: .leading, endPoint: .trailing))
                                    .frame(width: max(8, g.size.width * frac), height: 18)
                            }.frame(height: 18)
                            Text(RunFmt.pace(p)).font(Theme.Font.num(14)).foregroundStyle(Theme.Palette.text)
                                .frame(width: 52, alignment: .trailing)
                            if let hr = sp.avg_hr {
                                Text("\(hr)").font(Theme.Font.micro.monospacedDigit())
                                    .foregroundStyle(Theme.Palette.pink.opacity(0.75)).frame(width: 30, alignment: .trailing)
                            } else { Color.clear.frame(width: 30) }
                        }
                    }
                }
            }
        }
    }

    // — best efforts —
    private var sortedEfforts: [(String, BestEffort)]? {
        detail?.best_efforts?.sorted { ($0.value.distance_m ?? 0) < ($1.value.distance_m ?? 0) }.map { ($0.key, $0.value) }
    }

    private func effortsCard(_ efforts: [(String, BestEffort)]) -> some View {
        GlassCard {
            VStack(alignment: .leading, spacing: 8) {
                SectionHeader(title: "Best efforts")
                ScrollView(.horizontal, showsIndicators: false) {
                    HStack(spacing: 8) {
                        ForEach(efforts, id: \.0) { label, e in
                            VStack(alignment: .leading, spacing: 2) {
                                Text(label.uppercased()).font(Theme.Font.micro).foregroundStyle(Theme.Palette.mint)
                                Text(RunFmt.dur(e.elapsed_s.map { Int($0) })).font(Theme.Font.num(16)).foregroundStyle(Theme.Palette.text)
                            }
                            .padding(.vertical, 8).padding(.horizontal, 12)
                            .background(Theme.Palette.card, in: RoundedRectangle(cornerRadius: Theme.Radius.chip, style: .continuous))
                        }
                    }
                }
            }
        }
    }

    private func load() async {
        loading = true; failed = false
        do { detail = try await model.api.runDetail(runId) }
        catch { failed = (detail == nil) }
        loading = false
    }
}
