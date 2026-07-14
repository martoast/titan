import SwiftUI
import PhotosUI

// MARK: - Fuel (a segment of the Daily hub)

/// The Fuel content — camera-first macros, hydration, fasting, meals. Lives as a segment of the
/// `Daily` hub (DailyView), which owns the scan-result + targets sheets. Body tracking (weight +
/// progress photos) lives under You › Body. `ScanResultSheet`, `TargetsSheet`, `EditMealSheet`,
/// `TargetsSheet` and `RemoteImage` below are shared by the hub.
struct FuelSection: View {
    @EnvironmentObject var model: AppModel
    @State private var editing: Meal?
    @State private var showScanner = false
    @State private var scannerUnavailable = false
    @State private var staged: StagedMealPhoto?
    @State private var showQuickAdd = false
    @AppStorage("barcodeScanEnabled") private var barcodeEnabled = true

    private var isToday: Bool { Calendar.current.isDateInToday(model.fuelDate) }

    var body: some View {
        VStack(spacing: Theme.Space.m) {
            datePager
            if isToday {
                snapHero
                if barcodeEnabled { barcodeButton }
                manualAddButton
                yourMealsCard
                macrosCard
                glucoseLink
                HydrationCard()
                FastingCard()
                mealsList
            } else {
                // A past day is review-only — the "log now" actions belong to today. Just the day's totals + meals.
                macrosCard
                if !(model.nutrition?.meals.isEmpty ?? true) { copyDayButton }
                mealsList
            }
        }
        .task { await model.loadNutrition() }
        .sheet(isPresented: $showQuickAdd) { QuickAddMealSheet() }
        .sheet(item: $editing) { EditMealSheet(meal: $0) }
        .sheet(item: $staged) { s in
            MealCaptionSheet(photo: s.data) { caption in Task { await model.scanMeal(s.data, caption: caption) } }
        }
        .fullScreenCover(isPresented: $showScanner) {
            BarcodeScannerView { code in Task { await model.scanBarcode(code) } }
        }
        .alert("Barcode scanning unavailable", isPresented: $scannerUnavailable) {
            Button("OK", role: .cancel) {}
        } message: { Text("This device can't scan barcodes. Snap the nutrition label instead.") }
    }

