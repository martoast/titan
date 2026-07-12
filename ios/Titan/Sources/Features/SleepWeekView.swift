import SwiftUI

/// Sleep's "week at a glance" — the recovery-side counterpart to the Workouts week screen. A cumulative
/// week ring, the 7-night row (the heart of the ask), a consistency streak, a 5-week heat strip, and one
/// data-driven tip. Reads the `week` block from /me/sleep. Sleep palette (indigo/violet), not training.
struct SleepWeekView: View {
    let week: SleepWeekInfo
    @State private var selected: SleepWeekInfo.Day?

    private var accent: Color { Theme.Palette.indigo }

    var body: some View {
        ScrollView {
            VStack(spacing: Theme.Space.m) {
                weekRing
                nightsRow
                if let s = week.streak { streakCard(s) }
                heatStrip
                if let tip = week.tip { tipCard(tip) }
                Color.clear.frame(height: 8)
            }
            .padding(Theme.Space.m)
        }
        .background(Theme.Palette.bg.ignoresSafeArea())
        .navigationTitle("Sleep week").navigationBarTitleDisplayMode(.inline)
        .toolbarColorScheme(.dark, for: .navigationBar)
    }

    // The cumulative week score — the "how was my week" glance.
    private var weekRing: some View {
        GlassCard(padding: Theme.Space.l) {
            HStack(spacing: Theme.Space.l) {
                ZStack {
                    Circle().stroke(Color.white.opacity(0.08), lineWidth: 9)
                    Circle().trim(from: 0, to: max(0.001, CGFloat(week.week_score ?? 0) / 100))
                        .stroke(Theme.Grad.ring(accent), style: StrokeStyle(lineWidth: 9, lineCap: .round))
                        .rotationEffect(.degrees(-90))
                    VStack(spacing: 0) {
                        Text(week.week_score.map(String.init) ?? "–").font(Theme.Font.num(30)).foregroundStyle(.white)
                        Text("week").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
                    }
                }.frame(width: 96, height: 96)
                VStack(alignment: .leading, spacing: 4) {
                    if let label = week.week_label {
                        Text(label).font(Theme.Font.title).foregroundStyle(Theme.Palette.text)
                    }
                    if let n = week.nights_logged { Text("\(n) of 7 nights scored").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim) }
                    if let t = week.trend, t != "flat" {
                        HStack(spacing: 5) {
                            Image(systemName: t == "up" ? "arrow.up.right" : "arrow.down.right").font(.caption2)
                            Text(t == "up" ? "better than last week" : "down from last week").font(Theme.Font.micro)
                        }.foregroundStyle(t == "up" ? Theme.Palette.mint : Theme.Palette.amber)
                    }
                }
                Spacer()
            }
        }
    }

    // The 7-night row — every night's score as a bar; tap to see it. Missing = hollow, low-signal = dashed.
    private var nightsRow: some View {
        GlassCard {
            VStack(alignment: .leading, spacing: Theme.Space.s) {
                SectionHeader(title: "This week")
                HStack(alignment: .bottom, spacing: 6) {
                    ForEach(week.days) { day in
                        Button { Haptic.tap(); selected = day } label: { nightBar(day) }
                            .buttonStyle(.plain)
                    }
                }
                .frame(height: 120)
                if let d = selected { selectedNote(d) }
            }
        }
    }

    private func nightBar(_ day: SleepWeekInfo.Day) -> some View {
        let score = day.score ?? 0
        let hit = day.hit_need ?? false
        let low = day.low_confidence ?? false
        let logged = day.logged ?? false
        let fill: Color = !logged ? .clear : (low ? Theme.Palette.textFaint : (hit ? accent : accent.opacity(0.35)))
        return VStack(spacing: 5) {
            Spacer(minLength: 0)
            ZStack(alignment: .bottom) {
                RoundedRectangle(cornerRadius: 5).stroke(Theme.Palette.cardStroke, style: StrokeStyle(lineWidth: 1, dash: low ? [3] : []))
                    .frame(height: 90)
                RoundedRectangle(cornerRadius: 5).fill(fill)
                    .frame(height: max(logged ? 6 : 0, 90 * CGFloat(min(1, Double(score) / 100))))
            }
            .frame(height: 90)
            Text(day.weekday ?? "").font(Theme.Font.micro).foregroundStyle(selected?.id == day.id ? Theme.Palette.text : Theme.Palette.textFaint)
        }
        .frame(maxWidth: .infinity)
    }

    private func selectedNote(_ d: SleepWeekInfo.Day) -> some View {
        HStack(spacing: 8) {
            Text(d.weekday ?? "").font(Theme.Font.micro.weight(.semibold)).foregroundStyle(Theme.Palette.text)
            if let dur = d.duration_min { Text("\(dur / 60)h \(dur % 60)m").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim) }
            Spacer()
            if d.low_confidence == true { Text("~ estimate").font(Theme.Font.micro).foregroundStyle(Theme.Palette.amber) }
            else if let s = d.score { Text("\(s)%").font(Theme.Font.num(14)).foregroundStyle(accent) }
            else { Text("no night").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint) }
        }
        .padding(.top, 4)
    }

    private func streakCard(_ s: SleepWeekInfo.Streak) -> some View {
        GlassCard {
            HStack(spacing: Theme.Space.m) {
                Image(systemName: "moon.stars.fill").font(.title2).foregroundStyle(accent)
                VStack(alignment: .leading, spacing: 2) {
                    Text("\(s.current ?? 0)-night streak").font(Theme.Font.title).foregroundStyle(Theme.Palette.text)
                    Text("hitting your sleep need").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                }
                Spacer()
                if let l = s.longest, l > 0 {
                    VStack(spacing: 1) {
                        Text("\(l)").font(Theme.Font.num(18)).foregroundStyle(Theme.Palette.textDim)
                        Text("best").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
                    }
                }
            }
        }
    }

    // 5-week heat strip — nights that hit need, lit. Consistency is the metric that matters most.
    private var heatStrip: some View {
        let lit = Set(week.strip)
        let days: [String] = (0..<35).reversed().map { i in
            let d = Calendar.current.date(byAdding: .day, value: -i, to: Date()) ?? Date()
            let f = DateFormatter(); f.dateFormat = "yyyy-MM-dd"; return f.string(from: d)
        }
        return GlassCard {
            VStack(alignment: .leading, spacing: Theme.Space.s) {
                SectionHeader(title: "Last 5 weeks")
                LazyVGrid(columns: Array(repeating: GridItem(.flexible(), spacing: 4), count: 7), spacing: 4) {
                    ForEach(days, id: \.self) { d in
                        RoundedRectangle(cornerRadius: 3)
                            .fill(lit.contains(d) ? accent : Color.white.opacity(0.06))
                            .frame(height: 16)
                    }
                }
            }
        }
    }

    private func tipCard(_ tip: SleepWeekInfo.Tip) -> some View {
        GlassCard {
            VStack(alignment: .leading, spacing: 6) {
                HStack(spacing: 7) {
                    Image(systemName: "lightbulb.fill").foregroundStyle(Theme.Palette.amber)
                    Text(tip.headline ?? "This week").font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.text)
                }
                if let insight = tip.insight { Text(insight).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim) }
                if let action = tip.action {
                    HStack(spacing: 6) {
                        Image(systemName: "arrow.right.circle.fill").font(.caption).foregroundStyle(accent)
                        Text(action).font(Theme.Font.micro.weight(.semibold)).foregroundStyle(Theme.Palette.text)
                    }.padding(.top, 2)
                }
            }
        }
    }
}
