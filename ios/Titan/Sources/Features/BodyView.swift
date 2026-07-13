import SwiftUI

/// "Body & progress" — weight trend + progress photos. Lives under the **You** tab (it's about the
/// person, not the food), opened as a sheet from ProfileView. The Fuel tab is purely food now.
struct BodyView: View {
    @Environment(\.dismiss) private var dismiss

    var body: some View {
        VStack(spacing: Theme.Space.m) {
            WeightSection()          // weight EWMA trend + goal (defined in BodyFuel.swift)
            ProgressSection()        // private progress-photo gallery + compare, below
            Color.clear.frame(height: 8)
        }
        .toolbar { ToolbarItem(placement: .confirmationAction) { Button("Done") { dismiss() } } }
        .titanScreen("Body & progress", glow: Theme.Palette.violet)
    }
}

// MARK: - Progress photos

private struct ProgressSection: View {
    @EnvironmentObject var model: AppModel
    @State private var pending: ImageData?           // set ONCE when a photo is picked → stable sheet id
    @State private var viewing: ProgressPhoto?

    private let cols = [GridItem(.flexible(), spacing: Theme.Space.s), GridItem(.flexible(), spacing: Theme.Space.s)]

    var body: some View {
        VStack(spacing: Theme.Space.m) {
            PhotoSourceButton(onImage: { pending = ImageData(data: $0) }) {
                GlassCard(padding: Theme.Space.l) {
                    HStack(spacing: Theme.Space.m) {
                        ZStack {
                            Circle().fill(Theme.Palette.violet.opacity(0.16)).frame(width: 52, height: 52)
                            Image(systemName: "camera.viewfinder").font(.system(size: 22, weight: .semibold)).foregroundStyle(Theme.Palette.violet)
                        }
                        VStack(alignment: .leading, spacing: 2) {
                            Text("Add a progress photo").font(Theme.Font.title).foregroundStyle(Theme.Palette.text)
                            Text("Private. Front, side & back over time.").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                        }
                        Spacer()
                        if model.progressBusy { ProgressView().tint(Theme.Palette.violet) }
                        else { Image(systemName: "chevron.right").font(.caption).foregroundStyle(Theme.Palette.textFaint) }
                    }
                }
            }
            .buttonStyle(PressCard())

            compareCard
            gallery
        }
        .task { await model.loadProgress() }
        .sheet(item: $pending) { wrap in
            AddProgressSheet(imageData: wrap.data)
        }
        .sheet(item: $viewing) { PhotoViewerSheet(photo: $0) }
    }

    @ViewBuilder private var compareCard: some View {
        let photos = model.progressPhotos
        if photos.count >= 2, let newest = photos.first, let oldest = photos.last {
            GlassCard {
                VStack(spacing: Theme.Space.s) {
                    SectionHeader(title: "Then → now")
                    HStack(spacing: Theme.Space.s) {
                        comparePane(oldest, tag: "First")
                        comparePane(newest, tag: "Latest")
                    }
                }
            }
        }
    }

    private func comparePane(_ p: ProgressPhoto, tag: LocalizedStringKey) -> some View {
        VStack(spacing: 6) {
            RemoteImage(url: p.photo_url)
                .frame(height: 200).frame(maxWidth: .infinity)
                .clipShape(RoundedRectangle(cornerRadius: Theme.Radius.chip))
                .overlay(RoundedRectangle(cornerRadius: Theme.Radius.chip).strokeBorder(Theme.Palette.cardStroke))
            (Text(tag) + Text(" · \(photoDate(p.taken_at))")).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
        }
    }

    @ViewBuilder private var gallery: some View {
        let photos = model.progressPhotos
        if photos.isEmpty {
            GlassCard {
                VStack(alignment: .leading, spacing: 4) {
                    SectionHeader(title: "Gallery")
                    Text("No photos yet. Snap your first one — same lighting and pose each time makes the comparison honest. Your coach can render your dream physique from it.")
                        .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
                }
            }
        } else {
            LazyVGrid(columns: cols, spacing: Theme.Space.s) {
                ForEach(photos) { p in
                    Button { Haptic.tap(); viewing = p } label: {
                        RemoteImage(url: p.photo_url)
                            .aspectRatio(0.8, contentMode: .fill)
                            .frame(maxWidth: .infinity).clipped()
                            .clipShape(RoundedRectangle(cornerRadius: Theme.Radius.chip))
                            .overlay(alignment: .bottomLeading) {
                                Text(photoDate(p.taken_at)).font(Theme.Font.micro).foregroundStyle(.white)
                                    .padding(.horizontal, 8).padding(.vertical, 4)
                                    .background(.black.opacity(0.5), in: Capsule()).padding(8)
                            }
                    }
                    .buttonStyle(.plain)
                    .contextMenu {
                        Button(role: .destructive) { Task { await model.deleteProgress(p.id) } } label: { Label("Delete", systemImage: "trash") }
                    }
                }
            }
        }
    }
}

