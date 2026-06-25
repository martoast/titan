import SwiftUI

/// The "Daily" hub — one tab, one segmented switcher over the day's pillars: Sleep · Fuel · Train,
/// plus Cycle for women. Replaces the separate Fuel/Train tabs and houses Sleep + Cycle. Today stays
/// the recovery overview; this is where you go deep on each pillar.
struct DailyView: View {
    @EnvironmentObject var model: AppModel
    @State private var seg: Seg = .sleep
    @State private var showTargets = false

    enum Seg: String, CaseIterable { case sleep = "Sleep", fuel = "Fuel", train = "Train", cycle = "Cycle" }

    private var segs: [Seg] {
        model.showsCycle ? [.sleep, .fuel, .train, .cycle] : [.sleep, .fuel, .train]
    }
    private var glow: Color {
        switch seg { case .sleep: return Theme.Palette.indigo; case .fuel: return Theme.Palette.amber
        case .train: return Theme.Palette.cyan; case .cycle: return Theme.Palette.pink }
    }

    var body: some View {
        VStack(spacing: Theme.Space.m) {
            Picker("", selection: $seg) {
                ForEach(segs, id: \.self) { Text($0.rawValue).tag($0) }
            }
            .pickerStyle(.segmented)
            .padding(.top, Theme.Space.xs)

            switch seg {
            case .sleep: SleepSection()
            case .fuel: FuelSection()
            case .train: TrainSection()
            case .cycle: CycleSection()
            }
            Color.clear.frame(height: 8)
        }
        .animation(Theme.Motion.snappy, value: seg)
        .titanScreen("Daily", glow: glow)
        .toolbar {
            if seg == .fuel {
                ToolbarItem(placement: .topBarTrailing) {
                    Button { Haptic.tap(); showTargets = true } label: { Image(systemName: "slider.horizontal.3") }
                        .tint(Theme.Palette.textDim)
                }
            }
        }
        .sheet(item: $model.scanResult) { ScanResultSheet(result: $0) }
        .sheet(isPresented: $showTargets) { TargetsSheet() }
        .task { await model.loadCycle() }   // learn whether to offer the Cycle segment
    }
}

// MARK: - Sleep segment

private struct SleepSection: View {
    @EnvironmentObject var model: AppModel

    var body: some View {
        VStack(spacing: Theme.Space.m) {
            let night = model.sleepDetail?.nights.first
            let a = model.sleepDetail?.assess

            GlassCard(padding: Theme.Space.l) {
                VStack(spacing: Theme.Space.m) {
                    if let n = night, let dur = n.duration_min {
                        HStack(alignment: .firstTextBaseline, spacing: 6) {
                            Text(hm(dur)).font(Theme.Font.num(46)).foregroundStyle(.white)
                            if let p = a?.performance_pct { Text("· \(p)%").font(Theme.Font.body).foregroundStyle(Theme.Palette.indigo) }
                            Spacer()
                            Image(systemName: "moon.zzz.fill").foregroundStyle(Theme.Palette.indigo).font(.title3)
                        }
                        stagesBar(n)
                        HStack(spacing: Theme.Space.l) {
                            stat("\(n.deep_min ?? 0)m", "Deep", Theme.Palette.indigo)
                            stat("\(n.rem_min ?? 0)m", "REM", Theme.Palette.violet)
                            stat("\(n.light_min ?? 0)m", "Light", Theme.Palette.cyan)
                            if let q = n.quality { stat("\(q)%", "Quality", Theme.Palette.mint) }
                        }
                    } else {
                        VStack(spacing: 6) {
                            Image(systemName: "moon.zzz").font(.system(size: 30)).foregroundStyle(Theme.Palette.textFaint)
                            Text("No sleep logged yet").font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.text)
                            Text("Use the band's Stopwatch face — double-click to log a night.")
                                .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim).multilineTextAlignment(.center)
                        }.frame(maxWidth: .infinity).padding(.vertical, Theme.Space.s)
                    }
                }
            }

