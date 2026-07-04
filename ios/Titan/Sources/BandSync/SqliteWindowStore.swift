import Foundation
import SQLite3

/// Crash-durable `WindowStore` backed by the system SQLite (no external dependency). Each window
/// is persisted as JSON the instant it's built, so an app kill / relaunch never loses the
/// overnight buffer — the queue drains from disk on next launch (and during background wakes).
/// FIFO by insertion id; `attempts` supports backoff/inspection.
final class SqliteWindowStore: WindowStore {
    private var db: OpaquePointer?
    private let file: String
    private let lock = NSLock()
    private static let SQLITE_TRANSIENT = unsafeBitCast(-1, to: sqlite3_destructor_type.self)

    /// `path`: a file in Application Support (persists across launches). Defaults under the app's
    /// Application Support directory.
    init(path: String? = nil) {
        file = path ?? Self.defaultPath()
        tryOpen()
    }

    /// Open (or re-open) the database. Deletes + recreates the file ONLY on actual corruption —
    /// a TRANSIENT open failure (most plausibly iOS Data Protection before first unlock, during a
    /// background BLE relaunch) used to nuke the entire overnight backlog here. On a transient
    /// failure `db` stays nil; every public method retries the open, and the SyncQueue's in-memory
    /// buffer absorbs enqueues until the file becomes readable.
    private func tryOpen() {
        let rc = sqlite3_open(file, &db)
        if rc == SQLITE_OK { ensureSchema(); return }
        sqlite3_close(db)
        db = nil
        if rc == SQLITE_CORRUPT || rc == SQLITE_NOTADB {
            try? FileManager.default.removeItem(atPath: file)
            if sqlite3_open(file, &db) == SQLITE_OK { ensureSchema() } else { sqlite3_close(db); db = nil }
        }
    }

    private func ensureSchema() {
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

    /// Caller must hold `lock`. Retries the open if a transient failure left `db` nil.
    private func ensureOpen() throws {
        if db == nil { tryOpen() }
        if db == nil { throw StoreError.prepare }
    }

    deinit { sqlite3_close(db) }

    func enqueue(_ window: AnyWindow) throws {
        lock.lock(); defer { lock.unlock() }
        try ensureOpen()
        let data = try JSONEncoder().encode(window)
        var stmt: OpaquePointer?
        guard sqlite3_prepare_v2(db, "INSERT INTO windows (payload, created_at) VALUES (?, ?);", -1, &stmt, nil) == SQLITE_OK else {
            throw StoreError.prepare
        }
        defer { sqlite3_finalize(stmt) }
        _ = data.withUnsafeBytes { sqlite3_bind_blob(stmt, 1, $0.baseAddress, Int32(data.count), Self.SQLITE_TRANSIENT) }
        sqlite3_bind_double(stmt, 2, Date().timeIntervalSince1970)
        guard sqlite3_step(stmt) == SQLITE_DONE else { throw StoreError.step }
    }

    func pending(limit: Int) throws -> [(id: Int64, window: AnyWindow)] {
        lock.lock(); defer { lock.unlock() }
        try ensureOpen()
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
                    deleteRow(id: id)   // corrupt row — drop it (we already hold the lock; must NOT
                    // call the public, self-locking remove() here or NSLock deadlocks the drain).
                }
            }
        }
        return out
    }

    func remove(id: Int64) throws {
        lock.lock(); defer { lock.unlock() }
        try ensureOpen()
        // Surface a failed DELETE (disk full / IO error). If we swallow it, the row survives and the
        // drain re-selects the same head row forever — an infinite re-upload loop that wedges the queue.
        guard deleteRow(id: id) else { throw StoreError.step }
    }

    /// Delete a row; returns whether the DELETE actually completed. The CALLER must already hold `lock`
    /// (NSLock is non-recursive — re-locking from the same thread deadlocks). Used by both `remove(id:)`
    /// and `pending`'s corrupt-row drop.
    @discardableResult
    private func deleteRow(id: Int64) -> Bool {
        var stmt: OpaquePointer?
        guard sqlite3_prepare_v2(db, "DELETE FROM windows WHERE id = ?;", -1, &stmt, nil) == SQLITE_OK else { return false }
        defer { sqlite3_finalize(stmt) }
        sqlite3_bind_int64(stmt, 1, id)
        return sqlite3_step(stmt) == SQLITE_DONE
    }

    func bumpAttempt(id: Int64) throws {
        lock.lock(); defer { lock.unlock() }
        try ensureOpen()
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