// MARK: - Add progress photo

private struct AddProgressSheet: View {
    @EnvironmentObject var model: AppModel
    @Environment(\.dismiss) private var dismiss
    let imageData: Data
    @State private var pose: String?
    @State private var weight: String = ""
    @State private var notes: String = ""

    var body: some View {
        NavigationStack {
            ZStack {
                Theme.Palette.bg.ignoresSafeArea()
                ScrollView {
                    VStack(spacing: Theme.Space.m) {
                        if let ui = UIImage(data: imageData) {
                            Image(uiImage: ui).resizable().scaledToFill()
                                .frame(height: 240).frame(maxWidth: .infinity).clipped()
                                .clipShape(RoundedRectangle(cornerRadius: Theme.Radius.card))
                        }
                        VStack(alignment: .leading, spacing: 6) {
                            Text("POSE").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                            PillSwitch(options: [(String?.none, "—"), (.some("front"), "Front"), (.some("side"), "Side"), (.some("back"), "Back")],
                                       selection: $pose)
                        }
                        labeledField("Weight (kg, optional)", text: $weight, keyboard: .decimalPad)
                        labeledField("Note (optional)", text: $notes, keyboard: .default)

                        Button {
                            Haptic.success()
                            Task {
                                await model.uploadProgress(imageData, pose: pose,
                                                           weightKg: Double(weight.replacingOccurrences(of: ",", with: ".")),
                                                           notes: notes)
                                dismiss()
                            }
                        } label: {
                            Text(model.progressBusy ? "Saving…" : "Save photo").font(Theme.Font.body.weight(.semibold))
                                .frame(maxWidth: .infinity).padding(.vertical, 14)
                                .background(Theme.Grad.brand, in: RoundedRectangle(cornerRadius: Theme.Radius.chip))
                                .foregroundStyle(.white)
                        }.disabled(model.progressBusy)
                    }.padding(Theme.Space.m)
                }
            }
            .navigationTitle("New progress photo").navigationBarTitleDisplayMode(.inline)
            .toolbar { ToolbarItem(placement: .cancellationAction) { Button("Cancel") { dismiss() } } }
            .toolbarColorScheme(.dark, for: .navigationBar)
        }
    }

    private func labeledField(_ label: LocalizedStringKey, text: Binding<String>, keyboard: UIKeyboardType) -> some View {
        VStack(alignment: .leading, spacing: 5) {
            Text(label).textCase(.uppercase).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
            TextField("", text: text)
                .font(Theme.Font.body).foregroundStyle(Theme.Palette.text).keyboardType(keyboard)
                .padding(12).background(Theme.Palette.card, in: RoundedRectangle(cornerRadius: Theme.Radius.chip))
                .overlay(RoundedRectangle(cornerRadius: Theme.Radius.chip).strokeBorder(Theme.Palette.cardStroke))
        }
    }
}

// MARK: - Full-screen photo viewer

private struct PhotoViewerSheet: View {
    @EnvironmentObject var model: AppModel
    @Environment(\.dismiss) private var dismiss
    let photo: ProgressPhoto

    var body: some View {
        NavigationStack {
            ZStack {
                Color.black.ignoresSafeArea()
                RemoteImage(url: photo.photo_url).scaledToFit()
            }
            .navigationTitle(photoDate(photo.taken_at)).navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .confirmationAction) { Button("Done") { dismiss() } }
                ToolbarItem(placement: .destructiveAction) {
                    Button(role: .destructive) { Task { await model.deleteProgress(photo.id); dismiss() } } label: { Image(systemName: "trash") }
                }
            }
            .toolbarColorScheme(.dark, for: .navigationBar)
        }
    }
}

/// Identifiable wrapper so captured image Data can drive a `.sheet(item:)`.
private struct ImageData: Identifiable { let id = UUID(); let data: Data }

private func photoDate(_ ymd: String?) -> String {
    guard let ymd else { return "" }
    let inF = DateFormatter(); inF.dateFormat = "yyyy-MM-dd"
    guard let date = inF.date(from: ymd) else { return ymd }
    let out = DateFormatter(); out.dateFormat = "MMM d"
    return out.string(from: date)
}
