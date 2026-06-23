import Foundation

// Codable models for the Laravel API responses the app consumes (see routes/api.php +
// tasks/native-ios/03-architecture.md). Optionals everywhere the server may return null.

struct AuthUser: Codable, Identifiable, Equatable {
    let id: Int
    let name: String?
    let email: String?
}

struct LoginResponse: Codable {
    let token: String
    let user: AuthUser
}

/// `GET /api/me/dashboard`
struct Dashboard: Codable {
    let readiness: Readiness?
    let recovery: Recovery?
    let sleep: Sleep?
    let activity: Activity?

    struct Readiness: Codable {
        let score: Int?
        let label: String?
        let note: String?
        let provisional: Bool?
    }
    struct Recovery: Codable {
        let hrv_ms: Double?
        let resting_hr: Double?
        let resp_rate: Double?
        let stress: Int?
        let soreness: Int?
        let mood: Int?
        let energy: Int?
        let updated_via: String?
        let confidence: Confidence?
        struct Confidence: Codable { let level: String?; let caveat: String?; let nights_of_data: Int? }
    }
    struct Sleep: Codable {
        let duration_min: Int?
        let quality: Int?
        let deep_min: Int?
        let rem_min: Int?
        let light_min: Int?
        let awake_min: Int?
    }
    struct Activity: Codable {
        let steps: Int?
        let active_kcal: Int?
        let floors: Int?
        let distance_km: Double?
    }
}

/// `GET /api/me/trends`
struct TrendResponse: Codable {
    let metric: String
    let points: [Point]
    struct Point: Codable { let date: String; let value: Double }
}

/// `POST /api/devices/pair`
struct PairResponse: Codable {
    let device_id: String
    let secret: String
    let source: String?
    let connection_id: Int?
}

/// One coach chat message (local model; history comes from `/api/coach/{id}/messages`).
struct ChatMessage: Identifiable, Equatable {
    enum Role: String { case user, assistant }
    let id = UUID()
    var role: Role
    var text: String
    var streaming: Bool = false
}

enum APIError: LocalizedError {
    case http(Int, String), decoding, unauthorized, transport(String)
    var errorDescription: String? {
        switch self {
        case .http(let s, let m): return "Server error \(s): \(m)"
        case .decoding: return "Couldn't read the server response."
        case .unauthorized: return "Your session expired — please sign in again."
        case .transport(let m): return m
        }
    }
}
