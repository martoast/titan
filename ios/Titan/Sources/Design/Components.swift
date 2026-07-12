import SwiftUI
import Charts
import TitanCore

// MARK: - Glass card
struct GlassCard<Content: View>: View {
    var padding: CGFloat = Theme.Space.m
    @ViewBuilder var content: Content
    var body: some View {
        content
            .padding(padding)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(Theme.Palette.card, in: RoundedRectangle(cornerRadius: Theme.Radius.card, style: .continuous))
            .background(Theme.Grad.sheen, in: RoundedRectangle(cornerRadius: Theme.Radius.card, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: Theme.Radius.card, style: .continuous).strokeBorder(Theme.Palette.cardStroke))
            .shadow(color: .black.opacity(0.45), radius: 18, y: 10)
    }
}

struct SectionHeader: View {
    let title: String
    var trailing: String?
    var body: some View {
        HStack {
            Text(title.uppercased()).font(Theme.Font.label).tracking(1.2).foregroundStyle(Theme.Palette.textDim)
            Spacer()
            if let trailing { Text(trailing).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint) }
        }
    }
}

// MARK: - Animated count-up number
struct CountUp: View {
    let value: Int?
    var font: Font = Theme.Font.num(40)
    var color: Color = Theme.Palette.text
    @State private var shown = 0
    var body: some View {
        Text(value == nil ? "—" : "\(shown)")
            .font(font).foregroundStyle(color)
            .contentTransition(.numericText(value: Double(shown)))
            .monospacedDigit()
            .onChange(of: value) { _, v in animate(to: v) }
            .onAppear { animate(to: value) }
    }
    private func animate(to v: Int?) {
        guard let v else { return }
        withAnimation(Theme.Motion.ring) { shown = v }
    }
}

// MARK: - The hero metric ring (recovery / readiness)
struct MetricRing: View {
    let score: Int?
    let label: String
    var size: CGFloat = 168
    var color: Color { Theme.Palette.recovery(score) }
    @State private var progress: CGFloat = 0
    var body: some View {
        ZStack {
            Circle().stroke(Color.white.opacity(0.06), lineWidth: 16)
            Circle()
                .trim(from: 0, to: progress)
                .stroke(Theme.Grad.ring(color), style: StrokeStyle(lineWidth: 16, lineCap: .round))
                .rotationEffect(.degrees(-90))
                .shadow(color: color.opacity(0.6), radius: 10)
            VStack(spacing: 0) {
                CountUp(value: score, font: Theme.Font.num(size * 0.34), color: .white)
                Text(label.uppercased()).font(Theme.Font.micro).tracking(1.5).foregroundStyle(Theme.Palette.textDim)
            }
        }
        .frame(width: size, height: size)
        .background(Theme.Grad.glow(color).scaleEffect(1.2).opacity(0.7))
        .onAppear { withAnimation(Theme.Motion.ring) { progress = CGFloat(score ?? 0) / 100 } }
        .onChange(of: score) { _, s in withAnimation(Theme.Motion.ring) { progress = CGFloat(s ?? 0) / 100 } }
    }
}

// MARK: - Compact stat ring (the Whoop trio: Recovery / Sleep / Strain)
struct StatRing: View {
    let value: Double?          // score, %, or strain
    var max: Double = 100       // 100 for %, 21 for strain
    let label: String
    let color: Color
    var size: CGFloat = 100
    @State private var progress: CGFloat = 0
    var body: some View {
        VStack(spacing: 7) {
            ZStack {
                Circle().stroke(Color.white.opacity(0.06), lineWidth: 9)
                Circle().trim(from: 0, to: progress)
                    .stroke(Theme.Grad.ring(color), style: StrokeStyle(lineWidth: 9, lineCap: .round))
                    .rotationEffect(.degrees(-90))
                    .shadow(color: color.opacity(0.5), radius: 7)
                Text(formatted).font(Theme.Font.num(size * 0.30)).foregroundStyle(.white).monospacedDigit()
            }
            .frame(width: size, height: size)
            .background(Theme.Grad.glow(color).scaleEffect(1.1).opacity(0.55))
            Text(label.uppercased()).font(Theme.Font.micro).tracking(1.1).foregroundStyle(Theme.Palette.textDim)
        }
        .onAppear { animate() }
        .onChange(of: value) { _, _ in animate() }
    }
    private var formatted: String {
        guard let v = value else { return "—" }
        return v == v.rounded() ? "\(Int(v))" : String(format: "%.1f", v)
    }
    private func animate() { withAnimation(Theme.Motion.ring) { progress = CGFloat(min(1, (value ?? 0) / Swift.max(1, max))) } }
}

