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
    let rings: Rings?
    let bio_age: BioAge?
    let recovery: Recovery?
    let sleep: Sleep?
    let activity: Activity?
    let workout: Workout?

    /// Training on Today: what you did today (if anything) + the consecutive-day streak.
    struct Workout: Codable {
        let today: RunSummary?
        let streak: WorkoutStreakInfo?
    }

    /// The three Whoop rings: recovery %, sleep performance %, day strain (0-21).
    struct Rings: Codable {
        let recovery: Int?
        let sleep_performance: Int?
        let strain: Double?
    }

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
        let metrics: [Metric]?          // Whoop-style breakdown: each metric with its baseline + trend
        struct Confidence: Codable {
            let level: String?; let caveat: String?; let nights_of_data: Int?
            // Server keys (RecoveryConfidence::toArray) are `note` + `nights` — without this mapping
            // both fields silently decoded to nil forever (all-optional struct hides the mismatch).
            enum CodingKeys: String, CodingKey { case level, caveat = "note", nights_of_data = "nights" }
        }
        struct Metric: Codable, Identifiable {
            let key: String; let label: String; let unit: String
            let value: Double; let baseline: Double?
            let trend: String            // "up" | "down" | "flat"
            let higher_better: Bool; let good: Bool?
            var id: String { key }
        }
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

/// `GET /api/me/sleep`
struct SleepResponse: Codable {
    let assess: Assess?
    let detail: Detail?
    let nights: [Night]
    struct Assess: Codable {
        let need_h: Double?; let debt_h: Double?; let last_h: Double?
        let performance_pct: Int?; let band: String?; let label: String?; let advice: String?
    }
    /// The Whoop-style sleep breakdown.
    struct Detail: Codable {
        let date: String?
        let performance_pct: Int?
        let duration_min: Int?
        let need_h: Double?; let debt_h: Double?
        let in_bed_min: Int?; let asleep_min: Int?
        let efficiency_pct: Int?
        let restorative_min: Int?
        let respiratory_rate: Double?
        let consistency_pct: Int?
        let quality: Int?
        let stages: [Stage]
        let bedtime: String?; let wake_time: String?
        let hypnogram: [String]?         // per-30s stage codes: wake/light/deep/rem
        let epoch_sec: Int?
        struct Stage: Codable, Identifiable {
            let key: String; let label: String; let min: Int; let pct: Int; let color: String
            var id: String { key }
        }
    }
    struct Night: Codable, Identifiable {
        var id: String { date ?? "" }
        let date: String?; let duration_min: Int?; let quality: Int?
        let deep_min: Int?; let rem_min: Int?; let light_min: Int?; let awake_min: Int?
    }
}

/// The Whoop-style Overview history: a daily series of the three rings + HRV/RHR/sleep, with averages.
struct OverviewResponse: Codable {
    let days: Int
    let points: [Point]
    let averages: Averages
    struct Point: Codable, Identifiable {
        let date: String
        let recovery: Int?; let sleep_performance: Int?; let strain: Double?
        let hrv: Int?; let rhr: Int?; let sleep_h: Double?
        var id: String { date }
    }
    struct Averages: Codable {
        let recovery: Double?; let sleep_performance: Double?; let strain: Double?
        let hrv: Double?; let rhr: Double?; let sleep_h: Double?
    }
}

/// The Whoop-style Strain screen: today's day strain building through the day, the recovery-based
/// target band, and the workouts that drove it (each with the strain it added).
struct StrainResponse: Codable {
    let strain: Double?
    let max: Double?
    let band: String?
    let label: String?
    let target: Target?
    let status: String?
    let advice: String?
    let readiness: Int?
    let suggestion: Suggestion?
    let curve: [Point]
    let contributions: [Contribution]
    struct Target: Codable { let low: Double?; let high: Double?; let mode: String?; let label: String? }
    struct Suggestion: Codable { let minutes: Int; let zone: String; let label: String; let strain_to_go: Double }
    struct Point: Codable, Identifiable { let t: String; let strain: Double; var id: String { t } }
    struct Contribution: Codable, Identifiable {
        let id: Int
        let title: String?; let activity_type: String?; let started_at: String?
        let duration_min: Int?; let avg_hr: Int?; let trimp: Double?; let strain_added: Double?
    }
}

