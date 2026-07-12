import SwiftUI

// MARK: - Shared warm "streak" gradient (the flame)

enum Streak {
    static let flame = LinearGradient(colors: [Theme.Palette.amber, Theme.Palette.pink],
                                      startPoint: .topLeading, endPoint: .bottomTrailing)
}

// MARK: - The Workouts-screen hero: the big consecutive-day number

/// Leads the Workouts screen with the one number that drives consistency — the day streak — then the
/// supporting counts (longest / this week / this month) and a 5-week calendar strip you can *see* fill in.
struct StreakHero: View {
    let streak: WorkoutStreakInfo
    let activeDays: [String]

    private var lit: Bool { streak.current > 0 }

    var body: some View {
        GlassCard(padding: Theme.Space.l) {
            VStack(spacing: Theme.Space.l) {
                HStack(spacing: Theme.Space.m) {
                    ZStack {
                        Circle().fill((lit ? Theme.Palette.amber : Theme.Palette.textFaint).opacity(0.14))
                            .frame(width: 78, height: 78)
                        Image(systemName: "flame.fill").font(.system(size: 35, weight: .bold))
                            .foregroundStyle(lit ? AnyShapeStyle(Streak.flame) : AnyShapeStyle(Theme.Palette.textFaint))
                    }
                    VStack(alignment: .leading, spacing: 2) {
                        HStack(alignment: .firstTextBaseline, spacing: 6) {
                            Text("\(streak.current)").font(Theme.Font.num(58))
                                .foregroundStyle(lit ? AnyShapeStyle(Streak.flame) : AnyShapeStyle(Theme.Palette.text))
                                .contentTransition(.numericText())
                            Text(streak.current == 1 ? "day" : "days")
                                .font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.textDim)
                        }
                        Text(subtitle).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                    }
                    Spacer(minLength: 0)
                }

                HStack(spacing: 0) {
                    stat("\(streak.longest)", "Longest")
                    statDivider
                    stat("\(streak.this_week ?? 0)", "This week")
                    statDivider
                    stat("\(streak.this_month ?? 0)", "This month")
                }

                WorkoutCalendarStrip(activeDays: Set(activeDays))
            }
        }
    }

    private var subtitle: LocalizedStringKey {
        if streak.current == 0 { return "Work out today to light the flame" }
        if streak.worked_out_today == true { return "Locked in today — keep it rolling" }
        return "Don't break the chain — train today"
    }

    private func stat(_ value: String, _ label: LocalizedStringKey) -> some View {
        VStack(spacing: 3) {
            Text(value).font(Theme.Font.num(22)).foregroundStyle(Theme.Palette.text).monospacedDigit()
            Text(label).font(Theme.Font.micro).tracking(0.6).textCase(.uppercase).foregroundStyle(Theme.Palette.textDim)
        }.frame(maxWidth: .infinity)
    }

    private var statDivider: some View {
        Rectangle().fill(Theme.Palette.cardStroke).frame(width: 1, height: 26)
    }
}

// MARK: - GitHub-style contribution strip (last 5 weeks; a lit cell = a day you trained)

struct WorkoutCalendarStrip: View {
    let activeDays: Set<String>   // 'yyyy-MM-dd' in the local calendar

    private static let fmt: DateFormatter = {
        let f = DateFormatter(); f.dateFormat = "yyyy-MM-dd"
        f.calendar = Calendar.current; f.timeZone = .current; return f
    }()

