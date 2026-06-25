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

    var body: some View {
        VStack(spacing: Theme.Space.m) {
            if let c = model.cycle?.cycle {
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

                Text("Estimates for awareness — not a contraceptive method or medical advice.")
                    .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint).multilineTextAlignment(.center)
                    .frame(maxWidth: .infinity)
            } else {
                GlassCard {
                    VStack(alignment: .leading, spacing: 4) {
                        SectionHeader(title: "Cycle")
                        Text("Log the first day of your last period (tell the coach, or in You › Edit profile) and I'll map your phases, predict your next one, and show your daily pregnancy chance.")
                            .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                    }
                }
            }
        }
        .task { await model.loadCycle() }
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
