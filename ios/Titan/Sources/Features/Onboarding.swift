import SwiftUI

// MARK: - Shared form state (used by the wizard AND the edit screen)

final class OnboardingForm: ObservableObject {
    @Published var displayName = ""
    @Published var birthdate = Calendar.current.date(byAdding: .year, value: -28, to: Date()) ?? Date()
    @Published var sex = ""
    @Published var units = "metric"
    @Published var height = ""
    @Published var weight = ""
    @Published var activityLevel = ""
    @Published var primaryGoal = ""
    @Published var coachTone = ""
    @Published var coachingIntensity = "balanced"
    @Published var mealsPerDay = 4
    @Published var experience = ""
    @Published var trainAt = ""
    @Published var trainDays = 4
    @Published var diet = ""
    @Published var allergies = ""
    @Published var avoidFoods = ""
    @Published var injuries: [String] = []
    @Published var healthNotes = ""
    @Published var focusAreas: [String] = []
    @Published var motivation = ""
    @Published var cycleEnabled = false
    @Published var cycleLength = 28
    @Published var birthControl = "none"
    @Published var cycleIntent = "tracking"
    @Published var lastPeriod = Date()
    @Published var hasLastPeriod = false
    @Published var hasWearable = false

    var isFemale: Bool { sex == "F" }
    var heightUnit: String { units == "imperial" ? "in" : "cm" }
    var weightUnit: String { units == "imperial" ? "lb" : "kg" }

    func prefill(_ s: ProfileSnapshot) {
        displayName = s.display_name ?? ""
        if let b = s.birthdate, let d = OB.ymd(b) { birthdate = d }
        sex = s.sex ?? ""
        units = s.units ?? "metric"
        height = s.height.map { OB.trimNum($0) } ?? ""
        activityLevel = s.activity_level ?? ""
        primaryGoal = s.primary_goal ?? ""
        coachTone = s.coach_tone ?? ""
        coachingIntensity = s.coaching_intensity ?? "balanced"
        mealsPerDay = s.meals_per_day ?? 4
        experience = s.experience ?? ""
        trainAt = s.train_at ?? ""
        trainDays = s.train_days ?? 4
        diet = s.diet ?? ""
        allergies = s.allergies ?? ""
        avoidFoods = s.avoid_foods ?? ""
        injuries = OB.split(s.injuries)
        healthNotes = s.health_notes ?? ""
        focusAreas = OB.split(s.focus_areas)
        motivation = s.motivation ?? ""
        cycleEnabled = s.cycle_enabled ?? false
        cycleLength = s.cycle_length ?? 28
        birthControl = s.birth_control ?? "none"
        cycleIntent = s.cycle_intent ?? "tracking"
    }

    /// `full` = onboarding (requires weight + has_wearable). Edit sends `full: false`.
    func payload(full: Bool) -> [String: Any] {
        var p: [String: Any] = [
            "display_name": displayName,
            "birthdate": OB.ymdString(birthdate),
            "sex": sex,
            "units": units,
            "activity_level": activityLevel,
            "primary_goal": primaryGoal,
            "coach_tone": coachTone,
            "coaching_intensity": coachingIntensity,
            "meals_per_day": mealsPerDay,
            "train_days": trainDays,
            "timezone": TimeZone.current.identifier,
            "injuries": injuries.joined(separator: "|"),
            "focus_areas": focusAreas.joined(separator: "|"),
        ]
        if let h = Double(height) { p["height"] = h }
        if full, let w = Double(weight) { p["weight"] = w }
        for (k, v) in [("experience", experience), ("train_at", trainAt), ("diet", diet),
                       ("allergies", allergies), ("avoid_foods", avoidFoods),
                       ("health_notes", healthNotes), ("motivation", motivation)] where !v.isEmpty {
            p[k] = v
        }
        if isFemale {
            p["cycle_enabled"] = cycleEnabled
            if cycleEnabled {
                p["cycle_length"] = cycleLength
                p["birth_control"] = birthControl
                p["cycle_intent"] = cycleIntent
                if hasLastPeriod { p["last_period"] = OB.ymdString(lastPeriod) }   // also on edit, to anchor the cycle
            }
        }
        if full { p["has_wearable"] = hasWearable }
        return p
    }
}

// MARK: - Option catalog + helpers

enum OB {
    static let goals = [("build_muscle", "Build muscle"), ("lose_fat", "Lose fat / get lean"), ("recomp", "Recomposition (lean + strong)"), ("longevity", "Longevity & healthspan"), ("performance", "Athletic performance"), ("general", "General health & energy")]
    static let sexes = [("F", "Female"), ("M", "Male"), ("other", "Other")]
    static let units = [("metric", "Metric · kg / cm"), ("imperial", "Imperial · lb / in")]
    static let activity = [("sedentary", "Sedentary — desk job, little exercise"), ("light", "Lightly active — 1–3 days a week"), ("moderate", "Moderately active — 3–5 days"), ("active", "Very active — 6–7 days")]
    static let tones = [("tough_love", "Tough love — push me, no excuses"), ("balanced", "Balanced — honest + supportive"), ("gentle", "Gentle — warm + encouraging")]
    static let intensity = [("minimal", "Light touch — stay out of my way"), ("balanced", "Balanced — a few daily nudges"), ("intense", "All-in — coach me all day")]
    static let experience = [("beginner", "New / returning"), ("intermediate", "Intermediate"), ("advanced", "Advanced")]
    static let trainAt = [("full_gym", "Full gym"), ("home_weights", "Home with weights"), ("bodyweight", "Bodyweight only"), ("mix", "A mix")]
    static let diets = [("omnivore", "Omnivore"), ("vegetarian", "Vegetarian"), ("vegan", "Vegan"), ("pescatarian", "Pescatarian"), ("keto", "Keto"), ("halal", "Halal")]
    static let birthControl = [("none", "None"), ("pill", "Pill"), ("patch", "Patch"), ("ring", "Ring"), ("hormonal_iud", "Hormonal IUD"), ("copper_iud", "Copper IUD"), ("implant", "Implant"), ("injection", "Injection"), ("other", "Other")]
    static let cycleIntent = [("tracking", "Just tracking"), ("conceiving", "Trying to conceive"), ("avoiding", "Avoiding pregnancy")]
    static let focusSuggestions = ["Belly", "Arms", "Chest", "Back", "Legs", "Glutes", "Shoulders", "Core"]
    static let injurySuggestions = ["Lower back", "Knee", "Shoulder", "Wrist", "Neck", "Hip"]

