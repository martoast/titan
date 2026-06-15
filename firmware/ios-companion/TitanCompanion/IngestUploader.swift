import Foundation

/// Signs and POSTs windows to /api/devices/ingest. Store-and-forward: a window that
/// fails to upload (offline overnight) is written to disk and retried on the next
/// successful send or app launch, so a dropped Wi-Fi link never loses a night.
final class IngestUploader {
    private let settings: Settings
    private let session: URLSession
    private let bufferDir: URL

    /// Called on the main thread after each attempt so the UI can update.
    var onResult: ((_ ok: Bool, _ message: String, _ bufferedCount: Int) -> Void)?

    init(settings: Settings) {
        self.settings = settings
        let cfg = URLSessionConfiguration.default
        cfg.timeoutIntervalForRequest = 30
        cfg.waitsForConnectivity = true
        self.session = URLSession(configuration: cfg)

        let dir = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask)[0]
            .appendingPathComponent("titan-buffer", isDirectory: true)
        try? FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
        self.bufferDir = dir
    }

    func send(_ window: PPGWindow) {
        let batch = Batch(batch_uid: ULID.generate(), windows: [window])
        guard let body = try? JSONEncoder().encode(batch) else { return }
        post(body) { [weak self] ok in
            guard let self else { return }
            if !ok { self.buffer(body) }
            self.report(ok ? "window uploaded (\(window.ppg.count) samples)" : "offline — buffered window", ok: ok)
            if ok { self.flushBuffer() }
        }
    }

    // MARK: - networking

    private func post(_ body: Data, completion: @escaping (Bool) -> Void) {
        guard settings.isConfigured, let url = URL(string: settings.ingestURL.appending("/api/devices/ingest")) else {
            completion(false); return
        }
        let bodyString = String(decoding: body, as: UTF8.self)
        var req = URLRequest(url: url)
        req.httpMethod = "POST"
        req.setValue("application/json", forHTTPHeaderField: "Content-Type")
        req.setValue(settings.deviceId, forHTTPHeaderField: "X-Device-Id")
        req.setValue(Signer.signatureHeader(secret: settings.secret, body: bodyString),
                     forHTTPHeaderField: "X-Titan-Signature")
        req.httpBody = body

        session.dataTask(with: req) { _, resp, _ in
            let code = (resp as? HTTPURLResponse)?.statusCode ?? 0
            completion(code == 202 || code == 200)
        }.resume()
    }

    // MARK: - store-and-forward buffer

    private func buffer(_ body: Data) {
        let name = "\(Date().timeIntervalSince1970)-\(UUID().uuidString).json"
        try? body.write(to: bufferDir.appendingPathComponent(name))
    }

    /// Retry buffered windows oldest-first; stop on the first failure (still offline).
    func flushBuffer() {
        let files = (try? FileManager.default.contentsOfDirectory(at: bufferDir, includingPropertiesForKeys: nil))?
            .sorted { $0.lastPathComponent < $1.lastPathComponent } ?? []
        guard let next = files.first, let data = try? Data(contentsOf: next) else { return }
        post(data) { [weak self] ok in
            guard let self else { return }
            if ok {
                try? FileManager.default.removeItem(at: next)
                self.report("flushed buffered window", ok: true)
                self.flushBuffer() // keep draining
            }
        }
    }

    private var bufferedCount: Int {
        (try? FileManager.default.contentsOfDirectory(at: bufferDir, includingPropertiesForKeys: nil))?.count ?? 0
    }

    private func report(_ message: String, ok: Bool) {
        let n = bufferedCount
        DispatchQueue.main.async { self.onResult?(ok, message, n) }
    }
}
