import SwiftUI

/// The Language row for the Profile/Settings screen: a `GlassCard` with a menu that switches the app
/// language LIVE (no restart) and mirrors the choice to the server (profile.primary_language) so the
/// coach + emails match. "System" follows the device language.
struct LanguageRow: View {
    @EnvironmentObject var language: LanguageManager
    @EnvironmentObject var model: AppModel

    var body: some View {
        Menu {
            Button { choose("") } label: {
                Label("System", systemImage: language.isChosen("") ? "checkmark" : "globe")
            }
            ForEach(LanguageManager.supported, id: \.code) { l in
                Button { choose(l.code) } label: {
                    Label(l.native, systemImage: language.isChosen(l.code) ? "checkmark" : "")
                }
            }
        } label: {
            GlassCard {
                HStack {
                    Label("Language", systemImage: "globe").font(Theme.Font.body).foregroundStyle(Theme.Palette.text)
                    Spacer()
                    Text(currentLabel).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                    Image(systemName: "chevron.up.chevron.down").font(.caption2).foregroundStyle(Theme.Palette.textFaint)
                }
            }
        }
        .buttonStyle(PressCard())
    }

    private var currentLabel: String {
        if language.isChosen("") { return String(localized: "System") }
        return LanguageManager.supported.first { $0.code == language.choice }?.native ?? "English"
    }

    private func choose(_ code: String) {
        Haptic.tap()
        language.set(code)
        Task { await model.saveProfile(["primary_language": language.serverValue]) }
    }
}

/// A full-width, tappable language chooser for onboarding — two big cards (English / Español (México))
/// so a brand-new user picks their language before anything else. Also mirrors to the server.
struct LanguageChoiceCards: View {
    @EnvironmentObject var language: LanguageManager
    @EnvironmentObject var model: AppModel

    var body: some View {
        VStack(spacing: Theme.Space.s) {
            ForEach(LanguageManager.supported, id: \.code) { l in
                Button {
                    Haptic.tap()
                    language.set(l.code)
                    Task { await model.saveProfile(["primary_language": language.serverValue]) }
                } label: {
                    GlassCard {
                        HStack {
                            Text(l.native).font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.text)
                            Spacer()
                            if language.code == l.code {
                                Image(systemName: "checkmark.circle.fill").foregroundStyle(Theme.Palette.mint)
                            }
                        }
                    }
                }
                .buttonStyle(PressCard())
            }
        }
    }
}