// MARK: - Stat chip (HRV / RHR / etc.)
struct Metric: View {
    let value: String
    var unit: String? = nil
    let label: String
    var color: Color = Theme.Palette.text
    var icon: String? = nil
    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            if let icon { Image(systemName: icon).font(.system(size: 13)).foregroundStyle(color) }
            HStack(alignment: .firstTextBaseline, spacing: 2) {
                Text(value).font(Theme.Font.num(24)).foregroundStyle(color).monospacedDigit()
                if let unit { Text(unit).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint) }
            }
            Text(label.uppercased()).font(Theme.Font.micro).tracking(0.6).foregroundStyle(Theme.Palette.textDim)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
    }
}

// MARK: - Sleep stage breakdown bar (proportional, colored)
struct StageBars: View {
    // (label, minutes, color)
    let stages: [(String, Int, Color)]
    var total: Int { max(1, stages.reduce(0) { $0 + $1.1 }) }
    var body: some View {
        VStack(spacing: Theme.Space.s) {
            GeometryReader { geo in
                HStack(spacing: 2) {
                    ForEach(stages.indices, id: \.self) { i in
                        Capsule().fill(stages[i].2)
                            .frame(width: max(2, geo.size.width * CGFloat(stages[i].1) / CGFloat(total)))
                    }
                }
            }
            .frame(height: 12)
            // Four even columns (dot + label above the value) so it never wraps/clips on narrow phones.
            HStack(alignment: .top, spacing: Theme.Space.s) {
                ForEach(stages.indices, id: \.self) { i in
                    VStack(spacing: 3) {
                        HStack(spacing: 4) {
                            Circle().fill(stages[i].2).frame(width: 6, height: 6)
                            Text(stages[i].0).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                        }
                        Text(minToHrs(stages[i].1)).font(Theme.Font.micro.weight(.semibold)).foregroundStyle(Theme.Palette.text)
                    }
                    .lineLimit(1).minimumScaleFactor(0.7)
                    .frame(maxWidth: .infinity)
                }
            }
        }
    }
}

// MARK: - Trend line chart (HRV / RHR over time)
struct TrendChart: View {
    let points: [Double]
    var color: Color = Theme.Palette.cyan
    var body: some View {
        Chart(Array(points.enumerated()), id: \.offset) { i, v in
            LineMark(x: .value("i", i), y: .value("v", v))
                .interpolationMethod(.catmullRom)
                .lineStyle(.init(lineWidth: 2.5, lineCap: .round))
                .foregroundStyle(color)
            AreaMark(x: .value("i", i), y: .value("v", v))
                .interpolationMethod(.catmullRom)
                .foregroundStyle(LinearGradient(colors: [color.opacity(0.25), .clear], startPoint: .top, endPoint: .bottom))
        }
        .chartXAxis(.hidden).chartYAxis(.hidden)
        .frame(height: 90)
    }
}

// MARK: - Pulsing connection dot
struct PulseDot: View {
    let on: Bool
    @State private var pulse = false
    var body: some View {
        ZStack {
            if on {
                Circle().fill(Theme.Palette.mint).frame(width: 10, height: 10)
                    .scaleEffect(pulse ? 2.6 : 1).opacity(pulse ? 0 : 0.5)
                    .animation(.easeOut(duration: 1.4).repeatForever(autoreverses: false), value: pulse)
            }
            Circle().fill(on ? Theme.Palette.mint : Theme.Palette.textFaint).frame(width: 10, height: 10)
        }
        .onAppear { pulse = on }
        .onChange(of: on) { _, v in pulse = v }
    }
}

// MARK: - Shimmer placeholder
struct Shimmer: View {
    @State private var x: CGFloat = -1
    var body: some View {
        Theme.Palette.card
            .overlay(LinearGradient(colors: [.clear, .white.opacity(0.06), .clear], startPoint: .leading, endPoint: .trailing)
                .offset(x: x * 220))
            .onAppear { withAnimation(.linear(duration: 1.2).repeatForever(autoreverses: false)) { x = 1 } }
            .clipShape(RoundedRectangle(cornerRadius: Theme.Radius.chip))
    }
}

