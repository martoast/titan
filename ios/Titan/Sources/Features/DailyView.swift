import SwiftUI

// The pillar DETAIL screens. These deep-dives used to live behind the separate "Daily" tab and its
// segmented switcher; now each is PUSHED from its card on Today, so every pillar has exactly one home
// and there's no second "today" tab. The section views are reused verbatim — this just gives each the
// standard pushed-screen chrome (scroll + glow + title + back button).

struct SleepScreen: View {
    var body: some View { SleepSection().titanDetail("Sleep", glow: Theme.Palette.indigo) }
}

struct FuelScreen: View {
    @EnvironmentObject var model: AppModel
    @State private var showTargets = false
    var body: some View {
        VStack(spacing: Theme.Space.m) { FuelSection(); StackSection() }
            .titanDetail("Fuel", glow: Theme.Palette.amber)
            .toolbar {
                ToolbarItem(placement: .topBarTrailing) {
                    Button { Haptic.tap(); showTargets = true } label: { Image(systemName: "slider.horizontal.3") }
                        .tint(Theme.Palette.textDim)
                }
            }
            .sheet(item: $model.scanResult) { ScanResultSheet(result: $0) }
            .sheet(isPresented: $showTargets) { TargetsSheet() }
    }
}

struct TrainScreen: View {
    var body: some View { TrainSection().titanDetail("Training", glow: Theme.Palette.cyan) }
}

struct HeartScreen: View {
    var body: some View { HrSection().titanDetail("Heart", glow: Theme.Palette.pink) }
}

struct CycleScreen: View {
    @EnvironmentObject var model: AppModel
    var body: some View {
        CycleSection().titanDetail("Cycle", glow: Theme.Palette.pink)
            .task { await model.loadCycle() }
    }
}

// MARK: - Sleep segment

private struct SleepSection: View {
    @EnvironmentObject var model: AppModel

