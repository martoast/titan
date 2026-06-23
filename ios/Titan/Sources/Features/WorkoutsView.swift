import SwiftUI

/// Workouts are captured by the band (hold the button to start/end) and classified server-side,
/// or logged via the coach ("log my lift"). v1 surfaces guidance + the live workout state; a
/// full history list lands when the workouts read endpoint is added.
struct WorkoutsView: View {
    @EnvironmentObject var model: AppModel

    var body: some View {
        VStack(spacing: 16) {
            Card("Start a workout") {
                Label("Hold the band's button to begin a session — tap again to end it.", systemImage: "figure.run")
                Label("Or tell the coach: \"starting a run\" / \"log my lift\".", systemImage: "bubble.left.fill")
            }
            .font(.subheadline)

            Card("Live") {
                HStack {
                    Circle().fill(model.bandConnected ? .green : .gray).frame(width: 10, height: 10)
                    Text(model.bandConnected ? "Band streaming" : "Band offline").font(.subheadline)
                    Spacer()
                    if let bpm = model.liveBpm { Text("\(bpm) bpm").font(.headline) }
                }
            }

            Card("Recent") {
                Text("Your synced workouts will appear here.").font(.subheadline).foregroundStyle(.secondary)
            }
        }
        .screen("Train")
    }
}
