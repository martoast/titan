import SwiftUI

/// Workouts are captured by the band (double-tap the button) or logged via the coach, then classified
/// server-side. v1 surfaces live state + guidance; a history list lands with the workouts read API.
struct WorkoutsView: View {
    @EnvironmentObject var model: AppModel

    var body: some View {
        VStack(spacing: Theme.Space.m) {
            GlassCard(padding: Theme.Space.l) {
                VStack(spacing: Theme.Space.m) {
                    HStack {
                        PulseDot(on: model.bandConnected)
                        Text(model.bandConnected ? "Band streaming" : "Band offline")
                            .font(Theme.Font.body).foregroundStyle(Theme.Palette.text)
                        Spacer()
                        if let bpm = model.liveBpm, model.bandConnected {
                            HStack(spacing: 5) {
                                Image(systemName: "heart.fill").foregroundStyle(Theme.Palette.pink).font(.caption)
                                Text("\(bpm)").font(Theme.Font.num(20)).contentTransition(.numericText())
                            }
                        }
                    }
                }
            }

            GlassCard {
                VStack(alignment: .leading, spacing: Theme.Space.m) {
                    SectionHeader(title: "Start a workout")
                    row("hand.tap.fill", Theme.Palette.cyan, "Double-tap the band button", "Starts a session (engages workout HR) — double-tap again to end it.")
                    row("bubble.left.and.text.bubble.right.fill", Theme.Palette.indigo, "Or tell the coach", "\u{201C}starting a run\u{201D} · \u{201C}log my lift\u{201D}")
                }
            }

            GlassCard {
                VStack(alignment: .leading, spacing: Theme.Space.s) {
                    SectionHeader(title: "Recent")
                    VStack(spacing: 6) {
                        Image(systemName: "figure.strengthtraining.traditional")
                            .font(.system(size: 30)).foregroundStyle(Theme.Palette.textFaint)
                        Text("No sessions yet").font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.text)
                        Text("Start one above — training load, strain and recovery cost land here automatically.")
                            .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim).multilineTextAlignment(.center)
                    }
                    .frame(maxWidth: .infinity).padding(.vertical, Theme.Space.s)
                }
            }
            Color.clear.frame(height: 8)
        }
        .titanScreen("Train", glow: Theme.Palette.cyan)
    }

    private func row(_ icon: String, _ c: Color, _ title: String, _ sub: String) -> some View {
        HStack(alignment: .top, spacing: Theme.Space.m) {
            Image(systemName: icon).foregroundStyle(c).frame(width: 24)
            VStack(alignment: .leading, spacing: 2) {
                Text(title).font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.text)
                Text(sub).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
            }
        }
    }
}
