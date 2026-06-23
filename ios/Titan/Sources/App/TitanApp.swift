import SwiftUI

@main
struct TitanApp: App {
    @StateObject private var model = AppModel()

    var body: some Scene {
        WindowGroup {
            RootView()
                .environmentObject(model)
                .preferredColorScheme(.dark)
                .tint(.indigo)
        }
    }
}

/// Login gate → main tabbed app. The coach chat is the primary surface, first tab.
struct RootView: View {
    @EnvironmentObject var model: AppModel

    var body: some View {
        if model.isLoggedIn {
            TabView {
                CoachView()
                    .tabItem { Label("Coach", systemImage: "bubble.left.and.text.bubble.right.fill") }
                DashboardView()
                    .tabItem { Label("Today", systemImage: "chart.bar.doc.horizontal.fill") }
                WorkoutsView()
                    .tabItem { Label("Train", systemImage: "figure.run") }
                DevicesView()
                    .tabItem { Label("Band", systemImage: "applewatch.radiowaves.left.and.right") }
                ProfileView()
                    .tabItem { Label("You", systemImage: "person.fill") }
            }
        } else {
            LoginView()
        }
    }
}
