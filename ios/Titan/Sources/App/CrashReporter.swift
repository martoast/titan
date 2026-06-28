import Foundation

/// Last-resort crash capture for a crash we can't reproduce or pull device logs for. Records the
/// crash kind + a backtrace to UserDefaults so the NEXT launch can show exactly what died — both
/// Objective-C exceptions (e.g. CoreLocation/CoreBluetooth misuse, which trap via NSException) AND
/// Swift runtime traps (force-unwrap, array OOB, integer overflow), which arrive as POSIX signals.
///
/// Note: signal handlers aren't strictly async-signal-safe with Foundation, but for a diagnostic
/// build this reliably captures the symbol(s) before re-raising so the OS still logs the crash.
enum CrashReporter {
    private static let key = "titan.lastCrash"

    static func install() {
        NSSetUncaughtExceptionHandler { ex in
            let frames = ex.callStackSymbols.prefix(16).joined(separator: "\n")
            CrashReporter.store("NSException \(ex.name.rawValue): \(ex.reason ?? "—")\n\(frames)")
        }
        for sig in [SIGABRT, SIGILL, SIGSEGV, SIGFPE, SIGBUS, SIGTRAP] {
            signal(sig) { s in
                let frames = Thread.callStackSymbols.prefix(24).joined(separator: "\n")
                CrashReporter.store("signal \(s)\n\(frames)")
                signal(s, SIG_DFL); raise(s)   // restore default + re-raise so the OS records it too
            }
        }
    }

    private static func store(_ s: String) {
        let ud = UserDefaults.standard
        ud.set("t=\(time(nil))\n\(s)", forKey: key)
        ud.synchronize()
    }

    /// The last captured crash (nil if the app has never crashed since install).
    static var lastCrash: String? { UserDefaults.standard.string(forKey: key) }
    static func clear() { UserDefaults.standard.removeObject(forKey: key) }
}