            if let a, let need = a.need_h {
                GlassCard {
                    VStack(alignment: .leading, spacing: Theme.Space.s) {
                        SectionHeader(title: "Sleep need", trailing: a.label)
                        HStack(spacing: Theme.Space.l) {
                            stat(trim(need) + "h", "Need", Theme.Palette.cyan)
                            stat(trim(a.debt_h ?? 0) + "h", "Debt", (a.debt_h ?? 0) > 0.5 ? Theme.Palette.amber : Theme.Palette.mint)
                            stat((a.last_h.map { trim($0) } ?? "—") + "h", "Last", Theme.Palette.indigo)
                        }
                        if let adv = a.advice, !adv.isEmpty {
                            Text(adv).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                        }
                    }
                }
            }
        }
        .task { await model.loadSleepDetail() }
    }

    private func stagesBar(_ n: SleepResponse.Night) -> some View {
        let segs: [(Int, Color)] = [
            (n.deep_min ?? 0, Theme.Palette.indigo), (n.rem_min ?? 0, Theme.Palette.violet),
            (n.light_min ?? 0, Theme.Palette.cyan), (n.awake_min ?? 0, Theme.Palette.textFaint),
        ]
        let total = max(1, segs.reduce(0) { $0 + $1.0 })
        return GeometryReader { geo in
            HStack(spacing: 2) {
                ForEach(Array(segs.enumerated()), id: \.offset) { _, s in
                    if s.0 > 0 { s.1.frame(width: max(2, geo.size.width * CGFloat(s.0) / CGFloat(total))) }
                }
            }
        }
        .frame(height: 12).clipShape(Capsule())
    }

    private func stat(_ v: String, _ l: String, _ c: Color) -> some View {
        VStack(spacing: 3) {
            Text(v).font(Theme.Font.num(20)).foregroundStyle(c).monospacedDigit()
            Text(l.uppercased()).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
        }.frame(maxWidth: .infinity)
    }

    private func hm(_ min: Int) -> String { "\(min / 60)h \(min % 60)m" }
    private func trim(_ v: Double) -> String { v.truncatingRemainder(dividingBy: 1) == 0 ? "\(Int(v))" : String(format: "%.1f", v) }
}

// MARK: - Cycle segment (women) — Flo-style pregnancy chance front-and-center

private struct CycleSection: View {
    @EnvironmentObject var model: AppModel
    @State private var showLogPeriod = false
    @State private var periodDate = Date()
    @State private var todayFlow: String?
    @State private var todaySymptoms: Set<String> = []
    @State private var saving = false
    @State private var showCalendar = false

    private let chipCols = [GridItem(.adaptive(minimum: 96), spacing: 8)]