/// `GET /api/me/hr` — the 24/7 all-day HR graph: per-minute points + the day's resting/min/max/avg.
struct HrResponse: Codable {
    let date: String?
    let points: [Point]
    let resting_hr: Int?
    let min: Int?
    let max: Int?
    let avg: Int?
    let count: Int
    struct Point: Codable, Identifiable {
        var id: Int { t }
        let t: Int          // epoch seconds
        let bpm: Int
        let conf: Int?
    }
}

/// `GET /api/me/cycle` — the women's Cycle view (phase, fertile window, Flo-style pregnancy chance).
struct CycleResponse: Codable {
    let available: Bool
    let cycle: Cycle?
    let symptoms: [String]?
    let flows: [String]?
    struct Cycle: Codable {
        let cycle_day: Int?
        let phase: String?
        let phase_label: String?
        let phase_blurb: String?
        let avg_length: Int?
        let period_length: Int?
        let late: Bool?
        let conception: Conception?
        let fertile_window: Fertile?
        let next_period: NextPeriod?
        let ovulation: Ovulation?
        struct Conception: Codable { let likelihood: String?; let note: String? }
        struct Fertile: Codable { let start: String?; let end: String?; let active: Bool? }
        struct NextPeriod: Codable { let date: String?; let in_days: Int? }
        struct Ovulation: Codable { let date: String?; let in_days: Int?; let day: Int? }
    }
}

/// `GET /api/me/cycle/calendar` — projected per-day classification for the calendar view.
struct CycleCalendarResponse: Codable {
    let available: Bool
    let days: [Day]
    struct Day: Codable, Identifiable {
        var id: String { date }
        let date: String
        let cycle_day: Int?
        let phase: String?
        let period: Bool?
        let fertile: Bool?
        let ovulation: Bool?
    }
}

/// One coach chat message (local model; history comes from `/api/coach/{id}/messages`).
struct ChatMessage: Identifiable, Equatable {
    enum Role: String { case user, assistant }
    let id = UUID()
    var role: Role
    var text: String
    var streaming: Bool = false
    var imageData: Data? = nil      // a photo attached to a user message — shown inline in its bubble
}

/// `POST /api/coach/transcribe` → { ok, text } — Whisper transcription of a recorded voice clip.
struct TranscribeResult: Codable { let ok: Bool; let text: String? }

