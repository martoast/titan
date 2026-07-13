import SwiftUI

/// The Whoop-style Overview: your Recovery, Sleep Performance and Strain history over a week or month,
/// each a first-class branded chart (gradient area + date axis + drawn average + scrub) — matching the
/// sleep/heart charts, not stock Swift Charts (UI_POLISH job 1).
struct TrendsView: View {
    @EnvironmentObject var model: AppModel

    var body: some View {
        let o = model.overview
        VStack(spacing: Theme.Space.m) {
            // Week / Month toggle — the app's own PillSwitch, not a stock .segmented Picker.
            PillSwitch(options: [(7, "Week"), (30, "Month")],
                       selection: Binding(get: { model.overviewDays }, set: { d in Task { await model.setOverviewDays(d) } }))
                .padding(.top, Theme.Space.s)

            if let o {
                trendCard("Recovery", avg: o.averages.recovery, unit: "%", color: Theme.Palette.mint) {
                    TrendMetricChart(points: o.points.map { ($0.date, $0.recovery.map(Double.init)) },
                                     color: Theme.Palette.mint, unit: "%", average: o.averages.recovery, yDomain: 0...100)
                        .frame(height: 120)
                }
                trendCard("Sleep Performance", avg: o.averages.sleep_performance, unit: "%", color: Theme.Palette.indigo) {
                    TrendMetricChart(points: o.points.map { ($0.date, $0.sleep_performance.map(Double.init)) },
                                     color: Theme.Palette.indigo, unit: "%", average: o.averages.sleep_performance, yDomain: 0...100)
                        .frame(height: 120)
                }
                trendCard("Day Strain", avg: o.averages.strain, unit: "", color: Theme.Palette.cyan, decimals: 1) {
                    TrendMetricChart(points: o.points.map { ($0.date, $0.strain) },
                                     color: Theme.Palette.cyan, average: o.averages.strain, yDomain: 0...21, decimals: 1)
                        .frame(height: 120)
                }
                trendCard("HRV", avg: o.averages.hrv, unit: "ms", color: Theme.Palette.cyan) {
                    TrendMetricChart(points: o.points.map { ($0.date, $0.hrv.map(Double.init)) },
                                     color: Theme.Palette.cyan, unit: "ms", average: o.averages.hrv)
                        .frame(height: 110)
                }
                trendCard("Resting Heart Rate", avg: o.averages.rhr, unit: "bpm", color: Theme.Palette.pink) {
                    TrendMetricChart(points: o.points.map { ($0.date, $0.rhr.map(Double.init)) },
                                     color: Theme.Palette.pink, unit: "bpm", average: o.averages.rhr)
                        .frame(height: 110)
                }
                trendCard("Hours of Sleep", avg: o.averages.sleep_h, unit: "h", color: Theme.Palette.violet, decimals: 1) {
                    TrendMetricChart(points: o.points.map { ($0.date, $0.sleep_h) },
                                     color: Theme.Palette.violet, unit: "h", average: o.averages.sleep_h, decimals: 1)
                        .frame(height: 110)
                }
            } else {
                ProgressView().tint(Theme.Palette.indigo).frame(maxWidth: .infinity, minHeight: 200)
            }
            Color.clear.frame(height: 8)
        }
        .titanScreen("Trends", glow: Theme.Palette.violet)
        .task { if model.overview == nil { await model.loadOverview() } }
    }

    @ViewBuilder
    private func trendCard(_ title: LocalizedStringKey, avg: Double?, unit: String, color: Color, decimals: Int = 0, @ViewBuilder chart: () -> some View) -> some View {
        GlassCard {
            VStack(alignment: .leading, spacing: Theme.Space.s) {
                HStack(alignment: .firstTextBaseline) {
                    Text(title).textCase(.uppercase).font(Theme.Font.label).tracking(0.8).foregroundStyle(Theme.Palette.textDim)
                    Spacer()
                    if let avg {
                        Text(decimals > 0 ? String(format: "%.1f", avg) : "\(Int(avg))")
                            .font(Theme.Font.num(22)).foregroundStyle(color)
                        Text(unit).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
                        Text("avg").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                    }
                }
                chart()
            }
        }
    }
}
