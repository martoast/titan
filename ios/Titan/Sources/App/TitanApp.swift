import SwiftUI

@main
struct TitanApp: App {
    @StateObject private var model = AppModel()

    init() { Appearance.apply() }

    var body: some Scene {
        WindowGroup {
            RootView()
                .environmentObject(model)
                .preferredColorScheme(.dark)
                .tint(Theme.Palette.indigo)
        }
    }
}

/// Login gate → main tabbed app. The coach chat is the primary surface (first tab).
struct RootView: View {
    @EnvironmentObject var model: AppModel
    var body: some View {
        ZStack {
            if model.isLoggedIn {
                if model.onboarded {
                    TabView {
                        CoachView().tabItem { Label("Coach", systemImage: "bubble.left.and.text.bubble.right.fill") }
                        DashboardView().tabItem { Label("Today", systemImage: "circle.hexagongrid.fill") }
                        FuelView().tabItem { Label("Fuel", systemImage: "fork.knife") }
                        WorkoutsView().tabItem { Label("Train", systemImage: "figure.run") }
                        ProfileView().tabItem { Label("You", systemImage: "person.fill") }
                    }
                    .transition(.opacity)
                } else {
                    OnboardingView().transition(.move(edge: .trailing))
                }
            } else {
                LoginView().transition(.opacity)
            }
        }
        .animation(Theme.Motion.spring, value: model.isLoggedIn)
        .animation(Theme.Motion.spring, value: model.onboarded)
    }
}

/// Global UIKit appearance so the tab/nav bars match the dark, glassy theme.
enum Appearance {
    static func apply() {
        #if canImport(UIKit)
        let tab = UITabBarAppearance()
        tab.configureWithTransparentBackground()
        tab.backgroundEffect = UIBlurEffect(style: .systemUltraThinMaterialDark)
        tab.backgroundColor = UIColor(white: 0.02, alpha: 0.4)
        UITabBar.appearance().standardAppearance = tab
        UITabBar.appearance().scrollEdgeAppearance = tab

        let nav = UINavigationBarAppearance()
        nav.configureWithTransparentBackground()
        let largeTitle = [NSAttributedString.Key.foregroundColor: UIColor.white,
                          .font: UIFont.systemFont(ofSize: 32, weight: .bold)]
        nav.largeTitleTextAttributes = largeTitle
        nav.titleTextAttributes = [.foregroundColor: UIColor.white]
        UINavigationBar.appearance().standardAppearance = nav
        UINavigationBar.appearance().scrollEdgeAppearance = nav
        UINavigationBar.appearance().compactAppearance = nav
        #endif
    }
}