/// `POST /api/coach[/{id}]/scan` → the coach's reply to a photo. The server runs vision and
/// auto-logs meals/bloodwork/physique when it recognizes them; `reply` is always the chat answer.
struct CoachScanResult: Codable {
    let ok: Bool
    let conversation_id: Int?
    let reply: String?
    let image_url: String?
    let kind: String?
    let logged: Bool?
    let offline: Bool?
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

/// `POST /api/me/nutrition/scan` — photo → AI identifies it → macros nailed (your usuals / official
/// branded label / web) → a DRAFT to confirm the amount before logging. Non-meals are filed already.
struct MealScanResult: Codable, Identifiable {
    var id = UUID()
    let kind: String              // meal | physique | bloodwork | other
    let draft: MealDraft?         // present for a meal — not yet logged; the user confirms the amount
    let progress_photo_id: Int?
    let image_url: String?
    let message: String?
    let macros: MacroCard
    enum CodingKeys: String, CodingKey { case kind, draft, progress_photo_id, image_url, message, macros }
}

/// A scanned meal awaiting confirmation. Macros are for ONE serving of what was identified; the confirm
/// sheet scales them by the servings the user sets, then logs via `/meals/confirm`.
struct MealDraft: Codable {
    let photo_path: String?
    let image_url: String?
    let name: String
    let brand: String?
    let items: [String]?
    let calories: Int
    let protein_g: Double
    let carbs_g: Double
    let fat_g: Double
    let confidence: String        // low | medium | high
    let source: String            // your_meals | brand | web | photo — where the macros came from
    let serving_hint: String?
    let needs_confirmation: Bool
}

/// `POST/PATCH /api/me/meals` → { meal, macros }
struct MealMutation: Codable { let meal: Meal; let macros: MacroCard }
/// `DELETE /api/me/meals/{id}` → { ok, macros }
struct MacrosOnly: Codable { let ok: Bool?; let macros: MacroCard }

/// A remembered dish in the user's meal library — re-loggable in one tap (no camera/AI).
struct MealTemplate: Codable, Identifiable, Equatable {
    let id: Int
    let name: String
    let calories: Int
    let protein_g: Double
    let carbs_g: Double
    let fat_g: Double
    let photo_url: String?
    let times_logged: Int
    let last_eaten_at: String?
    let favorite: Bool
}
/// `GET /api/me/meals-library` → { meals: [...] }
struct MealLibrary: Codable { let meals: [MealTemplate] }
/// `PATCH /api/me/meals-library/{id}/favorite` → { template }
struct MealTemplateMutation: Codable { let template: MealTemplate }

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

// MARK: - The Stack ("What you take" — supplements & medications)

/// Decodes a value the API may send as either a String or a number, surfaced as a String. Used for
/// external catalog ids (DSLD ids / RxCUIs come back numeric from some endpoints, string from others).
struct LooseString: Codable, Equatable, Hashable {
    let value: String
    init(_ v: String) { value = v }
    init(from decoder: Decoder) throws {
        let c = try decoder.singleValueContainer()
        if let s = try? c.decode(String.self) { value = s }
        else if let i = try? c.decode(Int.self) { value = String(i) }
        else if let d = try? c.decode(Double.self) { value = String(Int(d)) }
        else { value = "" }
    }
    func encode(to encoder: Encoder) throws { var c = encoder.singleValueContainer(); try c.encode(value) }
}

/// The unified stack payload returned by `GET /api/me/stack` and every mutation
/// (add/update/delete/intake). All fields optional so one type decodes every shape — the mutations
/// echo back the refreshed snapshot plus an `item`/`event_id`/`ok` we mostly ignore.
struct StackResponse: Codable, Equatable {
    let ok: Bool?
    let item: StackItem?
    let event_id: Int?
    let today: StackToday?
    let items: [StackItem]?
    let flags: [InteractionFlag]?
    let disclaimer: String?
}

/// Today's checklist — doses grouped by time-of-day. No "x of y" scoreboard on the card; progress is
/// carried by checkmarks + the calm `footer`.
struct StackToday: Codable, Equatable {
    let type: String?
    let title: String?
    let slots: [StackSlot]
    let counts: Counts?
    let worth_knowing: Int?
    let footer: String?
    struct Counts: Codable, Equatable { let taken: Int; let total: Int; let extra: Int }
}

struct StackSlot: Codable, Equatable, Identifiable {
    var id: String { key }
    let key: String
    let label: String
    let items: [StackTodayItem]
}

/// One due dose in today's checklist. `id` is the parent stack item; `event_id` is set once taken
/// (so we can undo). `taken` drives the dim/collapse + checkmark.
struct StackTodayItem: Codable, Equatable, Identifiable {
    let id: Int
    let event_id: Int?
    let name: String
    let dose: String?
    let kind: String?
    let with_food: Bool?
    let slot: String?
    let taken: Bool
}

/// A persistent protocol entry (the thing you take, with a schedule).
struct StackItem: Codable, Equatable, Identifiable {
    let id: Int
    let name: String
    let kind: String                 // supplement | medication | other
    let brand: String?
    let dose_amount: Double?
    let dose_unit: String?
    let dose_label: String?
    let form: String?
    let schedule: StackSchedule?
    let slots: [String]
    let active: Bool
    let photo_url: String?
    let adherence: Int?              // last-~14d adherence %
    let notes: String?
}

struct StackSchedule: Codable, Equatable {
    let frequency: String?           // daily | specific | as_needed
    let times: [String]?            // slot keys (morning/midday/evening/night)
    let days: [String]?            // weekday keys, when frequency == specific
    let with_food: Bool?
}

/// One catalog hit from `GET /api/me/stack/search` (DSLD for supps, RxNorm for meds).
struct StackCatalogResult: Codable, Equatable, Identifiable {
    var id: String { (dsld_id?.value ?? "") + (rxcui?.value ?? "") + name + (brand ?? "") }
    let name: String
    let brand: String?
    let dose_amount: Double?
    let dose_unit: String?
    let form: String?
    let kind: String
    let dsld_id: LooseString?
    let rxcui: LooseString?
    let source: String?
}
struct StackCatalogResponse: Codable, Equatable { let results: [StackCatalogResult] }

/// `POST /api/me/stack/scan` — vision pulls candidate labels off a bottle (single) or a shelf.
struct StackScanResult: Codable, Equatable {
    let image_url: String?
    let photo_path: String?
    let candidates: [StackScanCandidate]
    let note: String?
}
struct StackScanCandidate: Codable, Equatable, Identifiable {
    var id = UUID()
    let name: String
    let brand: String?
    let dose_amount: Double?
    let dose_unit: String?
    let form: String?
    let kind: String?
    let confidence: Double?
    let source: String?
    enum CodingKeys: String, CodingKey { case name, brand, dose_amount, dose_unit, form, kind, confidence, source }
}

/// A "worth knowing" interaction flag — cited, severity-tiered, never alarming. `id` tolerates the
/// server sending it as int/string/absent.
struct InteractionFlag: Codable, Equatable, Identifiable {
    let flagID: LooseString?
    let a: String?
    let b: String?
    let severity: String?           // info | timing | moderate | major
    let summary: String?
    let source: String?
    var id: String { flagID?.value ?? "\(a ?? "")-\(b ?? "")-\(severity ?? "")" }
    enum CodingKeys: String, CodingKey { case flagID = "id", a, b, severity, summary, source }
}
struct StackInteractionsResponse: Codable, Equatable { let flags: [InteractionFlag]; let disclaimer: String? }

// MARK: - Runs (Strava-style) — `GET /api/me/runs` + `/api/me/runs/{id}`

/// One row in the runs list (and the header of the detail).
struct RunSummary: Codable, Equatable, Identifiable {
    let id: Int
    let title: String
    let activity_type: String?
    let started_at: String?
    let duration_min: Int?
    let distance_km: Double?
    let avg_pace_s_per_km: Int?
    let has_route: Bool
    let map_thumb_url: String?

