import Foundation
#if canImport(HealthKit)
import HealthKit
#endif

/// Reads the user's Apple Health data (iPhone steps/distance + Apple Watch HRV/RHR/sleep/workouts/
/// weight), aggregates it per-day, and hands a payload to AppModel to POST at /api/me/health/ingest —
/// so Titan's whole engine works with NO band. Daily-aggregate sync (re-pull a window each time; the
/// server upserts idempotently) — robust and free-Apple-ID friendly (no background-delivery entitlement).
///
/// Premium details from the research: HRV is SDNN sampled sporadically by the Watch, so we AVERAGE it
/// over each night's sleep window (never one spot reading); sleep + workouts are de-duped by source
/// (a Watch + Whoop + Oura user has the same night 3× in Health).
final class HealthKitManager {
    static let shared = HealthKitManager()

    #if canImport(HealthKit)
    private let store = HKHealthStore()

    private let quantityIDs: [HKQuantityTypeIdentifier] = [
        .stepCount, .distanceWalkingRunning, .flightsClimbed, .activeEnergyBurned,
        .restingHeartRate, .heartRateVariabilitySDNN, .respiratoryRate, .vo2Max,
        .bodyMass, .bodyFatPercentage,
    ]
    private var readTypes: Set<HKObjectType> {
        var s = Set(quantityIDs.compactMap { HKQuantityType.quantityType(forIdentifier: $0) as HKObjectType? })
        if let sleep = HKCategoryType.categoryType(forIdentifier: .sleepAnalysis) { s.insert(sleep) }
        s.insert(HKObjectType.workoutType())
        return s
    }
    #endif

    var isAvailable: Bool {
        #if canImport(HealthKit)
        HKHealthStore.isHealthDataAvailable()
        #else
        false
        #endif
    }

    /// Ask for read access. HealthKit never reveals read denials, so we just request and proceed.
    func requestAuthorization() async -> Bool {
        #if canImport(HealthKit)
        guard isAvailable else { return false }
        do { try await store.requestAuthorization(toShare: [], read: readTypes); return true }
        catch { return false }
        #else
        return false
        #endif
    }

    /// Collect the last `days` of Health data as the ingest payload (matches /api/me/health/ingest).
    func collectPayload(days: Int) async -> [String: Any] {
        #if canImport(HealthKit)
        guard isAvailable else { return [:] }
        async let activity = activityRows(days: days)
        async let recovery = recoveryRows(days: days)
        async let sleep = sleepRows(days: days)
        async let body = bodyRows(days: days)
        async let workouts = workoutRows(days: days)
        async let vo2 = latestVO2()

        var p: [String: Any] = [
            "activity": await activity, "recovery": await recovery, "sleep": await sleep,
            "body": await body, "workouts": await workouts,
        ]
        if let v = await vo2 { p["vo2max"] = v }
        return p
        #else
        return [:]
        #endif
    }

    #if canImport(HealthKit)

    // MARK: - sections

    private func activityRows(days: Int) async -> [[String: Any]] {
        async let steps = stats(.stepCount, days: days, sum: true, unit: .count())
        async let dist = stats(.distanceWalkingRunning, days: days, sum: true, unit: HKUnit.meterUnit(with: .kilo))
        async let flights = stats(.flightsClimbed, days: days, sum: true, unit: .count())
        async let energy = stats(.activeEnergyBurned, days: days, sum: true, unit: .kilocalorie())
        let s = await steps, di = await dist, fl = await flights, en = await energy

        return mergedDates(s, di, fl, en).compactMap { d in
            var row: [String: Any] = ["date": d]
            if let v = s[d], v > 0 { row["steps"] = Int(v) }
            if let v = di[d], v > 0 { row["distance_km"] = (v * 100).rounded() / 100 }
            if let v = fl[d], v > 0 { row["floors"] = Int(v) }
            if let v = en[d], v > 0 { row["active_kcal"] = Int(v) }
            return row.count > 1 ? row : nil
        }
    }

    private func recoveryRows(days: Int) async -> [[String: Any]] {
        async let rhr = stats(.restingHeartRate, days: days, sum: false, unit: HKUnit(from: "count/min"))
        async let resp = stats(.respiratoryRate, days: days, sum: false, unit: HKUnit(from: "count/min"))
        async let hrv = nightlyHRV(days: days)
        let r = await rhr, rp = await resp, h = await hrv

        return mergedDates(r, rp, h).compactMap { d in
            var row: [String: Any] = ["date": d]
            if let v = h[d], v > 0 { row["hrv_ms"] = Int(v.rounded()) }
            if let v = r[d], v > 0 { row["resting_hr"] = Int(v.rounded()) }
            if let v = rp[d], v > 0 { row["resp_rate"] = (v * 10).rounded() / 10 }
            return row.count > 1 ? row : nil
        }
    }