    /// Fuel history pager — scroll back to any past day; forward is capped at today (2.1).
    private var datePager: some View {
        HStack(spacing: Theme.Space.s) {
            pagerButton("chevron.left", enabled: true) { Task { await model.stepFuelDay(-1) } }
            Spacer()
            Text(fuelDateLabel).font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.text)
            Spacer()
            pagerButton("chevron.right", enabled: model.canFuelGoForward) { Task { await model.stepFuelDay(1) } }
        }
    }

    private func pagerButton(_ icon: String, enabled: Bool, _ action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Image(systemName: icon).font(.system(size: 15, weight: .bold))
                .foregroundStyle(enabled ? Theme.Palette.text : Theme.Palette.textFaint)
                .frame(width: 44, height: 36)
                .background(Theme.Palette.card, in: RoundedRectangle(cornerRadius: Theme.Radius.chip, style: .continuous))
                .overlay(RoundedRectangle(cornerRadius: Theme.Radius.chip, style: .continuous).strokeBorder(Theme.Palette.cardStroke))
        }.buttonStyle(.plain).disabled(!enabled)
    }

    private var fuelDateLabel: String {
        let cal = Calendar.current
        if cal.isDateInToday(model.fuelDate) { return String(localized: "Today") }
        if cal.isDateInYesterday(model.fuelDate) { return String(localized: "Yesterday") }
        let f = DateFormatter(); f.dateFormat = "EEE, MMM d"; return f.string(from: model.fuelDate)
    }

    /// The snap-a-meal hero (today only) — snap, then tell the AI what it is for accurate macros.
    private var snapHero: some View {
        PhotoSourceButton(onImage: { data in staged = StagedMealPhoto(data: data) }) {
            GlassCard(padding: Theme.Space.l) {
                HStack(spacing: Theme.Space.m) {
                    ZStack {
                        Circle().fill(Theme.Palette.amber.opacity(0.16)).frame(width: 52, height: 52)
                        Image(systemName: model.scanning ? "sparkles" : "camera.fill")
                            .font(.system(size: 22, weight: .semibold)).foregroundStyle(Theme.Palette.amber)
                            .symbolEffect(.pulse, options: model.scanning ? .repeating : .nonRepeating)
                    }
                    VStack(alignment: .leading, spacing: 2) {
                        (model.scanning ? Text("Reading your plate…") : Text("Snap a meal"))
                            .font(Theme.Font.title).foregroundStyle(Theme.Palette.text)
                        (model.scanning ? Text("Estimating macros with AI") : Text("Snap it, tell me what it is → accurate macros"))
                            .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                    }
                    Spacer()
                    if model.scanning { ProgressView().tint(Theme.Palette.amber) }
                    else { Image(systemName: "chevron.right").font(.caption).foregroundStyle(Theme.Palette.textFaint) }
                }
            }
        }
        .buttonStyle(PressCard())
        .disabled(model.scanning)
    }

    /// Scan a packaged product's barcode → exact macros from Open Food Facts, then confirm the amount.
    private var barcodeButton: some View {
        Button {
            Haptic.tap()
            if BarcodeScannerView.isAvailable { showScanner = true } else { scannerUnavailable = true }
        } label: {
            HStack(spacing: Theme.Space.s) {
                Image(systemName: "barcode.viewfinder").font(.system(size: 18, weight: .semibold)).foregroundStyle(Theme.Palette.cyan)
                VStack(alignment: .leading, spacing: 1) {
                    Text("Scan a barcode").font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.text)
                    Text("Packaged food → exact macros, saved for your coach").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                }
                Spacer()
                Image(systemName: "chevron.right").font(.caption).foregroundStyle(Theme.Palette.textFaint)
            }
            .padding(Theme.Space.m)
            .background(RoundedRectangle(cornerRadius: Theme.Radius.card, style: .continuous).fill(Theme.Palette.card))
            .overlay(RoundedRectangle(cornerRadius: Theme.Radius.card, style: .continuous).strokeBorder(Theme.Palette.cardStroke))
        }
        .buttonStyle(PressCard())
        .disabled(model.scanning)
    }

    /// Copy this past day's meals to today — for repeating diets. Re-logs them and jumps to today (2.3).
    @State private var copyingDay = false
    private var copyDayButton: some View {
        Button {
            copyingDay = true
            Task { await model.copyCurrentFuelDay(); copyingDay = false }
        } label: {
            HStack(spacing: Theme.Space.s) {
                if copyingDay { ProgressView().tint(Theme.Palette.mint) }
                else { Image(systemName: "doc.on.doc.fill").font(.system(size: 15, weight: .semibold)).foregroundStyle(Theme.Palette.mint) }
                Text("Log this day again").font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.text)
                Spacer()
                Image(systemName: "arrow.right").font(.caption).foregroundStyle(Theme.Palette.textFaint)
            }
            .padding(Theme.Space.m)
            .background(RoundedRectangle(cornerRadius: Theme.Radius.card, style: .continuous).fill(Theme.Palette.card))
            .overlay(RoundedRectangle(cornerRadius: Theme.Radius.card, style: .continuous).strokeBorder(Theme.Palette.cardStroke))
        }
        .buttonStyle(PressCard())
        .disabled(copyingDay)
    }

    /// Continuous glucose — tap through to the day's curve + time-in-range (or connect a CGM). Metabolic
    /// data lives with food; meal↔glucose overlay comes next (P2).
    private var glucoseLink: some View {
        NavigationLink { GlucoseView() } label: {
            HStack(spacing: Theme.Space.s) {
                Image(systemName: "drop.fill").font(.system(size: 18, weight: .semibold)).foregroundStyle(Theme.Palette.cyan)
                VStack(alignment: .leading, spacing: 1) {
                    Text("Glucose").font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.text)
                    Text("Your CGM curve + time in range").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                }
                Spacer()
                Image(systemName: "chevron.right").font(.caption).foregroundStyle(Theme.Palette.textFaint)
            }
            .padding(Theme.Space.m)
            .background(RoundedRectangle(cornerRadius: Theme.Radius.card, style: .continuous).fill(Theme.Palette.card))
            .overlay(RoundedRectangle(cornerRadius: Theme.Radius.card, style: .continuous).strokeBorder(Theme.Palette.cardStroke))
        }
        .buttonStyle(PressCard())
    }

    /// Manual quick-add — log a meal you didn't photograph or remember, without leaving the tab (the #1
    /// gap: this used to force a switch to coach chat). Name + kcal in seconds; P/C/F optional.
    private var manualAddButton: some View {
        Button {
            Haptic.tap(); showQuickAdd = true
        } label: {
            HStack(spacing: Theme.Space.s) {
                Image(systemName: "square.and.pencil").font(.system(size: 18, weight: .semibold)).foregroundStyle(Theme.Palette.mint)
                VStack(alignment: .leading, spacing: 1) {
                    Text("Log manually").font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.text)
                    Text("Know the numbers? Add a meal in seconds").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                }
                Spacer()
                Image(systemName: "chevron.right").font(.caption).foregroundStyle(Theme.Palette.textFaint)
            }
            .padding(Theme.Space.m)
            .background(RoundedRectangle(cornerRadius: Theme.Radius.card, style: .continuous).fill(Theme.Palette.card))
            .overlay(RoundedRectangle(cornerRadius: Theme.Radius.card, style: .continuous).strokeBorder(Theme.Palette.cardStroke))
        }
        .buttonStyle(PressCard())
    }

    /// "Your meals" — the dishes you eat, remembered. One tap re-logs a usual (no camera, no AI):
    /// the photo + macros are already saved. A horizontal shelf so your staples are always one reach away.
    @ViewBuilder private var yourMealsCard: some View {
        if !model.mealLibrary.isEmpty {
            GlassCard {
                VStack(alignment: .leading, spacing: Theme.Space.s) {
                    SectionHeader(title: "Your meals", trailing: "tap to log")
                    ScrollView(.horizontal, showsIndicators: false) {
                        HStack(spacing: Theme.Space.s) {
                            ForEach(model.mealLibrary) { t in
                                YourMealCard(template: t, busy: model.relogging == t.id) {
                                    Task { await model.relogMeal(t) }
                                }
                                .contextMenu {
                                    Button { Task { await model.relogMeal(t, portion: 1.5) } } label: { Label("Log 1.5×", systemImage: "plus.circle") }
                                    Button { Task { await model.relogMeal(t, portion: 0.5) } } label: { Label("Log ½×", systemImage: "minus.circle") }
                                    Button { Task { await model.toggleFavoriteMeal(t) } } label: {
                                        Label { t.favorite ? Text("Unfavorite") : Text("Favorite") } icon: { Image(systemName: t.favorite ? "star.slash" : "star") }
                                    }
                                    Button(role: .destructive) { Task { await model.forgetMeal(t) } } label: { Label("Forget", systemImage: "trash") }
                                }
                            }
                        }
                        .padding(.horizontal, 2).padding(.bottom, 2)
                    }
                }
            }
        }
    }

    @ViewBuilder private var macrosCard: some View {
        if let m = model.nutrition?.macros {
            GlassCard {
                VStack(spacing: Theme.Space.m) {
                    SectionHeader(title: m.title ?? "Today's fuel", trailing: m.footer)
                    // The "what's left" glance — the highest-frequency thing a daily user checks.
                    if let line = m.remaining_line, !line.isEmpty {
                        Text(verbatim: line).font(Theme.Font.body.weight(.semibold))
                            .foregroundStyle(m.over_budget == true ? Theme.Palette.pink : Theme.Palette.text)
                            .frame(maxWidth: .infinity, alignment: .leading)
                    }
                    MacroRing(line: m.calories, label: "Calories", unit: "kcal", color: Theme.Palette.cyan, size: 132)
                    HStack(spacing: Theme.Space.m) {
                        MacroRing(line: m.protein, label: "Protein", unit: "g", color: Theme.Palette.mint, size: 86)
                        MacroRing(line: m.carbs, label: "Carbs", unit: "g", color: Theme.Palette.amber, size: 86)
                        MacroRing(line: m.fat, label: "Fat", unit: "g", color: Theme.Palette.pink, size: 86)
                    }
                    // Fibre — a secondary daily stat (no target), only once a meal has reported it.
                    if let fiber = m.fiber_g {
                        HStack(spacing: 5) {
                            Image(systemName: "leaf.fill").font(.caption2).foregroundStyle(Theme.Palette.mint)
                            Text("\(fiber)g fiber today").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                        }
                    }
                }
            }
        } else {
            GlassCard { HStack { Text("Loading today's fuel…").font(Theme.Font.body).foregroundStyle(Theme.Palette.textDim); Spacer(); ProgressView().tint(Theme.Palette.textFaint) } }
        }
    }

    @ViewBuilder private var mealsList: some View {
        let meals = model.nutrition?.meals ?? []
        if meals.isEmpty {
            GlassCard {
                VStack(alignment: .leading, spacing: 4) {
                    SectionHeader(title: "Today's meals")
                    Text("Nothing logged yet. Snap your first meal — the macros land here and your coach sees them.")
                        .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
                }
            }
        } else {
            GlassCard {
                VStack(spacing: 0) {
                    SectionHeader(title: "Today's meals", trailing: "\(meals.count)")
                    // Group by meal type in day order (3.5) — breakfast / lunch / dinner / snack.
                    ForEach(Self.mealSections(meals), id: \.type) { section in
                        mealSectionHeader(section.type, section.meals)
                        ForEach(section.meals) { meal in
                            Button { Haptic.tap(); editing = meal } label: { mealRow(meal) }
                                .buttonStyle(.plain)
                                .contextMenu {
                                    Button { Haptic.tap(); editing = meal } label: { Label("Edit", systemImage: "slider.horizontal.3") }
                                    Button(role: .destructive) { Task { await model.deleteMeal(meal.id) } } label: { Label("Delete", systemImage: "trash") }
                                }
                            if meal.id != meals.last?.id { Divider().overlay(Theme.Palette.cardStroke) }
                        }
                    }
                }
            }
        }
    }

    /// Day order + labels for the sectioned list. `snack` covers afternoon/late gaps.
    private static let sectionOrder: [(type: String, label: String)] = [
        ("breakfast", "Breakfast"), ("lunch", "Lunch"), ("dinner", "Dinner"), ("snack", "Snacks"),
    ]

    /// Bucket the day's meals by type, keeping only non-empty sections in day order. A meal with an
    /// unknown/missing type falls to snacks so nothing is ever dropped.
    private static func mealSections(_ meals: [Meal]) -> [(type: String, label: String, meals: [Meal])] {
        sectionOrder.compactMap { section in
            let inSection = meals.filter { ($0.meal_type ?? "snack") == section.type }
            return inSection.isEmpty ? nil : (section.type, section.label, inSection)
        }
    }

    @ViewBuilder private func mealSectionHeader(_ type: String, _ meals: [Meal]) -> some View {
        let kcal = meals.reduce(0) { $0 + $1.calories }
        HStack {
            Text(Self.sectionOrder.first { $0.type == type }?.label ?? type.capitalized)
                .font(Theme.Font.micro.weight(.semibold)).textCase(.uppercase).tracking(0.6)
                .foregroundStyle(Theme.Palette.textDim)
            Spacer()
            Text("\(kcal) kcal").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
        }
        .padding(.top, 12).padding(.bottom, 4)
    }

    private func mealRow(_ meal: Meal) -> some View {
        HStack(spacing: Theme.Space.m) {
            // Only show a thumbnail when the meal actually has a photo — otherwise AsyncImage(nil) would
            // spin forever. A text-logged/manual meal just shows its name + macros.
            if let photo = meal.photo_url, !photo.isEmpty {
                RemoteImage(url: photo)
                    .frame(width: 50, height: 50).clipShape(RoundedRectangle(cornerRadius: 11))
            }
            VStack(alignment: .leading, spacing: 3) {
                (meal.name.map { Text($0) } ?? Text("Meal")).font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.text).lineLimit(1)
                HStack(spacing: 5) {
                    Text("\(meal.calories) kcal · \(Int(meal.protein_g))P · \(Int(meal.carbs_g))C · \(Int(meal.fat_g))F\(meal.fiber_g.map { " · \(Int($0))g fiber" } ?? "")")
                        .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                    // Honest cue that part of this split was server-estimated (MEAL_LOGGING_REVISION 3.6).
                    if let est = meal.macros_estimated, !est.isEmpty {
                        Image(systemName: "wand.and.stars").font(.system(size: 9)).foregroundStyle(Theme.Palette.amber)
                    }
                }
                // The meal's real glucose response (CGM P2), when glucose covered it.
                if let g = meal.glucose, let delta = g.peak_delta {
                    glucoseResponseLine(g, delta)
                }
            }
            Spacer()
            Text(mealTime(meal.eaten_at)).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
        }
        .padding(.vertical, 10)
        .contentShape(Rectangle())
    }

    /// "▲ +55 mg/dL · back in 1h40" — the meal's CGM response, colored by spike size.
    private func glucoseResponseLine(_ g: Meal.MealGlucose, _ delta: Int) -> some View {
        let color: Color = switch g.spike { case "large": Theme.Palette.pink; case "moderate": Theme.Palette.amber; default: Theme.Palette.mint }
        return HStack(spacing: 4) {
            Image(systemName: "drop.fill").font(.system(size: 9)).foregroundStyle(color)
            Text("+\(delta) mg/dL").font(Theme.Font.micro.weight(.semibold)).foregroundStyle(color)
            if let ttb = g.time_to_baseline_min {
                Text("· back in \(hm(ttb))").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
            } else if g.peak_delta != nil {
                Text("· still elevated").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
            }
        }
    }

    private func hm(_ minutes: Int) -> String {
        minutes < 60 ? "\(minutes)m" : "\(minutes / 60)h\(minutes % 60 == 0 ? "" : String(format: "%02d", minutes % 60))"
    }
}

