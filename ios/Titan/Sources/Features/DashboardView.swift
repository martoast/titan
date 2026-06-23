import SwiftUI

/// Today: readiness ring + recovery + sleep + activity, from `GET /api/me/dashboard`.
struct DashboardView: View {
    @EnvironmentObject var model: AppModel

    var body: some View {
        VStack(spacing: 16) {
            let d = model.dashboard

            Card {
                HStack(spacing: 20) {
                    ScoreRing(score: d?.readiness?.score, label: "Readiness")
                    VStack(alignment: .leading, spacing: 6) {
                        Text(d?.readiness?.label ?? "No data yet").font(.headline)
                        if let note = d?.readiness?.note {
                            Text(note).font(.subheadline).foregroundStyle(.secondary).lineLimit(4)
                        }
                        if d?.readiness?.provisional == true {
                            Label("Still building your baseline", systemImage: "hourglass")
                                .font(.caption).foregroundStyle(.orange)
                        }
                    }
                }
            }

            NavigationLink { RecoveryView() } label: {
                Card("Recovery") {
                    HStack {
                        StatTile(value: d?.recovery?.hrv_ms.map { String(Int($0)) } ?? "—", label: "HRV ms", accent: .cyan)
                        StatTile(value: d?.recovery?.resting_hr.map { String(Int($0)) } ?? "—", label: "Resting HR", accent: .pink)
                        StatTile(value: d?.recovery?.resp_rate.map { String(format: "%.1f", $0) } ?? "—", label: "Resp rate")
                    }
                }
            }.buttonStyle(.plain)

            NavigationLink { SleepView() } label: {
                Card("Last night's sleep") {
                    HStack {
                        StatTile(value: minToHrs(d?.sleep?.duration_min), label: "Duration", accent: .indigo)
                        StatTile(value: d?.sleep?.quality.map { "\($0)" } ?? "—", label: "Quality")
                        StatTile(value: minToHrs(d?.sleep?.rem_min), label: "REM")
                        StatTile(value: minToHrs(d?.sleep?.deep_min), label: "Deep")
                    }
                }
            }.buttonStyle(.plain)

            if let a = d?.activity {
                Card("Activity") {
                    HStack {
                        StatTile(value: a.steps.map(String.init) ?? "—", label: "Steps")
                        StatTile(value: a.active_kcal.map(String.init) ?? "—", label: "Active kcal")
                        StatTile(value: a.floors.map(String.init) ?? "—", label: "Floors")
                    }
                }
            }
        }
        .screen("Today")
        .refreshable { await model.refresh() }
        .task { await model.refresh() }
    }
}