    var body: some View {
        VStack(spacing: Theme.Space.m) {
            if let c = model.cycle?.cycle, c.cycle_day != nil {
                Picker("", selection: $showCalendar) {
                    Text("Overview").tag(false); Text("Calendar").tag(true)
                }.pickerStyle(.segmented)

                if showCalendar {
                    CycleCalendarView()
                    logPeriodButton
                } else {
                    overview(c)
                }
                disclaimer
            } else {
                // Empty: the period log IS the primary action (like a dedicated period app).
                GlassCard(padding: Theme.Space.l) {
                    VStack(spacing: Theme.Space.m) {
                        Image(systemName: "drop.fill").font(.system(size: 30)).foregroundStyle(Theme.Palette.pink)
                        Text("Log your period to begin").font(Theme.Font.title).foregroundStyle(Theme.Palette.text)
                        Text("Set the first day of your last period and I'll map your phases, predict your next one, and show your daily pregnancy chance.")
                            .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim).multilineTextAlignment(.center)
                        logPeriodButton
                    }.frame(maxWidth: .infinity)
                }
                disclaimer
            }
        }
        .task { await model.loadCycle() }
        .sheet(isPresented: $showLogPeriod) { logPeriodSheet }
    }

    @ViewBuilder private func overview(_ c: CycleResponse.Cycle) -> some View {
        let (label, color) = chance(c.conception?.likelihood)
        // Front-and-center: chance of pregnancy today.
        GlassCard(padding: Theme.Space.l) {
            VStack(spacing: Theme.Space.s) {
                Text("CHANCE OF PREGNANCY TODAY").font(Theme.Font.micro).tracking(0.8).foregroundStyle(Theme.Palette.textDim)
                Text(label).font(Theme.Font.num(40)).foregroundStyle(color)
                if let note = c.conception?.note, !note.isEmpty {
                    Text(note).font(Theme.Font.body).foregroundStyle(Theme.Palette.textDim).multilineTextAlignment(.center)
                }
            }.frame(maxWidth: .infinity)
        }
        GlassCard {
            VStack(alignment: .leading, spacing: Theme.Space.s) {
                SectionHeader(title: c.phase_label ?? "Cycle", trailing: c.cycle_day.map { "Day \($0)" })
                if let blurb = c.phase_blurb, !blurb.isEmpty {
                    Text(blurb).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                }
                HStack(spacing: Theme.Space.l) {
                    if let np = c.next_period?.in_days { stat(daysLabel(np), c.late == true ? "Late" : "Next period", Theme.Palette.pink) }
                    if let ov = c.ovulation?.in_days { stat(daysLabel(ov), "Ovulation", Theme.Palette.violet) }
                    if let f = c.fertile_window, f.active == true { stat("Now", "Fertile", Theme.Palette.cyan) }
                }
            }
        }
        logPeriodButton
        todayCard
    }

    private var logPeriodButton: some View {
        Button { Haptic.tap(); periodDate = Date(); showLogPeriod = true } label: {
            Label("Log period", systemImage: "drop.fill").font(Theme.Font.body.weight(.semibold))
                .frame(maxWidth: .infinity).padding(.vertical, 13)
                .background(Theme.Palette.pink.opacity(0.16), in: RoundedRectangle(cornerRadius: Theme.Radius.chip))
                .overlay(RoundedRectangle(cornerRadius: Theme.Radius.chip).strokeBorder(Theme.Palette.pink.opacity(0.5)))
                .foregroundStyle(Theme.Palette.pink)
        }
    }

    private var logPeriodSheet: some View {
        NavigationStack {
            ZStack {
                Theme.Palette.bg.ignoresSafeArea()
                VStack(spacing: Theme.Space.l) {
                    Text("When did your period start?").font(Theme.Font.title).foregroundStyle(Theme.Palette.text)
                    DatePicker("", selection: $periodDate, in: ...Date(), displayedComponents: .date)
                        .datePickerStyle(.graphical).tint(Theme.Palette.pink).padding(.horizontal, Theme.Space.m)
                    Button {
                        Haptic.success()
                        Task { await model.logPeriod(periodDate); showLogPeriod = false }
                    } label: {
                        Text("Log period start").font(Theme.Font.body.weight(.bold))
                            .frame(maxWidth: .infinity).padding(.vertical, 14)
                            .background(Theme.Grad.brand, in: RoundedRectangle(cornerRadius: Theme.Radius.chip)).foregroundStyle(.white)
                    }.padding(.horizontal, Theme.Space.m)
                    Spacer()
                }.padding(.top, Theme.Space.l)
            }
            .navigationTitle("Log period").navigationBarTitleDisplayMode(.inline)
            .toolbar { ToolbarItem(placement: .cancellationAction) { Button("Cancel") { showLogPeriod = false } } }
            .toolbarColorScheme(.dark, for: .navigationBar)
        }
    }

    private var todayCard: some View {
        GlassCard {
            VStack(alignment: .leading, spacing: Theme.Space.m) {
                SectionHeader(title: "Log today")
                if let flows = model.cycle?.flows {
                    Text("FLOW").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                    Picker("", selection: $todayFlow) {
                        Text("—").tag(String?.none)
                        ForEach(flows, id: \.self) { Text($0.capitalized).tag(String?.some($0)) }
                    }.pickerStyle(.segmented)
                }
                if let syms = model.cycle?.symptoms {
                    Text("SYMPTOMS").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                    LazyVGrid(columns: chipCols, spacing: 8) {
                        ForEach(syms, id: \.self) { s in
                            let on = todaySymptoms.contains(s)
                            Button { Haptic.tap(); if on { todaySymptoms.remove(s) } else { todaySymptoms.insert(s) } } label: {
                                Text(s.replacingOccurrences(of: "_", with: " ").capitalized)
                                    .font(Theme.Font.micro).foregroundStyle(on ? .white : Theme.Palette.textDim)
                                    .frame(maxWidth: .infinity).padding(.vertical, 8)
                                    .background(on ? Theme.Palette.violet : Theme.Palette.bg2, in: Capsule())
                                    .overlay(Capsule().strokeBorder(Theme.Palette.cardStroke))
                            }.buttonStyle(.plain)
                        }
                    }
                }
                Button {
                    Haptic.success(); saving = true
                    Task { await model.logCycleDay(flow: todayFlow, symptoms: Array(todaySymptoms)); saving = false }
                } label: {
                    Text(saving ? "Saving…" : "Save today").font(Theme.Font.body.weight(.semibold))
                        .frame(maxWidth: .infinity).padding(.vertical, 12)
                        .background(Theme.Palette.card, in: RoundedRectangle(cornerRadius: Theme.Radius.chip))
                        .overlay(RoundedRectangle(cornerRadius: Theme.Radius.chip).strokeBorder(Theme.Palette.cardStroke))
                        .foregroundStyle(Theme.Palette.text)
                }.disabled(saving || (todayFlow == nil && todaySymptoms.isEmpty))
            }
        }
    }

    private var disclaimer: some View {
        Text("Estimates for awareness — not a contraceptive method or medical advice.")
            .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint).multilineTextAlignment(.center)
            .frame(maxWidth: .infinity)
    }

    private func chance(_ s: String?) -> (String, Color) {
        let l = (s ?? "low").lowercased()
        if l.contains("high") { return ("HIGH", Theme.Palette.pink) }
        if l.contains("med") || l.contains("mod") { return ("MEDIUM", Theme.Palette.amber) }
        return ("LOW", Theme.Palette.mint)
    }
    private func daysLabel(_ d: Int) -> String { d == 0 ? "Today" : (d < 0 ? "\(-d)d ago" : "\(d)d") }
    private func stat(_ v: String, _ l: String, _ c: Color) -> some View {
        VStack(spacing: 3) {
            Text(v).font(Theme.Font.num(20)).foregroundStyle(c).monospacedDigit()
            Text(l.uppercased()).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
        }.frame(maxWidth: .infinity)
    }
}