    var body: some View {
        VStack(spacing: Theme.Space.m) {
            let night = model.sleepDetail?.nights.first
            let a = model.sleepDetail?.assess

            if model.sleepPhase == .loading && model.sleepDetail == nil {
                SkeletonCard()
            } else if model.sleepPhase == .failed && model.sleepDetail == nil {
                SyncErrorRow(message: "Couldn't load sleep") { await model.loadSleepDetail() }
            } else {
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
        }
        .animation(Theme.Motion.snappy, value: model.sleepPhase)
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

// MARK: - Cycle segment (women) — Flo-style: week strip, big prediction hero, pregnancy chance below

private struct CycleSection: View {
    @EnvironmentObject var model: AppModel
    @State private var showLogPeriod = false
    @State private var showSymptoms = false
    @State private var showCalendar = false
    @State private var periodDate = Date()
    @State private var todayFlow: String?
    @State private var todaySymptoms: Set<String> = []
    @State private var saving = false
    @State private var week: [String: CycleCalendarResponse.Day] = [:]

    private let cal = Calendar.current
    private let key: DateFormatter = { let f = DateFormatter(); f.dateFormat = "yyyy-MM-dd"; return f }()
    private let dowFmt: DateFormatter = { let f = DateFormatter(); f.dateFormat = "EEEEE"; return f }()
    private let titleFmt: DateFormatter = { let f = DateFormatter(); f.dateFormat = "MMMM d"; return f }()
    private let chipCols = [GridItem(.adaptive(minimum: 96), spacing: 8)]

    var body: some View {
        VStack(spacing: Theme.Space.l) {
            if model.cyclePhase == .loading && model.cycle == nil {
                SkeletonCard()
            } else if model.cyclePhase == .failed && model.cycle == nil {
                SyncErrorRow(message: "Couldn't load cycle") { await model.loadCycle() }
            } else if let c = model.cycle?.cycle, c.cycle_day != nil {
                weekStrip
                hero(c)              // the big prediction — NOT the pregnancy chance
                chanceLine(c)        // pregnancy chance, secondary, below the hero
                actionButtons
                phaseCard(c)
                disclaimer
            } else {
                emptyState
            }
        }
        .animation(Theme.Motion.snappy, value: model.cyclePhase)
        .task { await model.loadCycle() }
        .task(id: model.cycle?.cycle?.cycle_day ?? -1) { await loadWeek() }
        .sheet(isPresented: $showLogPeriod) { logPeriodSheet }
        .sheet(isPresented: $showSymptoms) { symptomsSheet }
        .sheet(isPresented: $showCalendar) { calendarSheet }
    }

    // MARK: week strip
    private var weekDates: [Date] {
        let wd = cal.component(.weekday, from: Date())   // 1 = Sunday
        let start = cal.date(byAdding: .day, value: -(wd - 1), to: cal.startOfDay(for: Date())) ?? Date()
        return (0..<7).compactMap { cal.date(byAdding: .day, value: $0, to: start) }
    }
    private var weekStrip: some View {
        VStack(spacing: Theme.Space.s) {
            HStack {
                Text(titleFmt.string(from: Date())).font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.text)
                Spacer()
                Button { Haptic.tap(); showCalendar = true } label: {
                    Image(systemName: "calendar").font(.body).foregroundStyle(Theme.Palette.pink)
                }
            }
            HStack(spacing: 0) { ForEach(weekDates, id: \.self) { weekCell($0) } }
        }
    }
    private func weekCell(_ date: Date) -> some View {
        let day = week[key.string(from: date)]
        let today = cal.isDateInToday(date)
        let isPeriod = day?.period == true
        let isOv = day?.ovulation == true
        let isFertile = day?.fertile == true
        return VStack(spacing: 5) {
            Text(dowFmt.string(from: date).uppercased()).font(Theme.Font.micro)
                .foregroundStyle(today ? Theme.Palette.text : Theme.Palette.textFaint)
            ZStack {
                if today { Circle().fill(Theme.Palette.pink) }
                else if isPeriod { Circle().fill(Theme.Palette.pink.opacity(0.8)) }
                else if isOv { Circle().strokeBorder(Theme.Palette.violet, style: StrokeStyle(lineWidth: 1.5, dash: [2, 2])) }
                else if isFertile { Circle().fill(Theme.Palette.cyan.opacity(0.18)) }
                Text("\(cal.component(.day, from: date))").font(Theme.Font.body)
                    .foregroundStyle((today || isPeriod) ? .white : Theme.Palette.text)
            }.frame(width: 36, height: 36)
        }.frame(maxWidth: .infinity)
    }

    // MARK: hero — the big imminent-event prediction
    private func hero(_ c: CycleResponse.Cycle) -> some View {
        let (top, big) = heroText(c)
        return VStack(spacing: 2) {
            Text(top).font(Theme.Font.body).foregroundStyle(Theme.Palette.textDim)
            Text(big).font(Theme.Font.num(46)).foregroundStyle(Theme.Palette.text).multilineTextAlignment(.center)
        }.frame(maxWidth: .infinity).padding(.top, Theme.Space.s)
    }
    private func heroText(_ c: CycleResponse.Cycle) -> (String, String) {
        let day = c.cycle_day ?? 1
        let periodLen = c.period_length ?? 5
        if c.late == true, let n = c.next_period?.in_days { return ("Period", "\(-n)d late") }
        if day <= periodLen { return ("Period", "Day \(day)") }
        if let ov = c.ovulation?.in_days {
            if ov == 0 { return ("Ovulation", "Today") }
            if ov > 0 && ov <= 6 { return ("Ovulation in", "\(ov) day\(ov == 1 ? "" : "s")") }
        }
        if let np = c.next_period?.in_days, np >= 0 { return ("Period in", "\(np) day\(np == 1 ? "" : "s")") }
        return (c.phase_label ?? "Cycle", "Day \(day)")
    }

    // MARK: pregnancy chance — secondary, below the hero
    private func chanceLine(_ c: CycleResponse.Cycle) -> some View {
        let (label, color) = chance(c.conception?.likelihood)
        return VStack(spacing: 2) {
            Text("Chance of pregnancy today").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
            Text(label).font(Theme.Font.body.weight(.bold)).foregroundStyle(color)
        }.frame(maxWidth: .infinity)
    }

    // MARK: circular actions (Flo-style)
    private var actionButtons: some View {
        HStack(spacing: 0) {
            circleButton("drop.fill", "Log period", filled: true) { Haptic.tap(); periodDate = Date(); showLogPeriod = true }
            circleButton("plus", "Symptoms", filled: false) { Haptic.tap(); showSymptoms = true }
            circleButton("calendar", "Calendar", filled: false) { Haptic.tap(); showCalendar = true }
        }
    }
    private func circleButton(_ icon: String, _ label: String, filled: Bool, _ action: @escaping () -> Void) -> some View {
        Button(action: action) {
            VStack(spacing: 7) {
                ZStack {
                    Circle().fill(filled ? Theme.Palette.pink : Theme.Palette.card).frame(width: 58, height: 58)
                        .overlay(Circle().strokeBorder(Theme.Palette.cardStroke, lineWidth: filled ? 0 : 1))
                    Image(systemName: icon).font(.system(size: 21, weight: .semibold)).foregroundStyle(filled ? .white : Theme.Palette.text)
                }
                Text(label).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
            }.frame(maxWidth: .infinity)
        }.buttonStyle(.plain)
    }

    // MARK: phase details
    private func phaseCard(_ c: CycleResponse.Cycle) -> some View {
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
    }

    private var emptyState: some View {
        VStack(spacing: Theme.Space.m) {
            GlassCard(padding: Theme.Space.l) {
                VStack(spacing: Theme.Space.m) {
                    Image(systemName: "drop.fill").font(.system(size: 30)).foregroundStyle(Theme.Palette.pink)
                    Text("Log your period to begin").font(Theme.Font.title).foregroundStyle(Theme.Palette.text)
                    Text("Set the first day of your last period and I'll map your phases, predict your next one, and show your daily pregnancy chance.")
                        .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim).multilineTextAlignment(.center)
                    Button { Haptic.tap(); periodDate = Date(); showLogPeriod = true } label: {
                        Label("Log period", systemImage: "drop.fill").font(Theme.Font.body.weight(.semibold))
                            .frame(maxWidth: .infinity).padding(.vertical, 13)
                            .background(Theme.Grad.brand, in: RoundedRectangle(cornerRadius: Theme.Radius.chip)).foregroundStyle(.white)
                    }
                }.frame(maxWidth: .infinity)
            }
            disclaimer
        }
    }

    // MARK: sheets
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

    private var symptomsSheet: some View {
        NavigationStack {
            ZStack {
                Theme.Palette.bg.ignoresSafeArea()
                ScrollView {
                    VStack(alignment: .leading, spacing: Theme.Space.m) {
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
                    }.padding(Theme.Space.m)
                }
            }
            .navigationTitle("Log today").navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) { Button("Cancel") { showSymptoms = false } }
                ToolbarItem(placement: .confirmationAction) {
                    Button(saving ? "Saving…" : "Save") {
                        Haptic.success(); saving = true
                        Task { await model.logCycleDay(flow: todayFlow, symptoms: Array(todaySymptoms)); saving = false; showSymptoms = false }
                    }.disabled(saving || (todayFlow == nil && todaySymptoms.isEmpty))
                }
            }
            .toolbarColorScheme(.dark, for: .navigationBar)
        }
    }

    private var calendarSheet: some View {
        NavigationStack {
            ZStack {
                Theme.Palette.bg.ignoresSafeArea()
                ScrollView { CycleCalendarView().padding(Theme.Space.m) }
            }
            .navigationTitle("Calendar").navigationBarTitleDisplayMode(.inline)
            .toolbar { ToolbarItem(placement: .cancellationAction) { Button { showCalendar = false } label: { Image(systemName: "xmark") } } }
            .toolbarColorScheme(.dark, for: .navigationBar)
        }
    }

    private var disclaimer: some View {
        Text("Estimates for awareness — not a contraceptive method or medical advice.")
            .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint).multilineTextAlignment(.center)
            .frame(maxWidth: .infinity)
    }

    // MARK: helpers
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
    private func loadWeek() async {
        let start = weekDates.first ?? Date()
        let list = await model.cycleCalendar(from: start, days: 7)
        week = Dictionary(list.map { ($0.date, $0) }, uniquingKeysWith: { a, _ in a })
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
        // Reload on month change AND whenever the cycle data changes (e.g. you just logged a period).
        .task(id: "\(month.timeIntervalSinceReferenceDate)-\(model.cycle?.cycle?.cycle_day ?? -1)") { await load() }
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

// MARK: - Heart segment

/// The 24/7 all-day HR graph — the band's continuous + duty-cycled heart rate, synced from the cloud.
/// Resting HR (the day's ~5th percentile) is the headline: it's your floor, and it trends down as you
/// get fitter.
private struct HrSection: View {
    @EnvironmentObject var model: AppModel

    var body: some View {
        VStack(spacing: Theme.Space.m) {
            // When the band is connected, show the same live feed as the Devices page — the beat-by-beat
            // BPM and the live PPG trace — above the all-day highs/lows graph.
            if model.bandConnected { liveCard }

            let hr = model.hrDay
            if model.hrPhase == .loading && model.hrDay == nil {
                SkeletonCard()
            } else if model.hrPhase == .failed && model.hrDay == nil {
                SyncErrorRow(message: "Couldn't load heart rate") { await model.loadHr() }
            } else {
            GlassCard(padding: Theme.Space.l) {
                VStack(spacing: Theme.Space.m) {
                    if let hr, hr.count > 0 {
                        HStack(alignment: .firstTextBaseline, spacing: 6) {
                            Text("\(hr.resting_hr ?? hr.min ?? 0)").font(Theme.Font.num(46)).foregroundStyle(.white)
                            Text("resting bpm").font(Theme.Font.body).foregroundStyle(Theme.Palette.pink)
                            Spacer()
                            Image(systemName: "heart.fill").foregroundStyle(Theme.Palette.pink).font(.title3)
                                .symbolEffect(.pulse, options: .repeating)
                        }
                        HrGraph(points: hr.points, color: Theme.Palette.pink).frame(height: 130)
                        HStack(spacing: Theme.Space.l) {
                            stat("\(hr.resting_hr ?? 0)", "Resting", Theme.Palette.mint)
                            stat("\(hr.avg ?? 0)", "Avg", Theme.Palette.cyan)
                            stat("\(hr.min ?? 0)", "Min", Theme.Palette.indigo)
                            stat("\(hr.max ?? 0)", "Max", Theme.Palette.pink)
                        }
                    } else {
                        VStack(spacing: 6) {
                            Image(systemName: "heart").font(.system(size: 30)).foregroundStyle(Theme.Palette.textFaint)
                            Text("No heart rate yet today").font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.text)
                            Text("Wear the band — it tracks HR 24/7 and syncs the whole day when your phone's near.")
                                .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim).multilineTextAlignment(.center)
                        }.frame(maxWidth: .infinity).padding(.vertical, Theme.Space.s)
                    }
                }
            }
            Text("Your all-day heart rate. Resting HR is your daily floor — it trends down as you get fitter.")
                .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
                .frame(maxWidth: .infinity, alignment: .leading)
            }
        }
        .animation(Theme.Motion.snappy, value: model.hrPhase)
        .task { await model.loadHr() }
    }

    /// The live band feed (mirrors the Devices "Live signal" card), in the heart context.
    private var liveCard: some View {
        GlassCard(padding: Theme.Space.l) {
            VStack(alignment: .leading, spacing: Theme.Space.m) {
                HStack(spacing: 8) {
                    PulseDot(on: true)
                    Text("Live now").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                    Spacer()
                    if model.liveHz > 0 {
                        Text("\(model.liveHz) Hz").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                    }
                }
                HStack(alignment: .firstTextBaseline, spacing: 6) {
                    Text(model.liveBpm.map { "\($0)" } ?? "—")
                        .font(Theme.Font.num(46)).foregroundStyle(.white)
                        .contentTransition(.numericText())
                    Text("bpm").font(Theme.Font.body).foregroundStyle(Theme.Palette.pink)
                    Spacer()
                    Image(systemName: "heart.fill").foregroundStyle(Theme.Palette.pink).font(.title3)
                        .symbolEffect(.pulse, options: .repeating)
                }
                WaveformView(samples: model.waveform, color: Theme.Palette.pink)
                    .frame(height: 56)
            }
        }
        .animation(Theme.Motion.snappy, value: model.liveBpm)
    }

    private func stat(_ v: String, _ l: String, _ c: Color) -> some View {
        VStack(spacing: 3) {
            Text(v).font(Theme.Font.num(20)).foregroundStyle(c).monospacedDigit()
            Text(l.uppercased()).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
        }.frame(maxWidth: .infinity)
    }
}

