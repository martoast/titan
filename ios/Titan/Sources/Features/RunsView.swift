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

struct RunsSection: View {
    @EnvironmentObject var model: AppModel
    @State private var runs: [RunSummary] = []
    @State private var loading = true
    @State private var selected: RunSummary?

    var body: some View {
        GlassCard {
            VStack(alignment: .leading, spacing: Theme.Space.s) {
                SectionHeader(title: "Recent runs")
                if loading {
                    Shimmer().frame(height: 54).clipShape(RoundedRectangle(cornerRadius: 14))
                } else if runs.isEmpty {
                    VStack(spacing: 6) {
                        Image(systemName: "figure.run").font(.system(size: 30)).foregroundStyle(Theme.Palette.textFaint)
                        Text("No runs yet").font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.text)
                        Text("Start a run on your band — the route, splits and pace land here.")
                            .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim).multilineTextAlignment(.center)
                    }
                    .frame(maxWidth: .infinity).padding(.vertical, Theme.Space.s)
                } else {
                    VStack(spacing: 8) {
                        ForEach(runs) { run in
                            Button { selected = run } label: { RunRow(run: run) }
                                .buttonStyle(.plain)
                        }
                    }
                }
            }
        }
        .task { await load() }
        .sheet(item: $selected) { run in RunDetailView(runId: run.id, fallback: run).environmentObject(model) }
    }

    private func load() async {
        if let r = try? await model.api.runs() { runs = r }
        loading = false
    }
}

