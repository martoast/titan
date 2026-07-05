import SwiftUI

@main
struct TitanApp: App {
    @StateObject private var model = AppModel()
    @Environment(\.scenePhase) private var scenePhase

    init() { Appearance.apply() }

    var body: some Scene {
        WindowGroup {
            RootView()
                .environmentObject(model)
                .preferredColorScheme(.dark)
                .tint(Theme.Palette.indigo)
                .onChange(of: scenePhase) { _, phase in
                    switch phase {
                    case .active:
                        // Pull fresh Apple Health data whenever the app comes forward (free-team friendly).
                        if model.isLoggedIn && model.healthConnected {
                            Task { await model.syncAppleHealth() }
                        }
                        // Burst-sync: hold a live band link while we're up front (workouts / checking stats).
                        model.holdConnection()
                    case .background:
                        // Idle in the background → release the link so the band saves battery. It
                        // re-syncs the whole day the next time you open the app (firmware auto-flushes
                        // its buffer on every reconnect).
                        model.releaseConnectionAfterGrace()
                    default:
                        break
                    }
                }
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
                        DailyView().tabItem { Label("Daily", systemImage: "square.stack.3d.up.fill") }
                        CommunityView().tabItem { Label("Community", systemImage: "person.2.fill") }
                        ProfileView().tabItem { Label("You", systemImage: "person.fill") }
                    }
                    .transition(.opacity)
                    // A run streaming from the band pops the live tracker from any tab.
                    .fullScreenCover(isPresented: $model.showLiveRunSheet) { LiveRunView().environmentObject(model) }
                    // The moment a workout ends, show its summary (run map/splits or lift HR/zones/sets).
                    .sheet(item: $model.workoutSummary) { s in WorkoutSummaryView(summary: s).environmentObject(model) }
                    // The moment the night ends (WAKE on the watch), show the premium sleep summary.
                    .sheet(item: $model.sleepSummary) { s in SleepSummaryView(summary: s).environmentObject(model) }
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
