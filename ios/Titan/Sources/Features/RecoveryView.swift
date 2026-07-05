import SwiftUI

struct RecoveryView: View {
    @EnvironmentObject var model: AppModel
    var body: some View {
        let r = model.dashboard?.recovery
        VStack(spacing: Theme.Space.m) {
            if model.dashboardPhase == .failed && model.dashboard == nil {
                SyncErrorRow(message: "Couldn't sync recovery") { await model.refresh() }
            }
            MetricRing(score: model.dashboard?.readiness?.score, label: "Recovery", size: 180).padding(.top, 6)

            // Whoop-style breakdown: each metric with its personal baseline + a trend arrow.
            if let metrics = r?.metrics, !metrics.isEmpty {
                GlassCard {
                    VStack(spacing: 0) {
                        SectionHeader(title: "Recovery breakdown", trailing: r?.updated_via)
                        ForEach(metrics) { m in
                            RecoveryMetricRow(metric: m)
                            if m.id != metrics.last?.id { Divider().overlay(Theme.Palette.textFaint.opacity(0.25)) }
                        }
                    }
                }
            } else {
                GlassCard {
                    VStack(alignment: .leading, spacing: Theme.Space.m) {
                        SectionHeader(title: "Vitals", trailing: r?.updated_via)
                        HStack(spacing: Theme.Space.m) {
                            Metric(value: r?.hrv_ms.map { "\(Int($0))" } ?? "—", unit: "ms", label: "HRV", color: Theme.Palette.cyan, icon: "waveform.path.ecg")
                            Metric(value: r?.resting_hr.map { "\(Int($0))" } ?? "—", unit: "bpm", label: "Resting HR", color: Theme.Palette.pink, icon: "heart.fill")
                            Metric(value: r?.resp_rate.map { String(format: "%.1f", $0) } ?? "—", unit: "br/m", label: "Respiration", color: Theme.Palette.violet, icon: "lungs.fill")
                        }
                    }
                }
            }

            if model.hrvTrend.count > 1 {
                GlassCard {
                    VStack(alignment: .leading, spacing: Theme.Space.s) {
                        SectionHeader(title: "HRV — 30 days")
                        TrendChart(points: model.hrvTrend, color: Theme.Palette.cyan)
                    }
                }
            }

            if let c = r?.confidence {
                GlassCard {
                    HStack(spacing: Theme.Space.m) {
                        Image(systemName: confidenceIcon(c.level)).font(.title3).foregroundStyle(Theme.Palette.amber)
                        VStack(alignment: .leading, spacing: 3) {
                            Text(c.caveat ?? (c.level?.capitalized ?? "Confidence")).font(Theme.Font.body).foregroundStyle(Theme.Palette.text)
                            if let n = c.nights_of_data { Text("\(n) nights of baseline").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim) }
                        }
                    }
                }
            }

            GlassCard {
                VStack(alignment: .leading, spacing: Theme.Space.m) {
                    SectionHeader(title: "How you feel")
                    HStack(spacing: Theme.Space.m) {
                        feel("Energy", r?.energy, "bolt.fill", Theme.Palette.amber)
                        feel("Mood", r?.mood, "face.smiling", Theme.Palette.mint)
                        feel("Stress", r?.stress, "exclamationmark.triangle.fill", Theme.Palette.pink)
                        feel("Soreness", r?.soreness, "figure.strengthtraining.traditional", Theme.Palette.violet)
                    }
                }
            }
            Color.clear.frame(height: 8)
        }
        .padding(.horizontal, Theme.Space.m)
        .background(Theme.Palette.bg.ignoresSafeArea())
        .navigationTitle("Recovery")
        .animation(Theme.Motion.snappy, value: model.dashboardPhase)
        .task { await model.refresh(); await model.loadTrends() }
    }

    private func feel(_ label: String, _ v: Int?, _ icon: String, _ c: Color) -> some View {
        VStack(spacing: 5) {
            Image(systemName: icon).font(.system(size: 15)).foregroundStyle(c)
            Text(v.map { "\($0)" } ?? "—").font(Theme.Font.num(20))
            Text(label.uppercased()).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
        }.frame(maxWidth: .infinity)
    }
    private func confidenceIcon(_ l: String?) -> String {
        switch l { case "high": return "checkmark.seal.fill"; case "low": return "questionmark.circle.fill"; default: return "hourglass" }
    }
}