// MARK: - Loading placeholder shaped like a daily-loop card
/// A shimmering stand-in for a metric card: a big headline bar, a wide sub-line, and a row of small
/// stat blocks — so a cold open reads as "loading" instead of flashing an empty state.
struct SkeletonCard: View {
    var body: some View {
        GlassCard(padding: Theme.Space.l) {
            VStack(alignment: .leading, spacing: Theme.Space.m) {
                Shimmer().frame(width: 150, height: 42)
                Shimmer().frame(height: 14).frame(maxWidth: .infinity)
                HStack(spacing: Theme.Space.l) {
                    ForEach(0..<4, id: \.self) { _ in
                        Shimmer().frame(height: 32).frame(maxWidth: .infinity)
                    }
                }
            }
        }
        .transition(.opacity)
    }
}

// MARK: - Inline "couldn't sync" row with a retry
/// The single error affordance for the daily loop: a failed fetch shows this instead of `—` forever,
/// so a network blip is legible (and recoverable) rather than indistinguishable from "no data".
struct SyncErrorRow: View {
    var message: String = "Couldn't sync"
    let retry: () async -> Void
    @State private var retrying = false
    var body: some View {
        GlassCard {
            HStack(spacing: Theme.Space.m) {
                Image(systemName: "exclamationmark.arrow.triangle.2.circlepath")
                    .font(.title3).foregroundStyle(Theme.Palette.amber)
                VStack(alignment: .leading, spacing: 2) {
                    Text(LocalizedStringKey(message)).font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.text)
                    Text("Check your connection").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                }
                Spacer()
                Button {
                    Haptic.tap()
                    Task { retrying = true; await retry(); retrying = false }
                } label: {
                    if retrying {
                        ProgressView().tint(Theme.Palette.indigo)
                    } else {
                        Text("Retry").font(Theme.Font.label.weight(.semibold)).foregroundStyle(Theme.Palette.indigo)
                    }
                }
                .frame(minWidth: 52, minHeight: 30)
                .background(Theme.Palette.indigo.opacity(0.14), in: Capsule())
                .disabled(retrying)
            }
        }
        .transition(.opacity)
    }
}

// MARK: - BLE signal strength bars
struct SignalBars: View {
    let rssi: Int   // dBm: ~ -30 (touching) … -100 (far)
    private var bars: Int { rssi > -55 ? 4 : (rssi > -67 ? 3 : (rssi > -80 ? 2 : 1)) }
    var body: some View {
        HStack(alignment: .bottom, spacing: 2) {
            ForEach(1...4, id: \.self) { i in
                Capsule().fill(i <= bars ? Theme.Palette.mint : Theme.Palette.cardStroke)
                    .frame(width: 3, height: CGFloat(4 + i * 3))
            }
        }
    }
}

// MARK: - Live PPG waveform (auto-scaled, glowing)
struct WaveformView: View {
    let samples: [Double]
    var color: Color = Theme.Palette.cyan
    var body: some View {
        GeometryReader { geo in
            if samples.count > 1 {
                let lo = samples.min() ?? 0, hi = samples.max() ?? 1
                let range = max(1, hi - lo)
                Path { p in
                    for (i, v) in samples.enumerated() {
                        let x = geo.size.width * CGFloat(i) / CGFloat(samples.count - 1)
                        let y = geo.size.height * (1 - CGFloat((v - lo) / range)) * 0.9 + geo.size.height * 0.05
                        i == 0 ? p.move(to: CGPoint(x: x, y: y)) : p.addLine(to: CGPoint(x: x, y: y))
                    }
                }
                .stroke(color, style: StrokeStyle(lineWidth: 2, lineCap: .round, lineJoin: .round))
                .shadow(color: color.opacity(0.6), radius: 5)
            } else {
                Text("Waiting for signal…")
                    .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
                    .frame(maxWidth: .infinity, maxHeight: .infinity)
            }
        }
        .frame(height: 84)
    }
}

func minToHrs(_ m: Int?) -> String {
    guard let m, m > 0 else { return "—" }
    let h = m / 60, mm = m % 60
    return h > 0 ? "\(h)h \(mm)m" : "\(mm)m"
}

// MARK: - Sleep stage color (the ONE app-side mapping)

