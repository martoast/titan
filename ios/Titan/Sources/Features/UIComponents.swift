import SwiftUI

/// Shared dark-theme building blocks so the screens stay terse and consistent.
struct Card<Content: View>: View {
    let title: String?
    @ViewBuilder var content: Content
    init(_ title: String? = nil, @ViewBuilder content: () -> Content) { self.title = title; self.content = content() }
    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            if let title {
                Text(title.uppercased())
                    .font(.caption.weight(.bold)).foregroundStyle(.secondary).tracking(1)
            }
            content
        }
        .padding(16)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(.white.opacity(0.04), in: RoundedRectangle(cornerRadius: 18))
        .overlay(RoundedRectangle(cornerRadius: 18).strokeBorder(.white.opacity(0.06)))
    }
}

struct StatTile: View {
    let value: String
    let label: String
    var accent: Color = .primary
    var body: some View {
        VStack(spacing: 4) {
            Text(value).font(.system(.title2, design: .rounded).weight(.bold)).foregroundStyle(accent)
            Text(label.uppercased()).font(.caption2.weight(.semibold)).foregroundStyle(.secondary).tracking(0.5)
        }
        .frame(maxWidth: .infinity)
    }
}

/// A readiness/score ring.
struct ScoreRing: View {
    let score: Int?
    let label: String
    var body: some View {
        ZStack {
            Circle().stroke(.white.opacity(0.08), lineWidth: 12)
            Circle()
                .trim(from: 0, to: CGFloat(score ?? 0) / 100)
                .stroke(ringColor, style: StrokeStyle(lineWidth: 12, lineCap: .round))
                .rotationEffect(.degrees(-90))
                .animation(.easeOut, value: score)
            VStack(spacing: 2) {
                Text(score.map(String.init) ?? "—").font(.system(size: 34, design: .rounded).weight(.bold))
                Text(label).font(.caption).foregroundStyle(.secondary)
            }
        }
        .frame(width: 140, height: 140)
    }
    private var ringColor: Color {
        switch score ?? 0 { case 75...: return .green; case 50..<75: return .yellow; default: return .orange }
    }
}

extension View {
    /// Standard screen scaffold: dark background + scrollable padded content.
    func screen(_ title: String) -> some View {
        NavigationStack { ScrollView { self.padding(16) }.navigationTitle(title).background(Color.black.ignoresSafeArea()) }
    }
}

func minToHrs(_ m: Int?) -> String { guard let m else { return "—" }; return String(format: "%.1fh", Double(m) / 60) }