// MARK: - Your meals (one-tap re-log)

/// A compact card in the "Your meals" shelf: the saved photo, name, and macros. Tapping logs it again
/// (one tap → today's meals + rings), with a subtle busy state while it lands.
private struct YourMealCard: View {
    let template: MealTemplate
    let busy: Bool
    let onLog: () -> Void

    var body: some View {
        Button { Haptic.tap(); onLog() } label: {
            VStack(alignment: .leading, spacing: 0) {
                ZStack(alignment: .topTrailing) {
                    RemoteImage(url: template.photo_url)
                        .frame(width: 132, height: 84).clipped()
                    if template.favorite {
                        Image(systemName: "star.fill").font(.system(size: 10, weight: .bold))
                            .foregroundStyle(Theme.Palette.amber).padding(5)
                            .background(.ultraThinMaterial, in: Circle()).padding(6)
                    }
                    // The affordance: a + badge (or spinner) that reads "add this".
                    ZStack {
                        Circle().fill(Theme.Palette.amber).frame(width: 26, height: 26)
                        if busy { ProgressView().tint(.black).scaleEffect(0.6) }
                        else { Image(systemName: "plus").font(.system(size: 13, weight: .black)).foregroundStyle(.black) }
                    }
                    .padding(6).frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .bottomTrailing)
                }
                VStack(alignment: .leading, spacing: 2) {
                    Text(template.name).font(Theme.Font.micro.weight(.semibold)).foregroundStyle(Theme.Palette.text)
                        .lineLimit(1)
                    Text("\(template.calories) kcal · \(Int(template.protein_g))P")
                        .font(.system(size: 10, weight: .medium, design: .rounded)).foregroundStyle(Theme.Palette.textDim)
                }
                .padding(.horizontal, 8).padding(.vertical, 7)
            }
            .frame(width: 132)
            .background(RoundedRectangle(cornerRadius: 14, style: .continuous).fill(Theme.Palette.card))
            .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous).strokeBorder(Theme.Palette.cardStroke))
            .clipShape(RoundedRectangle(cornerRadius: 14, style: .continuous))
        }
        .buttonStyle(PressCard())
        .disabled(busy)
    }
}