extension SleepStage {
    /// The single place a stage's platform-neutral `colorRole` becomes a real `Color`. Canonical:
    /// deep→indigo, rem→violet, light→cyan, wake→amber, hole→faint gray (the NODATA gap). Every sleep
    /// surface (timeline, proportional bar, legends) routes through here so colors can't diverge again.
    var color: Color {
        switch colorRole {
        case .deep:  return Theme.Palette.indigo
        case .rem:   return Theme.Palette.violet
        case .light: return Theme.Palette.cyan
        case .wake:  return Theme.Palette.amber
        case .hole:  return Theme.Palette.textFaint.opacity(0.22)
        }
    }

    /// Color for a raw stage code (routes legends/proportional bars through the shared enum). An
    /// unrecognized code falls back to faint gray rather than mis-coloring — the fail-loud path lives
    /// in `SleepStage.parse`, used by the timeline.
    static func color(forCode code: String) -> Color {
        SleepStage(code: code)?.color ?? Theme.Palette.textFaint
    }
}

// MARK: - Sleep timeline (one component, everywhere)

/// The one sleep timeline for the whole app: a stepped four-lane hypnogram ribbon (Awake / REM /
/// Light / Deep, top→bottom — depth reads as depth) across a real clock-time axis in the user's
/// timezone. Wake interruptions spike to the top lane; **NODATA epochs render as honest full-height
/// hatched gaps, never painted as sleep**. All lanes/colors come from the shared `SleepStage`
/// (TitanCore), so a new server-side stage fails loudly in exactly one place (`SleepStage.parse`).
///
/// Three states (spec §2.3): `computing` with no ribbon yet → a shimmering skeleton between the
/// bed/wake anchors; stages present → the ribbon; duration-only (thin coverage) → a span bar with a
/// low-signal note. Used interactive + full on the Sleep detail hero, and `mini` (compact,
/// non-interactive) on the morning summary card. Naps reuse it with a shorter axis (fewer epochs).
struct SleepTimeline: View {
    let stages: [String]                 // raw per-30s codes: wake/light/deep/rem/nodata
    var epochSec: Int? = nil             // night-start unix ts → real clock axis; epoch N = epochSec + N·30
    var bedtime: String? = nil           // "H:i" fallback label for the start anchor
    var wakeTime: String? = nil          // "H:i" fallback label for the wake anchor
    var computing: Bool = false          // stage_status == "computing" (still being sealed)
    var interactive: Bool = true
    var mini: Bool = false               // compact ~48pt, non-interactive summary-card variant
    // v2 layers — two SPARSE per-epoch series indexed off the SAME grid as `stages` (epoch i's clock =
    // epochSec + i·30). Only measured epochs are present; missing indices are honest gaps, never drawn.
    var hrSeries: [SleepResponse.Detail.EpochPoint]? = nil      // top HR-peaks overlay
    var motionSeries: [SleepResponse.Detail.EpochPoint]? = nil  // bottom restlessness strip

    private static let epochLen = 30
    private var parsed: [SleepStage] { stages.map(SleepStage.parse) }
    private var hasRibbon: Bool { parsed.contains { !$0.isHole } }
    private var ribbonHeight: CGFloat { mini ? 48 : 128 }

    // v2 overlay bands. Shown only when their series carry measured points (older nights → just ribbon).
    private var hasHR: Bool { !(hrSeries?.isEmpty ?? true) }
    private var hasMotion: Bool { !(motionSeries?.isEmpty ?? true) }
    private var hrBandHeight: CGFloat { mini ? 16 : 38 }
    private var motionStripHeight: CGFloat { mini ? 8 : 16 }
    private var layerSpacing: CGFloat { mini ? 2 : 4 }

    /// Epoch-index → value lookups for the scrub tooltip (sparse: absent key = signal gap).
    private var hrByIndex: [Int: Double] {
        Dictionary((hrSeries ?? []).map { ($0.i, $0.v) }, uniquingKeysWith: { a, _ in a })
    }
    private var motionByIndex: [Int: Double] {
        Dictionary((motionSeries ?? []).map { ($0.i, $0.v) }, uniquingKeysWith: { a, _ in a })
    }
    /// Night-relative "restless" cut (70th percentile of the night's own motion) — a per-night threshold
    /// so calm vs. restless reads against this sleeper, not an absolute scale.
    private var restlessThreshold: Double {
        let vals = (motionSeries ?? []).map { $0.v }.sorted()
        guard !vals.isEmpty else { return .greatestFiniteMagnitude }
        return vals[min(vals.count - 1, Int(Double(vals.count) * 0.7))]
    }

