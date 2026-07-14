import Foundation

/// Typed client for the Titan Laravel API. Carries the bearer token (set after login). All calls
/// are async; the coach chat uses Server-Sent Events parsed off `URLSession.bytes`.
/// Endpoints: routes/api.php (login, me/dashboard, devices/pair, coach/*).
final class APIClient {
    let baseURL: URL
    private let session: URLSession

    // The bearer token is WRITTEN on the main actor (login/logout) but READ off the cooperative pool by
    // the async request helpers (Swift runs a nonisolated async fn's body off-main). A plain `var` is a
    // data race on a refcounted String? — torn/stale reads (auth loop) or an over-release crash. Guard it.
    private var _token: String?
    private let tokenLock = NSLock()
    var token: String? {
        get { tokenLock.lock(); defer { tokenLock.unlock() }; return _token }
        set { tokenLock.lock(); defer { tokenLock.unlock() }; _token = newValue }
    }

    init(baseURL: URL, token: String? = nil, session: URLSession = .shared) {
        self.baseURL = baseURL; self._token = token; self.session = session
    }

    // MARK: requests

    private func request(_ path: String, method: String = "GET", json: [String: Any]? = nil) -> URLRequest {
        var req = URLRequest(url: Self.url(baseURL, path))
        req.httpMethod = method
        req.timeoutInterval = 25   // never hang a screen forever on a stalled request
        req.setValue("application/json", forHTTPHeaderField: "Accept")
        if let token { req.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization") }
        if let json {
            req.setValue("application/json", forHTTPHeaderField: "Content-Type")
            req.httpBody = try? JSONSerialization.data(withJSONObject: json)
        }
        return req
    }

    /// Build a request URL, preserving any `?query` (appendingPathComponent would percent-encode the
    /// `?`, silently dropping query params — that broke /me/cycle/calendar and /me/trends).
    static func url(_ base: URL, _ path: String) -> URL {
        guard let q = path.firstIndex(of: "?") else { return base.appendingPathComponent(path) }
        let p = base.appendingPathComponent(String(path[path.startIndex..<q]))
        var comps = URLComponents(url: p, resolvingAgainstBaseURL: false)
        comps?.percentEncodedQuery = String(path[path.index(after: q)...])
        return comps?.url ?? base.appendingPathComponent(path)
    }

    /// Build a multipart/form-data request (text fields + one image part) for photo uploads.
    private func multipart(_ path: String, fields: [String: String] = [:],
                           fileField: String, fileData: Data,
                           fileName: String = "photo.jpg", mime: String = "image/jpeg") -> URLRequest {
        let boundary = "TitanBoundary-\(UUID().uuidString)"
        var req = URLRequest(url: Self.url(baseURL, path))
        req.httpMethod = "POST"
        req.setValue("application/json", forHTTPHeaderField: "Accept")
        if let token { req.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization") }
        req.setValue("multipart/form-data; boundary=\(boundary)", forHTTPHeaderField: "Content-Type")

        var body = Data()
        func line(_ s: String) { body.append(Data(s.utf8)) }
        for (k, v) in fields {
            line("--\(boundary)\r\nContent-Disposition: form-data; name=\"\(k)\"\r\n\r\n\(v)\r\n")
        }
        line("--\(boundary)\r\nContent-Disposition: form-data; name=\"\(fileField)\"; filename=\"\(fileName)\"\r\n")
        line("Content-Type: \(mime)\r\n\r\n")
        body.append(fileData)
        line("\r\n--\(boundary)--\r\n")
        req.httpBody = body
        return req
    }

    private func send<T: Decodable>(_ req: URLRequest, as type: T.Type) async throws -> T {
        do {
            let (data, resp) = try await session.data(for: req)
            let status = (resp as? HTTPURLResponse)?.statusCode ?? 0
            if status == 401 { throw APIError.unauthorized }
            guard (200...299).contains(status) else {
                let msg = (try? JSONSerialization.jsonObject(with: data) as? [String: Any])?["error"] as? String
                throw APIError.http(status, msg ?? "")
            }
            do { return try JSONDecoder().decode(T.self, from: data) }
            catch { throw APIError.decoding }
        } catch let e as APIError { throw e }
        catch { throw APIError.transport(error.localizedDescription) }
    }

    // MARK: endpoints

    func login(email: String, password: String, deviceName: String) async throws -> LoginResponse {
        try await send(request("api/login", method: "POST",
                               json: ["email": email, "password": password, "device_name": deviceName]),
                       as: LoginResponse.self)
    }

    func logout() async { _ = try? await session.data(for: request("api/logout", method: "POST")) }

    /// Cheap probe that the stored token still authenticates. Throws `APIError.unauthorized` ONLY on a
    /// confirmed 401; a network/transport failure throws something else — so a caller can tell a genuinely
    /// revoked token from a transient blip and avoid nuking a good session on the latter.
    func verifyToken() async throws {
        _ = try await send(request("api/me"), as: MeResponse.self)
    }

    // MARK: onboarding + profile edit

    func onboardingStatus() async throws -> Bool {
        try await send(request("api/me/onboarding"), as: OnboardingStatus.self).onboarded
    }

    @discardableResult
    func submitOnboarding(_ fields: [String: Any]) async throws -> OnboardingResult {
        try await send(request("api/me/onboarding", method: "POST", json: fields), as: OnboardingResult.self)
    }

    func profileSnapshot() async throws -> ProfileSnapshot {
        try await send(request("api/me/profile"), as: ProfileResponse.self).profile
    }

    @discardableResult
    func updateProfile(_ fields: [String: Any]) async throws -> ProfileSnapshot {
        try await send(request("api/me/profile", method: "PATCH", json: fields), as: ProfileResponse.self).profile
    }

    func dashboard() async throws -> Dashboard {
        try await send(request("api/me/dashboard?tz=\(Self.localTZ)"), as: Dashboard.self)
    }

    func runs() async throws -> [RunSummary] {
        try await workouts().runs
    }

    /// Full Workouts payload: the list + the consecutive-day streak + calendar strip days.
    func workouts() async throws -> RunsResponse {
        try await send(request("api/me/runs?tz=\(Self.localTZ)"), as: RunsResponse.self)
    }

    /// The device's IANA zone, URL-encoded, so streaks bucket on the user's local calendar day.
    private static var localTZ: String {
        TimeZone.current.identifier.addingPercentEncoding(withAllowedCharacters: .urlQueryAllowed) ?? "UTC"
    }

    /// Manually log a workout the band didn't record (gym session, a run without the watch). Type +
    /// duration are required; `startedAt` defaults server-side to now, `intensity` to moderate.
    @discardableResult
    func logWorkout(type: String, durationMin: Int, startedAt: Date?, intensity: String?) async throws -> LoggedWorkout {
        var body: [String: Any] = ["activity_type": type, "duration_min": durationMin]
        if let startedAt { body["started_at"] = ISO8601DateFormatter().string(from: startedAt) }
        if let intensity { body["perceived_intensity"] = intensity }
        return try await send(request("api/me/workouts", method: "POST", json: body), as: LoggedWorkout.self)
    }

    func runDetail(_ id: Int) async throws -> RunDetail {
        try await send(request("api/me/runs/\(id)"), as: RunDetail.self)
    }

    func trends(metric: String = "hrv", days: Int = 30) async throws -> TrendResponse {
        try await send(request("api/me/trends?metric=\(metric)&days=\(days)"), as: TrendResponse.self)
    }

    func pairDevice(source: String = "bangle") async throws -> PairResponse {
        try await send(request("api/devices/pair", method: "POST", json: ["source": source]),
                       as: PairResponse.self)
    }

    // MARK: nutrition (Fuel tab)

    /// A day's fuel. `date` (yyyy-MM-dd) scopes to a past day for the Fuel history pager; nil → today.
    func nutritionToday(date: String? = nil) async throws -> NutritionToday {
        let path = date.map { "api/me/nutrition?date=\($0)" } ?? "api/me/nutrition"
        return try await send(request(path), as: NutritionToday.self)
    }

    func sleepDetail() async throws -> SleepResponse {
        try await send(request("api/me/sleep?tz=\(Self.localTZ)"), as: SleepResponse.self)
    }

    /// One night's full detail by local date (yyyy-MM-dd) — the Sleep Week per-night tap opens this.
    func sleepNight(date: String) async throws -> SleepResponse.Detail? {
        try await send(request("api/me/sleep/night?date=\(date)"), as: SleepNightResponse.self).detail
    }

    func hr() async throws -> HrResponse {
        try await send(request("api/me/hr"), as: HrResponse.self)
    }

    /// The full Bio Age page — Titan Age + the transparent contribution breakdown + tips.
    func longevity() async throws -> LongevityPage {
        try await send(request("api/me/longevity"), as: LongevityPage.self)
    }

    /// A day's continuous-glucose curve + metrics + connection status.
    func glucose(date: String? = nil) async throws -> GlucoseDay {
        let path = date.map { "api/me/glucose?date=\($0)" } ?? "api/me/glucose"
        return try await send(request(path), as: GlucoseDay.self)
    }

    /// Connect a CGM source (nightscout URL+token / healthkit) → returns the refreshed day.
    func connectGlucose(provider: String, nightscoutUrl: String?, token: String?) async throws -> GlucoseDay {
        var json: [String: Any] = ["provider": provider, "enabled": true]
        if let nightscoutUrl { json["nightscout_url"] = nightscoutUrl }
        if let token, !token.isEmpty { json["nightscout_token"] = token }
        return try await send(request("api/me/glucose/connect", method: "POST", json: json), as: GlucoseDay.self)
    }

    func strain() async throws -> StrainResponse {
        try await send(request("api/me/strain?tz=\(Self.localTZ)"), as: StrainResponse.self)
    }

    func overview(days: Int = 30) async throws -> OverviewResponse {
        try await send(request("api/me/overview?days=\(days)"), as: OverviewResponse.self)
    }

    func cycle() async throws -> CycleResponse {
        try await send(request("api/me/cycle"), as: CycleResponse.self)
    }

    func cycleCalendar(from: String, days: Int) async throws -> CycleCalendarResponse {
        try await send(request("api/me/cycle/calendar?from=\(from)&days=\(days)"), as: CycleCalendarResponse.self)
    }

    func logCyclePeriod(date: String) async throws {
        _ = try await session.data(for: request("api/me/cycle/period", method: "POST", json: ["date": date]))
    }

    func logCycleDay(date: String, flow: String?, symptoms: [String]) async throws {
        var json: [String: Any] = ["date": date, "symptoms": symptoms]
        if let flow { json["flow"] = flow }
        _ = try await session.data(for: request("api/me/cycle/day", method: "POST", json: json))
    }

    func scanMeal(_ imageData: Data, caption: String?) async throws -> MealScanResult {
        var fields: [String: String] = [:]
        if let caption, !caption.isEmpty { fields["caption"] = caption }
        return try await send(multipart("api/me/nutrition/scan", fields: fields, fileField: "photo", fileData: imageData),
                              as: MealScanResult.self)
    }

    /// Confirm a scanned draft (after the user set the amount) → log it, re-attaching the scan's photo.
    /// Copy a past day's meals to today ("log this day again"). Returns the refreshed today payload.
    func copyDay(date: String) async throws -> NutritionToday {
        try await send(request("api/me/meals/copy-day", method: "POST", json: ["date": date]), as: NutritionToday.self)
    }

    /// Manual quick-add (no photo/barcode) → POST /meals. Optional P/C/F default to 0; the server
    /// reconciles them against the calories, so "300 kcal" alone still stores a sensible split.
    func storeMeal(name: String, calories: Int, protein: Double?, carbs: Double?, fat: Double?, eatenAt: Date?) async throws -> MealMutation {
        var json: [String: Any] = ["name": name, "calories": calories,
                                   "protein_g": protein ?? 0, "carbs_g": carbs ?? 0, "fat_g": fat ?? 0]
        if let eatenAt { json["eaten_at"] = ISO8601DateFormatter().string(from: eatenAt) }
        return try await send(request("api/me/meals", method: "POST", json: json), as: MealMutation.self)
    }

    func confirmMeal(name: String, calories: Int, protein: Double, carbs: Double, fat: Double, fiber: Double? = nil, photoPath: String?, source: String? = nil) async throws -> MealMutation {
        var json: [String: Any] = ["name": name, "calories": calories, "protein_g": protein, "carbs_g": carbs, "fat_g": fat]
        if let fiber { json["fiber_g"] = fiber }   // secondary stat, when the draft carried one
        if let photoPath { json["photo_path"] = photoPath }
        if let source { json["source"] = source }   // stamp the draft's real source (barcode vs photo)
        return try await send(request("api/me/meals/confirm", method: "POST", json: json), as: MealMutation.self)
    }

    /// Barcode → product macros (Open Food Facts, cached) → a draft to confirm. Same result shape as a
    /// photo scan (kind "meal" with a draft, or "other" with a not-found message).
    func scanBarcode(_ code: String) async throws -> MealScanResult {
        try await send(request("api/me/nutrition/barcode", method: "POST", json: ["code": code]), as: MealScanResult.self)
    }

    func updateMeal(_ id: Int, fields: [String: Any]) async throws -> MealMutation {
        try await send(request("api/me/meals/\(id)", method: "PATCH", json: fields), as: MealMutation.self)
    }

    @discardableResult
    func deleteMeal(_ id: Int) async throws -> MacrosOnly {
        try await send(request("api/me/meals/\(id)", method: "DELETE"), as: MacrosOnly.self)
    }

    // MARK: meal memory ("Your meals")

    func mealLibrary() async throws -> MealLibrary {
        try await send(request("api/me/meals-library"), as: MealLibrary.self)
    }

    /// Re-log a remembered meal today (no camera/AI). `portion` scales it (1.0 = as saved).
    func relogMeal(_ templateId: Int, portion: Double = 1.0) async throws -> MealMutation {
        try await send(request("api/me/meals/relog", method: "POST", json: ["template_id": templateId, "portion": portion]),
                       as: MealMutation.self)
    }

    @discardableResult
    func favoriteMealTemplate(_ id: Int, favorite: Bool) async throws -> MealTemplateMutation {
        try await send(request("api/me/meals-library/\(id)/favorite", method: "PATCH", json: ["favorite": favorite]),
                       as: MealTemplateMutation.self)
    }

    func forgetMealTemplate(_ id: Int) async throws {
        _ = try await session.data(for: request("api/me/meals-library/\(id)", method: "DELETE"))
    }

    // MARK: Apple Health sync

    func healthStatus() async throws -> HealthStatus {
        try await send(request("api/me/health"), as: HealthStatus.self)
    }

    @discardableResult
    func ingestHealth(_ payload: [String: Any]) async throws -> HealthIngestResult {
        try await send(request("api/me/health/ingest", method: "POST", json: payload), as: HealthIngestResult.self)
    }

    // MARK: insights + journal + weight

    func insights() async throws -> [Insight] {
        try await send(request("api/me/insights"), as: InsightsResponse.self).insights
    }

    func journal() async throws -> JournalResponse {
        try await send(request("api/me/journal"), as: JournalResponse.self)
    }

    func logJournal(add: [String], remove: [String]) async throws -> [String] {
        var body: [String: Any] = [:]
        if !add.isEmpty { body["add"] = add }
        if !remove.isEmpty { body["remove"] = remove }
        return try await send(request("api/me/journal", method: "POST", json: body), as: JournalLogResponse.self).logged
    }

    func weight() async throws -> WeightCard {
        try await send(request("api/me/weight"), as: WeightCard.self)
    }

    @discardableResult
    func logWeight(kg: Double) async throws -> WeightCard {
        try await send(request("api/me/weight", method: "POST", json: ["weight_kg": kg]), as: WeightCard.self)
    }

    /// Real-time stress (0–3, motion-gated) + the stress-over-day strip.
    func stress() async throws -> StressResponse {
        try await send(request("api/me/stress?tz=\(Self.localTZ)"), as: StressResponse.self)
    }

    func hydration() async throws -> HydrationToday {
        try await send(request("api/me/hydration"), as: HydrationToday.self)
    }

    @discardableResult
    func logWater(ml: Int) async throws -> HydrationToday {
        try await send(request("api/me/hydration", method: "POST", json: ["ml": ml]), as: HydrationToday.self)
    }

    func fasting() async throws -> FastingStatus {
        try await send(request("api/me/fasting"), as: FastingStatus.self)
    }

    @discardableResult
    func startFast(goalHours: Double) async throws -> FastingStatus {
        try await send(request("api/me/fasting/start", method: "POST", json: ["goal_hours": goalHours]), as: FastingStatus.self)
    }

    @discardableResult
    func endFast() async throws -> FastingStatus {
        try await send(request("api/me/fasting/end", method: "POST"), as: FastingStatus.self)
    }

    // MARK: targets

    func targets() async throws -> Targets {
        try await send(request("api/me/targets"), as: TargetsResponse.self).targets
    }

    func updateTargets(_ fields: [String: Any]) async throws -> Targets {
        try await send(request("api/me/targets", method: "PATCH", json: fields), as: TargetsResponse.self).targets
    }

    @discardableResult
    func resetTargets() async throws -> Targets {
        try await send(request("api/me/targets", method: "DELETE"), as: TargetsResponse.self).targets
    }

    // MARK: progress photos

    func progressPhotos() async throws -> [ProgressPhoto] {
        try await send(request("api/me/progress-photos"), as: ProgressPhotosResponse.self).photos
    }

    func uploadProgressPhoto(_ imageData: Data, pose: String?, weightKg: Double?, notes: String?) async throws -> ProgressPhoto {
        var fields: [String: String] = [:]
        if let pose { fields["pose"] = pose }
        if let weightKg { fields["weight_kg"] = String(weightKg) }
        if let notes, !notes.isEmpty { fields["notes"] = notes }
        return try await send(multipart("api/me/progress-photos", fields: fields, fileField: "photo", fileData: imageData),
                              as: ProgressPhotoResponse.self).photo
    }

    func deleteProgressPhoto(_ id: Int) async {
        _ = try? await session.data(for: request("api/me/progress-photos/\(id)", method: "DELETE"))
    }

    func registerPush(token apns: String) async {
        _ = try? await session.data(for: request("api/devices/push-token", method: "POST",
                                                  json: ["token": apns, "platform": "ios"]))
    }

    // MARK: stack (What you take — supplements & medications)

    func stack() async throws -> StackResponse {
        try await send(request("api/me/stack"), as: StackResponse.self)
    }

    func stackSearch(_ q: String) async throws -> StackCatalogResponse {
        let enc = q.addingPercentEncoding(withAllowedCharacters: .urlQueryAllowed) ?? q
        return try await send(request("api/me/stack/search?q=\(enc)"), as: StackCatalogResponse.self)
    }

    func stackScan(_ imageData: Data, mode: String) async throws -> StackScanResult {
        try await send(multipart("api/me/stack/scan", fields: ["mode": mode], fileField: "photo", fileData: imageData),
                       as: StackScanResult.self)
    }

    func stackInteractions() async throws -> StackInteractionsResponse {
        try await send(request("api/me/stack/interactions"), as: StackInteractionsResponse.self)
    }

    @discardableResult
    func addStackItem(_ fields: [String: Any]) async throws -> StackResponse {
        try await send(request("api/me/stack", method: "POST", json: fields), as: StackResponse.self)
    }

    @discardableResult
    func updateStackItem(_ id: Int, fields: [String: Any]) async throws -> StackResponse {
        try await send(request("api/me/stack/\(id)", method: "PATCH", json: fields), as: StackResponse.self)
    }

    @discardableResult
    func deleteStackItem(_ id: Int) async throws -> StackResponse {
        try await send(request("api/me/stack/\(id)", method: "DELETE"), as: StackResponse.self)
    }

    @discardableResult
    func logStackIntake(itemID: Int, status: String? = nil, slot: String? = nil,
                        takenAt: String? = nil, notes: String? = nil) async throws -> StackResponse {
        var json: [String: Any] = [:]
        if let status { json["status"] = status }
        if let slot { json["slot"] = slot }
        if let takenAt { json["taken_at"] = takenAt }
        if let notes { json["notes"] = notes }
        return try await send(request("api/me/stack/\(itemID)/intake", method: "POST", json: json), as: StackResponse.self)
    }

    @discardableResult
    func logQuickIntake(_ fields: [String: Any]) async throws -> StackResponse {
        try await send(request("api/me/stack/intake", method: "POST", json: fields), as: StackResponse.self)
    }

    @discardableResult
    func deleteStackIntake(_ eventID: Int) async throws -> StackResponse {
        try await send(request("api/me/stack/intake/\(eventID)", method: "DELETE"), as: StackResponse.self)
    }

    // MARK: coach SSE
    // Streams `delta {text}` events as an AsyncStream of token chunks. Also surfaces `tool` pills
    // and the terminal `done`. Parser mirrors CoachController's event names.
    func coachStream(message: String, conversationId: Int?) -> AsyncThrowingStream<CoachEvent, Error> {
        let path = conversationId.map { "api/coach/\($0)/stream" } ?? "api/coach/stream"
        var req = request(path, method: "POST", json: ["message": message])
        req.setValue("text/event-stream", forHTTPHeaderField: "Accept")

        return AsyncThrowingStream { continuation in
            let task = Task {
                do {
                    let (bytes, resp) = try await session.bytes(for: req)
                    if (resp as? HTTPURLResponse)?.statusCode == 401 { throw APIError.unauthorized }
                    var event = "message"
                    for try await line in bytes.lines {
                        if line.hasPrefix("event:") { event = line.dropFirst(6).trimmingCharacters(in: .whitespaces) }
                        else if line.hasPrefix("data:") {
                            let json = String(line.dropFirst(5)).trimmingCharacters(in: .whitespaces)
                            let obj = (try? JSONSerialization.jsonObject(with: Data(json.utf8))) as? [String: Any] ?? [:]
                            switch event {
                            case "delta": if let t = obj["text"] as? String { continuation.yield(.delta(t)) }
                            case "tool": continuation.yield(.tool(obj["label"] as? String ?? ""))
                            case "done": continuation.yield(.done(obj["conversation_id"] as? Int)); continuation.finish(); return
                            case "error": throw APIError.transport(obj["message"] as? String ?? "coach error")
                            default: break
                            }
                            event = "message"
                        }
                    }
                    continuation.finish()
                } catch { continuation.finish(throwing: error) }
            }
            continuation.onTermination = { _ in task.cancel() }
        }
    }

    enum CoachEvent { case delta(String), tool(String), done(Int?) }

    // MARK: coach — durable background send (survives the phone suspending)

    /// Durable send: persists the message (+ optional photo) server-side and queues the reply, returning
    /// immediately with the pending assistant message id to poll. Text-only rides as JSON; a photo turn
    /// goes multipart. The reply generates off the request path, so locking the phone can't lose it.
    func coachSendAsync(message: String, imageData: Data?, conversationId: Int?) async throws -> CoachSendResult {
        let path = conversationId.map { "api/coach/\($0)/send-async" } ?? "api/coach/send-async"
        if let imageData {
            var fields: [String: String] = [:]
            if !message.isEmpty { fields["message"] = message }
            return try await send(multipart(path, fields: fields, fileField: "photo", fileData: imageData),
                                  as: CoachSendResult.self)
        }
        return try await send(request(path, method: "POST", json: ["message": message]), as: CoachSendResult.self)
    }

    /// Poll one message — its growing content + status — while a background job fills it in.
    func coachMessage(_ id: Int) async throws -> CoachMessageState {
        try await send(request("api/coach/messages/\(id)"), as: CoachMessageState.self)
    }

    /// Conversation history (for reconcile-on-reopen): the latest page, oldest→newest, with statuses.
    func coachHistory(conversationId: Int) async throws -> [CoachHistoryMessage] {
        try await send(request("api/coach/\(conversationId)/messages"), as: CoachHistoryResponse.self).messages
    }

    // MARK: coach — voice + photo (mirror the web app's mic + snap-to-coach)

    /// Upload a recorded clip → Whisper (`/api/coach/transcribe`). Returns the text to drop into the
    /// composer (we don't auto-send, exactly like the web app).
    func transcribe(_ audioData: Data, fileName: String = "voice.m4a", mime: String = "audio/m4a") async throws -> String {
        let res = try await send(multipart("api/coach/transcribe", fileField: "audio",
                                           fileData: audioData, fileName: fileName, mime: mime),
                                 as: TranscribeResult.self)
        let text = res.text?.trimmingCharacters(in: .whitespacesAndNewlines) ?? ""
        guard res.ok, !text.isEmpty else { throw APIError.transport("Couldn't transcribe that — try again.") }
        return text
    }

    /// Send a photo to the coach (`/api/coach[/{id}]/scan`). The server runs vision, auto-logs what it
    /// recognizes (meal/bloodwork/physique), and always returns a `reply`. Optional caption goes in `message`.
    func coachScan(_ imageData: Data, message: String?, conversationId: Int?) async throws -> CoachScanResult {
        let path = conversationId.map { "api/coach/\($0)/scan" } ?? "api/coach/scan"
        var fields: [String: String] = [:]
        if let message, !message.isEmpty { fields["message"] = message }
        return try await send(multipart(path, fields: fields, fileField: "photo", fileData: imageData),
                              as: CoachScanResult.self)
    }

    // MARK: community

    func communitySettings() async throws -> CommunitySettings {
        try await send(request("api/me/community/settings"), as: CommunitySettings.self)
    }

    @discardableResult
    func updateCommunitySettings(_ fields: [String: Any]) async throws -> CommunitySettings {
        try await send(request("api/me/community/settings", method: "PATCH", json: fields), as: CommunitySettings.self)
    }

    @discardableResult
    func uploadCommunityAvatar(_ imageData: Data) async throws -> CommunitySettings {
        try await send(multipart("api/me/community/avatar", fileField: "photo", fileData: imageData), as: CommunitySettings.self)
    }

    func communityFeed(offset: Int = 0) async throws -> FeedResponse {
        try await send(request("api/me/community/feed?offset=\(offset)"), as: FeedResponse.self)
    }

    func leaderboard(metric: String, window: String) async throws -> LeaderboardResponse {
        try await send(request("api/me/community/leaderboard?metric=\(metric)&window=\(window)"), as: LeaderboardResponse.self)
    }

    func followRequests() async throws -> [FollowRequest] {
        try await send(request("api/me/community/requests"), as: FollowRequestsResponse.self).requests
    }

    func acceptRequest(_ id: Int) async throws {
        _ = try await session.data(for: request("api/me/community/requests/\(id)/accept", method: "POST"))
    }

    func declineRequest(_ id: Int) async throws {
        _ = try await session.data(for: request("api/me/community/requests/\(id)/decline", method: "POST"))
    }

    func communityRecap() async throws -> CommunityRecap {
        try await send(request("api/me/community/recap"), as: CommunityRecap.self)
    }

    func achievements() async throws -> [Achievement] {
        try await send(request("api/me/achievements"), as: AchievementsResponse.self).achievements
    }

    func searchAthletes(_ q: String) async throws -> [Athlete] {
        let enc = q.addingPercentEncoding(withAllowedCharacters: .urlQueryAllowed) ?? q
        return try await send(request("api/me/athletes/search?q=\(enc)"), as: AthleteSearchResponse.self).athletes
    }

    func athlete(_ id: Int) async throws -> AthleteProfileResponse {
        try await send(request("api/me/athletes/\(id)"), as: AthleteProfileResponse.self)
    }

    @discardableResult
    func follow(_ id: Int) async throws -> FollowResult {
        try await send(request("api/me/athletes/\(id)/follow", method: "POST"), as: FollowResult.self)
    }

    @discardableResult
    func unfollow(_ id: Int) async throws -> FollowResult {
        try await send(request("api/me/athletes/\(id)/follow", method: "DELETE"), as: FollowResult.self)
    }

    @discardableResult
    func kudos(_ activityId: Int) async throws -> KudosState {
        try await send(request("api/me/activities/\(activityId)/kudos", method: "POST"), as: KudosState.self)
    }

    @discardableResult
    func unkudos(_ activityId: Int) async throws -> KudosState {
        try await send(request("api/me/activities/\(activityId)/kudos", method: "DELETE"), as: KudosState.self)
    }

    func comments(_ activityId: Int) async throws -> [CommentItem] {
        try await send(request("api/me/activities/\(activityId)/comments"), as: CommentsResponse.self).comments
    }

    @discardableResult
    func postComment(_ activityId: Int, body: String) async throws -> CommentItem {
        try await send(request("api/me/activities/\(activityId)/comments", method: "POST", json: ["body": body]), as: CommentItem.self)
    }

    func deleteComment(_ id: Int) async throws {
        _ = try await session.data(for: request("api/me/activities/comments/\(id)", method: "DELETE"))
    }
}
