import Foundation
import Network
import TitanCore

/// Offline-durable FIFO upload queue. Windows are persisted the instant they're built (so a
/// crash/relaunch never loses the overnight buffer), then drained whenever the network is up,
/// with exponential backoff. Reconcile dropped via `GET /api/devices/ingestions?since=`.
///
/// Storage is behind a protocol so the core stays testable; the app target backs it with GRDB
/// (SQLite). Enqueue is called from `FrameRouter` — including during `willRestoreState` background
/// wakes — so a relaunch flushes pending windows in the ~10s wake window.
public protocol WindowStore {
    func enqueue(_ window: AnyWindow) throws
    func pending(limit: Int) throws -> [(id: Int64, window: AnyWindow)]
    func remove(id: Int64) throws
    func bumpAttempt(id: Int64) throws
}

public actor SyncQueue {
    private let store: WindowStore
    private let client: IngestClient
    private let monitor = NWPathMonitor()
    private var online = true
    private var draining = false
    private let onUploaded: (@Sendable (Int) -> Void)?   // (cumulative windows uploaded) for the UI
    private var uploadedCount = 0

    public init(store: WindowStore, client: IngestClient, onUploaded: (@Sendable (Int) -> Void)? = nil) {
        self.store = store; self.client = client; self.onUploaded = onUploaded
        monitor.pathUpdateHandler = { [weak self] path in
            Task { await self?.setOnline(path.status == .satisfied) }
        }
        monitor.start(queue: DispatchQueue(label: "titan.sync.net"))
    }

    private func setOnline(_ up: Bool) { online = up; if up { Task { await drain() } } }

    /// Persist a window and try to drain. Safe to call from a background wake.
    public func submit(_ window: AnyWindow) async {
        try? store.enqueue(window)
        await drain()
    }

    /// Drain pending windows oldest-first while online. Stops on the first transport failure
    /// (will retry on the next network/up event) and drops permanently-rejected windows.
    public func drain() async {
        guard online, !draining else { return }
        draining = true; defer { draining = false }
        while online, let batch = try? store.pending(limit: 1), let item = batch.first {
            switch await client.ship(window: item.window) {
            case .accepted, .duplicate:
                try? store.remove(id: item.id)
                uploadedCount += 1
                onUploaded?(uploadedCount)
            case .rejected(let status, _):
                // 4xx (except 429) = won't ever succeed → drop so the queue can't wedge.
                if status == 429 { try? store.bumpAttempt(id: item.id); return }
                try? store.remove(id: item.id)
            case .transport:
                try? store.bumpAttempt(id: item.id); return  // retry later
            }
        }
    }
}
