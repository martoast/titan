import SwiftUI

// MARK: - The first-run wizard

struct OnboardingView: View {
    @EnvironmentObject var model: AppModel
    @StateObject private var form = OnboardingForm()
    @State private var index = 0
    @State private var submitting = false

    enum Step { case language, name, body, activity, goal, focus, training, nutrition, health, tone, intensity, meals, cycle, wearable }

    private var steps: [Step] {
        var s: [Step] = [.language, .name, .body, .activity, .goal, .focus, .training, .nutrition, .health, .tone, .intensity, .meals]
        if form.isFemale { s.append(.cycle) }
        s.append(.wearable)
        return s
    }
    private var step: Step { steps[min(index, steps.count - 1)] }
    private var isLast: Bool { index >= steps.count - 1 }
    private var progress: CGFloat { CGFloat(index + 1) / CGFloat(steps.count) }

    var body: some View {
        ZStack {
            Theme.Palette.bg.ignoresSafeArea()
            Theme.Grad.glow(Theme.Palette.indigo).frame(height: 320).opacity(0.45).offset(y: -150).ignoresSafeArea()

            VStack(spacing: Theme.Space.m) {
                GeometryReader { geo in
                    ZStack(alignment: .leading) {
                        Capsule().fill(Theme.Palette.card).frame(height: 5)
                        Capsule().fill(Theme.Grad.brand).frame(width: geo.size.width * progress, height: 5)
                            .animation(Theme.Motion.snappy, value: progress)
                    }
                }.frame(height: 5)

                ScrollView {
                    VStack(alignment: .leading, spacing: Theme.Space.m) {
                        Text(title).font(Theme.Font.display(28)).foregroundStyle(Theme.Palette.text)
                        if let sub = subtitle {
                            Text(sub).font(Theme.Font.body).foregroundStyle(Theme.Palette.textDim)
                        }
                        content.padding(.top, Theme.Space.s)
                        Color.clear.frame(height: 8)
                    }
                    .frame(maxWidth: .infinity, alignment: .leading).padding(.top, Theme.Space.s)
                }
                .scrollIndicators(.hidden)
                .id(index)   // reset scroll per step

                HStack(spacing: Theme.Space.m) {
                    if index > 0 {
                        Button { Haptic.tap(); withAnimation { index -= 1 } } label: {
                            Image(systemName: "chevron.left").font(.body.weight(.semibold)).foregroundStyle(Theme.Palette.textDim)
                                .frame(width: 54, height: 54).background(Theme.Palette.card, in: Circle())
                                .overlay(Circle().strokeBorder(Theme.Palette.cardStroke))
                        }
                    }
                    Button(action: advance) {
                        HStack(spacing: 8) {
                            if submitting { ProgressView().tint(.white) }
                            Text(advanceLabel).font(Theme.Font.body.weight(.bold))
                        }
                        .frame(maxWidth: .infinity).padding(.vertical, 16)
                        .background(canContinue ? AnyShapeStyle(Theme.Grad.brand) : AnyShapeStyle(Theme.Palette.card),
                                    in: RoundedRectangle(cornerRadius: Theme.Radius.chip))
                        .foregroundStyle(canContinue ? .white : Theme.Palette.textDim)
                    }
                    .disabled(!canContinue || submitting)
                }
            }
            .padding(Theme.Space.l)
        }
        .preferredColorScheme(.dark)
        .onAppear { if form.displayName.isEmpty { form.displayName = model.user?.name?.split(separator: " ").first.map(String.init) ?? "" } }
    }

    private func advance() {
        Haptic.tap()
        if isLast {
            Task { submitting = true; _ = await model.completeOnboarding(form.payload(full: true)); submitting = false }
        } else {
            withAnimation { index += 1 }
        }
    }

    private var advanceLabel: LocalizedStringKey {
        isLast ? (submitting ? "Building your Titan…" : "Finish") : "Continue"
    }

