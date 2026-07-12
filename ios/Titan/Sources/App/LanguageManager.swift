import SwiftUI

/// In-app language switching (English + Mexican Spanish) — independent of the device language and LIVE
/// (no restart). SwiftUI `Text("…")` / `String(localized:)` resolve against the chosen language because we
/// swap `Bundle.main`'s localization at runtime (the standard dependency-free trick), and the root applies
/// `.environment(\.locale, …)` so dates/numbers format for the locale too. The choice is persisted in
/// `@AppStorage` and mirrored to the server (profile.primary_language) so the coach + emails match.
final class LanguageManager: ObservableObject {
    static let shared = LanguageManager()

    /// (bcp47 code, name in that language). "" (follow device) is offered as "System".
    static let supported: [(code: String, native: String)] = [
        ("en", "English"),
        ("es-MX", "Español (México)"),
    ]

    static let storageKey = "app_language"

    /// The resolved, active language code ("en" | "es-MX") — always one of `supported`.
    @Published private(set) var code: String

    /// What the user actually chose ("" = follow the device). Distinct from `code` so the picker can show
    /// "System" while `code` is the concrete resolved language.
    private(set) var choice: String

    private init() {
        let saved = UserDefaults.standard.string(forKey: Self.storageKey) ?? ""
        choice = saved
        code = Self.resolve(saved)
        Bundle.setLanguage(code)
    }

    var locale: Locale { Locale(identifier: code) }

    /// True when the given code is the current choice (drives the picker checkmark).
    func isChosen(_ c: String) -> Bool { choice == c }

    /// Set the app language ("" to follow the device), swap the bundle, and re-render the whole tree.
    func set(_ newChoice: String) {
        choice = newChoice
        UserDefaults.standard.set(newChoice, forKey: Self.storageKey)
        code = Self.resolve(newChoice)
        Bundle.setLanguage(code)
        objectWillChange.send()
    }

    /// The value to sync to the server (concrete code, never "").
    var serverValue: String { code }

    /// Resolve a stored choice to a concrete supported language. "" or an unsupported device language
    /// falls back to English; a Spanish device maps to es-MX.
    private static func resolve(_ choice: String) -> String {
        if choice == "es-MX" || choice == "en" { return choice }
        let device = Locale.preferredLanguages.first ?? "en"
        return device.hasPrefix("es") ? "es-MX" : "en"
    }
}

// MARK: - Runtime bundle localization swap

private var associatedLanguageBundle: UInt8 = 0

/// A `Bundle` subclass that redirects `localizedString(forKey:)` to the selected-language `.lproj` bundle,
/// so `Text`/`String(localized:)` pick up the chosen language without an app restart.
private final class LocalizedBundle: Bundle, @unchecked Sendable {
    override func localizedString(forKey key: String, value: String?, table tableName: String?) -> String {
        guard let bundle = objc_getAssociatedObject(self, &associatedLanguageBundle) as? Bundle else {
            return super.localizedString(forKey: key, value: value, table: tableName)
        }

        return bundle.localizedString(forKey: key, value: value, table: tableName)
    }
}

extension Bundle {
    /// Point `Bundle.main` at the given language's `.lproj` (String Catalogs compile to `<lang>.lproj`).
    static func setLanguage(_ language: String) {
        object_setClass(Bundle.main, LocalizedBundle.self)
        let path = Bundle.main.path(forResource: language, ofType: "lproj")
            ?? Bundle.main.path(forResource: String(language.prefix(2)), ofType: "lproj")
        objc_setAssociatedObject(Bundle.main, &associatedLanguageBundle,
                                 path.flatMap { Bundle(path: $0) },
                                 .OBJC_ASSOCIATION_RETAIN)
    }
}