    var isLift: Bool { activity_type == "strength" }
}

struct RunsResponse: Codable, Equatable {
    let runs: [RunSummary]
    var streak: WorkoutStreakInfo? = nil
    var active_days: [String]? = nil   // 'yyyy-MM-dd' local days lit on the calendar strip
}

/// The consistency numbers behind the Workouts screen header + the Today streak badge.
/// Week/month counts are only present on the `/runs` payload (nil on the dashboard block).
struct WorkoutStreakInfo: Codable, Equatable {
    let current: Int
    let longest: Int
    var this_week: Int? = nil
    var this_month: Int? = nil
    var worked_out_today: Bool? = nil
}

struct RunSplit: Codable, Equatable, Identifiable {
    let index: Int
    let distance_m: Double?
    let elapsed_s: Double?
    let pace_s_per_unit: Double?
    let elev_delta_m: Double?
    let avg_hr: Int?
    let partial: Bool?
    var id: Int { index }
}

struct RunSplits: Codable, Equatable { let km: [RunSplit]?; let mi: [RunSplit]? }
struct ElevationPoint: Codable, Equatable { let d_km: Double; let alt_m: Double }
struct BestEffort: Codable, Equatable { let distance_m: Double?; let elapsed_s: Double?; let pace_s_per_km: Double? }

/// `GET /api/me/runs/{id}` — the full end-of-run summary.
struct RunDetail: Codable, Equatable, Identifiable {
    let id: Int
    let title: String
    let activity_type: String?
    let started_at: String?
    let duration_min: Int?
    let distance_km: Double?
    let distance_source: String?
    let moving_time_s: Int?
    let avg_pace_s_per_km: Int?
    let gap_s_per_km: Int?
    let elevation_gain_m: Int?
    let elevation_loss_m: Int?
    let elevation_profile: [ElevationPoint]?
    let splits: RunSplits?
    let best_efforts: [String: BestEffort]?
    let relative_effort: Int?
    let avg_hr: Int?
    let max_hr: Int?
    let hr_zones: HrZones?
    let trimp: Double?
    let hr_quality: Double?
    let workout_hrv_ms: Double?
    let calories_kcal: Int?
    let vo2max: Double?
    let fitness_level: String?
    let hrr_bpm: Double?
    let map_url_large: String?
    // Strength detail (exercises + sets/reps) for a lifting session; nil for a run.
    let strength: StrengthDetail?
    let units: String?