/// One Whoop-style recovery row: "Heart Rate Variability   65 ▼ / 92" — the value big, a trend arrow
/// coloured by whether it moved the healthy way, and the personal baseline underneath.
struct RecoveryMetricRow: View {
    let metric: Dashboard.Recovery.Metric

    private var valueText: String {
        metric.unit == "br/min" ? String(format: "%.1f", metric.value) : "\(Int(metric.value.rounded()))"
    }
    private var arrow: String { metric.trend == "up" ? "arrow.up" : (metric.trend == "down" ? "arrow.down" : "minus") }
    private var arrowColor: Color {
        switch metric.good { case .some(true): return Theme.Palette.mint; case .some(false): return Theme.Palette.amber; default: return Theme.Palette.textDim }
    }
    private var icon: String {
        switch metric.key {
        case "hrv": return "waveform.path.ecg"
        case "rhr": return "heart.fill"
        case "resp": return "lungs.fill"
        default: return "moon.stars.fill"
        }
    }

    var body: some View {
        HStack(spacing: Theme.Space.m) {
            Image(systemName: icon).font(.system(size: 15)).foregroundStyle(Theme.Palette.textDim).frame(width: 22)
            Text(metric.label).font(Theme.Font.body).foregroundStyle(Theme.Palette.text)
            Spacer()
            VStack(alignment: .trailing, spacing: 1) {
                HStack(spacing: 4) {
                    Text(valueText).font(Theme.Font.num(22)).foregroundStyle(Theme.Palette.text)
                    Text(metric.unit == "%" ? "%" : "").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                    Image(systemName: arrow).font(.system(size: 12, weight: .bold)).foregroundStyle(arrowColor)
                }
                if let b = metric.baseline {
                    Text(metric.unit == "br/min" ? String(format: "%.1f", b) : "\(Int(b.rounded()))")
                        .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                }
            }
        }
        .padding(.vertical, Theme.Space.s)
    }
}

struct SleepView: View {
    @EnvironmentObject var model: AppModel
    var body: some View {
        let s = model.dashboard?.sleep
        VStack(spacing: Theme.Space.m) {
            GlassCard {
                VStack(spacing: Theme.Space.s) {
                    HStack(alignment: .firstTextBaseline, spacing: 6) {
                        Image(systemName: "moon.stars.fill").foregroundStyle(Theme.Palette.indigo)
                        Text(minToHrs(s?.duration_min)).font(Theme.Font.num(44)).foregroundStyle(Theme.Palette.text)
                        Spacer()
                        if let q = s?.quality {
                            VStack { CountUp(value: q, font: Theme.Font.num(28), color: Theme.Palette.indigo); Text("QUALITY").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim) }
                        }
                    }
                }
            }
            GlassCard {
                VStack(alignment: .leading, spacing: Theme.Space.m) {
                    SectionHeader(title: "Sleep stages")
                    StageBars(stages: [
                        ("Deep", s?.deep_min ?? 0, Theme.Palette.indigo),
                        ("REM", s?.rem_min ?? 0, Theme.Palette.violet),
                        ("Light", s?.light_min ?? 0, Theme.Palette.cyan.opacity(0.6)),
                        ("Awake", s?.awake_min ?? 0, Theme.Palette.textFaint),
                    ])
                }
            }
            Color.clear.frame(height: 8)
        }
        .padding(.horizontal, Theme.Space.m)
        .background(Theme.Palette.bg.ignoresSafeArea())
        .navigationTitle("Sleep")
    }
}