// MARK: - Macro ring

private struct MacroRing: View {
    let line: MacroLine
    let label: LocalizedStringKey
    let unit: String
    let color: Color
    var size: CGFloat = 86
    @State private var progress: CGFloat = 0

    var body: some View {
        // Over budget → the ring + readout flip to a warning tint (honesty: don't hide going over).
        let ringColor = line.isOver ? Theme.Palette.pink : color
        VStack(spacing: 6) {
            ZStack {
                Circle().stroke(Color.white.opacity(0.07), lineWidth: size * 0.085)
                Circle().trim(from: 0, to: progress)
                    .stroke(Theme.Grad.ring(ringColor), style: StrokeStyle(lineWidth: size * 0.085, lineCap: .round))
                    .rotationEffect(.degrees(-90)).shadow(color: ringColor.opacity(0.5), radius: 6)
                VStack(spacing: 0) {
                    Text("\(line.value)").font(Theme.Font.num(size * 0.26)).foregroundStyle(.white).monospacedDigit()
                    Text("/\(line.target)").font(Theme.Font.num(size * 0.12)).foregroundStyle(Theme.Palette.textFaint)
                }
            }
            .frame(width: size, height: size)
            Text(label).font(Theme.Font.micro).tracking(0.6).foregroundStyle(Theme.Palette.textDim).textCase(.uppercase)
            // What's left (or how far over) — the glance a daily user actually wants.
            Text(line.isOver ? "\(-line.left) \(unit) over" : "\(line.left) \(unit) left")
                .font(Theme.Font.micro).foregroundStyle(line.isOver ? Theme.Palette.pink : Theme.Palette.textFaint)
        }
        .frame(maxWidth: .infinity)
        .onAppear { withAnimation(Theme.Motion.ring) { progress = CGFloat(line.fraction) } }
        .onChange(of: line) { _, l in withAnimation(Theme.Motion.ring) { progress = CGFloat(l.fraction) } }
    }
}

// MARK: - Snap → "what is this?" → analyze

/// A photo staged from the camera/library, waiting for the user to say what it is before we analyze it.
struct StagedMealPhoto: Identifiable { let id = UUID(); let data: Data }

/// The quick note step: after snapping, the user tells the AI what the food is (and rough portion). This
/// rides along as the caption — it sharpens the vision read, the your-usuals match, and the grounding, so
/// the macros come out far more accurate. The note is optional (Analyze works without it).
struct MealCaptionSheet: View {
    @Environment(\.dismiss) private var dismiss
    let photo: Data
    let onAnalyze: (String?) -> Void
    @State private var caption = ""
    @FocusState private var focused: Bool

