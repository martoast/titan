import SwiftUI

/// The detail screen for a sealed LIFTING session, opened from the Daily → Train list. A lift has no
/// route/distance, so this headlines the strength story: time, HR zones, peak HR, VO₂max, calories,
/// training load, and the detected sets/reps. Renders the SAME premium building blocks as the lift half
/// of WorkoutSummaryView (LiftHero / LiftStatGrid / HrZonesCard / LiftSetsCard) so a lift looks identical
/// wherever it's opened — driven by the server-sealed RunDetail, fetched by id like RunDetailView.
struct LiftDetailView: View {
    let runId: Int
    let fallback: RunSummary
    @EnvironmentObject var model: AppModel
    @Environment(\.dismiss) private var dismiss
    @State private var detail: RunDetail?
    @State private var loading = true
    @State private var failed = false

    private var accent: Color { Theme.Palette.pink }
    private var imperial: Bool { (detail?.units ?? "metric") == "imperial" }

    var body: some View {
        NavigationStack {
            ZStack(alignment: .top) {
                Theme.Palette.bg.ignoresSafeArea()
                Theme.Grad.glow(accent).frame(height: 320).opacity(0.35).ignoresSafeArea(edges: .top)

                ScrollView {
                    VStack(spacing: Theme.Space.m) {
                        LiftHero(duration: durationText, maxHr: detail?.max_hr, redMinutes: redMinutes)
                        if detail != nil {
                            LiftStatGrid(detail: detail, fallbackMaxBpm: 0, fallbackDuration: durationText)
                        }
                        if let z = detail?.hr_zones, hrZonesTotal(z) > 0 { HrZonesCard(zones: z) }
                        if let s = detail?.strength, let ex = s.exercises, !ex.isEmpty {
                            LiftSetsCard(strength: s, exercises: ex, imperial: imperial)
                        } else if loading {
                            loadingNote("Loading your lift…")
                        }
                        if failed {
                            Text("Couldn't load this workout. It's saved — pull back and reopen.")
                                .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
                                .frame(maxWidth: .infinity).multilineTextAlignment(.center)
                        }
                        Color.clear.frame(height: 6)
                    }
                    .padding(Theme.Space.m)
                }
                .scrollIndicators(.hidden)
            }
            .navigationTitle(detail?.title ?? fallback.title)
            .navigationBarTitleDisplayMode(.inline)
            .toolbarColorScheme(.dark, for: .navigationBar)
            .toolbar { ToolbarItem(placement: .topBarLeading) { Button("Done") { dismiss() } } }
            .task { await load() }
        }
    }

    private func load() async {
        loading = true; failed = false
        do { detail = try await model.api.runDetail(runId) } catch { failed = true }
        loading = false
    }

    private func loadingNote(_ text: LocalizedStringKey) -> some View {
        HStack(spacing: Theme.Space.s) {
            ProgressView().tint(Theme.Palette.textFaint)
            Text(text).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
        }
        .frame(maxWidth: .infinity).padding(.vertical, Theme.Space.m)
        .background(RoundedRectangle(cornerRadius: Theme.Radius.card).fill(Theme.Palette.card))
    }

    private var redMinutes: Int? {
        guard let z = detail?.hr_zones else { return nil }
        let r = (z.z4 ?? 0) + (z.z5 ?? 0)
        return r > 0 ? Int(r.rounded()) : nil
    }

    private var durationText: String {
        let s = (detail?.duration_min ?? fallback.duration_min ?? 0) * 60
        let h = s / 3600, m = (s % 3600) / 60, sec = s % 60
        return h > 0 ? String(format: "%d:%02d:%02d", h, m, sec) : String(format: "%d:%02d", m, sec)
    }
}