// MARK: - Cycle calendar (plan ahead)

/// A projected month calendar — each day colored by phase (period / fertile / ovulation / follicular /
/// luteal), so you can see the whole cycle and plan ahead. Read-only; logging stays on the overview.
private struct CycleCalendarView: View {
    @EnvironmentObject var model: AppModel
    @State private var month: Date = Calendar.current.date(from: Calendar.current.dateComponents([.year, .month], from: Date())) ?? Date()
    @State private var days: [String: CycleCalendarResponse.Day] = [:]

    private let cal = Calendar.current
    private let cols = Array(repeating: GridItem(.flexible(), spacing: 4), count: 7)
    private let key: DateFormatter = { let f = DateFormatter(); f.dateFormat = "yyyy-MM-dd"; return f }()
    private let monthFmt: DateFormatter = { let f = DateFormatter(); f.dateFormat = "MMMM yyyy"; return f }()

    var body: some View {
        VStack(spacing: Theme.Space.m) {
            GlassCard {
                VStack(spacing: Theme.Space.s) {
                    HStack {
                        Button { shift(-1) } label: { Image(systemName: "chevron.left") }.foregroundStyle(Theme.Palette.textDim)
                        Spacer()
                        Text(monthFmt.string(from: month)).font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.text)
                        Spacer()
                        Button { shift(1) } label: { Image(systemName: "chevron.right") }.foregroundStyle(Theme.Palette.textDim)
                    }
                    HStack(spacing: 4) {
                        ForEach(Array(["S", "M", "T", "W", "T", "F", "S"].enumerated()), id: \.offset) { _, d in
                            Text(d).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint).frame(maxWidth: .infinity)
                        }
                    }
                    LazyVGrid(columns: cols, spacing: 4) {
                        ForEach(gridDates, id: \.self) { cell($0) }
                    }
                }
            }
            GlassCard {
                HStack(spacing: Theme.Space.m) {
                    legend(Theme.Palette.pink, "Period")
                    legend(Theme.Palette.cyan.opacity(0.5), "Fertile")
                    legend(Theme.Palette.violet, "Ovulation")
                    Spacer()
                }
            }
        }
        .task(id: month) { await load() }
    }

    private func cell(_ date: Date) -> some View {
        let day = days[key.string(from: date)]
        let inMonth = cal.isDate(date, equalTo: month, toGranularity: .month)
        let (bg, fg) = style(day)
        return ZStack {
            Circle().fill(bg)
            if day?.ovulation == true { Circle().strokeBorder(Theme.Palette.violet, lineWidth: 2) }
            if cal.isDateInToday(date) { Circle().strokeBorder(Theme.Palette.text, lineWidth: 1.5) }
            Text("\(cal.component(.day, from: date))").font(Theme.Font.micro).foregroundStyle(fg)
        }
        .frame(height: 36).opacity(inMonth ? 1 : 0.3)
    }

    private func style(_ d: CycleCalendarResponse.Day?) -> (Color, Color) {
        guard let d else { return (.clear, Theme.Palette.textDim) }
        if d.period == true { return (Theme.Palette.pink, .white) }
        if d.ovulation == true { return (Theme.Palette.violet.opacity(0.3), .white) }
        if d.fertile == true { return (Theme.Palette.cyan.opacity(0.22), Theme.Palette.text) }
        switch d.phase {
        case "follicular": return (Theme.Palette.mint.opacity(0.13), Theme.Palette.text)
        case "luteal": return (Theme.Palette.amber.opacity(0.13), Theme.Palette.text)
        default: return (.clear, Theme.Palette.textDim)
        }
    }

    private func legend(_ c: Color, _ t: String) -> some View {
        HStack(spacing: 5) { Circle().fill(c).frame(width: 10, height: 10); Text(t).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim) }
    }

    private var gridStart: Date {
        let wd = cal.component(.weekday, from: month)   // 1 = Sunday
        return cal.date(byAdding: .day, value: -(wd - 1), to: month) ?? month
    }
    private var gridDates: [Date] { (0..<42).compactMap { cal.date(byAdding: .day, value: $0, to: gridStart) } }
    private func shift(_ n: Int) { if let m = cal.date(byAdding: .month, value: n, to: month) { month = m } }
    private func load() async {
        let list = await model.cycleCalendar(from: gridStart, days: 42)
        days = Dictionary(list.map { ($0.date, $0) }, uniquingKeysWith: { a, _ in a })
    }
}