    @State private var scrubFraction: CGFloat? = nil

    var body: some View {
        if computing && !hasRibbon {
            skeleton
        } else if hasRibbon {
            ribbonWithChrome
        } else {
            durationOnly
        }
    }

    // MARK: Ribbon + axis + (optional) lane labels

    private var ribbonWithChrome: some View {
        VStack(alignment: .leading, spacing: mini ? 4 : Theme.Space.s) {
            if mini {
                // Mini keeps the ribbon as the shape + adds the movement strip (at-a-glance restlessness);
                // a faint HR peak line is optional context — the scrub tooltip is detail-screen only.
                VStack(spacing: layerSpacing) {
                    if hasHR { hrOverlay(faint: true) }
                    ribbon
                    if hasMotion { movementStrip }
                }
            } else {
                // Full hero: three legible layers over one clock axis — HR peaks, the stage ribbon (hero),
                // the movement strip. Lane labels align to the ribbon via matching top/bottom spacers.
                HStack(alignment: .top, spacing: Theme.Space.s) {
                    VStack(spacing: layerSpacing) {
                        if hasHR { Color.clear.frame(width: 34, height: hrBandHeight) }
                        laneLabels
                        if hasMotion { Color.clear.frame(width: 34, height: motionStripHeight) }
                    }
                    VStack(spacing: layerSpacing) {
                        if hasHR { hrOverlay(faint: false) }
                        ribbon
                        if hasMotion { movementStrip }
                    }
                }
                clockAxis
            }
        }
    }

    // MARK: v2 — HR peaks overlay (top) + movement strip (bottom)

    /// Thin HR trace over the top band, drawn ONLY across contiguous measured runs (a break wherever
    /// epochs are missing — never connected across a gap). Y-scaled to the night's own HR min/max, with a
    /// faint fill so it reads as an envelope and the arousal peaks (the "3am spike") stand out.
    private func hrOverlay(faint: Bool) -> some View {
        Canvas { ctx, size in drawHR(ctx, size, faint: faint) }
            .frame(height: hrBandHeight)
            .frame(maxWidth: .infinity)
    }

    /// Per-epoch restlessness bars: height/opacity ∝ motion, measured epochs only. Calm deep sleep →
    /// nearly empty; restless light/wake → visible teeth. A subtle amber tint above the night-relative
    /// restless percentile, kept continuous underneath (no hard bucketing).
    private var movementStrip: some View {
        Canvas { ctx, size in drawMotion(ctx, size) }
            .frame(height: motionStripHeight)
            .frame(maxWidth: .infinity)
    }

    /// Group a sparse `{i,v}` series into runs of consecutive epoch indices. A run break = a real gap;
    /// the HR line only connects within a run, so it is never painted across missing epochs.
    private func contiguousRuns(_ series: [SleepResponse.Detail.EpochPoint]) -> [[SleepResponse.Detail.EpochPoint]] {
        let sorted = series.sorted { $0.i < $1.i }
        var runs: [[SleepResponse.Detail.EpochPoint]] = []
        var cur: [SleepResponse.Detail.EpochPoint] = []
        for p in sorted {
            if let last = cur.last, p.i != last.i + 1 { runs.append(cur); cur = [] }
            cur.append(p)
        }
        if !cur.isEmpty { runs.append(cur) }
        return runs
    }

