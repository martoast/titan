import SwiftUI
import TitanCore

/// The screen shown the INSTANT the night ends (WAKE on the watch) — the sleep "wow moment." Built to
/// feel like waking to a beautiful report: a deep-night hero with the sleep-performance ring and total
/// asleep time, a proud verdict, then the hypnogram (the centerpiece), stage breakdown, and the metrics
/// that matter (efficiency, restorative, respiratory rate, need vs debt). The in-bed time renders
/// immediately from the watch markers; the server-sealed staging fills in and animates. Bound to
/// `model.sleepSummary`.
struct SleepSummaryView: View {
    let summary: SleepSummaryState
    @EnvironmentObject var model: AppModel
    @Environment(\.dismiss) private var dismiss
    @State private var appeared = false
    @State private var ringProgress: Double = 0

    private var detail: SleepResponse.Detail? { summary.detail }
    private var assess: SleepResponse.Assess? { summary.assess }
    private let accent = Theme.Palette.indigo

    var body: some View {
        NavigationStack {
            ZStack(alignment: .top) {
                Theme.Palette.bg.ignoresSafeArea()
                // A deep-night wash behind the hero.
                LinearGradient(colors: [accent.opacity(0.34), Theme.Palette.violet.opacity(0.16), .clear],
                               startPoint: .top, endPoint: .bottom)
                    .frame(height: 420).ignoresSafeArea(edges: .top)

                ScrollView {
                    VStack(spacing: Theme.Space.m) {
                        hero
                            .opacity(appeared ? 1 : 0)
                            .offset(y: appeared ? 0 : 18)

                        verdictRow.stagger(appeared, 0.05)

                        bigStats.stagger(appeared, 0.10)

                        if let hyp = detail?.hypnogram, hyp.count >= 4 {
                            timelineCard(hyp).stagger(appeared, 0.15)
                        } else if summary.loading {
                            timelineCard([]).stagger(appeared, 0.15)   // computing → skeleton ribbon
                        }

                        if let stages = detail?.stages, !stages.isEmpty {
                            stagesCard(stages).stagger(appeared, 0.2)
                        }

                        needCard.stagger(appeared, 0.25)

                        if let advice = assess?.advice, !advice.isEmpty {
                            adviceCard(advice).stagger(appeared, 0.28)
                        }

                        shareRow.stagger(appeared, 0.3)
                        statusFooter
                        Color.clear.frame(height: 6)
                    }
                    .padding(Theme.Space.m)
                }
                .scrollIndicators(.hidden)
            }
            .navigationTitle("Sleep")
            .navigationBarTitleDisplayMode(.inline)
            .toolbarColorScheme(.dark, for: .navigationBar)
            .toolbar {
                ToolbarItem(placement: .confirmationAction) {
                    Button("Done") { dismiss() }.font(Theme.Font.label.weight(.bold)).foregroundStyle(accent)
                }
            }
            .onAppear {
                withAnimation(Theme.Motion.spring) { appeared = true }
                withAnimation(.easeOut(duration: 1.1).delay(0.15)) { ringProgress = performanceFraction }
                Haptic.success()
            }
        }
    }

    // MARK: - Hero (the performance ring + total asleep)

    private var hero: some View {
        ZStack {
            RoundedRectangle(cornerRadius: Theme.Radius.card, style: .continuous)
                .fill(LinearGradient(colors: [accent.opacity(0.30), Theme.Palette.violet.opacity(0.20), Theme.Palette.bg2],
                                     startPoint: .topLeading, endPoint: .bottomTrailing))
            Theme.Grad.glow(accent).opacity(0.5)

            VStack(spacing: Theme.Space.s) {
                HStack(spacing: 5) {
                    Image(systemName: "moon.stars.fill").font(.system(size: 11, weight: .bold))
                    Text("SLEEP").font(Theme.Font.micro).tracking(1.5)
                }
                .foregroundStyle(.white)
                .padding(.horizontal, 10).padding(.vertical, 5)
                .background(.ultraThinMaterial, in: Capsule())

                performanceRing

                VStack(spacing: 2) {
                    HStack(alignment: .firstTextBaseline, spacing: 6) {
                        Text(asleepText).font(Theme.Font.num(46)).foregroundStyle(.white)
                            .shadow(color: .black.opacity(0.4), radius: 8, y: 2)
                    }
                    Text(asleepLabel.uppercased()).font(Theme.Font.micro).tracking(2).foregroundStyle(.white.opacity(0.6))
                }

                if summary.bedtime != nil || summary.wake != nil {
                    HStack(spacing: Theme.Space.s) {
                        heroPill("bed.double.fill", clock(summary.bedtime) + " → " + clock(summary.wake))
                        if let e = detail?.efficiency_pct { heroPill("checkmark.seal.fill", "\(e)% efficient") }
                    }
                    .padding(.top, 2)
                }
            }
            .padding(.vertical, Theme.Space.l)
        }
        .frame(height: 340)
        .overlay(RoundedRectangle(cornerRadius: Theme.Radius.card, style: .continuous).strokeBorder(Theme.Palette.cardStroke))
    }