    var body: some View {
        NavigationStack {
            ZStack {
                Theme.Palette.bg.ignoresSafeArea()
                ScrollView {
                    VStack(spacing: Theme.Space.m) {
                        if let ui = UIImage(data: photo) {
                            Image(uiImage: ui).resizable().scaledToFill()
                                .frame(maxWidth: .infinity).frame(height: 220).clipped()
                                .clipShape(RoundedRectangle(cornerRadius: Theme.Radius.card))
                        }
                        VStack(alignment: .leading, spacing: 6) {
                            Text("What is this?").font(Theme.Font.title).foregroundStyle(Theme.Palette.text)
                            Text("A quick note makes the macros far more accurate — the dish, brand, and rough amount.")
                                .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                        }.frame(maxWidth: .infinity, alignment: .leading)

                        TextField("e.g. grilled chicken & rice, ~200g chicken", text: $caption, axis: .vertical)
                            .lineLimit(1...4)
                            .font(Theme.Font.body).foregroundStyle(Theme.Palette.text)
                            .padding(Theme.Space.m)
                            .background(RoundedRectangle(cornerRadius: Theme.Radius.chip).fill(Theme.Palette.card))
                            .overlay(RoundedRectangle(cornerRadius: Theme.Radius.chip).strokeBorder(Theme.Palette.cardStroke))
                            .focused($focused)

                        Button {
                            let note = caption.trimmingCharacters(in: .whitespacesAndNewlines)
                            onAnalyze(note.isEmpty ? nil : note)
                            dismiss()
                        } label: {
                            (caption.trimmingCharacters(in: .whitespaces).isEmpty ? Text("Analyze photo") : Text("Analyze with note"))
                                .font(Theme.Font.body.weight(.bold))
                                .frame(maxWidth: .infinity).padding(.vertical, 14)
                                .background(Theme.Palette.amber, in: RoundedRectangle(cornerRadius: Theme.Radius.chip))
                                .foregroundStyle(.black)
                        }
                    }.padding(Theme.Space.m)
                }
            }
            .navigationTitle("New meal").navigationBarTitleDisplayMode(.inline)
            .toolbar { ToolbarItem(placement: .cancellationAction) { Button("Cancel") { dismiss() } } }
            .toolbarColorScheme(.dark, for: .navigationBar)
            .onAppear { DispatchQueue.main.asyncAfter(deadline: .now() + 0.4) { focused = true } }
        }
    }
}

// MARK: - Scan result sheet

struct ScanResultSheet: View {
    @EnvironmentObject var model: AppModel
    @Environment(\.dismiss) private var dismiss
    let result: MealScanResult
    @State private var servings: Double = 1
    @State private var name: String = ""
    @State private var logging = false

    private var draft: MealDraft? { result.draft }
    private func scaledCal(_ d: MealDraft) -> Int { Int((Double(d.calories) * servings).rounded()) }
    private func scaled(_ v: Double) -> Double { (v * servings * 10).rounded() / 10 }

    var body: some View {
        NavigationStack {
            ZStack {
                Theme.Palette.bg.ignoresSafeArea()
                ScrollView {
                    VStack(spacing: Theme.Space.m) {
                        if let url = draft?.image_url ?? result.image_url {
                            RemoteImage(url: url).frame(height: 200).frame(maxWidth: .infinity)
                                .clipShape(RoundedRectangle(cornerRadius: Theme.Radius.card))
                        }
                        if let d = draft {
                            confirmCard(d)
                            logButton(d)
                        } else {
                            GlassCard {
                                VStack(spacing: Theme.Space.s) {
                                    Image(systemName: result.kind == "physique" ? "figure.stand" : "info.circle")
                                        .font(.system(size: 30)).foregroundStyle(Theme.Palette.violet)
                                    (result.message.map { Text($0) } ?? Text("Got it.")).font(Theme.Font.body).foregroundStyle(Theme.Palette.text)
                                        .multilineTextAlignment(.center)
                                }.frame(maxWidth: .infinity)
                            }
                        }
                    }.padding(Theme.Space.m)
                }
            }
            .navigationTitle(draft != nil ? Text("Confirm meal") : Text("Scanned"))
            .navigationBarTitleDisplayMode(.inline)
            .toolbar { ToolbarItem(placement: .cancellationAction) { Button { dismiss() } label: { draft != nil ? Text("Cancel") : Text("Done") } } }
            .toolbarColorScheme(.dark, for: .navigationBar)
            .onAppear { if name.isEmpty { name = draft?.name ?? "" } }
        }
    }

    // The confirm card: where the macros came from, an editable name, the amount (servings), and the
    // live macros for that amount — so the user dials it in before it's logged.
    private func confirmCard(_ d: MealDraft) -> some View {
        GlassCard {
            VStack(alignment: .leading, spacing: Theme.Space.m) {
                sourceBadge(d)
                TextField("Meal name", text: $name)
                    .font(Theme.Font.title).foregroundStyle(Theme.Palette.text).textFieldStyle(.plain)
                if let b = d.brand, d.source != "brand" { Text(b).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim) }

                VStack(alignment: .leading, spacing: 8) {
                    HStack {
                        Text("Amount").font(Theme.Font.label).foregroundStyle(Theme.Palette.textDim)
                        Spacer()
                        stepButton("minus") { servings = max(0.5, (servings - 0.5)) }
                        Text("×\(servings.formatted())").font(Theme.Font.num(18)).foregroundStyle(Theme.Palette.text)
                            .monospacedDigit().frame(minWidth: 52)
                        stepButton("plus") { servings = min(20, servings + 0.5) }
                    }
                    if let s = d.serving_hint { Text("Serving: \(s)").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint) }
                }

                HStack(spacing: Theme.Space.m) {
                    macroStat("\(scaledCal(d))", "kcal", Theme.Palette.cyan)
                    macroStat("\(Int(scaled(d.protein_g)))", "protein", Theme.Palette.mint)
                    macroStat("\(Int(scaled(d.carbs_g)))", "carbs", Theme.Palette.amber)
                    macroStat("\(Int(scaled(d.fat_g)))", "fat", Theme.Palette.pink)
                }
                if let fib = d.fiber_g, fib > 0 {
                    Text("+ \(Int(scaled(fib)))g fiber").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                }
            }
        }
    }

    private func logButton(_ d: MealDraft) -> some View {
        Button {
            logging = true
            Task {
                await model.confirmScannedMeal(
                    name: name.trimmingCharacters(in: .whitespaces).isEmpty ? d.name : name,
                    calories: scaledCal(d), protein: scaled(d.protein_g),
                    carbs: scaled(d.carbs_g), fat: scaled(d.fat_g),
                    fiber: d.fiber_g.map(scaled), photoPath: d.photo_path,
                    // Only 'barcode' is a real meal source among the draft's resolve-chain values; a photo
                    // scan (your_meals/brand/web/photo) → nil → the server stamps 'photo'.
                    source: d.source == "barcode" ? "barcode" : nil)
                logging = false
            }
        } label: {
            HStack(spacing: Theme.Space.s) {
                if logging { ProgressView().tint(.black) }
                (logging ? Text("Logging…") : Text("Log meal")).font(Theme.Font.body.weight(.bold))
            }
            .frame(maxWidth: .infinity).padding(.vertical, 14)
            .background(Theme.Palette.amber, in: RoundedRectangle(cornerRadius: Theme.Radius.chip))
            .foregroundStyle(.black)
        }
        .disabled(logging)
    }

    private func sourceBadge(_ d: MealDraft) -> some View {
        let brand = d.brand.map { " · \($0)" } ?? ""
        let (icon, label, color): (String, Text, Color) = {
            switch d.source {
            case "label": return ("doc.text.magnifyingglass", Text("Read straight from the label") + Text(brand), Theme.Palette.mint)
            case "your_meals": return ("star.fill", Text("Your usual — your saved macros"), Theme.Palette.mint)
            case "brand": return ("checkmark.seal.fill", Text("Official label") + Text(brand), Theme.Palette.cyan)
            case "web": return ("globe", Text("Grounded in real nutrition data"), Theme.Palette.cyan)
            default: return ("sparkles", Text("Estimated from your photo — confirm the amount"), Theme.Palette.amber)
            }
        }()
        return HStack(spacing: 6) {
            Image(systemName: icon).font(.system(size: 11, weight: .bold))
            label.font(Theme.Font.micro.weight(.semibold)).lineLimit(1)
        }
        .foregroundStyle(color)
        .padding(.horizontal, 10).padding(.vertical, 6)
        .background(color.opacity(0.12), in: Capsule())
    }

    private func stepButton(_ icon: String, _ action: @escaping () -> Void) -> some View {
        Button { Haptic.tap(); action() } label: {
            Image(systemName: icon).font(.system(size: 14, weight: .bold)).foregroundStyle(Theme.Palette.text)
                .frame(width: 34, height: 34)
                .background(Theme.Palette.card, in: Circle())
                .overlay(Circle().strokeBorder(Theme.Palette.cardStroke))
        }.buttonStyle(.plain)
    }

    private func macroStat(_ v: String, _ l: LocalizedStringKey, _ c: Color) -> some View {
        VStack(spacing: 3) {
            Text(v).font(Theme.Font.num(20)).foregroundStyle(c).monospacedDigit()
            Text(l).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim).textCase(.uppercase)
        }.frame(maxWidth: .infinity)
    }
}

