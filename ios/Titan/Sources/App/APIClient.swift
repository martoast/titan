import Foundation

/// Typed client for the Titan Laravel API. Carries the bearer token (set after login). All calls
/// are async; the coach chat uses Server-Sent Events parsed off `URLSession.bytes`.
/// Endpoints: routes/api.php (login, me/dashboard, devices/pair, coach/*).
final class APIClient {
    let baseURL: URL
    var token: String?
    private let session: URLSession

    init(baseURL: URL, token: String? = nil, session: URLSession = .shared) {
        self.baseURL = baseURL; self.token = token; self.session = session
    }

    // MARK: requests

    private func request(_ path: String, method: String = "GET", json: [String: Any]? = nil) -> URLRequest {
        var req = URLRequest(url: Self.url(baseURL, path))
        req.httpMethod = method
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
        try await send(request("api/me/dashboard"), as: Dashboard.self)
    }

    func trends(metric: String = "hrv", days: Int = 30) async throws -> TrendResponse {
        try await send(request("api/me/trends?metric=\(metric)&days=\(days)"), as: TrendResponse.self)
    }

    func pairDevice(source: String = "bangle") async throws -> PairResponse {
        try await send(request("api/devices/pair", method: "POST", json: ["source": source]),
                       as: PairResponse.self)
    }

    // MARK: nutrition (Fuel tab)

    func nutritionToday() async throws -> NutritionToday {
        try await send(request("api/me/nutrition"), as: NutritionToday.self)
    }

    func sleepDetail() async throws -> SleepResponse {
        try await send(request("api/me/sleep"), as: SleepResponse.self)
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

    func updateMeal(_ id: Int, fields: [String: Any]) async throws -> MealMutation {
        try await send(request("api/me/meals/\(id)", method: "PATCH", json: fields), as: MealMutation.self)
    }

    @discardableResult
    func deleteMeal(_ id: Int) async throws -> MacrosOnly {
        try await send(request("api/me/meals/\(id)", method: "DELETE"), as: MacrosOnly.self)
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
}
