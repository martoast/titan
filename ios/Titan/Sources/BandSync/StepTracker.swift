import Foundation
#if canImport(CoreMotion)
import CoreMotion
#endif

/// Live "steps today" from the iPhone's motion coprocessor — instant, no HealthKit prompt, no Apple
/// Watch. The cheapest possible first-run value: anyone with an iPhone in their pocket gets a moving
/// step ring immediately. (HealthKit layers historical trends + Watch data on top.)
///
/// Resets at local midnight: `CMPedometer.startUpdates(from:)` counts cumulatively from a FIXED anchor
/// and never rolls over on its own, so we re-anchor to the new start-of-day at midnight — otherwise
/// "today" would silently include yesterday's steps (and we'd push that inflated total to the band).
@MainActor
final class StepTracker: ObservableObject {
    @Published var steps = 0
    @Published var distanceKm = 0.0
    @Published var flights = 0
    @Published var available = false

    #if canImport(CoreMotion)
    private let pedometer = CMPedometer()
    #endif
    private var anchor = Calendar.current.startOfDay(for: Date())
    private var midnightTask: Task<Void, Never>?

    func start() {
        #if canImport(CoreMotion)
        guard CMPedometer.isStepCountingAvailable() else { return }
        available = true
        anchorToToday()
        scheduleMidnightReset()
        #endif
    }

    func stop() {
        #if canImport(CoreMotion)
        pedometer.stopUpdates()
        #endif
        midnightTask?.cancel(); midnightTask = nil
    }

    #if canImport(CoreMotion)
    /// (Re)start the live stream from the current start-of-day, backfilling today's total first.
    private func anchorToToday() {
        pedometer.stopUpdates()
        anchor = Calendar.current.startOfDay(for: Date())
        let from = anchor
        pedometer.queryPedometerData(from: from, to: Date()) { [weak self] d, _ in
            guard let d else { return }
            Task { @MainActor in self?.apply(d) }
        }
        pedometer.startUpdates(from: from) { [weak self] d, _ in
            guard let d else { return }
            Task { @MainActor in self?.apply(d) }
        }
    }

    private func apply(_ d: CMPedometerData) {
        // A late callback from the previous day's anchor could arrive just after we re-anchored — ignore
        // anything that predates the current start-of-day so the fresh day never jumps to yesterday's total.
        if d.endDate < anchor { return }
        steps = d.numberOfSteps.intValue
        distanceKm = (d.distance?.doubleValue ?? 0) / 1000
        flights = d.floorsAscended?.intValue ?? flights
    }
    #endif

    /// Zero the day and re-anchor at the next local midnight, then reschedule for the following night.
    private func scheduleMidnightReset() {
        let cal = Calendar.current
        guard let nextMidnight = cal.nextDate(after: Date(), matching: DateComponents(hour: 0, minute: 0, second: 2),
                                              matchingPolicy: .nextTime) else { return }
        let delay = max(1, nextMidnight.timeIntervalSinceNow)
        midnightTask?.cancel()
        midnightTask = Task { @MainActor [weak self] in
            try? await Task.sleep(nanoseconds: UInt64(delay * 1_000_000_000))
            guard let self, !Task.isCancelled else { return }
            self.steps = 0; self.distanceKm = 0; self.flights = 0
            #if canImport(CoreMotion)
            self.anchorToToday()
            #endif
            self.scheduleMidnightReset()
        }
    }
}
