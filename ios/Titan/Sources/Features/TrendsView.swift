import SwiftUI
import Charts

/// The Whoop-style Overview: your Recovery, Sleep Performance and Strain history over a week or month,
/// each as a daily graph with its period average — plus the HRV / Resting HR trends underneath.
struct TrendsView: View {
    @EnvironmentObject var model: AppModel

    var body: some View {
        let o = model.overview
        VStack(spacing: Theme.Space.m) {
            // Week / Month toggle.
            Picker("Range", selection: Binding(get: { model.overviewDays }, set: { d in Task { await model.setOverviewDays(d) } })) {
                Text("Week").tag(7)
                Text("Month").tag(30)
            }
            .pickerStyle(.segmented)
            .padding(.top, Theme.Space.s)

            if let o {
                trendCard("Recovery", avg: o.averages.recovery, unit: "%", color: Theme.Palette.mint) {
                    Chart(o.points) { p in
                        BarMark(x: .value("d", p.date), y: .value("recovery", p.recovery ?? 0))
                            .foregroundStyle(Theme.Palette.recovery(p.recovery))
                            .cornerRadius(3)
                    }
                    .chartYScale(domain: 0...100)
                    .chartXAxis(.hidden).frame(height: 120)
                }
                trendCard("Sleep Performance", avg: o.averages.sleep_performance, unit: "%", color: Theme.Palette.indigo) {
                    Chart(o.points) { p in
                        BarMark(x: .value("d", p.date), y: .value("sleep", p.sleep_performance ?? 0))
                            .foregroundStyle(Theme.Palette.indigo).cornerRadius(3)
                    }
                    .chartYScale(domain: 0...100)
                    .chartXAxis(.hidden).frame(height: 120)
                }
                trendCard("Day Strain", avg: o.averages.strain, unit: "", color: Theme.Palette.cyan) {
                    Chart(o.points) { p in
                        BarMark(x: .value("d", p.date), y: .value("strain", p.strain ?? 0))
                            .foregroundStyle(Theme.Palette.cyan.opacity(0.85)).cornerRadius(3)
                    }
                    .chartYScale(domain: 0...21)
                    .chartXAxis(.hidden).frame(height: 120)
                }
                // HRV + Resting HR — the two headline vitals over time (line trends).
                trendCard("HRV", avg: o.averages.hrv, unit: "ms", color: Theme.Palette.cyan) {
                    lineChart(o.points.map { ($0.date, $0.hrv.map(Double.init)) }, color: Theme.Palette.cyan)
                }
                trendCard("Resting Heart Rate", avg: o.averages.rhr, unit: "bpm", color: Theme.Palette.pink) {
                    lineChart(o.points.map { ($0.date, $0.rhr.map(Double.init)) }, color: Theme.Palette.pink)
                }
                trendCard("Hours of Sleep", avg: o.averages.sleep_h, unit: "h", color: Theme.Palette.violet, decimals: 1) {
                    Chart(o.points) { p in
                        BarMark(x: .value("d", p.date), y: .value("h", p.sleep_h ?? 0))
                            .foregroundStyle(Theme.Palette.violet.opacity(0.8)).cornerRadius(3)
                    }
                    .chartXAxis(.hidden).frame(height: 110)
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
    private func trendCard(_ title: String, avg: Double?, unit: String, color: Color, decimals: Int = 0, @ViewBuilder chart: () -> some View) -> some View {
        GlassCard {
            VStack(alignment: .leading, spacing: Theme.Space.s) {
                HStack(alignment: .firstTextBaseline) {
                    Text(title.uppercased()).font(Theme.Font.label).tracking(0.8).foregroundStyle(Theme.Palette.textDim)
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

    private func lineChart(_ points: [(String, Double?)], color: Color) -> some View {
        Chart {
            ForEach(Array(points.enumerated()), id: \.offset) { _, pt in
                if let v = pt.1 {
                    LineMark(x: .value("d", pt.0), y: .value("v", v))
                        .interpolationMethod(.monotone)
                        .lineStyle(.init(lineWidth: 2.5, lineCap: .round))
                        .foregroundStyle(color)
                    PointMark(x: .value("d", pt.0), y: .value("v", v))
                        .foregroundStyle(color).symbolSize(18)
                }
            }
        }
        .chartXAxis(.hidden).frame(height: 110)
    }
}