    private var performanceRing: some View {
        ZStack {
            Circle().stroke(Color.white.opacity(0.12), lineWidth: 12)
            Circle()
                .trim(from: 0, to: ringProgress)
                .stroke(AngularGradient(colors: [accent, Theme.Palette.violet, accent], center: .center),
                        style: StrokeStyle(lineWidth: 12, lineCap: .round))
                .rotationEffect(.degrees(-90))
                .shadow(color: accent.opacity(0.5), radius: 6)
            VStack(spacing: 0) {
                if let p = detail?.performance_pct {
                    Text("\(p)").font(Theme.Font.num(40)).foregroundStyle(.white)
                    Text("PERFORMANCE").font(.system(size: 8, weight: .bold, design: .rounded)).tracking(1.5).foregroundStyle(.white.opacity(0.55))
                } else {
                    Image(systemName: "moon.zzz.fill").font(.system(size: 30, weight: .semibold)).foregroundStyle(.white.opacity(0.85))
                    Text(summary.loading ? "SEALING" : "LOGGED").font(.system(size: 8, weight: .bold, design: .rounded)).tracking(1.5).foregroundStyle(.white.opacity(0.5))
                }
            }
        }
        .frame(width: 132, height: 132)
    }

    private func heroPill(_ icon: String, _ text: String) -> some View {
        HStack(spacing: 5) {
            Image(systemName: icon).font(.system(size: 11, weight: .semibold))
            Text(text).font(Theme.Font.num(13, .semibold))
        }
        .foregroundStyle(.white)
        .padding(.horizontal, 11).padding(.vertical, 7)
        .background(.ultraThinMaterial, in: Capsule())
    }

