import Foundation
import SQLite3

/// Crash-durable `WindowStore` backed by the system SQLite (no external dependency). Each window
/// is persisted as JSON the instant it's built, so an app kill / relaunch never loses the
/// overnight buffer — the queue drains from disk on next launch (and during background wakes).
/// FIFO by insertion id; `attempts` supports backoff/inspection.
final class SqliteWindowStore: WindowStore {
    private var db: OpaquePointer?
    private let lock = NSLock()
    private static let SQLITE_TRANSIENT = unsafeBitCast(-1, to: sqlite3_destructor_type.self)

    /// `path`: a file in Application Support (persists across launches). Defaults under the app's
    /// Application Support directory.
    init(path: String? = nil) {
        let file = path ?? Self.defaultPath()
        if sqlite3_open(file, &db) == SQLITE_OK {
            exec("""
                CREATE TABLE IF NOT EXISTS windows (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    payload BLOB NOT NULL,
                    attempts INTEGER NOT NULL DEFAULT 0,
                    created_at REAL NOT NULL
                );
            """)
            exec("PRAGMA journal_mode=WAL;")
        }
    }

    deinit { sqlite3_close(db) }

    func enqueue(_ window: AnyWindow) throws {
        lock.lock(); defer { lock.unlock() }
        let data = try JSONEncoder().encode(window)
        var stmt: OpaquePointer?
        guard sqlite3_prepare_v2(db, "INSERT INTO windows (payload, created_at) VALUES (?, ?);", -1, &stmt, nil) == SQLITE_OK else {
            throw StoreError.prepare
        }
        defer { sqlite3_finalize(stmt) }
        data.withUnsafeBytes { sqlite3_bind_blob(stmt, 1, $0.baseAddress, Int32(data.count), Self.SQLITE_TRANSIENT) }
        sqlite3_bind_double(stmt, 2, Date().timeIntervalSince1970)
        guard sqlite3_step(stmt) == SQLITE_DONE else { throw StoreError.step }
    }

    func pending(limit: Int) throws -> [(id: Int64, window: AnyWindow)] {
        lock.lock(); defer { lock.unlock() }
        var stmt: OpaquePointer?
        guard sqlite3_prepare_v2(db, "SELECT id, payload FROM windows ORDER BY id ASC LIMIT ?;", -1, &stmt, nil) == SQLITE_OK else {
            throw StoreError.prepare
        }
        defer { sqlite3_finalize(stmt) }
        sqlite3_bind_int(stmt, 1, Int32(limit))
        var out: [(id: Int64, window: AnyWindow)] = []
        while sqlite3_step(stmt) == SQLITE_ROW {
            let id = sqlite3_column_int64(stmt, 0)
            if let blob = sqlite3_column_blob(stmt, 1) {
                let bytes = Int(sqlite3_column_bytes(stmt, 1))
                let data = Data(bytes: blob, count: bytes)
                if let w = try? JSONDecoder().decode(AnyWindow.self, from: data) {
                    out.append((id, w))
                } else {
                    try? remove(id: id)   // corrupt row — drop it rather than wedge the queue
                }
            }
        }
        return out
    }

    func remove(id: Int64) throws {
        lock.lock(); defer { lock.unlock() }
        var stmt: OpaquePointer?
        sqlite3_prepare_v2(db, "DELETE FROM windows WHERE id = ?;", -1, &stmt, nil)
        defer { sqlite3_finalize(stmt) }
        sqlite3_bind_int64(stmt, 1, id)
        sqlite3_step(stmt)
    }

    func bumpAttempt(id: Int64) throws {
        lock.lock(); defer { lock.unlock() }
        var stmt: OpaquePointer?
        sqlite3_prepare_v2(db, "UPDATE windows SET attempts = attempts + 1 WHERE id = ?;", -1, &stmt, nil)
        defer { sqlite3_finalize(stmt) }
        sqlite3_bind_int64(stmt, 1, id)
        sqlite3_step(stmt)
    }

    private func exec(_ sql: String) { sqlite3_exec(db, sql, nil, nil, nil) }

    private static func defaultPath() -> String {
        let dir = (try? FileManager.default.url(for: .applicationSupportDirectory, in: .userDomainMask, appropriateFor: nil, create: true))
            ?? URL(fileURLWithPath: NSTemporaryDirectory())
        return dir.appendingPathComponent("titan-sync.sqlite").path
    }

    enum StoreError: Error { case prepare, step }
}
