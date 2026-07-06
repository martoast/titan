import SwiftUI

@main
struct TitanApp: App {
    // Registers AppDelegate so `didFinishLaunchingWithOptions` builds the band stack (restore-id central)
    // synchronously at launch — BEFORE the WindowGroup body evaluates `AppModel()` — for reliable BLE
    // State Restoration on a cold background relaunch (see AppDelegate / B1).
    @UIApplicationDelegateAdaptor(AppDelegate.self) private var appDelegate
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
                        // Foreground housekeeping: make sure the always-on link is up, resync the clock,
                        // push steps, and catch up any workout/sleep that sealed while we were away.
                        model.holdConnection()
                    case .background:
                        // Whoop-style ALWAYS-ON: backgrounding must NOT drop the band — the persistent
                        // 24/7 link keeps streaming while the app is backgrounded / the phone is locked.
                        // (No release; the old burst-sync that dropped the link here is retired.)
                        // DO ship the trailing partial windows (HR trend + PPG) so the cloud graph is
                        // current and nothing is lost if iOS jetsams the suspended app.
                        model.flushWindowsForBackground()
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
                        TrendsView().tabItem { Label("Trends", systemImage: "chart.xyaxis.line") }
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
