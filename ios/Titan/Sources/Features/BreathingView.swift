import SwiftUI

/// A guided-breathing intervention — the delightful half of the Stress Monitor. An animated orb paces
/// the breath: a **physiological sigh** (double-inhale, long exhale) to down-regulate when stressed, or
/// **box breathing** to steady focus. Screen-only and self-contained; the coach's stress card deep-links
/// here with the pattern preselected.
struct BreathingView: View {
    @Environment(\.dismiss) private var dismiss
    let pattern: BreathPattern

    @State private var phaseIndex = 0
    @State private var running = false
    @State private var elapsed = 0.0
    @State private var scale = 0.55
    @State private var finished = false

    /// Drives the cycle — one tick advances the animation clock; phase changes are computed from elapsed.
    private let tick = Timer.publish(every: 0.05, on: .main, in: .common).autoconnect()
    private let targetSeconds = 90.0

    private var phases: [BreathPhase] { pattern.phases }
    private var phase: BreathPhase { phases[phaseIndex] }

    var body: some View {
        ZStack {
            Theme.Palette.bg.ignoresSafeArea()
            RadialGradient(colors: [pattern.color.opacity(0.28), .clear], center: .center, startRadius: 20, endRadius: 320)
                .ignoresSafeArea().opacity(running ? 1 : 0.4).animation(.easeInOut(duration: 1), value: running)

            VStack(spacing: Theme.Space.l) {
                HStack {
                    Button { dismiss() } label: {
                        Image(systemName: "xmark").font(.body.weight(.semibold)).foregroundStyle(Theme.Palette.textDim)
                            .frame(width: 40, height: 40).background(Theme.Palette.card, in: Circle())
                    }
                    Spacer()
                }

                Spacer()

                Text(pattern.title).font(Theme.Font.display(24)).foregroundStyle(Theme.Palette.text)
                Text(pattern.subtitle).font(Theme.Font.body).foregroundStyle(Theme.Palette.textDim)
                    .multilineTextAlignment(.center).padding(.horizontal, Theme.Space.l)

                Spacer()

                ZStack {
                    Circle().fill(pattern.color.opacity(0.14)).frame(width: 240, height: 240)
                    Circle().fill(pattern.color.opacity(0.22))
                        .frame(width: 240, height: 240).scaleEffect(scale)
                    Circle().strokeBorder(pattern.color.opacity(0.5), lineWidth: 2)
                        .frame(width: 240, height: 240).scaleEffect(scale)
                    Text(finished ? "Nice." : (running ? phase.label : "Ready?"))
                        .font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.text)
                }
                .frame(height: 260)

                Spacer()

                if finished {
                    Text("That's the reset. Your nervous system just shifted — notice how you feel.")
                        .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                        .multilineTextAlignment(.center).padding(.horizontal, Theme.Space.l)
                }

                Button(action: toggle) {
                    Text(buttonLabel).font(Theme.Font.body.weight(.bold)).foregroundStyle(.white)
                        .frame(maxWidth: .infinity).padding(.vertical, 16)
                        .background(pattern.color.gradient, in: RoundedRectangle(cornerRadius: Theme.Radius.chip))
                }
                Color.clear.frame(height: 8)
            }
            .padding(Theme.Space.l)
        }
        .preferredColorScheme(.dark)
        .onReceive(tick) { _ in advance() }
        .onDisappear { running = false }
    }

    private var buttonLabel: LocalizedStringKey {
        if finished { return "Done" }
        return running ? "Stop" : "Begin"
    }

    private func toggle() {
        Haptic.tap()
        if finished { dismiss(); return }
        running.toggle()
        if running {
            elapsed = 0; phaseIndex = 0
            withAnimation(.easeInOut(duration: phase.seconds)) { scale = phase.scale }
        } else {
            withAnimation(.easeInOut(duration: 0.4)) { scale = 0.55 }
        }
    }

    /// Advance the animation clock; when the current phase elapses, step to the next and re-pace the orb.
    private func advance() {
        guard running else { return }
        elapsed += 0.05
        if elapsed >= targetSeconds {
            running = false; finished = true
            Haptic.success()
            withAnimation(.easeInOut(duration: 0.6)) { scale = 0.55 }
            return
        }
        // Time within the current phase.
        if phaseElapsed >= phase.seconds {
            phaseAccum += phase.seconds
            phaseIndex = (phaseIndex + 1) % phases.count
            Haptic.tap()
            withAnimation(.easeInOut(duration: phase.seconds)) { scale = phase.scale }
        }
    }

    @State private var phaseAccum = 0.0
    private var phaseElapsed: Double { elapsed - phaseAccum }
}

/// A breathing pattern = an ordered list of phases (label, seconds, orb target scale) + its framing.
enum BreathPattern: String, Identifiable {
    case physiologicalSigh
    case box

    var id: String { rawValue }

    var title: LocalizedStringKey {
        switch self {
        case .physiologicalSigh: return "Physiological sigh"
        case .box: return "Box breathing"
        }
    }
    var subtitle: LocalizedStringKey {
        switch self {
        case .physiologicalSigh: return "Two breaths in through the nose, one long breath out. The fastest way to calm down."
        case .box: return "In, hold, out, hold — even and steady. Settles a racing mind."
        }
    }
    var color: Color {
        switch self {
        case .physiologicalSigh: return Theme.Palette.cyan
        case .box: return Theme.Palette.indigo
        }
    }
    var phases: [BreathPhase] {
        switch self {
        case .physiologicalSigh:
            return [
                .init(label: "Breathe in", seconds: 2.0, scale: 0.9),
                .init(label: "Sip more in", seconds: 1.0, scale: 1.0),
                .init(label: "Long breath out", seconds: 5.0, scale: 0.5),
            ]
        case .box:
            return [
                .init(label: "Breathe in", seconds: 4.0, scale: 1.0),
                .init(label: "Hold", seconds: 4.0, scale: 1.0),
                .init(label: "Breathe out", seconds: 4.0, scale: 0.5),
                .init(label: "Hold", seconds: 4.0, scale: 0.5),
            ]
        }
    }
}

struct BreathPhase {
    let label: LocalizedStringKey
    let seconds: Double
    let scale: Double
}