    // Oldest → today, 35 days, so the last cell is always "today".
    private var days: [(key: String, today: Bool)] {
        let cal = Calendar.current
        let start = cal.startOfDay(for: Date())
        return (0..<35).reversed().map { back in
            let d = cal.date(byAdding: .day, value: -back, to: start) ?? start
            return (Self.fmt.string(from: d), back == 0)
        }
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 7) {
            Text("Last 5 weeks").font(Theme.Font.micro).tracking(1).foregroundStyle(Theme.Palette.textFaint)
            LazyVGrid(columns: Array(repeating: GridItem(.flexible(), spacing: 5), count: 7), spacing: 5) {
                ForEach(days, id: \.key) { day in
                    let on = activeDays.contains(day.key)
                    RoundedRectangle(cornerRadius: 4, style: .continuous)
                        .fill(on ? AnyShapeStyle(Streak.flame) : AnyShapeStyle(Theme.Palette.card))
                        .aspectRatio(1, contentMode: .fit)
                        .overlay {
                            if day.today {
                                RoundedRectangle(cornerRadius: 4, style: .continuous)
                                    .strokeBorder(Theme.Palette.text.opacity(0.65), lineWidth: 1.5)
                            }
                        }
                }
            }
        }
    }
}

// MARK: - Compact flame badge (Today card + anywhere a streak needs a quiet mention)

struct StreakBadge: View {
    let count: Int
    var body: some View {
        HStack(spacing: 5) {
            Image(systemName: "flame.fill").font(.system(size: 14, weight: .bold))
                .foregroundStyle(Streak.flame)
            Text("\(count)").font(Theme.Font.num(20)).foregroundStyle(Theme.Palette.text).monospacedDigit()
        }
        .padding(.horizontal, 11).padding(.vertical, 6)
        .background(Theme.Palette.amber.opacity(0.12), in: Capsule())
    }
}

// MARK: - Today's Training card (lives on the Today hero screen)

/// On Today: what you trained today (if anything) + your live streak, with a tap through to Workouts.
/// Designed to read in a glance — a done workout shows its headline stat; an untrained day nudges you.
struct TodayTrainingCard: View {
    let workout: Dashboard.Workout

    private var streakCount: Int { workout.streak?.current ?? 0 }

    var body: some View {
        GlassCard {
            VStack(alignment: .leading, spacing: Theme.Space.m) {
                SectionHeader(title: "Training", trailing: "›")
                if let today = workout.today {
                    HStack(spacing: Theme.Space.m) {
                        iconTile(today)
                        VStack(alignment: .leading, spacing: 2) {
                            Text(headline(today)).font(Theme.Font.num(22)).foregroundStyle(Theme.Palette.text)
                            Text(today.title).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                        }
                        Spacer(minLength: 0)
                        if streakCount > 0 { StreakBadge(count: streakCount) }
                    }
                } else {
                    HStack(spacing: Theme.Space.m) {
                        VStack(alignment: .leading, spacing: 2) {
                            Text(streakCount > 0 ? "Keep the streak alive" : "Start a streak today")
                                .font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.text)
                            Text(streakCount > 0 ? "You haven't trained yet today" : "Run or lift — your streak starts on day one")
                                .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                        }
                        Spacer(minLength: 0)
                        if streakCount > 0 { StreakBadge(count: streakCount) }
                        else { Image(systemName: "flame").font(.system(size: 26)).foregroundStyle(Theme.Palette.textFaint) }
                    }
                }
            }
        }
    }

    private func iconTile(_ run: RunSummary) -> some View {
        let tint = run.isLift ? Theme.Palette.amber : Theme.Palette.mint
        return ZStack {
            RoundedRectangle(cornerRadius: Theme.Radius.chip).fill(tint.opacity(0.14)).frame(width: 44, height: 44)
            Image(systemName: icon(run)).foregroundStyle(tint).font(.system(size: 18, weight: .semibold))
        }
    }

    private func headline(_ run: RunSummary) -> String {
        if run.isLift { return run.duration_min.map { RunFmt.dur($0 * 60) } ?? String(localized: "Lift") }
        return run.distance_km.map { String(format: "%.2f km", $0) } ?? String(localized: "Run")
    }

    private func icon(_ run: RunSummary) -> String {
        switch run.activity_type {
        case "strength": return "dumbbell.fill"
        case "cycle": return "bicycle"
        case "walk": return "figure.walk"
        default: return "figure.run"
        }
    }
}
