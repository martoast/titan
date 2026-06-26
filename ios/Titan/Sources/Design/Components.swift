import SwiftUI
import Charts

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
            HStack(spacing: Theme.Space.m) {
                ForEach(stages.indices, id: \.self) { i in
                    HStack(spacing: 5) {
                        Circle().fill(stages[i].2).frame(width: 7, height: 7)
                        Text(stages[i].0).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                        Text(minToHrs(stages[i].1)).font(Theme.Font.micro).foregroundStyle(Theme.Palette.text)
                    }
                }
                Spacer()
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
                    Text(message).font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.text)
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