    private func drawHR(_ ctx: GraphicsContext, _ size: CGSize, faint: Bool) {
        guard let series = hrSeries, !series.isEmpty else { return }
        let n = parsed.count
        guard n > 0, size.width > 0, size.height > 0 else { return }
        let vals = series.map { $0.v }
        let lo = vals.min() ?? 0, hi = vals.max() ?? 1
        let span = max(1, hi - lo)
        let colW = size.width / CGFloat(n)
        let pad: CGFloat = 3
        func point(_ p: SleepResponse.Detail.EpochPoint) -> CGPoint {
            let x = (CGFloat(p.i) + 0.5) * colW
            let yFrac = (p.v - lo) / span                       // night-relative
            let y = pad + (size.height - 2 * pad) * (1 - CGFloat(yFrac))
            return CGPoint(x: x, y: y)
        }
        let stroke = Theme.Palette.pink.opacity(faint ? 0.5 : 0.9)
        let fill = Theme.Palette.pink.opacity(faint ? 0.06 : 0.13)
        for run in contiguousRuns(series) {
            let pts = run.map(point)
            if pts.count == 1 {
                // Isolated measured epoch — a dot, so a lone peak is still honest and visible.
                let r = CGRect(x: pts[0].x - 1.2, y: pts[0].y - 1.2, width: 2.4, height: 2.4)
                ctx.fill(Path(ellipseIn: r), with: .color(stroke))
                continue
            }
            var line = Path(); line.addLines(pts)
            var envelope = line
            envelope.addLine(to: CGPoint(x: pts.last!.x, y: size.height))
            envelope.addLine(to: CGPoint(x: pts.first!.x, y: size.height))
            envelope.closeSubpath()
            ctx.fill(envelope, with: .color(fill))
            ctx.stroke(line, with: .color(stroke),
                       style: StrokeStyle(lineWidth: faint ? 1 : 1.5, lineCap: .round, lineJoin: .round))
        }
    }

    private func drawMotion(_ ctx: GraphicsContext, _ size: CGSize) {
        guard let series = motionSeries, !series.isEmpty else { return }
        let n = parsed.count
        guard n > 0, size.width > 0, size.height > 0 else { return }
        let hi = max(1, series.map { $0.v }.max() ?? 1)
        let thr = restlessThreshold
        let colW = size.width / CGFloat(n)
        let barW = max(1, colW * 0.7)
        for p in series {
            let frac = min(1, max(0, p.v / hi))
            let h = max(1, size.height * CGFloat(frac))
            let x = (CGFloat(p.i) + 0.5) * colW - barW / 2
            let rect = CGRect(x: x, y: size.height - h, width: barW, height: h)
            // Continuous opacity ∝ motion; a subtle amber tint above the night's restless percentile.
            let base = p.v >= thr ? Theme.Palette.amber : Theme.Palette.text
            let c = base.opacity(0.28 + 0.52 * Double(frac))
            ctx.fill(Path(roundedRect: rect, cornerRadius: min(1.5, barW / 2)), with: .color(c))
        }
    }

    private var laneLabels: some View {
        VStack(alignment: .trailing, spacing: 0) {
            ForEach(SleepStage.lanes, id: \.self) { s in
                Text(s.label).font(.system(size: 9)).foregroundStyle(Theme.Palette.textDim)
                    .frame(maxHeight: .infinity)
            }
        }.frame(width: 34, height: ribbonHeight)
    }

    private var ribbon: some View {
        GeometryReader { geo in
            ZStack(alignment: .topLeading) {
                Canvas { ctx, size in draw(ctx, size) }

                if let f = scrubFraction, let idx = index(at: f) {
                    scrubOverlay(fraction: f, index: idx, width: geo.size.width, height: geo.size.height)
                }
            }
            .contentShape(Rectangle())
            .modifier(ScrubGesture(enabled: interactive && !mini, width: geo.size.width, fraction: $scrubFraction))
        }
        .frame(height: ribbonHeight)
    }

    /// Paint the stepped ribbon: runs of a sleep stage as rounded blocks in their lane; runs of NODATA
    /// as a full-height faint rect with a diagonal hatch — an honest gap, never a lane.
    private func draw(_ ctx: GraphicsContext, _ size: CGSize) {
        let stages = parsed
        let n = stages.count
        guard n > 0 else { return }
        let laneH = size.height / CGFloat(SleepStage.laneCount)
        let barH = laneH * 0.62
        let colW = size.width / CGFloat(n)

        var i = 0
        while i < n {
            let s = stages[i]
            var j = i
            while j < n && stages[j] == s { j += 1 }
            let x = CGFloat(i) * colW
            let w = max(1, CGFloat(j - i) * colW)

            if let lane = s.lane {
                let y = CGFloat(lane) * laneH + (laneH - barH) / 2
                let rect = CGRect(x: x, y: y, width: w, height: barH)
                ctx.fill(Path(roundedRect: rect, cornerRadius: min(3, barH / 2)), with: .color(s.color))
            } else {
                // NODATA coverage hole — full-height faint rect + diagonal hatch, clipped to the gap.
                let hole = CGRect(x: x, y: 0, width: w, height: size.height)
                ctx.fill(Path(hole), with: .color(s.color))
                var hatched = ctx
                hatched.clip(to: Path(hole))
                var lines = Path()
                let step: CGFloat = 6
                var hx = hole.minX - hole.height
                while hx < hole.maxX {
                    lines.move(to: CGPoint(x: hx, y: hole.maxY))
                    lines.addLine(to: CGPoint(x: hx + hole.height, y: hole.minY))
                    hx += step
                }
                hatched.stroke(lines, with: .color(Theme.Palette.textFaint.opacity(0.5)), lineWidth: 1)
            }
            i = j
        }
    }

