import Foundation
import Security

/// Minimal Keychain wrapper for the device signing secret (never in UserDefaults).
enum Keychain {
    static func set(_ value: String, for key: String) {
        let q: [String: Any] = [kSecClass as String: kSecClassGenericPassword,
                                kSecAttrAccount as String: key]
        SecItemDelete(q as CFDictionary)
        var add = q
        add[kSecValueData as String] = Data(value.utf8)
        add[kSecAttrAccessible as String] = kSecAttrAccessibleAfterFirstUnlock // readable overnight
        SecItemAdd(add as CFDictionary, nil)
    }

    static func get(_ key: String) -> String? {
        let q: [String: Any] = [kSecClass as String: kSecClassGenericPassword,
                                kSecAttrAccount as String: key,
                                kSecReturnData as String: true,
                                kSecMatchLimit as String: kSecMatchLimitOne]
        var out: AnyObject?
        guard SecItemCopyMatching(q as CFDictionary, &out) == errSecSuccess,
              let data = out as? Data else { return nil }
        return String(decoding: data, as: UTF8.self)
    }
}

/// Pairing + endpoint config. deviceId/ingestURL in UserDefaults, secret in Keychain.
final class Settings: ObservableObject {
    @Published var ingestURL: String {
        didSet { UserDefaults.standard.set(ingestURL, forKey: "titan.ingestURL") }
    }
    @Published var deviceId: String {
        didSet { UserDefaults.standard.set(deviceId, forKey: "titan.deviceId") }
    }
    @Published var secret: String {
        didSet { Keychain.set(secret, for: "titan.secret") }
    }

    init() {
        ingestURL = UserDefaults.standard.string(forKey: "titan.ingestURL") ?? ""
        deviceId = UserDefaults.standard.string(forKey: "titan.deviceId") ?? ""
        secret = Keychain.get("titan.secret") ?? ""
    }

    var isConfigured: Bool {
        !ingestURL.isEmpty && deviceId.count > 4 && secret.count >= 32
    }
}