/// A lightweight line+area HR chart over the day's points (x = time of day, y = bpm, auto-scaled).
private struct HrGraph: View {
    let points: [HrResponse.Point]
    let color: Color

    var body: some View {
        GeometryReader { geo in
            let w = geo.size.width, h = geo.size.height
            if points.count >= 2 {
                let bpms = points.map { Double($0.bpm) }
                let lo = max(35, (bpms.min() ?? 50) - 5)
                let hi = (bpms.max() ?? 120) + 5
                let span = max(1, hi - lo)
                let t0 = Double(points.first!.t), t1 = Double(points.last!.t)
                let tSpan = max(1, t1 - t0)
                let pt: (HrResponse.Point) -> CGPoint = { p in
                    CGPoint(x: CGFloat((Double(p.t) - t0) / tSpan) * w,
                            y: h - CGFloat((Double(p.bpm) - lo) / span) * h)
                }
                ZStack {
                    Path { path in
                        path.move(to: CGPoint(x: 0, y: h))
                        for p in points { path.addLine(to: pt(p)) }
                        path.addLine(to: CGPoint(x: w, y: h))
                        path.closeSubpath()
                    }.fill(LinearGradient(colors: [color.opacity(0.28), color.opacity(0.02)],
                                          startPoint: .top, endPoint: .bottom))
                    Path { path in
                        path.move(to: pt(points[0]))
                        for p in points.dropFirst() { path.addLine(to: pt(p)) }
                    }.stroke(color, style: StrokeStyle(lineWidth: 2, lineJoin: .round))
                }
            } else {
                Text("Not enough data yet").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
                    .frame(width: w, height: h)
            }
        }
    }
}