    // MARK: - Verdict

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
        // While staging, don't declare a quantity verdict off the in-bed envelope — it would flip when the
        // real asleep time / performance lands on finalize.
        if summary.loading { return ("moon.stars.fill", "Calculating your sleep — hang tight.") }
        let p = detail?.performance_pct
        if let p {
            if p >= 90 { return ("crown.fill", "Peak recovery night — you're fully charged.") }
            if p >= 75 { return ("checkmark.seal.fill", "Strong sleep. You banked real recovery.") }
            if p >= 55 { return ("moon.fill", "Decent night — a little short of your need.") }
            return ("battery.25", "Light on sleep. Prioritize an early night tonight.") }
        let h = Double(asleepSec) / 3600
        if h >= 7.5 { return ("checkmark.seal.fill", "A full night in the bank. Well rested.") }
        return ("moon.stars.fill", "Night logged — your recovery is being scored.")
    }

    // MARK: - Big stats

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
        if let e = detail?.efficiency_pct { t.append(.init("checkmark.seal.fill", "\(e)", "%", "efficiency", Theme.Palette.mint)) }
        if let r = detail?.restorative_min { t.append(.init("sparkles", hm(r), nil, "restorative", Theme.Palette.violet)) }
        if let rr = detail?.respiratory_rate { t.append(.init("wind", String(format: "%.1f", rr), "rpm", "respiratory", Theme.Palette.cyan)) }
        if let c = detail?.consistency_pct { t.append(.init("repeat", "\(c)", "%", "consistency", Theme.Palette.amber)) }
        if let ib = detail?.in_bed_min { t.append(.init("bed.double.fill", hm(ib), nil, "time in bed", Theme.Palette.indigo)) }
        if let q = detail?.quality { t.append(.init("star.fill", "\(q)", nil, "quality", Theme.Palette.amber)) }
        // Before the seal lands, show what the watch markers give so the grid is never empty.
        if t.count < 2 {
            t = [.init("bed.double.fill", hm(summary.inBedSec / 60), nil, "time in bed", Theme.Palette.indigo)]
            if summary.bedtime != nil { t.append(.init("moon.fill", clock(summary.bedtime), nil, "bedtime", Theme.Palette.violet)) }
            if summary.wake != nil { t.append(.init("sunrise.fill", clock(summary.wake), nil, "wake", Theme.Palette.amber)) }
        }
        return t
    }

    private struct Tile { let icon, value: String; let unit: String?; let label: String; let accent: Color
        init(_ i: String, _ v: String, _ u: String?, _ l: String, _ a: Color) { icon = i; value = v; unit = u; label = l; accent = a } }

    // MARK: - Timeline (the shape of the night, at a glance)

    /// A MINI, non-interactive `SleepTimeline` under the headline numbers (spec §2.2) — the shape of
    /// the night at a glance, with the four-depth legend below. Uses the shared `SleepStage` mapping,
    /// so NODATA renders as an honest hatched gap. While the night is still sealing (`summary.loading`
    /// with no stages yet) it shows the computing skeleton ribbon.
    private func timelineCard(_ hyp: [String]) -> some View {
        card("The night", clock(summary.bedtime) + " – " + clock(summary.wake)) {
            VStack(spacing: Theme.Space.s) {
                SleepTimeline(stages: hyp,
                              epochSec: detail?.epoch_sec,
                              bedtime: detail?.bedtime, wakeTime: detail?.wake_time,
                              computing: summary.loading || detail?.stage_status == "computing",
                              interactive: false, mini: true)
                // Row labels for the four depths.
                HStack {
                    ForEach(SleepStage.lanes, id: \.self) { s in
                        HStack(spacing: 4) {
                            Circle().fill(s.color).frame(width: 7, height: 7)
                            Text(s.label).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                        }
                        if s != .deep { Spacer() }
                    }
                }
            }
        }
    }

    // MARK: - Stage breakdown (stacked bar + legend)

    private func stagesCard(_ stages: [SleepResponse.Detail.Stage]) -> some View {
        let total = max(1, stages.reduce(0) { $0 + $1.min })
        return card("Time in each stage", "\(stages.count) stages") {
            VStack(spacing: Theme.Space.m) {
                GeometryReader { geo in
                    HStack(spacing: 2) {
                        ForEach(stages) { s in
                            if s.min > 0 {
                                Capsule().fill(SleepStage.color(forCode: s.key))
                                    .frame(width: max(3, geo.size.width * CGFloat(Double(s.min) / Double(total))))
                            }
                        }
                    }
                }.frame(height: 16)
                VStack(spacing: Theme.Space.xs) {
                    ForEach(stages) { s in
                        HStack(spacing: Theme.Space.s) {
                            Circle().fill(SleepStage.color(forCode: s.key)).frame(width: 8, height: 8)
                            Text(s.label).font(Theme.Font.body).foregroundStyle(Theme.Palette.text)
                            Spacer()
                            Text("\(s.pct)%").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim).frame(width: 40, alignment: .trailing)
                            Text(hm(s.min)).font(Theme.Font.num(14)).foregroundStyle(Theme.Palette.text).frame(width: 52, alignment: .trailing)
                        }
                    }
                }
            }
        }
    }

    // MARK: - Need vs debt

    private var needCard: some View {
        let need = detail?.need_h ?? assess?.need_h
        let debt = detail?.debt_h ?? assess?.debt_h
        return card("Sleep need", assess?.label ?? "") {
            VStack(spacing: Theme.Space.m) {
                HStack {
                    needStat("Slept", asleepText, accent)
                    Divider().frame(height: 30).overlay(Theme.Palette.cardStroke)
                    needStat("Need", need.map { hoursText($0) } ?? "—", Theme.Palette.cyan)
                    Divider().frame(height: 30).overlay(Theme.Palette.cardStroke)
                    needStat("Debt", debt.map { hoursText($0) } ?? "—", (debt ?? 0) > 0.5 ? Theme.Palette.amber : Theme.Palette.mint)
                }
                if let need, need > 0 {
                    let frac = min(1, Double(asleepSec) / 3600 / need)
                    GeometryReader { g in
                        ZStack(alignment: .leading) {
                            Capsule().fill(Color.white.opacity(0.08))
                            Capsule().fill(LinearGradient(colors: [accent, Theme.Palette.violet], startPoint: .leading, endPoint: .trailing))
                                .frame(width: max(6, g.size.width * CGFloat(frac)))
                        }
                    }.frame(height: 10)
                }
            }
        }
    }

    private func needStat(_ label: String, _ value: String, _ color: Color) -> some View {
        VStack(spacing: 2) {
            Text(value).font(Theme.Font.num(18)).foregroundStyle(color)
            Text(label.uppercased()).font(Theme.Font.micro).tracking(0.5).foregroundStyle(Theme.Palette.textDim)
        }.frame(maxWidth: .infinity)
    }

    // MARK: - Advice

    private func adviceCard(_ advice: String) -> some View {
        HStack(alignment: .top, spacing: Theme.Space.s) {
            Image(systemName: "lightbulb.fill").font(.system(size: 14, weight: .bold)).foregroundStyle(Theme.Palette.amber)
            Text(advice).font(Theme.Font.body).foregroundStyle(Theme.Palette.textDim)
            Spacer(minLength: 0)
        }
        .padding(Theme.Space.m)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(RoundedRectangle(cornerRadius: Theme.Radius.card, style: .continuous).fill(Theme.Palette.card))
        .overlay(RoundedRectangle(cornerRadius: Theme.Radius.card, style: .continuous).strokeBorder(Theme.Palette.cardStroke))
    }

    // MARK: - Share + footer

    private var shareRow: some View {
        ShareLink(item: shareCaption) {
            HStack(spacing: Theme.Space.s) {
                Image(systemName: "square.and.arrow.up.fill")
                Text("Share this night").font(Theme.Font.body.weight(.semibold))
            }
            .foregroundStyle(.white)
            .frame(maxWidth: .infinity).padding(.vertical, Theme.Space.m)
            .background(RoundedRectangle(cornerRadius: Theme.Radius.chip, style: .continuous).fill(accent.opacity(0.9)))
        }
    }

    private var shareCaption: String {
        if let p = detail?.performance_pct { return "Slept \(asleepText) — \(p)% sleep performance on Titan 😴" }
        return "Slept \(asleepText) — tracked on Titan 😴"
    }

    private var statusFooter: some View {
        Group {
            if summary.loading { Label("Staging your night…", systemImage: "sparkles") }
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


    // The asleep total: prefer the server's duration; fall back to the watch's in-bed markers. While the
    // night is still computing, the server's `duration_min` is the ENVELOPE (bed→wake = time in bed), not
    // measured asleep time — so show it as "in bed" and don't let the headline shrink/relabel when the real
    // asleep duration lands on finalize.
    private var asleepSec: Int {
        if summary.loading { return summary.inBedSec }
        return (detail?.duration_min ?? detail?.asleep_min).map { $0 * 60 } ?? summary.inBedSec
    }
    private var asleepText: String { hm(asleepSec / 60) }
    private var asleepLabel: String { (summary.loading || detail?.duration_min == nil) ? "in bed" : "asleep" }
    private var performanceFraction: Double { detail?.performance_pct.map { min(1, Double($0) / 100) } ?? 0 }

    private func hm(_ minutes: Int) -> String {
        let h = minutes / 60, m = minutes % 60
        return h > 0 ? "\(h)h \(m)m" : "\(m)m"
    }
    private func hoursText(_ h: Double) -> String {
        let total = Int((h * 60).rounded())
        return hm(total)
    }
    private func clock(_ date: Date?) -> String {
        guard let date else { return "—" }
        let f = DateFormatter(); f.dateFormat = "h:mm a"; return f.string(from: date)
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