    // MARK: Clock axis (real times, user tz)

    private var clockAxis: some View {
        HStack(spacing: 0) {
            // Aligns the axis under the ribbon (which sits right of the 34pt lane-label column).
            Color.clear.frame(width: 34 + Theme.Space.s)
            GeometryReader { geo in
                ForEach(axisTicks, id: \.fraction) { tick in
                    Text(tick.label).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
                        .fixedSize()
                        .position(x: min(max(18, geo.size.width * tick.fraction), geo.size.width - 18), y: 7)
                }
            }.frame(height: 14)
        }
    }

    private struct Tick { let fraction: CGFloat; let label: String }

    private var axisTicks: [Tick] {
        let n = parsed.count
        guard n > 0 else { return [] }
        // Prefer real clock times from the night-start epoch; fall back to the bed/wake "H:i" labels.
        if let start = epochSec {
            let steps = 4
            return (0...steps).map { k in
                let frac = CGFloat(k) / CGFloat(steps)
                let idx = Int((CGFloat(n - 1) * frac).rounded())
                let t = Date(timeIntervalSince1970: TimeInterval(start + idx * Self.epochLen))
                return Tick(fraction: frac, label: Self.clock(t))
            }
        }
        var ticks: [Tick] = []
        if let b = bedtime.map(Self.hhmm) { ticks.append(Tick(fraction: 0, label: b)) }
        if let w = wakeTime.map(Self.hhmm) { ticks.append(Tick(fraction: 1, label: w)) }
        return ticks
    }

    // MARK: Scrub tooltip

    private func index(at fraction: CGFloat) -> Int? {
        let n = parsed.count
        guard n > 0 else { return nil }
        return min(n - 1, max(0, Int(fraction * CGFloat(n))))
    }

    private func scrubOverlay(fraction: CGFloat, index: Int, width: CGFloat, height: CGFloat) -> some View {
        let x = fraction * width
        let stage = parsed[index]
        var label = stage.label
        if !stage.isHole { label = "\(stage.label) sleep" }
        if let start = epochSec {
            let t = Date(timeIntervalSince1970: TimeInterval(start + index * Self.epochLen))
            label = "\(Self.clock(t)) · \(label)"
        }
        // v2: append the measured per-epoch HR + restlessness for this epoch → "3:12 AM · Deep · HR 52 ·
        // calm". Sparse: an epoch with no measured HR/motion says "· signal gap" — never a stale value.
        let hr = hrByIndex[index]
        let motion = motionByIndex[index]
        if let hr { label += " · HR \(Int(hr.rounded()))" }
        if let motion { label += " · " + (motion >= restlessThreshold ? String(localized: "restless") : String(localized: "calm")) }
        // Only when the night HAS these channels but this epoch measured neither — honest gap, not silence.
        if hr == nil, motion == nil, hasHR || hasMotion { label += " · " + String(localized: "signal gap") }
        return ZStack(alignment: .topLeading) {
            Rectangle().fill(Theme.Palette.text.opacity(0.35)).frame(width: 1, height: height)
                .position(x: x, y: height / 2)
            Text(label)
                .font(Theme.Font.micro).foregroundStyle(Theme.Palette.text)
                .padding(.horizontal, 8).padding(.vertical, 4)
                .background(Capsule().fill(Theme.Palette.bg2))
                .overlay(Capsule().strokeBorder(Theme.Palette.cardStroke))
                .fixedSize()
                .position(x: min(max(48, x), width - 48), y: -2)
        }
    }

    // MARK: Computing (skeleton) + duration-only states

