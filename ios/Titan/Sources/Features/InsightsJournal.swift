import SwiftUI

/// One ranked insight card (anomaly / goal / win / correlation) for the Today feed.
struct InsightCard: View {
    let insight: Insight
    var body: some View {
        GlassCard {
            HStack(alignment: .top, spacing: Theme.Space.m) {
                ZStack {
                    Circle().fill(color.opacity(0.16)).frame(width: 40, height: 40)
                    Image(systemName: insight.icon ?? "sparkles")
                        .font(.system(size: 17, weight: .semibold)).foregroundStyle(color)
                }
                VStack(alignment: .leading, spacing: 3) {
                    Text(insight.title).font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.text)
                    Text(insight.detail).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                        .fixedSize(horizontal: false, vertical: true)
                }
                Spacer(minLength: 0)
            }
        }
    }

    private var color: Color {
        switch insight.tone {
        case "alert": return Theme.Palette.pink
        case "bad": return Theme.Palette.amber
        case "good": return Theme.Palette.mint
        default: return Theme.Palette.indigo
        }
    }
}

/// The behavior journal — tap what happened today; it feeds the correlation engine.
struct JournalSheet: View {
    @EnvironmentObject var model: AppModel
    @Environment(\.dismiss) private var dismiss

    private let cols = [GridItem(.adaptive(minimum: 104), spacing: 8)]

    var body: some View {
        NavigationStack {
            ZStack {
                Theme.Palette.bg.ignoresSafeArea()
                ScrollView {
                    VStack(alignment: .leading, spacing: Theme.Space.l) {
                        Text("Tap what happened today. Titan learns what actually moves your recovery & sleep — then tells you.")
                            .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)

                        ForEach(categories, id: \.self) { cat in
                            VStack(alignment: .leading, spacing: Theme.Space.s) {
                                Text(cat.uppercased()).font(Theme.Font.micro).tracking(1).foregroundStyle(Theme.Palette.textFaint)
                                LazyVGrid(columns: cols, spacing: 8) {
                                    ForEach(model.journalCatalog.filter { $0.category == cat }) { chip($0) }
                                }
                            }
                        }
                        Color.clear.frame(height: 8)
                    }
                    .padding(Theme.Space.m)
                }
            }
            .navigationTitle("Log your day").navigationBarTitleDisplayMode(.inline)
            .toolbar { ToolbarItem(placement: .confirmationAction) { Button("Done") { dismiss() } } }
            .toolbarColorScheme(.dark, for: .navigationBar)
            .task { await model.loadJournal() }
        }
    }

    private var categories: [String] {
        var seen = Set<String>(), order: [String] = []
        for item in model.journalCatalog where !seen.contains(item.category) {
            seen.insert(item.category); order.append(item.category)
        }
        return order
    }

    private func chip(_ item: JournalItem) -> some View {
        let on = model.journalLogged.contains(item.key)
        return Button {
            Haptic.tap()
            Task { await model.toggleBehavior(item.key) }
        } label: {
            Text(item.label).font(Theme.Font.micro).multilineTextAlignment(.center)
                .foregroundStyle(on ? .white : Theme.Palette.textDim)
                .frame(maxWidth: .infinity, minHeight: 34)
                .padding(.horizontal, 8)
                .background(on ? polarityColor(item.polarity) : Theme.Palette.card, in: Capsule())
                .overlay(Capsule().strokeBorder(on ? .clear : Theme.Palette.cardStroke))
        }
        .buttonStyle(.plain)
    }

    private func polarityColor(_ p: String) -> Color {
        switch p {
        case "good": return Theme.Palette.mint
        case "bad": return Theme.Palette.pink
        default: return Theme.Palette.indigo
        }
    }
}
