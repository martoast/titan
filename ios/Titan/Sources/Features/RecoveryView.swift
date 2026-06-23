import SwiftUI

struct RecoveryView: View {
    @EnvironmentObject var model: AppModel
    var body: some View {
        let r = model.dashboard?.recovery
        VStack(spacing: 16) {
            Card("Vitals") {
                HStack {
                    StatTile(value: r?.hrv_ms.map { String(Int($0)) } ?? "—", label: "HRV ms", accent: .cyan)
                    StatTile(value: r?.resting_hr.map { String(Int($0)) } ?? "—", label: "Resting HR", accent: .pink)
                    StatTile(value: r?.resp_rate.map { String(format: "%.1f", $0) } ?? "—", label: "Resp")
                }
            }
            if let c = r?.confidence {
                Card("Confidence") {
                    Text(c.caveat ?? (c.level?.capitalized ?? "—")).font(.subheadline).foregroundStyle(.secondary)
                    if let n = c.nights_of_data { Text("\(n) nights of baseline").font(.caption).foregroundStyle(.tertiary) }
                }
            }
            Card("How you feel") {
                HStack {
                    StatTile(value: r?.energy.map { "\($0)" } ?? "—", label: "Energy")
                    StatTile(value: r?.mood.map { "\($0)" } ?? "—", label: "Mood")
                    StatTile(value: r?.stress.map { "\($0)" } ?? "—", label: "Stress")
                    StatTile(value: r?.soreness.map { "\($0)" } ?? "—", label: "Soreness")
                }
            }
            if let via = r?.updated_via {
                Text("Source: \(via)").font(.caption2).foregroundStyle(.tertiary).frame(maxWidth: .infinity, alignment: .leading)
            }
        }
        .padding(16)
        .navigationTitle("Recovery")
    }
}

struct SleepView: View {
    @EnvironmentObject var model: AppModel
    var body: some View {
        let s = model.dashboard?.sleep
        VStack(spacing: 16) {
            Card("Last night") {
                HStack {
                    StatTile(value: minToHrs(s?.duration_min), label: "Asleep", accent: .indigo)
                    StatTile(value: s?.quality.map { "\($0)" } ?? "—", label: "Quality")
                }
            }
            Card("Stages") {
                HStack {
                    StatTile(value: minToHrs(s?.deep_min), label: "Deep", accent: .blue)
                    StatTile(value: minToHrs(s?.rem_min), label: "REM", accent: .purple)
                    StatTile(value: minToHrs(s?.light_min), label: "Light")
                    StatTile(value: minToHrs(s?.awake_min), label: "Awake")
                }
            }
        }
        .padding(16)
        .navigationTitle("Sleep")
    }
}
