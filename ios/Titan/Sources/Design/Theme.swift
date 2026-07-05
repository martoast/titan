import SwiftUI

/// Titan's design language — a premium, dark, data-forward system (Whoop/Oura-grade). One source
/// of truth for color, type, spacing, motion, and haptics so every screen feels intentional.
enum Theme {

    // MARK: Color — near-black canvas, luminous accents, semantic recovery scale.
    enum Palette {
        static let bg = Color(hex: 0x07070A)              // app canvas
        static let bg2 = Color(hex: 0x0E0E13)             // raised
        static let card = Color.white.opacity(0.045)
        static let cardStroke = Color.white.opacity(0.07)
        static let text = Color.white
        static let textDim = Color.white.opacity(0.62)
        static let textFaint = Color.white.opacity(0.34)

        static let indigo = Color(hex: 0x6D6BF6)
        static let cyan = Color(hex: 0x22D3EE)
        static let mint = Color(hex: 0x34E5C0)
        static let pink = Color(hex: 0xFF4D8D)
        static let amber = Color(hex: 0xFFB020)
        static let violet = Color(hex: 0xA78BFA)

        /// Recovery/readiness color scale (green→yellow→red), Whoop-style.
        static func recovery(_ score: Int?) -> Color {
            switch score ?? -1 {
            case 67...: return mint
            case 34..<67: return amber
            case 0..<34: return pink
            default: return Color.white.opacity(0.25)
            }
        }
    }

    enum Grad {
        static func ring(_ c: Color) -> AngularGradient {
            AngularGradient(colors: [c.opacity(0.35), c, c.opacity(0.9), c],
                            center: .center, startAngle: .degrees(-90), endAngle: .degrees(270))
        }
        static let brand = LinearGradient(colors: [Palette.indigo, Palette.cyan],
                                          startPoint: .topLeading, endPoint: .bottomTrailing)
        static let sheen = LinearGradient(colors: [.white.opacity(0.07), .clear],
                                          startPoint: .top, endPoint: .bottom)
        static func glow(_ c: Color) -> RadialGradient {
            RadialGradient(colors: [c.opacity(0.5), .clear], center: .center, startRadius: 1, endRadius: 120)
        }
    }

    // MARK: Type — SF Rounded, tight numeric hierarchy.
    enum Font {
        static func display(_ s: CGFloat) -> SwiftUI.Font { .system(size: s, weight: .bold, design: .rounded) }
        static func num(_ s: CGFloat, _ w: SwiftUI.Font.Weight = .bold) -> SwiftUI.Font { .system(size: s, weight: w, design: .rounded) }
        static let title = SwiftUI.Font.system(.title3, design: .rounded).weight(.bold)
        static let body = SwiftUI.Font.system(.subheadline, design: .rounded)
        static let label = SwiftUI.Font.system(.caption, design: .rounded).weight(.semibold)
        static let micro = SwiftUI.Font.system(.caption2, design: .rounded).weight(.semibold)
    }

    enum Space { static let xs: CGFloat = 6, s: CGFloat = 10, m: CGFloat = 16, l: CGFloat = 22, xl: CGFloat = 32 }
    enum Radius { static let card: CGFloat = 22, chip: CGFloat = 14, pill: CGFloat = 100 }

    enum Motion {
        static let spring = Animation.spring(response: 0.5, dampingFraction: 0.78)
        static let snappy = Animation.spring(response: 0.34, dampingFraction: 0.7)
        static let ring = Animation.easeOut(duration: 1.1)
    }
}

// MARK: - Haptics
enum Haptic {
    #if canImport(UIKit)
    static func tap() { UIImpactFeedbackGenerator(style: .light).impactOccurred() }
    static func soft() { UIImpactFeedbackGenerator(style: .soft).impactOccurred() }
    static func rigid() { UIImpactFeedbackGenerator(style: .rigid).impactOccurred() }
    static func success() { UINotificationFeedbackGenerator().notificationOccurred(.success) }
    static func warning() { UINotificationFeedbackGenerator().notificationOccurred(.warning) }
    #else
    static func tap() {}; static func soft() {}; static func rigid() {}
    static func success() {}; static func warning() {}
    #endif
}

// MARK: - Helpers
extension Color {
    init(hex: UInt32, alpha: Double = 1) {
        self.init(.sRGB,
                  red: Double((hex >> 16) & 0xFF) / 255,
                  green: Double((hex >> 8) & 0xFF) / 255,
                  blue: Double(hex & 0xFF) / 255,
                  opacity: alpha)
    }
}

extension View {
    /// The app's standard scrollable screen: dark canvas, large title, soft top-glow.
    func titanScreen(_ title: String, glow: Color = Theme.Palette.indigo) -> some View {
        NavigationStack {
            ZStack(alignment: .top) {
                Theme.Palette.bg.ignoresSafeArea()
                Theme.Grad.glow(glow).frame(height: 320).opacity(0.5).ignoresSafeArea(edges: .top)
                ScrollView { self.padding(.horizontal, Theme.Space.m).padding(.top, Theme.Space.s) }
                    .scrollIndicators(.hidden)
            }
            .navigationTitle(title)
            .toolbarColorScheme(.dark, for: .navigationBar)
        }
    }

    /// Same look as `titanScreen`, but WITHOUT its own NavigationStack — for a detail screen PUSHED from
    /// Today (it inherits the parent stack, so it gets a back button and no double nav bar).
    func titanDetail(_ title: String, glow: Color = Theme.Palette.indigo) -> some View {
        ZStack(alignment: .top) {
            Theme.Palette.bg.ignoresSafeArea()
            Theme.Grad.glow(glow).frame(height: 320).opacity(0.5).ignoresSafeArea(edges: .top)
            ScrollView { self.padding(.horizontal, Theme.Space.m).padding(.top, Theme.Space.s) }
                .scrollIndicators(.hidden)
        }
        .navigationTitle(title)
        .navigationBarTitleDisplayMode(.inline)
        .toolbarColorScheme(.dark, for: .navigationBar)
    }
}