// MARK: - Manual quick-add

/// Log a meal by hand — name + calories in seconds, P/C/F optional (the server reconciles them so even
/// "300 kcal" alone stores a sensible split), time defaults to now. The boring path, made fast.
private struct QuickAddMealSheet: View {
    @EnvironmentObject var model: AppModel
    @Environment(\.dismiss) private var dismiss
    @State private var name = ""
    @State private var calories = ""
    @State private var protein = ""
    @State private var carbs = ""
    @State private var fat = ""
    @State private var when = Date()
    @State private var saving = false

    private var canSave: Bool {
        !name.trimmingCharacters(in: .whitespaces).isEmpty && (Int(calories) ?? 0) > 0
    }

    var body: some View {
        NavigationStack {
            ZStack {
                Theme.Palette.bg.ignoresSafeArea()
                ScrollView {
                    VStack(spacing: Theme.Space.m) {
                        field("Name", text: $name, keyboard: .default)
                        field("Calories (kcal)", text: $calories, keyboard: .numberPad)
                        HStack(spacing: Theme.Space.s) {
                            field("Protein (g)", text: $protein, keyboard: .numberPad)
                            field("Carbs (g)", text: $carbs, keyboard: .numberPad)
                            field("Fat (g)", text: $fat, keyboard: .numberPad)
                        }
                        Text("Macros optional — leave them blank and I'll estimate a split from the calories.")
                            .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
                            .frame(maxWidth: .infinity, alignment: .leading)

                        VStack(alignment: .leading, spacing: 5) {
                            Text("When").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim).textCase(.uppercase)
                            TitanDateField(selection: $when, components: [.date, .hourAndMinute])
                        }

                        Button {
                            saving = true
                            Task {
                                let ok = await model.addMeal(
                                    name: name.trimmingCharacters(in: .whitespaces),
                                    calories: Int(calories) ?? 0,
                                    protein: Double(protein), carbs: Double(carbs), fat: Double(fat),
                                    eatenAt: when)
                                saving = false
                                if ok { dismiss() }
                            }
                        } label: {
                            HStack(spacing: 8) {
                                if saving { ProgressView().tint(.white) }
                                (saving ? Text("Logging…") : Text("Log meal")).font(Theme.Font.body.weight(.semibold))
                            }
                            .frame(maxWidth: .infinity).padding(.vertical, 14)
                            .background(Theme.Grad.brand, in: RoundedRectangle(cornerRadius: Theme.Radius.chip))
                            .foregroundStyle(.white).opacity(canSave ? 1 : 0.5)
                        }
                        .disabled(!canSave || saving)
                    }.padding(Theme.Space.m)
                }
            }
            .navigationTitle("Log a meal").navigationBarTitleDisplayMode(.inline)
            .toolbar { ToolbarItem(placement: .cancellationAction) { Button("Cancel") { dismiss() } } }
            .toolbarColorScheme(.dark, for: .navigationBar)
        }
    }

    private func field(_ label: LocalizedStringKey, text: Binding<String>, keyboard: UIKeyboardType) -> some View {
        VStack(alignment: .leading, spacing: 5) {
            Text(label).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim).textCase(.uppercase)
            TextField("", text: text)
                .font(Theme.Font.body).foregroundStyle(Theme.Palette.text).keyboardType(keyboard)
                .padding(12).background(Theme.Palette.card, in: RoundedRectangle(cornerRadius: Theme.Radius.chip))
                .overlay(RoundedRectangle(cornerRadius: Theme.Radius.chip).strokeBorder(Theme.Palette.cardStroke))
        }
    }
}

// MARK: - Edit meal

private struct EditMealSheet: View {
    @EnvironmentObject var model: AppModel
    @Environment(\.dismiss) private var dismiss
    let meal: Meal
    @State private var name: String
    @State private var calories: String
    @State private var protein: String
    @State private var carbs: String
    @State private var fat: String
    @State private var when: Date
    @State private var mealType: String