    static func ymd(_ s: String) -> Date? { let f = DateFormatter(); f.dateFormat = "yyyy-MM-dd"; return f.date(from: s) }
    static func ymdString(_ d: Date) -> String { let f = DateFormatter(); f.dateFormat = "yyyy-MM-dd"; return f.string(from: d) }
    static func trimNum(_ v: Double) -> String { v == v.rounded() ? "\(Int(v))" : "\(v)" }
    static func split(_ s: String?) -> [String] { (s ?? "").split(separator: "|").map { $0.trimmingCharacters(in: .whitespaces) }.filter { !$0.isEmpty } }
}

// MARK: - Reusable inputs

/// A vertical list of selectable option cards (single choice).
struct OBOptionList: View {
    let options: [(String, String)]
    @Binding var selection: String
    var body: some View {
        VStack(spacing: Theme.Space.s) {
            ForEach(options, id: \.0) { opt in
                Button { Haptic.tap(); selection = opt.0 } label: {
                    HStack {
                        Text(opt.1).font(Theme.Font.body).foregroundStyle(selection == opt.0 ? .white : Theme.Palette.text)
                        Spacer()
                        if selection == opt.0 { Image(systemName: "checkmark.circle.fill").foregroundStyle(.white) }
                    }
                    .padding(.horizontal, Theme.Space.m).padding(.vertical, 14)
                    .background(selection == opt.0 ? AnyShapeStyle(Theme.Grad.brand) : AnyShapeStyle(Theme.Palette.card),
                                in: RoundedRectangle(cornerRadius: Theme.Radius.chip))
                    .overlay(RoundedRectangle(cornerRadius: Theme.Radius.chip).strokeBorder(selection == opt.0 ? .clear : Theme.Palette.cardStroke))
                }
                .buttonStyle(PressCard())
            }
        }
    }
}

/// Multi-select chips with a custom add field.
struct OBChips: View {
    let suggestions: [String]
    @Binding var selected: [String]
    @State private var custom = ""
    private let cols = [GridItem(.adaptive(minimum: 92), spacing: 8)]

    var body: some View {
        VStack(spacing: Theme.Space.s) {
            LazyVGrid(columns: cols, spacing: 8) {
                ForEach(allChips, id: \.self) { chip in
                    let on = selected.contains(chip)
                    Button {
                        Haptic.tap()
                        if on { selected.removeAll { $0 == chip } } else { selected.append(chip) }
                    } label: {
                        Text(chip).font(Theme.Font.micro).foregroundStyle(on ? .white : Theme.Palette.textDim)
                            .frame(maxWidth: .infinity, minHeight: 32).padding(.horizontal, 8)
                            .background(on ? Theme.Palette.indigo : Theme.Palette.card, in: Capsule())
                            .overlay(Capsule().strokeBorder(on ? .clear : Theme.Palette.cardStroke))
                    }.buttonStyle(.plain)
                }
            }
            HStack {
                TextField("Add your own…", text: $custom).font(Theme.Font.micro).foregroundStyle(Theme.Palette.text)
                    .onSubmit(addCustom)
                if !custom.isEmpty { Button("Add", action: addCustom).font(Theme.Font.micro).foregroundStyle(Theme.Palette.indigo) }
            }
            .padding(.horizontal, 12).padding(.vertical, 9)
            .background(Theme.Palette.card, in: Capsule()).overlay(Capsule().strokeBorder(Theme.Palette.cardStroke))
        }
    }

    private var allChips: [String] {
        var list = suggestions
        for s in selected where !list.contains(s) { list.append(s) }
        return list
    }
    private func addCustom() {
        let t = custom.trimmingCharacters(in: .whitespaces)
        if !t.isEmpty && !selected.contains(t) { selected.append(t) }
        custom = ""
    }
}

/// A labeled text field for the edit screen / free-text steps.
struct OBTextField: View {
    let label: String
    @Binding var text: String
    var keyboard: UIKeyboardType = .default
    var body: some View {
        VStack(alignment: .leading, spacing: 5) {
            Text(label.uppercased()).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
            TextField("", text: $text).font(Theme.Font.body).foregroundStyle(Theme.Palette.text).keyboardType(keyboard)
                .padding(12).background(Theme.Palette.card, in: RoundedRectangle(cornerRadius: Theme.Radius.chip))
                .overlay(RoundedRectangle(cornerRadius: Theme.Radius.chip).strokeBorder(Theme.Palette.cardStroke))
        }
    }
}
