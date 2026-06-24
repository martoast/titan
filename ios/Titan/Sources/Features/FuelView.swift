import SwiftUI
import PhotosUI

/// The "Fuel" tab — purely nutrition (camera-first macros, hydration, fasting), wired to the coach
/// via the same Meal rows its tools read. Body tracking (weight trend + progress photos) lives under
/// the You tab in `BodyView`, so this page stays just about food.
struct FuelView: View {
    @EnvironmentObject var model: AppModel
    @State private var showTargets = false

    var body: some View {
        VStack(spacing: Theme.Space.m) {
            MacrosSection()          // Fuel is purely food now — weight + progress photos live in You › Body
            Color.clear.frame(height: 8)
        }
        .titanScreen("Fuel", glow: Theme.Palette.amber)
        .toolbar {
            ToolbarItem(placement: .topBarTrailing) {
                Button { Haptic.tap(); showTargets = true } label: { Image(systemName: "slider.horizontal.3") }
                    .tint(Theme.Palette.textDim)
            }
        }
        .sheet(item: $model.scanResult) { ScanResultSheet(result: $0) }
        .sheet(isPresented: $showTargets) { TargetsSheet() }
    }
}

// MARK: - Macros

private struct MacrosSection: View {
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

            macrosCard
            HydrationCard()
            FastingCard()
            mealsList
        }
        .task { await model.loadNutrition() }
        .sheet(item: $editing) { EditMealSheet(meal: $0) }
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

private struct ScanResultSheet: View {
    @EnvironmentObject var model: AppModel
    @Environment(\.dismiss) private var dismiss
    let result: MealScanResult
    @State private var editing: Meal?

    var body: some View {
        NavigationStack {
            ZStack {
                Theme.Palette.bg.ignoresSafeArea()
                ScrollView {
                    VStack(spacing: Theme.Space.m) {
                        if let url = result.image_url {
                            RemoteImage(url: url).frame(height: 200).frame(maxWidth: .infinity)
                                .clipShape(RoundedRectangle(cornerRadius: Theme.Radius.card))
                        }
                        if let meal = result.meal {
                            GlassCard {
                                VStack(spacing: Theme.Space.m) {
                                    HStack {
                                        Image(systemName: "checkmark.seal.fill").foregroundStyle(Theme.Palette.mint)
                                        Text("Logged").font(Theme.Font.label).foregroundStyle(Theme.Palette.mint)
                                        Spacer()
                                    }
                                    Text(meal.name ?? "Meal").font(Theme.Font.title).foregroundStyle(Theme.Palette.text)
                                        .frame(maxWidth: .infinity, alignment: .leading)
                                    HStack(spacing: Theme.Space.m) {
                                        macroStat("\(meal.calories)", "kcal", Theme.Palette.cyan)
                                        macroStat("\(Int(meal.protein_g))", "protein", Theme.Palette.mint)
                                        macroStat("\(Int(meal.carbs_g))", "carbs", Theme.Palette.amber)
                                        macroStat("\(Int(meal.fat_g))", "fat", Theme.Palette.pink)
                                    }
                                    Text("Estimated by AI from your photo. Tap Adjust if it's off.")
                                        .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
                                        .frame(maxWidth: .infinity, alignment: .leading)
                                }
                            }
                            Button { editing = meal } label: {
                                Text("Adjust macros").font(Theme.Font.body.weight(.semibold))
                                    .frame(maxWidth: .infinity).padding(.vertical, 13)
                                    .background(Theme.Palette.card, in: RoundedRectangle(cornerRadius: Theme.Radius.chip))
                                    .overlay(RoundedRectangle(cornerRadius: Theme.Radius.chip).strokeBorder(Theme.Palette.cardStroke))
                                    .foregroundStyle(Theme.Palette.text)
                            }
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
            .navigationTitle(result.kind == "meal" ? "Meal logged" : "Scanned")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar { ToolbarItem(placement: .confirmationAction) { Button("Done") { dismiss() } } }
            .toolbarColorScheme(.dark, for: .navigationBar)
        }
        .sheet(item: $editing) { EditMealSheet(meal: $0) }
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

private struct TargetsSheet: View {
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