    private static let types: [(String, String)] = [
        ("breakfast", "Breakfast"), ("lunch", "Lunch"), ("dinner", "Dinner"), ("snack", "Snack"),
    ]

    init(meal: Meal) {
        self.meal = meal
        _name = State(initialValue: meal.name ?? "")
        _calories = State(initialValue: "\(meal.calories)")
        _protein = State(initialValue: "\(Int(meal.protein_g))")
        _carbs = State(initialValue: "\(Int(meal.carbs_g))")
        _fat = State(initialValue: "\(Int(meal.fat_g))")
        _when = State(initialValue: meal.eaten_at.flatMap { ISO8601DateFormatter().date(from: $0) } ?? Date())
        _mealType = State(initialValue: meal.meal_type ?? "snack")
    }

    var body: some View {
        NavigationStack {
            ZStack {
                Theme.Palette.bg.ignoresSafeArea()
                ScrollView {
                    VStack(spacing: Theme.Space.m) {
                        field("Name", text: $name, keyboard: .default)
                        field("Calories (kcal)", text: $calories, keyboard: .numberPad)
                        field("Protein (g)", text: $protein, keyboard: .numberPad)
                        field("Carbs (g)", text: $carbs, keyboard: .numberPad)
                        field("Fat (g)", text: $fat, keyboard: .numberPad)

                        VStack(alignment: .leading, spacing: 5) {
                            Text("When").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim).textCase(.uppercase)
                            TitanDateField(selection: $when, components: [.date, .hourAndMinute])
                        }

                        VStack(alignment: .leading, spacing: 5) {
                            Text("Meal").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim).textCase(.uppercase)
                            Picker("Meal", selection: $mealType) {
                                ForEach(Self.types, id: \.0) { Text($0.1).tag($0.0) }
                            }
                            .pickerStyle(.segmented)
                        }

                        Button {
                            Haptic.success()
                            Task {
                                await model.updateMeal(meal.id, name: name,
                                                       calories: Int(calories) ?? meal.calories,
                                                       protein: Double(protein) ?? meal.protein_g,
                                                       carbs: Double(carbs) ?? meal.carbs_g,
                                                       fat: Double(fat) ?? meal.fat_g,
                                                       eatenAt: when, mealType: mealType)
                                dismiss()
                            }
                        } label: {
                            Text("Save").font(Theme.Font.body.weight(.semibold))
                                .frame(maxWidth: .infinity).padding(.vertical, 14)
                                .background(Theme.Grad.brand, in: RoundedRectangle(cornerRadius: Theme.Radius.chip))
                                .foregroundStyle(.white)
                        }

                        Button(role: .destructive) {
                            Haptic.warning(); Task { await model.deleteMeal(meal.id); dismiss() }
                        } label: {
                            Text("Delete meal").font(Theme.Font.micro).foregroundStyle(Theme.Palette.pink)
                        }.frame(maxWidth: .infinity)
                    }.padding(Theme.Space.m)
                }
            }
            .navigationTitle("Adjust meal").navigationBarTitleDisplayMode(.inline)
            .toolbar { ToolbarItem(placement: .cancellationAction) { Button("Cancel") { dismiss() } } }
            .toolbarColorScheme(.dark, for: .navigationBar)
        }
    }

    private func field(_ label: LocalizedStringKey, text: Binding<String>, keyboard: UIKeyboardType) -> some View {
        VStack(alignment: .leading, spacing: 5) {
            Text(label).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim).textCase(.uppercase)
            TextField("", text: text)
                .font(Theme.Font.body).foregroundStyle(Theme.Palette.text).keyboardType(keyboard)
                .padding(12).background(Theme.Palette.card, in: RoundedRectangle(cornerRadius: Theme.Radius.chip))
                .overlay(RoundedRectangle(cornerRadius: Theme.Radius.chip).strokeBorder(Theme.Palette.cardStroke))
        }
    }
}

// MARK: - Targets editor

struct TargetsSheet: View {
    @EnvironmentObject var model: AppModel
    @Environment(\.dismiss) private var dismiss
    @State private var calories = ""
    @State private var protein = ""
    @State private var carbs = ""
    @State private var fat = ""
    @State private var sleep = ""
    @State private var loaded = false

    var body: some View {
        NavigationStack {
            ZStack {
                Theme.Palette.bg.ignoresSafeArea()
                ScrollView {
                    VStack(spacing: Theme.Space.m) {
                        GlassCard {
                            VStack(spacing: Theme.Space.s) {
                                SectionHeader(title: "Daily macros")
                                row("Calories", "kcal", $calories)
                                row("Protein", "g", $protein)
                                row("Carbs", "g", $carbs)
                                row("Fat", "g", $fat)
                            }
                        }
                        GlassCard {
                            VStack(spacing: Theme.Space.s) {
                                SectionHeader(title: "Sleep")
                                row("Sleep target", "hours", $sleep, decimal: true)
                            }
                        }
                        Button(action: save) {
                            Text("Save targets").font(Theme.Font.body.weight(.semibold))
                                .frame(maxWidth: .infinity).padding(.vertical, 14)
                                .background(Theme.Grad.brand, in: RoundedRectangle(cornerRadius: Theme.Radius.chip))
                                .foregroundStyle(.white)
                        }
                        if model.targets?.custom == true {
                            Button(action: resetToAuto) {
                                Label("Recalculate from my bodyweight", systemImage: "arrow.counterclockwise")
                                    .font(Theme.Font.micro).foregroundStyle(Theme.Palette.cyan)
                            }.frame(maxWidth: .infinity).padding(.vertical, 2)
                        }
                        Text("Or just tell your coach — “set my protein to 180”, “target 7.5 hours of sleep.”")
                            .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
                            .multilineTextAlignment(.center).frame(maxWidth: .infinity)
                    }.padding(Theme.Space.m)
                }
            }
            .navigationTitle("Your targets").navigationBarTitleDisplayMode(.inline)
            .toolbar { ToolbarItem(placement: .cancellationAction) { Button("Cancel") { dismiss() } } }
            .toolbarColorScheme(.dark, for: .navigationBar)
            .task {
                if model.targets == nil { await model.loadTargets() }
                if let t = model.targets, !loaded { prime(t); loaded = true }
            }
            .onChange(of: model.targets) { _, t in if let t, !loaded { prime(t); loaded = true } }
        }
    }