private struct RunRow: View {
    let run: RunSummary
    var body: some View {
        HStack(spacing: Theme.Space.m) {
            ZStack {
                RoundedRectangle(cornerRadius: 12).fill(Theme.Palette.mint.opacity(0.12)).frame(width: 44, height: 44)
                Image(systemName: icon).foregroundStyle(Theme.Palette.mint).font(.system(size: 18, weight: .semibold))
            }
            VStack(alignment: .leading, spacing: 2) {
                Text(run.title).font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.text)
                HStack(spacing: 8) {
                    if let d = run.distance_km { Text(String(format: "%.2f km", d)).foregroundStyle(Theme.Palette.textDim) }
                    if let p = run.avg_pace_s_per_km { Text("\(RunFmt.pace(Double(p))) /km").foregroundStyle(Theme.Palette.textDim) }
                    Text(RunFmt.dayLabel(run.started_at)).foregroundStyle(Theme.Palette.textFaint)
                }.font(Theme.Font.micro)
            }
            Spacer()
            if run.has_route { Image(systemName: "chevron.right").font(.caption.weight(.semibold)).foregroundStyle(Theme.Palette.textFaint) }
        }
        .padding(10)
        .background(RoundedRectangle(cornerRadius: 14).fill(Color.white.opacity(0.03)))
    }
    private var icon: String {
        switch run.activity_type { case "cycle": return "bicycle"; case "walk": return "figure.walk"; default: return "figure.run" }
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

    private var imperial: Bool { (detail?.units ?? "metric") == "imperial" }

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(spacing: Theme.Space.m) {
                    mapHero
                    statGrid
                    if let prof = detail?.elevation_profile, prof.count >= 2 { elevationCard(prof) }
                    if let splits = splitsForUnit, !splits.isEmpty { splitsCard(splits) }
                    if let efforts = sortedEfforts, !efforts.isEmpty { effortsCard(efforts) }
                    Text("GPS-grade estimates · sealed from your band")
                        .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
                        .frame(maxWidth: .infinity).padding(.top, 4)
                }
                .padding(Theme.Space.m)
            }
            .background(Theme.Palette.bg.ignoresSafeArea())
            .navigationTitle(detail?.title ?? fallback.title)
            .navigationBarTitleDisplayMode(.inline)
            .toolbar { ToolbarItem(placement: .topBarTrailing) { Button("Done") { dismiss() } } }
            .task { await load() }
        }
    }

    // — map —
    private var mapHero: some View {
        ZStack {
            RoundedRectangle(cornerRadius: 22).fill(Color.white.opacity(0.03))
            if let urlStr = detail?.map_url_large ?? fallback.map_thumb_url, let url = URL(string: urlStr) {
                AsyncImage(url: url) { phase in
                    switch phase {
                    case .success(let img): img.resizable().scaledToFill()
                    case .failure: placeholder("map")
                    default: Shimmer()
                    }
                }
            } else { placeholder(detail == nil && loading ? nil : "map") }
        }
        .frame(height: 220).clipShape(RoundedRectangle(cornerRadius: 22))
        .overlay(RoundedRectangle(cornerRadius: 22).strokeBorder(Color.white.opacity(0.08)))
        .overlay(alignment: .bottomLeading) {
            Text(RunFmt.dateLabel(detail?.started_at ?? fallback.started_at))
                .font(Theme.Font.micro).foregroundStyle(.white.opacity(0.85))
                .padding(8).background(.black.opacity(0.35), in: Capsule()).padding(10)
        }
    }

    private func placeholder(_ kind: String?) -> some View {
        VStack(spacing: 6) {
            if let kind { Image(systemName: kind == "map" ? "map" : "questionmark").font(.system(size: 26)).foregroundStyle(Theme.Palette.textFaint) }
            if kind == "map" { Text("No GPS route").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim) }
        }
    }

    // — stats —
    private var statGrid: some View {
        let d = detail
        let distStr: String = {
            guard let km = d?.distance_km ?? fallback.distance_km else { return "—" }
            return imperial ? String(format: "%.2f mi", km * 0.621371) : String(format: "%.2f km", km)
        }()
        return LazyVGrid(columns: Array(repeating: GridItem(.flexible(), spacing: 10), count: 3), spacing: 10) {
            stat("Distance", distStr, Theme.Palette.text)
            stat("Moving", RunFmt.dur(d?.moving_time_s ?? fallback.duration_min.map { $0 * 60 }), Theme.Palette.text)
            stat("Avg pace", paceLabel(d?.avg_pace_s_per_km ?? fallback.avg_pace_s_per_km), Theme.Palette.mint)
            if let gap = d?.gap_s_per_km { stat("GAP", paceLabel(gap), Theme.Palette.mint) }
            if let gain = d?.elevation_gain_m { stat("Elev gain", imperial ? "\(Int(Double(gain) * 3.28084)) ft" : "\(gain) m", Theme.Palette.amber) }
            if let hr = d?.avg_hr { stat("Avg HR", "\(hr)", Theme.Palette.pink) }
            if let hr = d?.max_hr { stat("Max HR", "\(hr)", Theme.Palette.pink) }
            if let re = d?.relative_effort { stat("Effort", "\(re)", Theme.Palette.pink) }
            if let c = d?.calories_kcal { stat("Calories", "\(c)", Theme.Palette.text) }
            if let v = d?.vo2max { stat("VO₂max", String(format: "%.1f", v), Theme.Palette.mint) }
        }
    }

    private func stat(_ label: String, _ value: String, _ tone: Color) -> some View {
        VStack(alignment: .leading, spacing: 2) {
            Text(label.uppercased()).font(.system(size: 10, weight: .semibold)).foregroundStyle(Theme.Palette.textFaint).tracking(0.5)
            Text(value).font(Theme.Font.num(18)).foregroundStyle(tone)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(.vertical, 10).padding(.horizontal, 12)
        .background(RoundedRectangle(cornerRadius: 16).fill(Color.white.opacity(0.03)))
    }

    private func paceLabel(_ secPerKm: Int?) -> String {
        guard let s = secPerKm, s > 0 else { return "—" }
        let v = imperial ? Int(Double(s) * 1.609344) : s
        return "\(RunFmt.pace(Double(v)))\(imperial ? " /mi" : " /km")"
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
                                Text(label.uppercased()).font(.system(size: 10, weight: .semibold)).foregroundStyle(Theme.Palette.textFaint)
                                Text(RunFmt.dur(e.elapsed_s.map { Int($0) })).font(Theme.Font.num(15)).foregroundStyle(Theme.Palette.text)
                            }
                            .padding(.vertical, 8).padding(.horizontal, 12)
                            .background(RoundedRectangle(cornerRadius: 12).fill(Color.white.opacity(0.03)))
                        }
                    }
                }
            }
        }
    }

    private func load() async {
        if let d = try? await model.api.runDetail(runId) { detail = d }
        loading = false
    }
}
