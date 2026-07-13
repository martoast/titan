import SwiftUI

/// One past night opened from the Sleep Week per-night tap (in the `sleepweek` coach card). Fetches that
/// night's full detail by date and renders the SAME hero the Sleep screen shows — story → interactive
/// timeline (with cycle markers) → stage breakdown — so a tapped week night looks identical to "last night".
struct SleepNightSheet: View {
    @EnvironmentObject var model: AppModel
    @Environment(\.dismiss) private var dismiss
    let date: String                       // yyyy-MM-dd (local day of the night)

    @State private var detail: SleepResponse.Detail?
    @State private var loading = true
    @State private var failed = false

    var body: some View {
        NavigationStack {
            ZStack(alignment: .top) {
                Theme.Palette.bg.ignoresSafeArea()
                Theme.Grad.glow(Theme.Palette.indigo).frame(height: 300).opacity(0.45).ignoresSafeArea(edges: .top)

                ScrollView {
                    VStack(spacing: Theme.Space.m) {
                        if let d = detail {
                            if let story = d.story, let text = story.text, !text.isEmpty {
                                GlassCard { SleepStoryCard(story: story) }
                            }
                            GlassCard {
                                VStack(alignment: .leading, spacing: Theme.Space.s) {
                                    SectionHeader(title: "Sleep timeline")
                                    SleepTimeline(stages: d.hypnogram ?? [],
                                                  epochSec: d.epoch_sec,
                                                  bedtime: d.bedtime, wakeTime: d.wake_time,
                                                  computing: d.stage_status == "computing",
                                                  interactive: true,
                                                  hrSeries: d.hr_series, motionSeries: d.motion_series,
                                                  cycleBoundaries: d.story?.cycle_boundaries)
                                }
                            }
                            GlassCard {
                                VStack(alignment: .leading, spacing: Theme.Space.m) {
                                    SectionHeader(title: "Sleep stages")
                                    StageBreakdown(stages: d.stages)
                                }
                            }
                        } else if loading {
                            ProgressView().tint(Theme.Palette.indigo).frame(maxWidth: .infinity).padding(.top, 80)
                        } else if failed {
                            Text("Couldn't load that night. It may not have a sealed detail yet.")
                                .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
                                .multilineTextAlignment(.center).frame(maxWidth: .infinity).padding(.top, 80)
                        }
                        Color.clear.frame(height: 8)
                    }
                    .padding(Theme.Space.m)
                }
                .scrollIndicators(.hidden)
            }
            .navigationTitle(Self.pretty(date))
            .navigationBarTitleDisplayMode(.inline)
            .toolbarColorScheme(.dark, for: .navigationBar)
            .toolbar { ToolbarItem(placement: .confirmationAction) { Button("Done") { dismiss() } } }
            .task { await load() }
        }
    }

    private func load() async {
        loading = true; failed = false
        do { detail = try await model.api.sleepNight(date: date); failed = (detail == nil) }
        catch { failed = true }
        loading = false
    }

    /// "2026-07-11" → "Fri, Jul 11".
    static func pretty(_ ymd: String) -> String {
        let inF = DateFormatter(); inF.dateFormat = "yyyy-MM-dd"
        guard let d = inF.date(from: ymd) else { return ymd }
        let out = DateFormatter(); out.dateFormat = "EEE, MMM d"
        return out.string(from: d)
    }
}

/// Identifiable date wrapper so a tapped night can drive a `.fullScreenCover(item:)`.
struct SleepNightRef: Identifiable, Equatable { let id = UUID(); let date: String }
