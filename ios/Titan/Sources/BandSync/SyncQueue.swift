import Foundation
import Network
import os
import TitanCore
#if canImport(UIKit)
import UIKit
#endif

/// Holds a UIApplication background-task assertion for the lifetime of one upload drain. The band
/// wakes a suspended/terminated app in the background for BLE events; that wake window is short and
/// ends the moment BLE goes quiet — which can be mid-upload. This assertion asks iOS for the extra
/// runtime (~30s) to finish flushing the queue before the app is suspended again. Best-effort: the
/// persistent WindowStore still covers anything we can't finish in time. No-op where UIKit is
/// absent (the core unit tests run on macOS).
private final class BackgroundAssertion {
    #if canImport(UIKit)
    private var id = UIBackgroundTaskIdentifier.invalid
    init() {
        // beginBackgroundTask/endBackgroundTask are documented thread-safe, so calling from the
        // SyncQueue actor (off the main thread) is fine.
        id = UIApplication.shared.beginBackgroundTask(withName: "titan.sync.drain") { [weak self] in
            self?.end()   // expiration handler — release before the OS force-suspends us
        }
    }

    func end() {
        guard id != .invalid else { return }
        UIApplication.shared.endBackgroundTask(id)
        id = .invalid
    }
    #else
    init() {}
    func end() {}
    #endif
}

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

    /// Bounded in-memory hold for windows that FAILED to persist (disk full / locked / unusable DB).
    /// Without this, a thrown enqueue silently dropped the sample. We retry persisting these on every
    /// drain; capped so a permanently-broken store can't grow memory without bound.
    private var unpersisted: [AnyWindow] = []
    private static let maxUnpersisted = 500
    private static let log = Logger(subsystem: "org.titan.band", category: "sync")

    /// Timer-backed retry so a transient failure (server down, 5xx, 429, transport) doesn't wedge the
    /// queue until the next new window or network-path CHANGE. Exponential backoff, capped.
    private var retryTask: Task<Void, Never>?
    private var backoffStep = 0

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
        do {
            try store.enqueue(window)
        } catch {
            // Persisting failed — keep it in the bounded in-memory buffer instead of dropping it
            // silently; the next drain retries persistence (and the disk may have recovered by then).
            Self.log.error("enqueue failed, buffering in memory: \(error.localizedDescription, privacy: .public)")
            if unpersisted.count < Self.maxUnpersisted { unpersisted.append(window) }
        }
        await drain()
    }

    /// Drain pending windows oldest-first while online. Stops on the first transport failure
    /// (will retry on the next network/up event) and drops permanently-rejected windows.
    public func drain() async {
        guard online, !draining else { return }
        draining = true; defer { draining = false }
        retryTask?.cancel(); retryTask = nil   // we're draining now — fold in any scheduled retry

        // Keep the app alive long enough to empty the queue after a background BLE wake.
        let assertion = BackgroundAssertion()
        defer { assertion.end() }

        // Retry persisting anything that failed to write earlier; once on disk it drains normally below.
        if !unpersisted.isEmpty {
            var stillFailing: [AnyWindow] = []
            for w in unpersisted {
                do { try store.enqueue(w) } catch { stillFailing.append(w) }
            }
            unpersisted = stillFailing
        }

        while online, let batch = try? store.pending(limit: 1), let item = batch.first {
            switch await client.ship(window: item.window) {
            case .accepted, .duplicate:
                do {
                    try store.remove(id: item.id)
                } catch {
                    // The DELETE failed (disk full / IO error). If we continued we'd re-select this same
                    // head row forever (the server returns `duplicate`), spinning network + battery and
                    // blocking every newer window. Stop and retry later instead of looping.
                    Self.log.error("remove failed after upload; pausing drain to avoid a re-upload loop")
                    scheduleRetry(); return
                }
                uploadedCount += 1
                onUploaded?(uploadedCount)
                backoffStep = 0
            case .rejected(let status, _):
                // Only a TRUE client error (4xx, not 429) is permanent → drop so the queue can't wedge on
                // one bad window. 5xx / status 0 (server down, deploy, gateway hiccup, non-HTTP, encode)
                // are transient — KEEP the window and retry, or a routine restart silently eats the buffer.
                if (400..<500).contains(status) && status != 429 {
                    try? store.remove(id: item.id)
                } else {
                    try? store.bumpAttempt(id: item.id); scheduleRetry(); return
                }
            case .transport:
                try? store.bumpAttempt(id: item.id); scheduleRetry(); return  // retry on a timer
            }
        }
        if online { backoffStep = 0 }   // queue emptied while online → reset backoff
    }

    /// Re-attempt the drain after an exponential backoff (2,4,…,300s cap) so a transient failure recovers
    /// without needing a new window or a network-path change. Only one retry is ever pending.
    private func scheduleRetry() {
        retryTask?.cancel()
        let delaySec = min(300, 1 << min(backoffStep, 8))   // 2 << step would start at 2; 1<<step → 1,2,4,…
        backoffStep += 1
        retryTask = Task { [weak self] in
            try? await Task.sleep(nanoseconds: UInt64(max(2, delaySec)) * 1_000_000_000)
            if Task.isCancelled { return }
            await self?.drain()
        }
    }
}
