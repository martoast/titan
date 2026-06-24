import Foundation

// Codable models for the Laravel API responses the app consumes (see routes/api.php +
// tasks/native-ios/03-architecture.md). Optionals everywhere the server may return null.

struct AuthUser: Codable, Identifiable, Equatable {
    let id: Int
    let name: String?
    let email: String?
    let onboarded: Bool?
}

/// `GET /api/me/profile` (and `/me/onboarding`) — the editable onboarding profile, for pre-fill.
struct ProfileSnapshot: Codable, Equatable {
    var display_name: String?
    var birthdate: String?
    var sex: String?
    var units: String?
    var height: Double?
    var activity_level: String?
    var primary_goal: String?
    var coach_tone: String?
    var coaching_intensity: String?
    var meals_per_day: Int?
    var eat_start: String?
    var eat_end: String?
    var timezone: String?
    var experience: String?
    var train_at: String?
    var train_days: Int?
    var diet: String?
    var allergies: String?
    var avoid_foods: String?
    var injuries: String?
    var health_notes: String?
    var focus_areas: String?
    var motivation: String?
    var event_date: String?
    var cycle_enabled: Bool?
    var cycle_length: Int?
    var birth_control: String?
    var cycle_intent: String?
}
struct ProfileResponse: Codable { let profile: ProfileSnapshot }
struct OnboardingStatus: Codable { let onboarded: Bool }
struct OnboardingResult: Codable { let onboarded: Bool; let route: String? }

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

// MARK: - Editable targets

struct TargetsResponse: Codable { let targets: Targets }

/// `GET/PATCH /api/me/targets` — daily macro + nightly sleep targets (also settable via the coach).
struct Targets: Codable, Equatable {
    var calories: Int
    var protein_g: Int
    var carbs_g: Int
    var fat_g: Int
    var sleep_h: Double
    var custom: Bool
}

// MARK: - Insight feed + journal (Whoop/Oura-parity)

struct InsightsResponse: Codable { let insights: [Insight] }

/// `GET /api/me/insights` — a ranked personalized card.
struct Insight: Codable, Identifiable {
    var id = UUID()
    let kind: String        // anomaly | goal | win | correlation
    let tone: String        // good | bad | neutral | alert
    let icon: String?
    let title: String
    let detail: String
    enum CodingKeys: String, CodingKey { case kind, tone, icon, title, detail }
}

/// `GET /api/me/journal`
struct JournalResponse: Codable { let date: String; let catalog: [JournalItem]; let logged: [String] }
/// `POST /api/me/journal`
struct JournalLogResponse: Codable { let date: String; let logged: [String] }

struct JournalItem: Codable, Identifiable {
    var id: String { key }
    let key: String
    let label: String
    let polarity: String    // good | bad | neutral
    let category: String
}

/// `GET /api/me/weight`
struct WeightCard: Codable {
    let trend_kg: Double?
    let latest_kg: Double?
    let rate_kg_wk: Double?
    let goal: WeightGoal?
    let series: [WeightPoint]?
    struct WeightGoal: Codable {
        let target_kg: Double?
        let target_date: String?
        let on_track: Bool?
        let projected_date: String?
        let eta_days: Int?
        let daily_kcal: Int?
        let vs_goal_days: Int?
    }
    struct WeightPoint: Codable { let date: String; let weight: Double?; let trend: Double }
}

/// `GET/POST /api/me/hydration`
struct HydrationToday: Codable, Equatable {
    let total_ml: Int
    let target_ml: Int
    let pct: Int
    let date: String?
    var litres: Double { Double(total_ml) / 1000 }
    var targetLitres: Double { Double(target_ml) / 1000 }
}

/// `GET /api/me/fasting`, `POST /api/me/fasting/{start,end}`
struct FastingStatus: Codable, Equatable {
    let active: Bool
    let started_at: String?
    let elapsed_h: Double?
    let goal_h: Double?
    let pct: Int?
    let stage: String?
    let stage_blurb: String?
    let next_stage_in_h: Double?
}

/// `GET /api/me/health` · result of `POST /api/me/health/ingest`
struct HealthStatus: Codable { let connected: Bool; let last_sync_at: String? }
struct HealthIngestResult: Codable { let ok: Bool; let synced_at: String? }

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
