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
    let bio_age: BioAge?
    let recovery: Recovery?
    let sleep: Sleep?
    let activity: Activity?

    struct BioAge: Codable {
        let biological_age: Double?
        let chronological_age: Double?
        let delta: Double?           // bio − chrono; negative = younger than your years
        let label: String?
        let confidence: String?      // high | medium | low
        let fitness_age: Double?
    }

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

// MARK: - Nutrition (the Fuel tab)

/// `GET /api/me/nutrition` — the macro-ring card + today's logged meals.
struct NutritionToday: Codable, Equatable {
    let macros: MacroCard
    let meals: [Meal]
}

/// Today's fuel card (mirrors Macros::today): each line is value vs target.
struct MacroCard: Codable, Equatable {
    let title: String?
    let calories: MacroLine
    let protein: MacroLine
    let carbs: MacroLine
    let fat: MacroLine
    let footer: String?
}
struct MacroLine: Codable, Equatable {
    let value: Int
    let target: Int
    var fraction: Double { target > 0 ? min(1, Double(value) / Double(target)) : 0 }
}

struct Meal: Codable, Identifiable, Equatable {
    let id: Int
    let name: String?
    let eaten_at: String?
    let calories: Int
    let protein_g: Double
    let carbs_g: Double
    let fat_g: Double
    let photo_url: String?
    let source: String?
}

/// `POST /api/me/nutrition/scan` — photo → AI macros (grounded + logged) + updated card.
struct MealScanResult: Codable, Identifiable {
    var id = UUID()
    let kind: String              // meal | physique | bloodwork | other
    let meal: Meal?
    let progress_photo_id: Int?
    let image_url: String?
    let message: String?
    let macros: MacroCard
    enum CodingKeys: String, CodingKey { case kind, meal, progress_photo_id, image_url, message, macros }
}

/// `POST/PATCH /api/me/meals` → { meal, macros }
struct MealMutation: Codable { let meal: Meal; let macros: MacroCard }
/// `DELETE /api/me/meals/{id}` → { ok, macros }
struct MacrosOnly: Codable { let ok: Bool?; let macros: MacroCard }

// MARK: - Progress photos

struct ProgressPhotosResponse: Codable { let photos: [ProgressPhoto] }
struct ProgressPhotoResponse: Codable { let photo: ProgressPhoto }

struct ProgressPhoto: Codable, Identifiable, Equatable {
    let id: Int
    let taken_at: String?
    let pose: String?
    let weight_kg: Double?
    let notes: String?
    let photo_url: String?
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
