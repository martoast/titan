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

    var body: some View {
        VStack(spacing: Theme.Space.m) {
            // Hero: snap a meal.
            PhotoSourceButton(onImage: { data in Task { await model.scanMeal(data) } }) {
                GlassCard(padding: Theme.Space.l) {
                    HStack(spacing: Theme.Space.m) {
                        ZStack {
                            Circle().fill(Theme.Palette.amber.opacity(0.16)).frame(width: 52, height: 52)
                            Image(systemName: model.scanning ? "sparkles" : "camera.fill")
                                .font(.system(size: 22, weight: .semibold)).foregroundStyle(Theme.Palette.amber)
                                .symbolEffect(.pulse, options: model.scanning ? .repeating : .nonRepeating)
                        }
                        VStack(alignment: .leading, spacing: 2) {
                            Text(model.scanning ? "Reading your plate…" : "Snap a meal")
                                .font(Theme.Font.title).foregroundStyle(Theme.Palette.text)
                            Text(model.scanning ? "Estimating macros with AI" : "Photo → instant macros, logged for your coach")
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

            yourMealsCard
            macrosCard
            HydrationCard()
            FastingCard()
            mealsList
        }
        .task { await model.loadNutrition() }
        .sheet(item: $editing) { EditMealSheet(meal: $0) }
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
                                        Label(t.favorite ? "Unfavorite" : "Favorite", systemImage: t.favorite ? "star.slash" : "star")
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
                    MacroRing(line: m.calories, label: "Calories", unit: "kcal", color: Theme.Palette.cyan, size: 132)
                    HStack(spacing: Theme.Space.m) {
                        MacroRing(line: m.protein, label: "Protein", unit: "g", color: Theme.Palette.mint, size: 86)
                        MacroRing(line: m.carbs, label: "Carbs", unit: "g", color: Theme.Palette.amber, size: 86)
                        MacroRing(line: m.fat, label: "Fat", unit: "g", color: Theme.Palette.pink, size: 86)
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
                    ForEach(meals) { meal in
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

    private func mealRow(_ meal: Meal) -> some View {
        HStack(spacing: Theme.Space.m) {
            RemoteImage(url: meal.photo_url)
                .frame(width: 50, height: 50).clipShape(RoundedRectangle(cornerRadius: 11))
            VStack(alignment: .leading, spacing: 3) {
                Text(meal.name ?? "Meal").font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.text).lineLimit(1)
                Text("\(meal.calories) kcal · \(Int(meal.protein_g))P · \(Int(meal.carbs_g))C · \(Int(meal.fat_g))F")
                    .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
            }
            Spacer()
            Text(mealTime(meal.eaten_at)).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
        }
        .padding(.vertical, 10)
        .contentShape(Rectangle())
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
    let label: String
    let unit: String
    let color: Color
    var size: CGFloat = 86
    @State private var progress: CGFloat = 0

    var body: some View {
        VStack(spacing: 6) {
            ZStack {
                Circle().stroke(Color.white.opacity(0.07), lineWidth: size * 0.085)
                Circle().trim(from: 0, to: progress)
                    .stroke(Theme.Grad.ring(color), style: StrokeStyle(lineWidth: size * 0.085, lineCap: .round))
                    .rotationEffect(.degrees(-90)).shadow(color: color.opacity(0.5), radius: 6)
                VStack(spacing: 0) {
                    Text("\(line.value)").font(Theme.Font.num(size * 0.26)).foregroundStyle(.white).monospacedDigit()
                    Text("/\(line.target)").font(Theme.Font.num(size * 0.12)).foregroundStyle(Theme.Palette.textFaint)
                }
            }
            .frame(width: size, height: size)
            Text(label.uppercased()).font(Theme.Font.micro).tracking(0.6).foregroundStyle(Theme.Palette.textDim)
        }
        .frame(maxWidth: .infinity)
        .onAppear { withAnimation(Theme.Motion.ring) { progress = CGFloat(line.fraction) } }
        .onChange(of: line) { _, l in withAnimation(Theme.Motion.ring) { progress = CGFloat(l.fraction) } }
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
                                    Text(result.message ?? "Got it.").font(Theme.Font.body).foregroundStyle(Theme.Palette.text)
                                        .multilineTextAlignment(.center)
                                }.frame(maxWidth: .infinity)
                            }
                        }
                    }.padding(Theme.Space.m)
                }
            }
            .navigationTitle(draft != nil ? "Confirm meal" : "Scanned")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar { ToolbarItem(placement: .cancellationAction) { Button(draft != nil ? "Cancel" : "Done") { dismiss() } } }
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
                    carbs: scaled(d.carbs_g), fat: scaled(d.fat_g), photoPath: d.photo_path)
                logging = false
            }
        } label: {
            HStack(spacing: Theme.Space.s) {
                if logging { ProgressView().tint(.black) }
                Text(logging ? "Logging…" : "Log meal").font(Theme.Font.body.weight(.bold))
            }
            .frame(maxWidth: .infinity).padding(.vertical, 14)
            .background(Theme.Palette.amber, in: RoundedRectangle(cornerRadius: Theme.Radius.chip))
            .foregroundStyle(.black)
        }
        .disabled(logging)
    }

    private func sourceBadge(_ d: MealDraft) -> some View {
        let (icon, text, color): (String, String, Color) = {
            switch d.source {
            case "label": return ("doc.text.magnifyingglass", "Read straight from the label" + (d.brand.map { " · \($0)" } ?? ""), Theme.Palette.mint)
            case "your_meals": return ("star.fill", "Your usual — your saved macros", Theme.Palette.mint)
            case "brand": return ("checkmark.seal.fill", "Official label" + (d.brand.map { " · \($0)" } ?? ""), Theme.Palette.cyan)
            case "web": return ("globe", "Grounded in real nutrition data", Theme.Palette.cyan)
            default: return ("sparkles", "Estimated from your photo — confirm the amount", Theme.Palette.amber)
            }
        }()
        return HStack(spacing: 6) {
            Image(systemName: icon).font(.system(size: 11, weight: .bold))
            Text(text).font(Theme.Font.micro.weight(.semibold)).lineLimit(1)
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

    private func macroStat(_ v: String, _ l: String, _ c: Color) -> some View {
        VStack(spacing: 3) {
            Text(v).font(Theme.Font.num(20)).foregroundStyle(c).monospacedDigit()
            Text(l.uppercased()).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
        }.frame(maxWidth: .infinity)
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

    init(meal: Meal) {
        self.meal = meal
        _name = State(initialValue: meal.name ?? "")
        _calories = State(initialValue: "\(meal.calories)")
        _protein = State(initialValue: "\(Int(meal.protein_g))")
        _carbs = State(initialValue: "\(Int(meal.carbs_g))")
        _fat = State(initialValue: "\(Int(meal.fat_g))")
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

                        Button {
                            Haptic.success()
                            Task {
                                await model.updateMeal(meal.id, name: name,
                                                       calories: Int(calories) ?? meal.calories,
                                                       protein: Double(protein) ?? meal.protein_g,
                                                       carbs: Double(carbs) ?? meal.carbs_g,
                                                       fat: Double(fat) ?? meal.fat_g)
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

    private func field(_ label: String, text: Binding<String>, keyboard: UIKeyboardType) -> some View {
        VStack(alignment: .leading, spacing: 5) {
            Text(label.uppercased()).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
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

    private func row(_ label: String, _ unit: String, _ text: Binding<String>, decimal: Bool = false) -> some View {
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

/// Async network image with themed loading/failure states.
struct RemoteImage: View {
    let url: String?
    var body: some View {
        AsyncImage(url: url.flatMap { URL(string: $0) }) { phase in
            switch phase {
            case .success(let img): img.resizable().scaledToFill()
            case .empty: ZStack { Theme.Palette.bg2; ProgressView().tint(Theme.Palette.textFaint) }
            default: ZStack { Theme.Palette.bg2; Image(systemName: "photo").foregroundStyle(Theme.Palette.textFaint) }
            }
        }
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