    var isLift: Bool { activity_type == "strength" }
}

/// Time-in-HR-zone (minutes) — the headline of a lift summary, where avg HR understates the effort.
/// Double, NOT Int: the server sends fractional minutes (round($sec/60, 1) → e.g. 3.4), and one
/// non-integer here failed the WHOLE RunDetail decode — a big part of the "summary shows failed" bug.
struct HrZones: Codable, Equatable {
    let z1: Double?; let z2: Double?; let z3: Double?; let z4: Double?; let z5: Double?
}

struct StrengthDetail: Codable, Equatable {
    let total_sets: Int?
    let total_reps: Int?
    let exercises: [StrengthExercise]?
}

struct StrengthExercise: Codable, Equatable, Identifiable {
    let name: String
    let muscle_group: String?
    let sets: [StrengthSet]?
    var id: String { name }
}

struct StrengthSet: Codable, Equatable, Identifiable {
    let set_number: Int
    let reps: Int?
    let weight_kg: Double?
    var id: Int { set_number }
}

/// The post-workout summary shown the moment a workout ends: live stats right away, enriched with the
/// sealed server detail (zones/splits/VO₂max/sets) a few seconds later. Branches run vs lift on `kind`.
struct WorkoutSummaryState: Identifiable {
    let id = UUID()
    let kind: String              // "run" | "strength"
    let distanceKm: Double
    let elapsedSec: Int
    let maxBpm: Int
    let startedAt: Date?
    let hasGps: Bool
    var detail: RunDetail?        // enriched from the server once sealed
    var loading = true
    var failed = false
    var isLift: Bool { kind == "strength" || kind == "lift" || detail?.isLift == true }
}

/// The post-sleep "wow moment" state, mirroring `WorkoutSummaryState`. Built the instant the watch
/// reports WAKE (bed/wake epochs from the `TN` marker), so the summary appears immediately with the
/// in-bed time; the server-sealed night (stages, hypnogram, efficiency, performance) enriches it a
/// little later via `api.sleepDetail()`. Bound to `model.sleepSummary`.
struct SleepSummaryState: Identifiable {
    let id = UUID()
    let bedtime: Date?
    let wake: Date?
    let inBedSec: Int             // watch markers: wake − bed (fallback headline before the seal)
    var detail: SleepResponse.Detail?     // enriched from the server once the night seals
    var assess: SleepResponse.Assess?
    var loading = true
    var failed = false
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

// MARK: - Community (opt-in social: follow graph, feed, leaderboard, kudos/comments, badges)

struct CommunitySettings: Codable, Equatable {
    var community_enabled: Bool
    var username: String?
    var bio: String?
    var display_name: String?
    var avatar_url: String?
    var followers_require_approval: Bool
    var default_activity_visibility: String
    var follower_count: Int
    var following_count: Int
    var pending_request_count: Int
}

/// The compact athlete reference embedded in activity cards + comments.
struct AthleteMini: Codable, Equatable, Identifiable {
    let id: Int
    let name: String
    let username: String?
    let avatar_url: String?
    var is_you: Bool?
}

/// A feed / list card for one activity, with social counts. `kudos_*` are `var` for optimistic toggles.
struct ActivityCard: Codable, Equatable, Identifiable {
    let id: Int
    let title: String
    let activity_type: String?
    let started_at: String?
    let duration_min: Int?
    let distance_km: Double?
    let avg_pace_s_per_km: Int?
    let elevation_gain_m: Double?
    let relative_effort: Int?
    let has_route: Bool
    let map_thumb_url: String?
    let athlete: AthleteMini
    var kudos_count: Int
    var comment_count: Int
    var did_kudos: Bool
}

struct FeedResponse: Codable, Equatable { let items: [ActivityCard]; let next_offset: Int? }

struct LeaderboardRow: Codable, Equatable, Identifiable {
    let rank: Int
    let profile_id: Int
    let name: String
    let username: String?
    let avatar_url: String?
    let activity_count: Int
    let value: Double
    let is_you: Bool
    var id: Int { profile_id }
}

struct LeaderboardResponse: Codable, Equatable {
    let metric: String
    let window: String
    let unit: String
    let athletes: [LeaderboardRow]
    let you: LeaderboardRow?
}

/// A full athlete profile + the viewer's relationship to them.
struct Athlete: Codable, Equatable, Identifiable {
    let id: Int
    let name: String
    let username: String?
    let bio: String?
    let avatar_url: String?
    let community_enabled: Bool
    let is_you: Bool
    var follow_state: String?     // "accepted" | "pending" | nil
    let follows_you: Bool?
    let follower_count: Int
    let following_count: Int
    let total_activities: Int
    let total_distance_km: Double
}

struct AthleteSearchResponse: Codable, Equatable { let athletes: [Athlete] }

struct AthleteProfileResponse: Codable, Equatable {
    let athlete: Athlete
    let activities: [ActivityCard]
    let achievements: [Achievement]
}

struct CommentItem: Codable, Equatable, Identifiable {
    let id: Int
    let body: String
    let created_at: String?
    let is_mine: Bool
    let athlete: AthleteMini
}

struct CommentsResponse: Codable, Equatable { let comments: [CommentItem] }

struct KudosState: Codable, Equatable { let kudos_count: Int; let did_kudos: Bool }

struct FollowRequest: Codable, Equatable, Identifiable {
    let follow_id: Int
    let requested_at: String?
    let athlete: Athlete
    var id: Int { follow_id }
}

struct FollowRequestsResponse: Codable, Equatable { let requests: [FollowRequest] }

struct FollowResult: Codable, Equatable { let follow_state: String?; let athlete: Athlete }

struct Achievement: Codable, Equatable, Identifiable {
    let key: String
    let title: String
    let blurb: String
    let icon: String
    let emoji: String
    let earned: Bool
    let awarded_at: String?
    var id: String { key }
}

struct AchievementsResponse: Codable, Equatable { let achievements: [Achievement] }

struct CommunityRecap: Codable, Equatable {
    let window: String
    let your_activities: Int
    let your_distance_km: Double
    let your_effort: Int
    let your_rank: Int?
    let group_size: Int
    let top_performer: TopPerformer?
    struct TopPerformer: Codable, Equatable { let name: String; let value: Double; let is_you: Bool }
}
