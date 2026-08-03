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
                // No .shadow here: a blurred drop-shadow on a vector path forces an offscreen rasterize +
                // Gaussian blur EVERY render, and this trace re-strokes ~10×/sec while the band streams —
                // a continuous GPU load (device heat). The 2pt round stroke reads fine without it.
                .stroke(color, style: StrokeStyle(lineWidth: 2, lineCap: .round, lineJoin: .round))
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
    // Ultradian cycle-boundary epoch indices from SleepStory (server) — the SAME filtered+capped set behind
    // the story's REM-period count, so the marker count on the graph matches the sentence. When nil (naps /
    // older nights with no story) the hero falls back to a local REM-run scan.
    var cycleBoundaries: [Int]? = nil

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

    @State private var scrubFraction: CGFloat? = nil    // transient drag readout
    @State private var selectedRun: Int? = nil          // sticky tapped-segment start index (hero only)
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

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

                // A live drag preview wins over the sticky selection; otherwise the tapped segment persists.
                if let f = scrubFraction, let idx = index(at: f) {
                    scrubOverlay(fraction: f, index: idx, width: geo.size.width, height: geo.size.height, segment: false)
                } else if let sel = selectedRun, let f = runCenterFraction(sel) {
                    scrubOverlay(fraction: f, index: sel, width: geo.size.width, height: geo.size.height, segment: true)
                }
            }
            .contentShape(Rectangle())
            .modifier(TimelineInteraction(enabled: interactive && !mini, width: geo.size.width, count: parsed.count,
                                          fraction: $scrubFraction, selected: $selectedRun,
                                          runStart: { runBounds(at: $0).0 }))
            .animation(reduceMotion ? nil : Theme.Motion.spring, value: selectedRun)
        }
        .frame(height: ribbonHeight)
    }

    /// Paint the stepped ribbon: runs of a sleep stage as rounded blocks in their lane; runs of NODATA
    /// as a full-height faint rect with a diagonal hatch — an honest gap, never a lane. On the full hero
    /// (never mini) deep/REM read as the restorative sleep they are (a soft halo), faint ultradian cycle
    /// markers trace the ~90-min sleep cycles, and a tapped segment lifts with a bright outline.
    private func draw(_ ctx: GraphicsContext, _ size: CGSize) {
        let stages = parsed
        let n = stages.count
        guard n > 0 else { return }
        let laneH = size.height / CGFloat(SleepStage.laneCount)
        let barH = laneH * 0.62
        let colW = size.width / CGFloat(n)
        let emphasize = !mini    // restorative halo + cycle markers + selection are hero-only; mini stays calm

        // Faint ultradian cycle boundaries behind the ribbon (end of each completed REM period).
        if emphasize {
            for b in cycleMarkers {
                let x = min(size.width - 1, CGFloat(b) * colW)   // a tail boundary (b == n) sits at the edge
                var line = Path()
                line.move(to: CGPoint(x: x, y: 0)); line.addLine(to: CGPoint(x: x, y: size.height))
                ctx.stroke(line, with: .color(Theme.Palette.textFaint.opacity(0.35)),
                           style: StrokeStyle(lineWidth: 1, dash: [2, 3]))
            }
        }

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
                let radius = min(3, barH / 2)
                // Restorative (deep + REM) get a soft halo so the eye reads where the real recovery happened.
                if emphasize, s.colorRole == .deep || s.colorRole == .rem {
                    let halo = rect.insetBy(dx: -1.5, dy: -2)
                    ctx.fill(Path(roundedRect: halo, cornerRadius: radius + 2), with: .color(s.color.opacity(0.22)))
                }
                ctx.fill(Path(roundedRect: rect, cornerRadius: radius), with: .color(s.color))
                // Tapped segment: a bright outline so the sticky readout has a clear anchor.
                if emphasize, selectedRun == i {
                    ctx.stroke(Path(roundedRect: rect.insetBy(dx: -1, dy: -1.5), cornerRadius: radius + 1),
                               with: .color(Theme.Palette.text.opacity(0.9)), lineWidth: 1.5)
                }
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

    /// Epoch indices where a sleep cycle completes. Prefer the server's `cycleBoundaries` (the SAME
    /// filtered+capped set behind the story's REM-period count, so the marker count == the narrated count).
    /// Only when it's absent (naps / older nights with no story) fall back to a local REM-run scan that
    /// mirrors SleepStory's rules — REM runs ≥6 epochs (≥3 min), capped at ~one per 80 min asleep — so even
    /// the fallback can't reintroduce the micro-REM over-count (review 04e14c5).
    private var cycleMarkers: [Int] {
        if let server = cycleBoundaries { return server }
        let p = parsed
        guard p.count > 6 else { return [] }
        var out: [Int] = []
        var i = 0
        while i < p.count {
            if p[i] == .rem {
                var j = i
                while j < p.count && p[j] == .rem { j += 1 }
                if j - i >= 6 { out.append(j == p.count ? p.count : j) }   // ≥3-min REM run only
                i = j
            } else { i += 1 }
        }
        // Cap at the physiological max for the sleep time (asleep = non-hole epochs), earliest kept.
        let asleepMin = Double(p.filter { !$0.isHole }.count) * Double(Self.epochLen) / 60
        let cap = max(1, Int(asleepMin / 80))
        return Array(out.prefix(cap))
    }

    /// The contiguous run (same stage) containing `index`, as [start, endExclusive).
    private func runBounds(at index: Int) -> (Int, Int) {
        let p = parsed
        guard index >= 0, index < p.count else { return (index, index + 1) }
        let s = p[index]
        var a = index; while a > 0 && p[a - 1] == s { a -= 1 }
        var b = index; while b < p.count - 1 && p[b + 1] == s { b += 1 }
        return (a, b + 1)
    }

    /// Center-of-run fraction for a run identified by its start index — where the sticky readout sits.
    private func runCenterFraction(_ start: Int) -> CGFloat? {
        let n = parsed.count
        guard n > 0, start >= 0, start < n else { return nil }
        let (a, b) = runBounds(at: start)
        return (CGFloat(a) + CGFloat(b)) / 2 / CGFloat(n)
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

    private func scrubOverlay(fraction: CGFloat, index: Int, width: CGFloat, height: CGFloat, segment: Bool) -> some View {
        let x = fraction * width
        let stage = parsed[index]
        let label = segment ? segmentLabel(runStart: index, stage: stage) : scrubLabel(index: index, stage: stage)
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

    /// Transient drag readout: the single epoch under the finger → "3:12 AM · Deep · HR 52 · calm".
    private func scrubLabel(index: Int, stage: SleepStage) -> String {
        var label = stage.isHole ? stage.label : "\(stage.label) sleep"
        if let start = epochSec {
            let t = Date(timeIntervalSince1970: TimeInterval(start + index * Self.epochLen))
            label = "\(Self.clock(t)) · \(label)"
        }
        // v2: append the measured per-epoch HR + restlessness. Sparse: an epoch with no measured HR/motion
        // says "· signal gap" — never a stale value.
        let hr = hrByIndex[index]
        let motion = motionByIndex[index]
        if let hr { label += " · HR \(Int(hr.rounded()))" }
        if let motion { label += " · " + (motion >= restlessThreshold ? String(localized: "restless") : String(localized: "calm")) }
        if hr == nil, motion == nil, hasHR || hasMotion { label += " · " + String(localized: "signal gap") }
        return label
    }

    /// Sticky segment readout: the whole tapped run → "Deep · 42m · 1:10–1:52 AM". A NODATA gap reads
    /// as its span without a stage claim.
    private func segmentLabel(runStart: Int, stage: SleepStage) -> String {
        let (a, b) = runBounds(at: runStart)
        let mins = (b - a) * Self.epochLen / 60
        var label = stage.isHole ? String(localized: "No data") : "\(stage.label) · \(mins)m"
        if stage.isHole { label += " · \(mins)m" }
        if let start = epochSec {
            let t0 = Date(timeIntervalSince1970: TimeInterval(start + a * Self.epochLen))
            let t1 = Date(timeIntervalSince1970: TimeInterval(start + b * Self.epochLen))
            label += " · \(Self.clock(t0))–\(Self.clock(t1))"
        }
        return label
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

// MARK: - Themed form controls (UI_POLISH job 2 — replace stock Stepper / compact DatePicker)

/// A themed +/− stepper: SF-rounded number, accent circular buttons, haptic tick. Replaces the stock
/// `Stepper` (which looks off on the dark canvas). Reused across onboarding + the log sheets.
struct TitanStepper: View {
    @Binding var value: Int
    let range: ClosedRange<Int>
    var step: Int = 1
    var unit: String = ""

    var body: some View {
        HStack(spacing: Theme.Space.m) {
            button("minus") { adjust(-step) }
            HStack(alignment: .firstTextBaseline, spacing: 3) {
                Text("\(value)").font(Theme.Font.num(22)).foregroundStyle(Theme.Palette.text).monospacedDigit()
                if !unit.isEmpty { Text(unit).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim) }
            }
            .frame(minWidth: 70)
            button("plus") { adjust(step) }
        }
    }

    private func adjust(_ d: Int) {
        let n = min(range.upperBound, max(range.lowerBound, value + d))
        if n != value { value = n; Haptic.tap() }
    }

    private func button(_ icon: String, _ action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Image(systemName: icon).font(.body.weight(.bold)).foregroundStyle(Theme.Palette.text)
                .frame(width: 42, height: 42)
                .background(Theme.Palette.card, in: Circle()).overlay(Circle().strokeBorder(Theme.Palette.cardStroke))
        }.buttonStyle(PressCard())
    }
}

/// A themed date/time field — the native compact picker (familiar tap-to-open UX) inside the app's
/// dark-canvas pill, accent-tinted. Replaces bare `.datePickerStyle(.compact)`. Defaults to past-only
/// (matches birthdate / when-did-this-happen); pass `notAfter` to widen.
struct TitanDateField: View {
    @Binding var selection: Date
    var components: DatePickerComponents = .date
    var notAfter: Date = Date()
    var accent: Color = Theme.Palette.indigo

    var body: some View {
        HStack {
            DatePicker("", selection: $selection, in: Date.distantPast...notAfter, displayedComponents: components)
                .labelsHidden().datePickerStyle(.compact).tint(accent).colorScheme(.dark)
            Spacer(minLength: 0)
        }
        .padding(.horizontal, Theme.Space.m).padding(.vertical, 8)
        .background(Theme.Palette.card, in: RoundedRectangle(cornerRadius: Theme.Radius.chip))
        .overlay(RoundedRectangle(cornerRadius: Theme.Radius.chip).strokeBorder(Theme.Palette.cardStroke))
    }
}

/// A themed toggle — accent tint + the app's label styling, so it doesn't read as a stock iOS switch.
struct TitanToggle: View {
    let title: LocalizedStringKey
    @Binding var isOn: Bool
    var accent: Color = Theme.Palette.indigo
    var body: some View {
        Toggle(isOn: $isOn) {
            Text(title).font(Theme.Font.body).foregroundStyle(Theme.Palette.text)
        }
        .tint(accent)
    }
}

/// A first-class trend chart matching the app's crafted look (HrGraph/SleepTimeline family) — a gradient
/// area + line, light gridlines + a real date axis, the period AVERAGE drawn on the chart (dashed rule),
/// an emphasized endpoint, and drag-to-scrub reading a day's date + value. Replaces the stock Swift-Charts
/// bars on the Trends tab (UI_POLISH job 1). Nil values are honest gaps, never interpolated across.
struct TrendMetricChart: View {
    let points: [(date: String, value: Double?)]
    let color: Color
    var unit: String = ""
    var average: Double? = nil
    var yDomain: ClosedRange<Double>? = nil
    var decimals: Int = 0
    @State private var selected: Int?

    private var vals: [Double?] { points.map(\.value) }

    var body: some View {
        GeometryReader { geo in
            let w = geo.size.width, h = geo.size.height
            let present = vals.compactMap { $0 }
            if present.count >= 2 {
                let lo = yDomain?.lowerBound ?? max(0, (present.min() ?? 0) - (present.max()! - present.min()!) * 0.15)
                let hi = yDomain?.upperBound ?? ((present.max() ?? 1) + (present.max()! - present.min()!) * 0.15 + 0.001)
                let span = max(0.001, hi - lo)
                let n = max(1, points.count - 1)
                let x: (Int) -> CGFloat = { CGFloat($0) / CGFloat(n) * w }
                let y: (Double) -> CGFloat = { h - CGFloat(($0 - lo) / span) * h }

                ZStack(alignment: .topLeading) {
                    // Gridlines.
                    ForEach(0..<3) { i in
                        let gy = h * CGFloat(i) / 2
                        Path { $0.move(to: .init(x: 0, y: gy)); $0.addLine(to: .init(x: w, y: gy)) }
                            .stroke(Color.white.opacity(0.05), lineWidth: 0.5)
                    }
                    // Period average — drawn ON the chart.
                    if let average, average >= lo, average <= hi {
                        Path { $0.move(to: .init(x: 0, y: y(average))); $0.addLine(to: .init(x: w, y: y(average))) }
                            .stroke(color.opacity(0.4), style: StrokeStyle(lineWidth: 1, dash: [4, 4]))
                    }
                    // Area fill + line, breaking at nil (real gaps).
                    area(w: w, h: h, x: x, y: y)
                    line(x: x, y: y)
                    // Emphasized endpoint.
                    if let li = vals.lastIndex(where: { $0 != nil }), let v = vals[li] {
                        Circle().fill(color).frame(width: 7, height: 7).position(x: x(li), y: y(v))
                            .shadow(color: color.opacity(0.6), radius: 4)
                    }
                    // Scrub highlight + the day's readout.
                    if let s = selected, let v = vals[s] {
                        Path { $0.move(to: .init(x: x(s), y: 0)); $0.addLine(to: .init(x: x(s), y: h)) }
                            .stroke(Color.white.opacity(0.25), lineWidth: 1)
                        Circle().stroke(color, lineWidth: 2).background(Circle().fill(Theme.Palette.bg))
                            .frame(width: 10, height: 10).position(x: x(s), y: y(v))
                        HStack(spacing: 5) {
                            Text(Self.shortDate(points[s].date)).foregroundStyle(Theme.Palette.textDim)
                            Text((decimals > 0 ? String(format: "%.\(decimals)f", v) : "\(Int(v.rounded()))") + unit)
                                .foregroundStyle(color).fontWeight(.semibold)
                        }
                        .font(Theme.Font.micro).padding(.horizontal, 8).padding(.vertical, 4)
                        .background(Theme.Palette.card, in: Capsule()).overlay(Capsule().strokeBorder(Theme.Palette.cardStroke))
                        .padding(6)
                    }
                }
                .contentShape(Rectangle())
                .gesture(DragGesture(minimumDistance: 0).onChanged { g in
                    let i = max(0, min(points.count - 1, Int((g.location.x / max(1, w)) * CGFloat(n) + 0.5)))
                    if vals[i] != nil { if selected != i { Haptic.tap() }; selected = i }
                }.onEnded { _ in selected = nil })
            } else {
                Text("Not enough data yet").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
                    .frame(maxWidth: .infinity, maxHeight: .infinity)
            }
        }
    }

    /// Value + date readout for the scrubbed point (shown above the chart by the caller).
    var readout: (label: String, value: String)? {
        guard let s = selected, let v = vals[s] else { return nil }
        return (Self.shortDate(points[s].date), (decimals > 0 ? String(format: "%.\(decimals)f", v) : "\(Int(v.rounded()))") + unit)
    }

    private func area(w: CGFloat, h: CGFloat, x: (Int) -> CGFloat, y: (Double) -> CGFloat) -> some View {
        Path { p in
            var open = false
            for (i, v) in vals.enumerated() {
                guard let v else { open = false; continue }
                if !open { p.move(to: .init(x: x(i), y: h)); p.addLine(to: .init(x: x(i), y: y(v))); open = true }
                else { p.addLine(to: .init(x: x(i), y: y(v))) }
            }
        }
        .fill(LinearGradient(colors: [color.opacity(0.26), color.opacity(0.02)], startPoint: .top, endPoint: .bottom))
    }

    private func line(x: @escaping (Int) -> CGFloat, y: @escaping (Double) -> CGFloat) -> some View {
        Path { p in
            var open = false
            for (i, v) in vals.enumerated() {
                guard let v else { open = false; continue }
                open ? p.addLine(to: .init(x: x(i), y: y(v))) : p.move(to: .init(x: x(i), y: y(v)))
                open = true
            }
        }.stroke(color, style: StrokeStyle(lineWidth: 2.5, lineCap: .round, lineJoin: .round))
    }

    static func shortDate(_ s: String) -> String {
        let f = DateFormatter(); f.dateFormat = "yyyy-MM-dd"
        guard let d = f.date(from: s) else { return s }
        let o = DateFormatter(); o.dateFormat = "MMM d"; o.locale = .current
        return o.string(from: d)
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
/// One gesture over the hero timeline: a DRAG scrubs a live per-epoch readout (`fraction`); a TAP (a
/// press that barely moves) selects the whole stage SEGMENT under the finger (`selected` = its run-start
/// index) with a haptic, and tapping the same segment again clears it. Disabled on the mini variant.
private struct TimelineInteraction: ViewModifier {
    let enabled: Bool
    let width: CGFloat
    let count: Int
    @Binding var fraction: CGFloat?
    @Binding var selected: Int?
    let runStart: (Int) -> Int

    func body(content: Content) -> some View {
        guard enabled, width > 0, count > 0 else { return AnyView(content) }
        return AnyView(content.gesture(
            DragGesture(minimumDistance: 0)
                .onChanged { v in fraction = min(1, max(0, v.location.x / width)) }
                .onEnded { v in
                    let moved = abs(v.translation.width) + abs(v.translation.height)
                    if moved < 8 {   // a tap, not a scrub → sticky-select the segment
                        let frac = min(1, max(0, v.location.x / width))
                        let idx = min(count - 1, max(0, Int(frac * CGFloat(count))))
                        let start = runStart(idx)
                        if selected == start { selected = nil } else { selected = start; Haptic.tap() }
                    }
                    fraction = nil
                }
        ))
    }
}