    /// HRV averaged over each night's sleep window (the premium method — Watch SDNN is sparse & noisy).
    private func nightlyHRV(days: Int) async -> [String: Double] {
        guard let type = HKQuantityType.quantityType(forIdentifier: .heartRateVariabilitySDNN) else { return [:] }
        let sessions = await sleepSessions(days: days + 1)
        let samples: [HKQuantitySample] = await samples(type, days: days + 1)
        let ms = HKUnit.secondUnit(with: .milli)
        var out: [String: Double] = [:]
        for s in sessions {
            let inWindow = samples.filter { $0.startDate >= s.bedtime && $0.endDate <= s.wake }
            guard !inWindow.isEmpty else { continue }
            out[s.dateKey] = inWindow.map { $0.quantity.doubleValue(for: ms) }.reduce(0, +) / Double(inWindow.count)
        }
        return out
    }

    private struct SleepSession { let bedtime: Date; let wake: Date; let dateKey: String; let deep, rem, core, awake: Double; var asleepMin: Double { (deep + rem + core) / 60 } }

    private func sleepSessions(days: Int) async -> [SleepSession] {
        guard let type = HKCategoryType.categoryType(forIdentifier: .sleepAnalysis) else { return [] }
        let all: [HKCategorySample] = await samples(type, days: days)
        guard !all.isEmpty else { return [] }

        // Cluster into nights: a gap > 3h starts a new session.
        let sorted = all.sorted { $0.startDate < $1.startDate }
        var groups: [[HKCategorySample]] = []
        var cur: [HKCategorySample] = []
        var lastEnd: Date?
        for s in sorted {
            if let le = lastEnd, s.startDate.timeIntervalSince(le) > 3 * 3600, !cur.isEmpty {
                groups.append(cur); cur = []
            }
            cur.append(s); lastEnd = max(lastEnd ?? s.endDate, s.endDate)
        }
        if !cur.isEmpty { groups.append(cur) }

        return groups.compactMap { g in
            // De-dup overlapping sources: keep the source with the most asleep time.
            let bySource = Dictionary(grouping: g) { $0.sourceRevision.source.bundleIdentifier }
            let dominant = bySource.values.max { Self.asleep($0) < Self.asleep($1) } ?? g
            var deep = 0.0, rem = 0.0, core = 0.0, awake = 0.0
            var bedtime = Date.distantFuture, wake = Date.distantPast
            for s in dominant {
                let d = s.endDate.timeIntervalSince(s.startDate)
                switch HKCategoryValueSleepAnalysis(rawValue: s.value) {
                case .asleepDeep: deep += d
                case .asleepREM: rem += d
                case .asleepCore, .asleepUnspecified: core += d
                case .awake: awake += d
                default: continue  // .inBed envelope — ignore
                }
                if s.value != HKCategoryValueSleepAnalysis.inBed.rawValue {
                    bedtime = min(bedtime, s.startDate); wake = max(wake, s.endDate)
                }
            }
            guard deep + rem + core > 0 else { return nil }
            return SleepSession(bedtime: bedtime, wake: wake, dateKey: Self.ymd(wake), deep: deep, rem: rem, core: core, awake: awake)
        }
    }

    private static func asleep(_ samples: [HKCategorySample]) -> Double {
        samples.reduce(0) { acc, s in
            let asleepValues: [Int] = [HKCategoryValueSleepAnalysis.asleepCore.rawValue, HKCategoryValueSleepAnalysis.asleepDeep.rawValue, HKCategoryValueSleepAnalysis.asleepREM.rawValue, HKCategoryValueSleepAnalysis.asleepUnspecified.rawValue]
            return acc + (asleepValues.contains(s.value) ? s.endDate.timeIntervalSince(s.startDate) : 0)
        }
    }

    private func sleepRows(days: Int) async -> [[String: Any]] {
        await sleepSessions(days: days).map {
            ["date": $0.dateKey, "duration_min": Int($0.asleepMin),
             "deep_min": Int($0.deep / 60), "rem_min": Int($0.rem / 60),
             "light_min": Int($0.core / 60), "awake_min": Int($0.awake / 60)]
        }
    }

    private func bodyRows(days: Int) async -> [[String: Any]] {
        let mass = await dailyLatest(.bodyMass, days: days, unit: .gramUnit(with: .kilo))
        let fat = await dailyLatest(.bodyFatPercentage, days: days, unit: .percent())
        return mergedDates(mass, fat).compactMap { d in
            var row: [String: Any] = ["date": d]
            if let v = mass[d] { row["weight_kg"] = (v * 100).rounded() / 100 }
            if let v = fat[d] { row["body_fat_pct"] = (v * 1000).rounded() / 10 }  // 0–1 → %
            return row.count > 1 ? row : nil
        }
    }