    private var canContinue: Bool {
        switch step {
        case .name: return !form.displayName.trimmingCharacters(in: .whitespaces).isEmpty
        case .body: return form.sex != "" && Double(form.height) != nil && Double(form.weight) != nil
        case .activity: return form.activityLevel != ""
        case .goal: return form.primaryGoal != ""
        case .tone: return form.coachTone != ""
        default: return true
        }
    }

    private var title: LocalizedStringKey {
        switch step {
        case .language: return "Choose your language"
        case .name: return "Let's build your Titan."
        case .body: return "About you."
        case .activity: return "How active are you?"
        case .goal: return "What's your main goal?"
        case .focus: return "Where do you want change?"
        case .training: return "Your training profile."
        case .nutrition: return "Your nutrition style."
        case .health: return "Anything to work around?"
        case .tone: return "How should I push you?"
        case .intensity: return "Your coach, all day?"
        case .meals: return "How many meals a day?"
        case .cycle: return "Track your cycle?"
        case .wearable: return "Got your band?"
        }
    }

    private var subtitle: LocalizedStringKey? {
        switch step {
        case .language: return "You can change this anytime in Settings."
        case .name: return "First — what should I call you?"
        case .body: return "So your scores, macros and bio-age are tuned to you."
        case .focus: return "Pick the areas you care about most. Optional."
        case .training, .nutrition, .health: return "All optional — but it makes your coach sharper."
        case .meals: return "I'll space your protein + calories across the day."
        case .wearable: return "If it's in hand, we'll connect it right after setup."
        default: return nil
        }
    }

    @ViewBuilder private var content: some View {
        switch step {
        case .language:
            LanguageChoiceCards()
        case .name:
            OBTextField(label: String(localized: "Your name"), text: $form.displayName)
        case .body:
            VStack(spacing: Theme.Space.m) {
                VStack(alignment: .leading, spacing: 5) {
                    Text("BIRTHDATE").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                    TitanDateField(selection: $form.birthdate)
                }
                inlineLabel("SEX"); OBOptionList(options: OB.sexes, selection: $form.sex)
                inlineLabel("UNITS"); OBOptionList(options: OB.units, selection: $form.units)
                HStack(spacing: Theme.Space.m) {
                    OBTextField(label: String(localized: "Height (\(form.heightUnit))"), text: $form.height, keyboard: .decimalPad)
                    OBTextField(label: String(localized: "Weight (\(form.weightUnit))"), text: $form.weight, keyboard: .decimalPad)
                }
            }
        case .activity: OBOptionList(options: OB.activity, selection: $form.activityLevel)
        case .goal: OBOptionList(options: OB.goals, selection: $form.primaryGoal)
        case .focus:
            VStack(spacing: Theme.Space.m) {
                OBChips(suggestions: OB.focusSuggestions, selected: $form.focusAreas)
                OBTextField(label: String(localized: "What's driving you? (optional)"), text: $form.motivation)
            }
        case .training:
            VStack(spacing: Theme.Space.m) {
                inlineLabel("EXPERIENCE"); OBOptionList(options: OB.experience, selection: $form.experience)
                inlineLabel("YOU TRAIN AT"); OBOptionList(options: OB.trainAt, selection: $form.trainAt)
                stepperRow("Days per week", value: $form.trainDays, range: 0...7)
            }
        case .nutrition:
            VStack(spacing: Theme.Space.m) {
                inlineLabel("DIET"); OBOptionList(options: OB.diets, selection: $form.diet)
                OBTextField(label: String(localized: "Allergies / intolerances (optional)"), text: $form.allergies)
                OBTextField(label: String(localized: "Foods you won't eat (optional)"), text: $form.avoidFoods)
            }
        case .health:
            VStack(spacing: Theme.Space.m) {
                OBChips(suggestions: OB.injurySuggestions, selected: $form.injuries)
                OBTextField(label: String(localized: "Anything else I should know? (optional)"), text: $form.healthNotes)
            }
        case .tone: OBOptionList(options: OB.tones, selection: $form.coachTone)
        case .intensity: OBOptionList(options: OB.intensity, selection: $form.coachingIntensity)
        case .meals: stepperRow("Meals per day", value: $form.mealsPerDay, range: 2...6)
        case .cycle:
            VStack(spacing: Theme.Space.m) {
                Toggle("Track my cycle", isOn: $form.cycleEnabled).tint(Theme.Palette.pink)
                    .font(Theme.Font.body).foregroundStyle(Theme.Palette.text)
                if form.cycleEnabled {
                    stepperRow("Average cycle length", value: $form.cycleLength, range: 21...45, unit: "days")
                    inlineLabel("BIRTH CONTROL"); OBOptionList(options: OB.birthControl, selection: $form.birthControl)
                    inlineLabel("INTENT"); OBOptionList(options: OB.cycleIntent, selection: $form.cycleIntent)
                    Toggle("Set my last period start", isOn: $form.hasLastPeriod).tint(Theme.Palette.pink)
                        .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                    if form.hasLastPeriod {
                        TitanDateField(selection: $form.lastPeriod, accent: Theme.Palette.pink)
                    }
                }
            }
        case .wearable:
            VStack(spacing: Theme.Space.m) {
                Toggle("My band is in hand", isOn: $form.hasWearable).tint(Theme.Palette.mint)
                    .font(Theme.Font.body).foregroundStyle(Theme.Palette.text)
                Text(wearableNote)
                    .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
            }
        }
    }

