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
        var req = URLRequest(url: baseURL.appendingPathComponent(path))
        req.httpMethod = method
        req.setValue("application/json", forHTTPHeaderField: "Accept")
        if let token { req.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization") }
        if let json {
            req.setValue("application/json", forHTTPHeaderField: "Content-Type")
            req.httpBody = try? JSONSerialization.data(withJSONObject: json)
        }
        return req
    }

    /// Build a multipart/form-data request (text fields + one image part) for photo uploads.
    private func multipart(_ path: String, fields: [String: String] = [:],
                           fileField: String, fileData: Data,
                           fileName: String = "photo.jpg", mime: String = "image/jpeg") -> URLRequest {
        let boundary = "TitanBoundary-\(UUID().uuidString)"
        var req = URLRequest(url: baseURL.appendingPathComponent(path))
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