    private func prime(_ t: Targets) {
        calories = "\(t.calories)"; protein = "\(t.protein_g)"; carbs = "\(t.carbs_g)"; fat = "\(t.fat_g)"
        sleep = t.sleep_h.truncatingRemainder(dividingBy: 1) == 0 ? "\(Int(t.sleep_h))" : "\(t.sleep_h)"
    }

    private func save() {
        Haptic.success()
        let t = model.targets
        Task {
            await model.saveTargets(
                calories: Int(calories) ?? t?.calories ?? 2800,
                protein: Int(protein) ?? t?.protein_g ?? 200,
                carbs: Int(carbs) ?? t?.carbs_g ?? 280,
                fat: Int(fat) ?? t?.fat_g ?? 84,
                sleepH: Double(sleep.replacingOccurrences(of: ",", with: ".")) ?? t?.sleep_h ?? 8)
            dismiss()
        }
    }

    private func resetToAuto() {
        Haptic.tap()
        Task {
            await model.resetTargets()
            if let t = model.targets { prime(t) }   // show the recalculated auto values
        }
    }

    private func row(_ label: LocalizedStringKey, _ unit: LocalizedStringKey, _ text: Binding<String>, decimal: Bool = false) -> some View {
        HStack {
            Text(label).font(Theme.Font.body).foregroundStyle(Theme.Palette.text)
            Spacer()
            TextField("", text: text)
                .font(Theme.Font.num(20)).foregroundStyle(Theme.Palette.text)
                .keyboardType(decimal ? .decimalPad : .numberPad)
                .multilineTextAlignment(.trailing).frame(width: 84)
            Text(unit).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint).frame(width: 38, alignment: .leading)
        }
        .padding(.vertical, 3)
    }
}

// MARK: - Photo source (camera / library) + remote image

/// A button that offers Take Photo / Choose from Library, returning compressed JPEG Data.
struct PhotoSourceButton<Label: View>: View {
    let onImage: (Data) -> Void
    @ViewBuilder var label: Label
    @State private var ask = false
    @State private var showCamera = false
    @State private var showLibrary = false
    @State private var item: PhotosPickerItem?

    var body: some View {
        Button { Haptic.tap(); ask = true } label: { label }
            .confirmationDialog("Add a photo", isPresented: $ask, titleVisibility: .visible) {
                Button("Take Photo") { showCamera = true }
                Button("Choose from Library") { showLibrary = true }
                Button("Cancel", role: .cancel) {}
            }
            .sheet(isPresented: $showCamera) {
                CameraPicker { ui in if let d = ui.jpegData(compressionQuality: 0.7) { onImage(d) } }
                    .ignoresSafeArea()
            }
            .photosPicker(isPresented: $showLibrary, selection: $item, matching: .images)
            .onChange(of: item) { _, newItem in
                guard let newItem else { return }
                Task {
                    if let d = try? await newItem.loadTransferable(type: Data.self),
                       let ui = UIImage(data: d), let jpeg = ui.jpegData(compressionQuality: 0.7) {
                        onImage(jpeg)
                    }
                    item = nil
                }
            }
    }
}

/// UIKit camera capture (falls back to the library on devices without a camera, e.g. the simulator).
struct CameraPicker: UIViewControllerRepresentable {
    let onImage: (UIImage) -> Void
    @Environment(\.dismiss) private var dismiss

    func makeUIViewController(context: Context) -> UIImagePickerController {
        let p = UIImagePickerController()
        p.sourceType = UIImagePickerController.isSourceTypeAvailable(.camera) ? .camera : .photoLibrary
        p.delegate = context.coordinator
        return p
    }
    func updateUIViewController(_ uiViewController: UIImagePickerController, context: Context) {}
    func makeCoordinator() -> Coordinator { Coordinator(self) }

    final class Coordinator: NSObject, UIImagePickerControllerDelegate, UINavigationControllerDelegate {
        let parent: CameraPicker
        init(_ parent: CameraPicker) { self.parent = parent }
        func imagePickerController(_ picker: UIImagePickerController, didFinishPickingMediaWithInfo info: [UIImagePickerController.InfoKey: Any]) {
            if let img = info[.originalImage] as? UIImage { parent.onImage(img) }
            parent.dismiss()
        }
        func imagePickerControllerDidCancel(_ picker: UIImagePickerController) { parent.dismiss() }
    }
}

/// Async network image with themed loading/failure states. With no URL it shows a calm static
/// placeholder — never a spinner (AsyncImage(nil) would otherwise stay "loading" forever).
struct RemoteImage: View {
    let url: String?
    var body: some View {
        if let s = url, !s.isEmpty, let parsed = URL(string: s) {
            AsyncImage(url: parsed) { phase in
                switch phase {
                case .success(let img): img.resizable().scaledToFill()
                case .empty: ZStack { Theme.Palette.bg2; ProgressView().tint(Theme.Palette.textFaint) }   // genuinely loading
                default: placeholder
                }
            }
        } else {
            placeholder   // no photo → static, not an endless spinner
        }
    }
    private var placeholder: some View {
        ZStack { Theme.Palette.bg2; Image(systemName: "fork.knife").foregroundStyle(Theme.Palette.textFaint) }
    }
}

// MARK: - Date helpers

private func mealTime(_ iso: String?) -> String {
    guard let iso else { return "" }
    let parser = ISO8601DateFormatter()
    parser.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
    let date = parser.date(from: iso) ?? ISO8601DateFormatter().date(from: iso)
    guard let date else { return "" }
    let f = DateFormatter(); f.dateFormat = "h:mm a"
    return f.string(from: date)
}

