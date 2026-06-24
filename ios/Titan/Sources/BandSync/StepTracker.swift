import Foundation
#if canImport(CoreMotion)
import CoreMotion
#endif

/// Live "steps today" from the iPhone's motion coprocessor — instant, no HealthKit prompt, no Apple
/// Watch. The cheapest possible first-run value: anyone with an iPhone in their pocket gets a moving
/// step ring immediately. (HealthKit layers historical trends + Watch data on top.)
@MainActor
final class StepTracker: ObservableObject {
    @Published var steps = 0
    @Published var distanceKm = 0.0
    @Published var flights = 0
    @Published var available = false

    #if canImport(CoreMotion)
    private let pedometer = CMPedometer()
    #endif

    func start() {
        #if canImport(CoreMotion)
        guard CMPedometer.isStepCountingAvailable() else { return }
        available = true
        let startOfDay = Calendar.current.startOfDay(for: Date())

        // Backfill today's totals so far (the coprocessor caches ~7 days), then stream live updates.
        pedometer.queryPedometerData(from: startOfDay, to: Date()) { [weak self] d, _ in
            guard let d else { return }
            Task { @MainActor in self?.apply(d) }
        }
        pedometer.startUpdates(from: startOfDay) { [weak self] d, _ in
            guard let d else { return }
            Task { @MainActor in self?.apply(d) }
        }
        #endif
    }

    func stop() {
        #if canImport(CoreMotion)
        pedometer.stopUpdates()
        #endif
    }

    #if canImport(CoreMotion)
    private func apply(_ d: CMPedometerData) {
        steps = d.numberOfSteps.intValue
        distanceKm = (d.distance?.doubleValue ?? 0) / 1000
        flights = d.floorsAscended?.intValue ?? flights
    }
    #endif
}