    private func workoutRows(days: Int) async -> [[String: Any]] {
        let workouts: [HKWorkout] = await samples(HKObjectType.workoutType(), days: days)
        var seen = Set<String>()
        var rows: [[String: Any]] = []
        for w in workouts.sorted(by: { $0.duration > $1.duration }) {  // prefer the longer of overlaps
            let key = "\(Int(w.startDate.timeIntervalSince1970 / 120))-\(w.workoutActivityType.rawValue)"
            if seen.contains(key) { continue }
            seen.insert(key)
            var row: [String: Any] = [
                "started_at": Self.iso(w.startDate), "ended_at": Self.iso(w.endDate),
                "type": Self.activityName(w.workoutActivityType),
            ]
            if let km = w.totalDistance?.doubleValue(for: HKUnit.meterUnit(with: .kilo)), km > 0 { row["distance_km"] = (km * 100).rounded() / 100 }
            if let kcal = w.totalEnergyBurned?.doubleValue(for: .kilocalorie()), kcal > 0 { row["active_kcal"] = Int(kcal) }
            rows.append(row)
        }
        return rows
    }

    private func latestVO2() async -> Double? {
        guard let type = HKQuantityType.quantityType(forIdentifier: .vo2Max) else { return nil }
        let s: [HKQuantitySample] = await samples(type, days: 180)
        guard let last = s.last else { return nil }
        return (last.quantity.doubleValue(for: HKUnit(from: "ml/kg*min")) * 10).rounded() / 10
    }

    // MARK: - query helpers

    private func stats(_ id: HKQuantityTypeIdentifier, days: Int, sum: Bool, unit: HKUnit) async -> [String: Double] {
        guard let type = HKQuantityType.quantityType(forIdentifier: id) else { return [:] }
        let cal = Calendar.current
        let anchor = cal.startOfDay(for: Date())
        let start = cal.date(byAdding: .day, value: -days, to: anchor) ?? anchor
        return await withCheckedContinuation { cont in
            let q = HKStatisticsCollectionQuery(
                quantityType: type,
                quantitySamplePredicate: HKQuery.predicateForSamples(withStart: start, end: Date()),
                options: sum ? .cumulativeSum : .discreteAverage,
                anchorDate: anchor, intervalComponents: DateComponents(day: 1))
            q.initialResultsHandler = { _, res, _ in
                var out: [String: Double] = [:]
                res?.enumerateStatistics(from: start, to: Date()) { st, _ in
                    if let v = (sum ? st.sumQuantity() : st.averageQuantity())?.doubleValue(for: unit) {
                        out[Self.ymd(st.startDate)] = v
                    }
                }
                cont.resume(returning: out)
            }
            store.execute(q)
        }
    }

    /// The last value per day (for body mass / fat %).
    private func dailyLatest(_ id: HKQuantityTypeIdentifier, days: Int, unit: HKUnit) async -> [String: Double] {
        guard let type = HKQuantityType.quantityType(forIdentifier: id) else { return [:] }
        let s: [HKQuantitySample] = await samples(type, days: days)
        var out: [String: Double] = [:]
        for sample in s { out[Self.ymd(sample.endDate)] = sample.quantity.doubleValue(for: unit) }  // sorted asc → last wins
        return out
    }

    private func samples<T: HKSample>(_ type: HKSampleType, days: Int) async -> [T] {
        let start = Calendar.current.date(byAdding: .day, value: -days, to: Date()) ?? Date()
        return await withCheckedContinuation { cont in
            let q = HKSampleQuery(sampleType: type,
                                  predicate: HKQuery.predicateForSamples(withStart: start, end: Date()),
                                  limit: HKObjectQueryNoLimit,
                                  sortDescriptors: [NSSortDescriptor(key: HKSampleSortIdentifierStartDate, ascending: true)]) { _, samples, _ in
                cont.resume(returning: (samples as? [T]) ?? [])
            }
            store.execute(q)
        }
    }

    private func mergedDates(_ maps: [String: Double]...) -> [String] {
        var set = Set<String>()
        for m in maps { set.formUnion(m.keys) }
        return set.sorted()
    }

    private static func ymd(_ d: Date) -> String {
        let f = DateFormatter(); f.dateFormat = "yyyy-MM-dd"; f.timeZone = .current; return f.string(from: d)
    }
    private static func iso(_ d: Date) -> String { ISO8601DateFormatter().string(from: d) }

    private static func activityName(_ t: HKWorkoutActivityType) -> String {
        switch t {
        case .running: return "running"
        case .walking, .hiking: return "walking"
        case .cycling: return "cycling"
        case .swimming: return "swimming"
        case .rowing: return "rowing"
        case .traditionalStrengthTraining, .functionalStrengthTraining: return "strength"
        case .highIntensityIntervalTraining: return "hiit"
        case .yoga, .flexibility: return "yoga"
        default: return "other"
        }
    }
    #endif
}
