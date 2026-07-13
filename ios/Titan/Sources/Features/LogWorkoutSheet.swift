import SwiftUI

/// Manually log a workout the band didn't record — a gym session, or a run you did without the watch.
/// Deliberately minimal: type + duration + when + intensity. No HR/route/calories (there's no sensor
/// data), but it still counts for the streak and — from duration × intensity — registers on strain.
struct LogWorkoutSheet: View {
    @EnvironmentObject var model: AppModel
    @Environment(\.dismiss) private var dismiss

    /// Called after a successful save so the parent list refreshes.
    let onSaved: () async -> Void

    @State private var type = "run"
    @State private var minutes = 45
    @State private var when = Date()
    @State private var intensity = "moderate"
    @State private var saving = false
    @State private var errorText: String?

    private let types: [(key: String, label: LocalizedStringKey, icon: String)] = [
        ("run", "Run", "figure.run"),
        ("cycle", "Ride", "bicycle"),
        ("walk", "Walk", "figure.walk"),
        ("strength", "Gym", "dumbbell.fill"),
        ("other", "Other", "figure.mixed.cardio"),
    ]
    private let presets = [15, 30, 45, 60, 90]
    private let intensities: [(key: String, label: LocalizedStringKey, color: Color)] = [
        ("easy", "Easy", Theme.Palette.mint),
        ("moderate", "Moderate", Theme.Palette.cyan),
        ("hard", "Hard", Theme.Palette.pink),
    ]

    var body: some View {
        NavigationStack {
            ZStack {
                Theme.Palette.bg.ignoresSafeArea()
                ScrollView {
                    VStack(alignment: .leading, spacing: Theme.Space.l) {
                        Text("Log a workout you did without your band — it still counts toward your streak and strain.")
                            .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)

                        field("TYPE") {
                            LazyVGrid(columns: [GridItem(.adaptive(minimum: 92), spacing: 8)], spacing: 8) {
                                ForEach(types, id: \.key) { t in
                                    typeCard(t)
                                }
                            }
                        }

                        field("DURATION") {
                            VStack(spacing: Theme.Space.s) {
                                HStack {
                                    Text("\(minutes)").font(Theme.Font.num(34)).foregroundStyle(Theme.Palette.text).monospacedDigit()
                                    Text("min").font(Theme.Font.body).foregroundStyle(Theme.Palette.textDim)
                                    Spacer()
                                    TitanStepper(value: $minutes, range: 1...600, step: 5)
                                }
                                HStack(spacing: 6) {
                                    ForEach(presets, id: \.self) { p in
                                        let on = minutes == p
                                        Button { Haptic.tap(); minutes = p } label: {
                                            Text("\(p)").font(Theme.Font.micro.weight(.semibold))
                                                .foregroundStyle(on ? Theme.Palette.bg : Theme.Palette.textDim)
                                                .frame(maxWidth: .infinity).padding(.vertical, 8)
                                                .background(Capsule().fill(on ? Theme.Palette.text : Theme.Palette.card))
                                        }.buttonStyle(.plain)
                                    }
                                }
                            }
                        }

                        field("INTENSITY") {
                            HStack(spacing: 8) {
                                ForEach(intensities, id: \.key) { i in
                                    let on = intensity == i.key
                                    Button { Haptic.tap(); intensity = i.key } label: {
                                        Text(i.label).font(Theme.Font.body.weight(.semibold))
                                            .foregroundStyle(on ? Theme.Palette.bg : Theme.Palette.textDim)
                                            .frame(maxWidth: .infinity).padding(.vertical, 12)
                                            .background(RoundedRectangle(cornerRadius: Theme.Radius.chip).fill(on ? i.color : Theme.Palette.card))
                                    }.buttonStyle(.plain)
                                }
                            }
                        }

                        field("WHEN") {
                            TitanDateField(selection: $when, components: [.date, .hourAndMinute])
                        }

                        if let errorText {
                            Text(errorText).font(Theme.Font.micro).foregroundStyle(Theme.Palette.pink)
                        }

                        Button(action: save) {
                            HStack(spacing: 8) {
                                if saving { ProgressView().tint(.white) }
                                // A ternary of string literals resolves to Text's VERBATIM initializer (no
                                // localization) — so branch with two real LocalizedStringKey Texts instead.
                                Group { if saving { Text("Saving…") } else { Text("Save workout") } }
                                    .font(Theme.Font.body.weight(.bold))
                            }
                            .frame(maxWidth: .infinity).padding(.vertical, 16)
                            .background(Theme.Grad.brand, in: RoundedRectangle(cornerRadius: Theme.Radius.chip))
                            .foregroundStyle(.white)
                        }
                        .disabled(saving)
                        Color.clear.frame(height: 8)
                    }
                    .padding(Theme.Space.l)
                }
            }
            .navigationTitle("Log workout").navigationBarTitleDisplayMode(.inline)
            .toolbar { ToolbarItem(placement: .cancellationAction) { Button("Cancel") { dismiss() } } }
            .toolbarColorScheme(.dark, for: .navigationBar)
        }
        .preferredColorScheme(.dark)
    }

    private func typeCard(_ t: (key: String, label: LocalizedStringKey, icon: String)) -> some View {
        let on = type == t.key
        return Button { Haptic.tap(); type = t.key } label: {
            VStack(spacing: 6) {
                Image(systemName: t.icon).font(.system(size: 20, weight: .semibold))
                Text(t.label).font(Theme.Font.micro.weight(.semibold))
            }
            .foregroundStyle(on ? Theme.Palette.bg : Theme.Palette.text)
            .frame(maxWidth: .infinity).padding(.vertical, Theme.Space.m)
            .background(RoundedRectangle(cornerRadius: Theme.Radius.chip).fill(on ? Theme.Palette.text : Theme.Palette.card))
        }.buttonStyle(.plain)
    }

    private func field(_ label: LocalizedStringKey, @ViewBuilder _ content: () -> some View) -> some View {
        VStack(alignment: .leading, spacing: Theme.Space.s) {
            Text(label).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
            content()
        }
    }

    private func save() {
        Haptic.tap()
        saving = true
        errorText = nil
        Task {
            do {
                // Only send a time if the user moved it off "now" — otherwise let the server stamp now.
                let picked = abs(when.timeIntervalSinceNow) > 60 ? when : nil
                try await model.api.logWorkout(type: type, durationMin: minutes, startedAt: picked, intensity: intensity)
                Haptic.success()
                await onSaved()
                dismiss()
            } catch {
                saving = false
                errorText = String(localized: "Couldn't save that — check your connection and try again.")
            }
        }
    }
}
