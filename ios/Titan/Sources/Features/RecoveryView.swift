import SwiftUI
import TitanCore

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
                            if let caveat = c.caveat ?? c.level?.capitalized {
                                Text(caveat).font(Theme.Font.body).foregroundStyle(Theme.Palette.text)
                            } else {
                                Text("Confidence").font(Theme.Font.body).foregroundStyle(Theme.Palette.text)
                            }
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
        .titanDetail("Recovery", glow: Theme.Palette.mint)
        .animation(Theme.Motion.snappy, value: model.dashboardPhase)
        .task { await model.refresh(); await model.loadTrends() }
    }

    private func feel(_ label: LocalizedStringKey, _ v: Int?, _ icon: String, _ c: Color) -> some View {
        VStack(spacing: 5) {
            Image(systemName: icon).font(.system(size: 15)).foregroundStyle(c)
            Text(v.map { "\($0)" } ?? "—").font(Theme.Font.num(20))
            Text(label).textCase(.uppercase).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
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
        let d = model.sleepDetail?.detail
        let fallback = model.dashboard?.sleep
        VStack(spacing: Theme.Space.m) {
            // Hero: sleep-performance ring + hours slept vs need.
            VStack(spacing: Theme.Space.s) {
                StatRing(value: (d?.performance_pct ?? model.dashboard?.rings?.sleep_performance).map(Double.init),
                         max: 100, label: "Sleep Performance", color: Theme.Palette.indigo, size: 150)
                    .padding(.top, Theme.Space.s)
                HStack(spacing: 6) {
                    Text(minToHrs(d?.duration_min ?? fallback?.duration_min))
                        .font(Theme.Font.num(30)).foregroundStyle(Theme.Palette.text)
                    if let need = d?.need_h {
                        Text("/ \(hrsText(need)) needed").font(Theme.Font.body).foregroundStyle(Theme.Palette.textDim)
                    }
                }
            }.frame(maxWidth: .infinity)

            // HERO: the full interactive stage timeline — the night as a story (spec §2.2). Scrub for a
            // "3:12 AM · Deep sleep" tooltip; NODATA renders as honest hatched gaps. Totals live below.
            if d != nil {
                GlassCard {
                    VStack(alignment: .leading, spacing: Theme.Space.s) {
                        SectionHeader(title: "Sleep timeline")
                        SleepTimeline(stages: d?.hypnogram ?? [],
                                      epochSec: d?.epoch_sec,
                                      bedtime: d?.bedtime, wakeTime: d?.wake_time,
                                      computing: d?.stage_status == "computing",
                                      interactive: true,
                                      hrSeries: d?.hr_series, motionSeries: d?.motion_series)
                    }
                }
            }

            // Stages — a proportional bar + per-stage minutes & %. Colors route through the shared enum.
            GlassCard {
                VStack(alignment: .leading, spacing: Theme.Space.m) {
                    SectionHeader(title: "Sleep stages")
                    let stages = d?.stages ?? fallbackStages(fallback)
                    GeometryReader { geo in
                        let total = max(1, stages.reduce(0) { $0 + $1.min })
                        HStack(spacing: 2) {
                            ForEach(stages) { st in
                                Capsule().fill(SleepStage.color(forCode: st.key))
                                    .frame(width: max(2, geo.size.width * CGFloat(st.min) / CGFloat(total)))
                            }
                        }
                    }.frame(height: 14)
                    VStack(spacing: Theme.Space.s) {
                        ForEach(stages) { st in
                            HStack(spacing: Theme.Space.s) {
                                Circle().fill(SleepStage.color(forCode: st.key)).frame(width: 8, height: 8)
                                Text(st.label).font(Theme.Font.body).foregroundStyle(Theme.Palette.text)
                                Spacer()
                                Text("\(st.pct)%").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim).frame(width: 40, alignment: .trailing)
                                Text(minToHrs(st.min)).font(Theme.Font.body).foregroundStyle(Theme.Palette.text).frame(width: 56, alignment: .trailing)
                            }
                        }
                    }
                }
            }

            // Key metrics — the Whoop headline stats.
            GlassCard {
                VStack(alignment: .leading, spacing: Theme.Space.m) {
                    SectionHeader(title: "Metrics")
                    let cols = [GridItem(.flexible()), GridItem(.flexible()), GridItem(.flexible())]
                    LazyVGrid(columns: cols, spacing: Theme.Space.l) {
                        sleepStat("Efficiency", d?.efficiency_pct.map { "\($0)" }, "%", Theme.Palette.mint)
                        sleepStat("Restorative", d?.restorative_min.map(minToHrs), nil, Theme.Palette.violet)
                        sleepStat("Consistency", d?.consistency_pct.map { "\($0)" }, "%", Theme.Palette.cyan)
                        sleepStat("Sleep debt", d?.debt_h.map { hrsText($0) }, nil, Theme.Palette.amber)
                        sleepStat("Respiration", d?.respiratory_rate.map { String(format: "%.1f", $0) }, "br/m", Theme.Palette.pink)
                        sleepStat("Quality", (d?.quality ?? fallback?.quality).map { "\($0)" }, nil, Theme.Palette.indigo)
                    }
                }
            }

            if let advice = model.sleepDetail?.assess?.advice {
                GlassCard {
                    HStack(spacing: Theme.Space.m) {
                        Image(systemName: "moon.zzz.fill").foregroundStyle(Theme.Palette.indigo).font(.title3)
                        Text(advice).font(Theme.Font.body).foregroundStyle(Theme.Palette.text)
                        Spacer(minLength: 0)
                    }
                }
            }
            Color.clear.frame(height: 8)
        }
        .titanDetail("Sleep", glow: Theme.Palette.indigo)
        .task { await model.loadSleepDetail() }
    }

    private func sleepStat(_ label: LocalizedStringKey, _ value: String?, _ unit: String?, _ color: Color) -> some View {
        VStack(spacing: 4) {
            HStack(alignment: .firstTextBaseline, spacing: 2) {
                Text(value ?? "—").font(Theme.Font.num(22)).foregroundStyle(color)
                if let unit, value != nil { Text(unit).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint) }
            }
            Text(label).textCase(.uppercase).font(Theme.Font.micro).tracking(0.5).foregroundStyle(Theme.Palette.textDim)
        }.frame(maxWidth: .infinity)
    }

    private func hrsText(_ h: Double) -> String {
        let m = Int((h * 60).rounded()); return "\(m / 60)h \(m % 60)m"
    }
    private func fallbackStages(_ s: Dashboard.Sleep?) -> [SleepResponse.Detail.Stage] {
        let raw = [("deep", "Deep (SWS)", s?.deep_min ?? 0), ("rem", "REM", s?.rem_min ?? 0),
                   ("light", "Light", s?.light_min ?? 0), ("awake", "Awake", s?.awake_min ?? 0)]
        let total = max(1, raw.reduce(0) { $0 + $1.2 })
        return raw.map { .init(key: $0.0, label: $0.1, min: $0.2, pct: Int(Double($0.2) / Double(total) * 100), color: $0.0) }
    }
}
