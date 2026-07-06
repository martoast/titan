import SwiftUI
import Charts

/// The Whoop-style Strain screen: today's Day Strain (0-21) building through the day, the recovery-based
/// TARGET band (push / maintain / hold back), and the workouts that drove it — each with the strain it
/// added. Mirrors Whoop's "your strain builds as you train, and gets harder to raise as the day goes on".
struct StrainView: View {
    @EnvironmentObject var model: AppModel

    private let strainColor = Theme.Palette.cyan

    var body: some View {
        let s = model.strainDetail
        VStack(spacing: Theme.Space.m) {
            // A failed cold fetch is legible + recoverable, not a lone "—" ring that dead-ends.
            if model.strainPhase == .failed && s == nil {
                SyncErrorRow(message: "Couldn't load strain") { await model.loadStrain() }
            } else if model.strainPhase == .loading && s == nil {
                SkeletonCard()
            }
            // Hero: the strain ring (0-21).
            VStack(spacing: Theme.Space.s) {
                StatRing(value: s?.strain, max: s?.max ?? 21, label: "Day Strain", color: strainColor, size: 160)
                    .padding(.top, Theme.Space.s)
                if let label = s?.label { Text(label).font(Theme.Font.title).foregroundStyle(Theme.Palette.text) }
            }.frame(maxWidth: .infinity)

            // Target band from this morning's recovery.
            if let t = s?.target {
                GlassCard {
                    VStack(alignment: .leading, spacing: Theme.Space.s) {
                        SectionHeader(title: "Today's target", trailing: t.label)
                        TargetBar(strain: s?.strain ?? 0, low: t.low ?? 0, high: t.high ?? 0, max: s?.max ?? 21, color: strainColor)
                        // The concrete session to hit target — Whoop's "a 30-min Z2 run gets you there".
                        if let sug = s?.suggestion {
                            HStack(spacing: Theme.Space.s) {
                                Image(systemName: "figure.run").foregroundStyle(strainColor)
                                Text("A ~\(sug.minutes)-min \(sug.zone) (\(sug.label)) session gets you there — \(strainStr(sug.strain_to_go)) to go.")
                                    .font(Theme.Font.body).foregroundStyle(Theme.Palette.text)
                            }
                            .padding(Theme.Space.s)
                            .frame(maxWidth: .infinity, alignment: .leading)
                            .background(strainColor.opacity(0.12), in: RoundedRectangle(cornerRadius: 10))
                        }
                        if let advice = s?.advice {
                            Text(advice).font(Theme.Font.body).foregroundStyle(Theme.Palette.textDim)
                        }
                    }
                }
            }

            // The strain building through the day.
            if let curve = s?.curve, curve.count > 1 {
                GlassCard {
                    VStack(alignment: .leading, spacing: Theme.Space.s) {
                        SectionHeader(title: "Building through the day")
                        Chart {
                            if let t = s?.target {
                                RectangleMark(yStart: .value("low", t.low ?? 0), yEnd: .value("high", t.high ?? 0))
                                    .foregroundStyle(Theme.Palette.mint.opacity(0.12))
                            }
                            ForEach(Array(curve.enumerated()), id: \.offset) { i, p in
                                LineMark(x: .value("i", i), y: .value("strain", p.strain))
                                    .interpolationMethod(.monotone)
                                    .lineStyle(.init(lineWidth: 2.5, lineCap: .round))
                                    .foregroundStyle(strainColor)
                                AreaMark(x: .value("i", i), y: .value("strain", p.strain))
                                    .interpolationMethod(.monotone)
                                    .foregroundStyle(LinearGradient(colors: [strainColor.opacity(0.25), .clear], startPoint: .top, endPoint: .bottom))
                            }
                        }
                        .chartYScale(domain: 0...(s?.max ?? 21))
                        .chartXAxis(.hidden)
                        .frame(height: 130)
                    }
                }
            }

            // Workouts that drove the strain.
            if let cons = s?.contributions, !cons.isEmpty {
                GlassCard {
                    VStack(alignment: .leading, spacing: Theme.Space.m) {
                        SectionHeader(title: "What drove it")
                        ForEach(cons) { c in
                            HStack(spacing: Theme.Space.m) {
                                Image(systemName: icon(c.activity_type)).foregroundStyle(strainColor).frame(width: 24)
                                VStack(alignment: .leading, spacing: 2) {
                                    Text(c.title ?? (c.activity_type?.capitalized ?? "Workout")).font(Theme.Font.body).foregroundStyle(Theme.Palette.text)
                                    Text(subtitle(c)).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                                }
                                Spacer()
                                if let added = c.strain_added {
                                    Text("+\(strainStr(added))").font(Theme.Font.num(20)).foregroundStyle(strainColor)
                                }
                            }
                            if c.id != cons.last?.id { Divider().overlay(Theme.Palette.textFaint.opacity(0.25)) }
                        }
                    }
                }
            } else {
                GlassCard {
                    HStack(spacing: Theme.Space.m) {
                        Image(systemName: "figure.walk").foregroundStyle(Theme.Palette.textDim)
                        Text("No workouts yet today — your strain is from daily movement.").font(Theme.Font.body).foregroundStyle(Theme.Palette.textDim)
                        Spacer(minLength: 0)
                    }
                }
            }
            Color.clear.frame(height: 8)
        }
        .titanDetail("Strain", glow: Theme.Palette.cyan)
        .task { await model.loadStrain() }
    }

    private func strainStr(_ v: Double) -> String { v == v.rounded() ? "\(Int(v))" : String(format: "%.1f", v) }
    private func icon(_ type: String?) -> String {
        switch type {
        case "run": return "figure.run"
        case "walk", "hike": return "figure.walk"
        case "cycle": return "figure.outdoor.cycle"
        case "strength": return "figure.strengthtraining.traditional"
        default: return "bolt.heart"
        }
    }
    private func subtitle(_ c: StrainResponse.Contribution) -> String {
        var bits: [String] = []
        if let m = c.duration_min { bits.append("\(m) min") }
        if let hr = c.avg_hr { bits.append("\(hr) bpm avg") }
        return bits.joined(separator: " · ")
    }
}

/// A horizontal 0…max bar with the recovery-based target range shaded and the current strain marked —
/// so you can see at a glance whether you're under, in, or over today's target.
struct TargetBar: View {
    let strain: Double; let low: Double; let high: Double; let max: Double; let color: Color
    var body: some View {
        GeometryReader { geo in
            let w = geo.size.width
            let x: (Double) -> CGFloat = { CGFloat(min(1, $0 / Swift.max(1, max))) * w }
            ZStack(alignment: .leading) {
                Capsule().fill(Theme.Palette.bg2).frame(height: 10)
                Capsule().fill(Theme.Palette.mint.opacity(0.35))
                    .frame(width: Swift.max(4, x(high) - x(low)), height: 10)
                    .offset(x: x(low))
                Circle().fill(color).frame(width: 16, height: 16)
                    .shadow(color: color.opacity(0.6), radius: 5)
                    .offset(x: Swift.max(0, x(strain) - 8))
            }
        }
        .frame(height: 18)
    }
}