    private var skeleton: some View {
        VStack(alignment: .leading, spacing: mini ? 4 : Theme.Space.s) {
            Shimmer().frame(height: ribbonHeight).frame(maxWidth: .infinity)
            if !mini {
                HStack {
                    Text(bedtime.map(Self.hhmm) ?? "—").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
                    Spacer()
                    Label("Writing your story…", systemImage: "sparkles")
                        .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
                    Spacer()
                    Text(wakeTime.map(Self.hhmm) ?? "—").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
                }
            }
        }
    }

    /// Thin-coverage fallback: no per-stage data this night, so show an honest span bar + a plain note
    /// instead of an empty chart.
    private var durationOnly: some View {
        VStack(alignment: .leading, spacing: mini ? 4 : Theme.Space.s) {
            ZStack(alignment: .leading) {
                Capsule().fill(Theme.Palette.card).frame(height: mini ? 14 : 20)
                Capsule().fill(Theme.Palette.indigo.opacity(0.55)).frame(height: mini ? 14 : 20)
            }
            if !mini {
                HStack {
                    Text(bedtime.map(Self.hhmm) ?? "—").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
                    Spacer()
                    Text("stages unavailable — low signal this night")
                        .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
                    Spacer()
                    Text(wakeTime.map(Self.hhmm) ?? "—").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
                }
            }
        }
    }

    // MARK: Time formatting (user's timezone via the current locale/calendar)

    private static let clockFormatter: DateFormatter = {
        let f = DateFormatter(); f.dateFormat = "h:mm a"; return f
    }()
    private static func clock(_ date: Date) -> String { clockFormatter.string(from: date) }

    /// Format an "H:i" (24h) server string like "23:05" as a 12h clock "11:05 PM".
    private static func hhmm(_ s: String) -> String {
        let parts = s.split(separator: ":")
        guard parts.count == 2, let h = Int(parts[0]), let m = Int(parts[1]) else { return s }
        var c = DateComponents(); c.hour = h; c.minute = m
        if let d = Calendar.current.date(from: c) { return clock(d) }
        return s
    }
}

/// "The story of your night" — the narrative that frames the hypnogram (server-derived, shared across the
/// summary sheet + the detail screen + the coach). The takeaway leads (bold), the read follows.
struct SleepStoryCard: View {
    let story: SleepResponse.Detail.Story
    var body: some View {
        let low = story.low_confidence == true
        return VStack(alignment: .leading, spacing: 8) {
            HStack(spacing: 7) {
                Image(systemName: low ? "waveform.badge.exclamationmark" : "text.alignleft")
                    .foregroundStyle(low ? Theme.Palette.amber : Theme.Palette.indigo)
                Text("Your night").font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.text)
            }
            if let text = story.text, !text.isEmpty {
                Text(text).font(Theme.Font.body).foregroundStyle(Theme.Palette.textDim).fixedSize(horizontal: false, vertical: true)
            }
        }
    }
}

/// The stacked stage %/minutes breakdown — ONE shared component (was duplicated in the summary sheet and
/// the detail screen). Palette matches the timeline via SleepStage.
struct StageBreakdown: View {
    let stages: [SleepResponse.Detail.Stage]
    var body: some View {
        let total = max(1, stages.reduce(0) { $0 + $1.min })
        return VStack(spacing: Theme.Space.m) {
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
                        Text(SleepFmt.hm(s.min)).font(Theme.Font.num(14)).foregroundStyle(Theme.Palette.text).frame(width: 52, alignment: .trailing)
                    }
                }
            }
        }
    }
}

/// Shared h/m formatting for sleep minutes.
enum SleepFmt {
    static func hm(_ minutes: Int) -> String {
        let h = minutes / 60, m = minutes % 60
        return h > 0 ? "\(h)h \(m)m" : "\(m)m"
    }
}

/// Drag/tap scrubbing for the timeline, gated by `enabled` so the mini/non-interactive variants ignore
/// touches. Reports the touch position as a 0…1 fraction of the ribbon width.
private struct ScrubGesture: ViewModifier {
    let enabled: Bool
    let width: CGFloat
    @Binding var fraction: CGFloat?

    func body(content: Content) -> some View {
        guard enabled, width > 0 else { return AnyView(content) }
        return AnyView(content.gesture(
            DragGesture(minimumDistance: 0)
                .onChanged { v in fraction = min(1, max(0, v.location.x / width)) }
                .onEnded { _ in fraction = nil }
        ))
    }
}