    private var wearableNote: LocalizedStringKey {
        form.hasWearable
            ? "We'll head to pairing right after this so your coach reads recovery from night one."
            : "No band yet? No problem — log by photo + chat, and add a band anytime."
    }

    private func inlineLabel(_ s: LocalizedStringKey) -> some View {
        Text(s).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim).frame(maxWidth: .infinity, alignment: .leading)
    }
    private func stepperRow(_ label: LocalizedStringKey, value: Binding<Int>, range: ClosedRange<Int>, unit: String = "") -> some View {
        HStack {
            Text(label).font(Theme.Font.body).foregroundStyle(Theme.Palette.text)
            Spacer()
            TitanStepper(value: value, range: range, unit: unit)
        }
        .padding(.horizontal, Theme.Space.m).padding(.vertical, 10)
        .background(Theme.Palette.card, in: RoundedRectangle(cornerRadius: Theme.Radius.chip))
    }
}

// MARK: - Edit profile (change anything from onboarding later)

struct EditProfileView: View {
    @EnvironmentObject var model: AppModel
    @Environment(\.dismiss) private var dismiss
    @StateObject private var form = OnboardingForm()
    @State private var loaded = false
    @State private var saving = false
    @State private var saveTask: Task<Void, Never>?

    var body: some View {
        NavigationStack {
            ZStack {
                Theme.Palette.bg.ignoresSafeArea()
                ScrollView {
                    VStack(spacing: Theme.Space.m) {
                        section("About you") {
                            OBTextField(label: String(localized: "Name"), text: $form.displayName)
                            label("Birthdate"); TitanDateField(selection: $form.birthdate)
                            label("Sex"); OBOptionList(options: OB.sexes, selection: $form.sex)
                            label("Units"); OBOptionList(options: OB.units, selection: $form.units)
                            OBTextField(label: String(localized: "Height (\(form.heightUnit))"), text: $form.height, keyboard: .decimalPad)
                        }
                        section("Goal & coaching") {
                            label("Main goal"); OBOptionList(options: OB.goals, selection: $form.primaryGoal)
                            label("Focus areas"); OBChips(suggestions: OB.focusSuggestions, selected: $form.focusAreas)
                            OBTextField(label: String(localized: "Motivation"), text: $form.motivation)
                            label("Coach tone"); OBOptionList(options: OB.tones, selection: $form.coachTone)
                            label("Coaching intensity"); OBOptionList(options: OB.intensity, selection: $form.coachingIntensity)
                        }
                        section("Training") {
                            label("Experience"); OBOptionList(options: OB.experience, selection: $form.experience)
                            label("You train at"); OBOptionList(options: OB.trainAt, selection: $form.trainAt)
                        }
                        section("Nutrition") {
                            label("Diet"); OBOptionList(options: OB.diets, selection: $form.diet)
                            OBTextField(label: String(localized: "Allergies / intolerances"), text: $form.allergies)
                            OBTextField(label: String(localized: "Foods you won't eat"), text: $form.avoidFoods)
                        }
                        section("Health") {
                            label("Injuries / areas to respect"); OBChips(suggestions: OB.injurySuggestions, selected: $form.injuries)
                            OBTextField(label: String(localized: "Notes"), text: $form.healthNotes)
                        }
                        if form.isFemale {
                            section("Cycle") {
                                TitanToggle(title: "Track my cycle", isOn: $form.cycleEnabled, accent: Theme.Palette.pink)
                                if form.cycleEnabled {
                                    TitanToggle(title: "Log my last period", isOn: $form.hasLastPeriod, accent: Theme.Palette.pink)
                                    if form.hasLastPeriod {
                                        label("First day of last period"); TitanDateField(selection: $form.lastPeriod, accent: Theme.Palette.pink)
                                    }
                                    HStack {
                                        Text("Average cycle length").font(Theme.Font.body).foregroundStyle(Theme.Palette.text)
                                        Spacer()
                                        TitanStepper(value: $form.cycleLength, range: 21...45, unit: "days")
                                    }
                                    label("Birth control"); OBOptionList(options: OB.birthControl, selection: $form.birthControl)
                                    label("Intent"); OBOptionList(options: OB.cycleIntent, selection: $form.cycleIntent)
                                }
                            }
                        }

                        HStack(spacing: 6) {
                            if saving { ProgressView().controlSize(.mini).tint(Theme.Palette.textFaint) }
                            else { Image(systemName: "checkmark.circle.fill").font(.caption).foregroundStyle(Theme.Palette.mint) }
                            Text(saveStatus).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
                        }.frame(maxWidth: .infinity).padding(.vertical, 4)
                        Color.clear.frame(height: 8)
                    }.padding(Theme.Space.m)
                }
            }
            .navigationTitle("Edit profile").navigationBarTitleDisplayMode(.inline)
            .toolbar { ToolbarItem(placement: .confirmationAction) { Button("Done") { dismiss() } } }
            .toolbarColorScheme(.dark, for: .navigationBar)
            .task {
                if !loaded, let snap = await model.loadProfileSnapshot() { form.prefill(snap); loaded = true }
            }
            // Auto-save: debounce changes so each edit persists without a Save button.
            .onReceive(form.objectWillChange) { _ in
                guard loaded else { return }      // ignore the burst from prefill()
                saveTask?.cancel()
                saveTask = Task {
                    try? await Task.sleep(nanoseconds: 700_000_000)
                    if Task.isCancelled { return }
                    saving = true
                    _ = await model.saveProfile(form.payload(full: false))
                    saving = false
                }
            }
        }
    }

    private var saveStatus: LocalizedStringKey { saving ? "Saving…" : "Changes save automatically" }

    private func label(_ s: LocalizedStringKey) -> some View {
        Text(s).textCase(.uppercase).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim).frame(maxWidth: .infinity, alignment: .leading)
    }
    @ViewBuilder private func section(_ title: LocalizedStringResource, @ViewBuilder _ content: () -> some View) -> some View {
        GlassCard {
            VStack(alignment: .leading, spacing: Theme.Space.s) {
                SectionHeader(title: String(localized: title))
                content()
            }
        }
    }
}
