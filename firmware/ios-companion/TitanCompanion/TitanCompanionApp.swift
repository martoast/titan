import SwiftUI

@main
struct TitanCompanionApp: App {
    // BangleManager owns the single Settings instance; we surface both to the views.
    @StateObject private var bangle = BangleManager(settings: Settings())

    var body: some Scene {
        WindowGroup {
            ContentView()
                .environmentObject(bangle)
                .environmentObject(bangle.settings)
        }
    }
}
