import SwiftUI

// COACH v2 · Phase 2 — real native widgets for the ```titan-card blocks the coach already emits.
// The registry that maps a card `type` to one of these lives in ONE place: TitanCardView in
// CoachView.swift. Every card shares the same chrome (`.coachCard()`) so they read as one family.
// A card reads only the fields it needs and degrades gracefully when a field is missing (the coach
// omits soft numbers), so a thin payload renders a smaller-but-correct card, never a broken one.

// MARK: - Shared chrome + helpers

/// The card shell every coach widget wears — matches the original macros/generic cards.
private struct CoachCardChrome: ViewModifier {
    func body(content: Content) -> some View {
        content
            .padding(14)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(Theme.Palette.card, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 18).strokeBorder(Theme.Palette.cardStroke))
    }
}

extension View {
    func coachCard() -> some View { modifier(CoachCardChrome()) }
}

// MARK: - Interactive card actions (COACH CARDS v2 · Thrust A2)

/// A tap action a card can carry. The card is a shortcut — every action has a typed-text equivalent.
/// `prompt` sends a canned coach turn; `tool` calls a coach tool directly (a write, with confirm/undo);
/// `intent` is a client-side move (launch breathing, open the night, jump to a tab).
enum CardAction: Equatable {
    case prompt(String)
    case tool(name: String, args: [String: Any], confirm: String)
    case intent(String, [String: Any])

    static func == (l: CardAction, r: CardAction) -> Bool { l.label == r.label }
    var label: String {
        switch self {
        case .prompt(let p): return p
        case .tool(let n, _, _): return n
        case .intent(let i, _): return i
        }
    }

    /// Decode the card JSON's `actions[]` into typed actions (ignores malformed entries).
    static func list(from json: [String: Any]) -> [(label: String, action: CardAction)] {
        guard let raw = json["actions"] as? [[String: Any]] else { return [] }
        return raw.compactMap { a in
            guard let label = a["label"] as? String else { return nil }
            if let prompt = a["prompt"] as? String { return (label, .prompt(prompt)) }
            if let tool = a["tool"] as? String {
                return (label, .tool(name: tool, args: a["args"] as? [String: Any] ?? [:], confirm: (a["confirm"] as? String) ?? ""))
            }
            if let intent = a["intent"] as? String { return (label, .intent(intent, a["args"] as? [String: Any] ?? [:])) }
            return nil
        }
    }
}

/// The environment hook every card's action row calls — CoachView provides it (send / tool / navigate).
struct CardActionKey: EnvironmentKey {
    static let defaultValue: (CardAction) -> Void = { _ in }
}
extension EnvironmentValues {
    var cardAction: (CardAction) -> Void {
        get { self[CardActionKey.self] }
        set { self[CardActionKey.self] = newValue }
    }
}

/// The row of tap targets rendered under any card that carries `actions[]`. One shared look + haptic.
struct CardActionsRow: View {
    let json: [String: Any]
    @Environment(\.cardAction) private var run
    var body: some View {
        let actions = CardAction.list(from: json)
        if !actions.isEmpty {
            HStack(spacing: 8) {
                ForEach(Array(actions.enumerated()), id: \.offset) { _, a in
                    Button { Haptic.tap(); run(a.action) } label: {
                        Text(verbatim: a.label).font(Theme.Font.micro.weight(.semibold))
                            .foregroundStyle(Theme.Palette.text)
                            .padding(.horizontal, 12).padding(.vertical, 8)
                            .background(Theme.Palette.card, in: Capsule())
                            .overlay(Capsule().strokeBorder(Theme.Palette.cardStroke))
                    }.buttonStyle(.plain)
                }
                Spacer(minLength: 0)
            }
            .padding(.top, 8)
        }
    }
}

/// A thin progress capsule (value toward target). Shared by macros + the new widgets.
struct CardBar: View {
    let value: Double
    let target: Double
    let color: Color
    var body: some View {
        let pct = target > 0 ? min(1, value / target) : 0
        Capsule().fill(Color.white.opacity(0.08)).frame(height: 6)
            .overlay(alignment: .leading) {
                GeometryReader { geo in
                    Capsule().fill(color).frame(width: geo.size.width * pct)
                }
            }
            .frame(height: 6)
    }
}

/// Biomarker / vitals flag → color. `optimal`/`normal` read calm; anything else is a caution.
func coachFlagColor(_ flag: String?) -> Color {
    switch (flag ?? "").lowercased() {
    case "optimal", "great", "good": return Theme.Palette.mint
    case "normal", "ok", "": return Theme.Palette.textDim
    case "low", "borderline", "elevated", "watch": return Theme.Palette.amber
    case "high", "bad", "critical", "flag": return Theme.Palette.pink
    default: return Theme.Palette.amber
    }
}

private func cardTitle(_ text: LocalizedStringKey) -> some View {
    Text(text).font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.text)
}

/// Same title chrome, but for dynamic (coach-supplied) strings that must render verbatim.
private func cardTitle(verbatim text: String) -> some View {
    Text(text).font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.text)
}

/// Localized display name for a sleep-stage key (the ribbon legend).
private func sleepStageName(_ key: String) -> Text {
    switch key {
    case "deep": return Text("Deep")
    case "rem": return Text("REM")
    case "light": return Text("Light")
    case "awake": return Text("Awake")
    default: return Text(verbatim: key.capitalized)
    }
}

private func cardCaption(_ text: String?) -> some View {
    Group {
        if let text, !text.isEmpty {
            Text(text).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
        }
    }
}

// MARK: - Readiness (recovery ring)

struct ReadinessCard: View {
    let json: [String: Any]
    var body: some View {
        let score = Int(jsonNum(json["score"]) ?? 0)
        let color = Theme.Palette.recovery(score)
        HStack(spacing: 14) {
            ZStack {
                Circle().stroke(Color.white.opacity(0.08), lineWidth: 7)
                Circle().trim(from: 0, to: max(0.001, min(1, Double(score) / 100)))
                    .stroke(Theme.Grad.ring(color), style: StrokeStyle(lineWidth: 7, lineCap: .round))
                    .rotationEffect(.degrees(-90))
                Text("\(score)").font(Theme.Font.num(22)).foregroundStyle(Theme.Palette.text)
            }
            .frame(width: 66, height: 66)
            VStack(alignment: .leading, spacing: 3) {
                if let label = json["label"] as? String, !label.isEmpty {
                    Text(label).font(Theme.Font.body.weight(.semibold)).foregroundStyle(color)
                }
                cardCaption(json["caption"] as? String)
            }
            Spacer(minLength: 0)
        }
        .coachCard()
    }
}

// MARK: - Strain (0–21 gauge with recovery-aware target zone)

struct StrainCard: View {
    let json: [String: Any]
    private let maxStrain = 21.0
    var body: some View {
        let value = jsonNum(json["value"]) ?? 0
        let low = jsonNum(json["target_low"])
        let high = jsonNum(json["target_high"])
        VStack(alignment: .leading, spacing: 10) {
            HStack(alignment: .firstTextBaseline) {
                cardTitle("Strain")
                Spacer()
                Text(String(format: "%.1f", value)).font(Theme.Font.num(20)).foregroundStyle(Theme.Palette.cyan)
                    + Text(" / 21").font(Theme.Font.num(13)).foregroundStyle(Theme.Palette.textDim)
            }
            GeometryReader { geo in
                let w = geo.size.width
                ZStack(alignment: .leading) {
                    Capsule().fill(Color.white.opacity(0.08)).frame(height: 8)
                    // Recovery-aware target zone.
                    if let low, let high, high > low {
                        Capsule().fill(Theme.Palette.mint.opacity(0.30))
                            .frame(width: w * CGFloat((high - low) / maxStrain), height: 8)
                            .offset(x: w * CGFloat(low / maxStrain))
                    }
                    // Achieved strain.
                    Capsule().fill(Theme.Palette.cyan)
                        .frame(width: max(4, w * CGFloat(min(1, value / maxStrain))), height: 8)
                }
            }
            .frame(height: 8)
            if let band = json["band"] as? String, !band.isEmpty {
                Text(band).font(Theme.Font.micro).foregroundStyle(Theme.Palette.text)
            }
            cardCaption(json["advice"] as? String)
        }
        .coachCard()
    }
}

// MARK: - Stress now (0–3, motion-gated, with a breathing CTA)

struct StressCard: View {
    let json: [String: Any]
    @State private var breathe: BreathPattern?
    private let maxStress = 3.0

    private var level: String { (json["level"] as? String) ?? "calm" }
    private var moving: Bool { (json["moving"] as? Bool) ?? false }
    private var color: Color {
        switch level {
        case "high": return Theme.Palette.pink
        case "medium": return Theme.Palette.amber
        case "low": return Theme.Palette.cyan
        default: return Theme.Palette.mint
        }
    }
    private var levelLabel: LocalizedStringKey {
        switch level {
        case "high": return "High stress"
        case "medium": return "Medium stress"
        case "low": return "A little stress"
        default: return "Calm"
        }
    }

    var body: some View {
        let value = jsonNum(json["value"]) ?? 0
        VStack(alignment: .leading, spacing: 10) {
            HStack(alignment: .firstTextBaseline) {
                cardTitle("Stress now")
                Spacer()
                Text(String(format: "%.1f", value)).font(Theme.Font.num(20)).foregroundStyle(color)
                    + Text(" / 3").font(Theme.Font.num(13)).foregroundStyle(Theme.Palette.textDim)
            }
            // 0–3 gauge.
            GeometryReader { geo in
                let w = geo.size.width
                ZStack(alignment: .leading) {
                    Capsule().fill(Color.white.opacity(0.08)).frame(height: 8)
                    Capsule().fill(color)
                        .frame(width: max(4, w * CGFloat(min(1, value / maxStress))), height: 8)
                }
            }
            .frame(height: 8)

            HStack(spacing: 6) {
                Circle().fill(color).frame(width: 7, height: 7)
                Text(moving ? "Moving — that's your workout, not stress" : levelLabel)
                    .font(Theme.Font.micro.weight(.semibold)).foregroundStyle(Theme.Palette.text)
            }
            cardCaption(json["drivers"] as? String)
            if let note = json["note"] as? String, !note.isEmpty {
                Text(note).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
            }

            // The intervention — offered when there's stress to shed.
            if !moving && (level == "medium" || level == "high") {
                Button { Haptic.tap(); breathe = .physiologicalSigh } label: {
                    HStack(spacing: 7) {
                        Image(systemName: "wind")
                        Text("Take a minute to breathe").font(Theme.Font.micro.weight(.semibold))
                    }
                    .foregroundStyle(Theme.Palette.bg)
                    .frame(maxWidth: .infinity).padding(.vertical, 11)
                    .background(color, in: RoundedRectangle(cornerRadius: Theme.Radius.chip))
                }.buttonStyle(.plain)
            }
        }
        .coachCard()
        .fullScreenCover(item: $breathe) { p in BreathingView(pattern: p) }
    }
}

// MARK: - Sleep debt (the ledger — balance + trend + payback plan)

struct SleepDebtCard: View {
    let json: [String: Any]
    private var history: [[String: Any]] { json["history"] as? [[String: Any]] ?? [] }

    private var band: String { (json["band"] as? String) ?? "none" }
    private var color: Color {
        switch band {
        case "heavy": return Theme.Palette.pink
        case "moderate": return Theme.Palette.amber
        case "light": return Theme.Palette.cyan
        default: return Theme.Palette.mint
        }
    }

    var body: some View {
        let balance = jsonNum(json["balance"]) ?? 0
        let paid = jsonNum(json["paid_back"]) ?? 0
        let added = jsonNum(json["added"]) ?? 0
        VStack(alignment: .leading, spacing: 10) {
            HStack(alignment: .firstTextBaseline) {
                cardTitle("Sleep debt")
                Spacer()
                if balance <= 0 {
                    Text("Rested").font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.mint)
                } else {
                    Text(String(format: "%.1fh", balance)).font(Theme.Font.num(22)).foregroundStyle(color)
                }
            }
            // Balance "battery" — fuller = more debt (drains as you catch up). Capped display at 6h.
            GeometryReader { geo in
                let frac = min(1, balance / 6)
                ZStack(alignment: .leading) {
                    Capsule().fill(Color.white.opacity(0.08)).frame(height: 8)
                    Capsule().fill(color).frame(width: max(balance <= 0 ? 0 : 4, geo.size.width * frac), height: 8)
                }
            }.frame(height: 8)

            // Last night's ± and the trend.
            HStack(spacing: 8) {
                if paid > 0 {
                    Label(String(format: "paid back %.1fh", paid), systemImage: "arrow.down").font(Theme.Font.micro).foregroundStyle(Theme.Palette.mint)
                } else if added > 0 {
                    Label(String(format: "added %.1fh", added), systemImage: "arrow.up").font(Theme.Font.micro).foregroundStyle(Theme.Palette.amber)
                }
                Spacer()
                if let trend = json["trend"] as? String, trend != "steady" {
                    Text(trend).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                }
            }
            if history.count > 1 { DebtTrend(history: history, color: color) }
            cardCaption(json["plan"] as? String)
        }
        .coachCard()
    }
}

/// A 14-night debt trend line (higher = more debt).
private struct DebtTrend: View {
    let history: [[String: Any]]
    let color: Color
    var body: some View {
        let vals = history.compactMap { jsonNum($0["balance"]) }
        let maxV = max(1, vals.max() ?? 1)
        GeometryReader { geo in
            let w = geo.size.width, h = geo.size.height, n = max(1, vals.count - 1)
            Path { p in
                for (i, v) in vals.enumerated() {
                    let x = w * CGFloat(i) / CGFloat(n)
                    let y = h * (1 - CGFloat(v / maxV))
                    i == 0 ? p.move(to: .init(x: x, y: y)) : p.addLine(to: .init(x: x, y: y))
                }
            }.stroke(color, style: StrokeStyle(lineWidth: 2, lineCap: .round, lineJoin: .round))
        }
        .frame(height: 28)
    }
}

// MARK: - Night story (the story of your night as a card)

struct NightStoryCard: View {
    let json: [String: Any]
    private var hypnogram: [String] { json["hypnogram"] as? [String] ?? [] }
    var body: some View {
        let low = json["low_confidence"] as? Bool == true
        VStack(alignment: .leading, spacing: 10) {
            HStack(alignment: .firstTextBaseline, spacing: 6) {
                cardTitle("Last night")
                if let h = jsonNum(json["hours"]) {
                    Text(String(format: "%.1fh", h)).font(Theme.Font.num(18)).foregroundStyle(Theme.Palette.text)
                }
                if let p = jsonNum(json["performance"]) { Text("· \(Int(p))%").font(Theme.Font.micro).foregroundStyle(Theme.Palette.indigo) }
                Spacer()
                if low { EstimateChip() }
            }
            if hypnogram.count > 4 {
                SleepTimeline(stages: hypnogram, epochSec: json["epoch_sec"] as? Int,
                              bedtime: json["bedtime"] as? String, interactive: false, mini: true)
            }
            if let text = json["story"] as? String, !text.isEmpty {
                Text(text).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim).fixedSize(horizontal: false, vertical: true)
            }
            if let takeaway = json["takeaway"] as? String, !takeaway.isEmpty {
                HStack(alignment: .top, spacing: 6) {
                    Image(systemName: "lightbulb.fill").font(.caption2).foregroundStyle(Theme.Palette.amber)
                    Text(takeaway).font(Theme.Font.micro.weight(.semibold)).foregroundStyle(Theme.Palette.text).fixedSize(horizontal: false, vertical: true)
                }
            }
        }
        .coachCard()
    }
}

// MARK: - Streaks (training + sleep consistency in one card)

struct StreakCard: View {
    let json: [String: Any]
    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            cardTitle("Your streaks")
            HStack(spacing: Theme.Space.m) {
                streak("figure.run", "Training", Theme.Palette.mint, json["workout_current"], json["workout_longest"])
                streak("moon.stars.fill", "Sleep", Theme.Palette.indigo, json["sleep_current"], json["sleep_longest"])
            }
        }
        .coachCard()
    }

    private func streak(_ icon: String, _ label: LocalizedStringKey, _ color: Color, _ current: Any?, _ longest: Any?) -> some View {
        let cur = Int(jsonNum(current) ?? 0)
        let best = Int(jsonNum(longest) ?? 0)
        return VStack(alignment: .leading, spacing: 3) {
            HStack(spacing: 6) {
                Image(systemName: icon).foregroundStyle(color)
                Text(label).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
            }
            HStack(alignment: .firstTextBaseline, spacing: 3) {
                Text("\(cur)").font(Theme.Font.num(26)).foregroundStyle(Theme.Palette.text)
                Text(cur == 1 ? "day" : "days").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
            }
            if best > 0 { Text("best \(best)").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint) }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(Theme.Space.s)
        .background(Theme.Palette.card, in: RoundedRectangle(cornerRadius: Theme.Radius.chip))
    }
}

// MARK: - Lesson (a teaching moment as a card — Coach v3 educational stance)

struct LessonCard: View {
    let json: [String: Any]
    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            HStack(spacing: 7) {
                Image(systemName: "graduationcap.fill").foregroundStyle(Theme.Palette.cyan)
                Text((json["title"] as? String) ?? "").font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.text)
                    .fixedSize(horizontal: false, vertical: true)
            }
            if let body = json["body"] as? String, !body.isEmpty {
                Text(body).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim).fixedSize(horizontal: false, vertical: true)
            }
            if let analogy = json["analogy"] as? String, !analogy.isEmpty {
                HStack(alignment: .top, spacing: 6) {
                    Image(systemName: "quote.opening").font(.caption2).foregroundStyle(Theme.Palette.textFaint)
                    Text(analogy).font(Theme.Font.micro.italic()).foregroundStyle(Theme.Palette.textDim).fixedSize(horizontal: false, vertical: true)
                }
            }
        }
        .coachCard()
    }
}

// MARK: - Sleep week (the week at a glance, in chat)

struct SleepWeekCard: View {
    let json: [String: Any]
    @Environment(\.cardAction) private var run
    private var days: [[String: Any]] { json["days"] as? [[String: Any]] ?? [] }
    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            HStack(alignment: .firstTextBaseline) {
                cardTitle("Sleep week")
                Spacer()
                if let s = jsonNum(json["week_score"]) { Text("\(Int(s))").font(Theme.Font.num(20)).foregroundStyle(Theme.Palette.indigo) }
            }
            if let label = json["week_label"] as? String {
                HStack(spacing: 6) {
                    Text(label).font(Theme.Font.micro.weight(.semibold)).foregroundStyle(Theme.Palette.text)
                    if let t = json["trend"] as? String, t != "flat" {
                        Image(systemName: t == "up" ? "arrow.up.right" : "arrow.down.right").font(.caption2)
                            .foregroundStyle(t == "up" ? Theme.Palette.mint : Theme.Palette.amber)
                    }
                }
            }
            // The 7-night bar row. Tap a logged night → open that night's hero timeline.
            HStack(alignment: .bottom, spacing: 5) {
                ForEach(Array(days.enumerated()), id: \.offset) { _, d in
                    let score = jsonNum(d["score"]) ?? 0
                    let hit = d["hit"] as? Bool ?? false
                    let low = d["low"] as? Bool ?? false
                    let logged = d["logged"] as? Bool ?? false
                    let date = d["date"] as? String
                    Button {
                        guard logged, let date else { return }
                        Haptic.tap(); run(.intent("open_sleep_night", ["date": date]))
                    } label: {
                        VStack(spacing: 4) {
                            Spacer(minLength: 0)
                            RoundedRectangle(cornerRadius: 3)
                                .fill(!logged ? Color.white.opacity(0.06) : (low ? Theme.Palette.textFaint : (hit ? Theme.Palette.indigo : Theme.Palette.indigo.opacity(0.35))))
                                .frame(height: max(logged ? 5 : 3, 44 * CGFloat(min(1, score / 100))))
                            Text((d["weekday"] as? String)?.prefix(1).uppercased() ?? "").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
                        }.frame(maxWidth: .infinity, minHeight: 56)
                    }
                    .buttonStyle(.plain)
                    .disabled(!logged || date == nil)
                }
            }
            if let streak = jsonNum(json["streak"]), streak > 0 {
                Text("🌙 \(Int(streak))-night streak").font(Theme.Font.micro.weight(.semibold)).foregroundStyle(Theme.Palette.text)
            }
            if let tip = json["tip_headline"] as? String, !tip.isEmpty {
                cardCaption(tip)
            }
        }
        .coachCard()
    }
}

/// The honesty chip (COACH CARDS v2 · A1) — an estimate/low-confidence card reads as an estimate, never a
/// confident number. Same rule as the timeline + debt ledger.
struct EstimateChip: View {
    var body: some View {
        Text("~ estimate").font(Theme.Font.micro.weight(.semibold)).foregroundStyle(Theme.Palette.amber)
            .padding(.horizontal, 8).padding(.vertical, 3)
            .background(Theme.Palette.amber.opacity(0.14), in: Capsule())
    }
}

// MARK: - Sleep plan (tonight's recommended bedtime)

struct SleepPlanCard: View {
    let json: [String: Any]
    var body: some View {
        let bed = (json["bedtime"] as? String) ?? "–"
        let ws = json["window_start"] as? String
        let we = json["window_end"] as? String
        VStack(alignment: .leading, spacing: 10) {
            HStack(alignment: .firstTextBaseline) {
                cardTitle("Tonight's bedtime")
                Spacer()
                Image(systemName: "moon.stars.fill").foregroundStyle(Theme.Palette.indigo)
            }
            HStack(alignment: .firstTextBaseline, spacing: 6) {
                Text(pretty(bed)).font(Theme.Font.num(26)).foregroundStyle(Theme.Palette.text)
                if let ws, let we { Text("\(pretty(ws))–\(pretty(we))").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim) }
            }
            HStack(spacing: 12) {
                if let wake = json["target_wake"] as? String {
                    stat("Up at", pretty(wake))
                }
                if let need = jsonNum(json["need_h"]) {
                    stat("Need", String(format: "%.1fh", need))
                }
            }
            cardCaption(json["reason"] as? String)
        }
        .coachCard()
    }

    private func stat(_ label: LocalizedStringKey, _ value: String) -> some View {
        VStack(alignment: .leading, spacing: 1) {
            Text(label).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
            Text(value).font(Theme.Font.num(15)).foregroundStyle(Theme.Palette.text)
        }
    }

    /// "22:40" → "10:40 PM" in the device locale.
    private func pretty(_ hhmm: String) -> String {
        let f = DateFormatter(); f.dateFormat = "HH:mm"
        guard let d = f.date(from: String(hhmm.prefix(5))) else { return hhmm }
        let o = DateFormatter(); o.timeStyle = .short; o.locale = .current
        return o.string(from: d)
    }
}

// MARK: - Sleep (headline + stage breakdown)

struct SleepCard: View {
    let json: [String: Any]
    private let order: [(String, Color)] = [
        ("deep", Theme.Palette.indigo), ("rem", Theme.Palette.violet),
        ("light", Theme.Palette.cyan), ("awake", Theme.Palette.amber),
    ]
    var body: some View {
        let hours = jsonNum(json["hours"]) ?? 0
        let stages = json["stages"] as? [String: Any] ?? [:]
        let mins = order.map { ($0.0, $0.1, jsonNum(stages[$0.0]) ?? 0) }
        let total = max(1, mins.reduce(0) { $0 + $1.2 })
        VStack(alignment: .leading, spacing: 10) {
            HStack(alignment: .firstTextBaseline) {
                cardTitle("Last night")
                Spacer()
                Text(String(format: "%.1fh", hours)).font(Theme.Font.num(20)).foregroundStyle(Theme.Palette.text)
            }
            // Proportional stage ribbon.
            GeometryReader { geo in
                HStack(spacing: 2) {
                    ForEach(mins.filter { $0.2 > 0 }, id: \.0) { st in
                        RoundedRectangle(cornerRadius: 3)
                            .fill(st.1)
                            .frame(width: max(3, (geo.size.width - 6) * CGFloat(st.2 / total)))
                    }
                }
            }
            .frame(height: 10)
            // Legend with minutes.
            HStack(spacing: 10) {
                ForEach(mins.filter { $0.2 > 0 }, id: \.0) { st in
                    HStack(spacing: 4) {
                        Circle().fill(st.1).frame(width: 6, height: 6)
                        (sleepStageName(st.0) + Text(" \(Int(st.2))m")).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                    }
                }
            }
            HStack(spacing: 12) {
                if let perf = jsonNum(json["performance"]) {
                    Text("\(Int(perf))% performance").font(Theme.Font.micro).foregroundStyle(Theme.Palette.mint)
                }
                if let debt = jsonNum(json["debt"]), debt > 0 {
                    Text("\(String(format: "%.1f", debt))h debt").font(Theme.Font.micro).foregroundStyle(Theme.Palette.amber)
                }
                if let status = json["status"] as? String, !status.isEmpty {
                    Text(status).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                }
            }
        }
        .coachCard()
    }
}

// MARK: - Sparkline / trend (a real mini line-chart)

struct SparklineCard: View {
    let json: [String: Any]
    private var points: [Double] {
        (json["points"] as? [Any])?.compactMap { jsonNum($0) } ?? []
    }
    var body: some View {
        let pts = points
        let last = pts.last
        let unit = (json["unit"] as? String).map { " \($0)" } ?? ""
        VStack(alignment: .leading, spacing: 8) {
            HStack(alignment: .firstTextBaseline) {
                if let label = json["label"] as? String, !label.isEmpty {
                    Text(label).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                }
                Spacer()
                if let last {
                    Text(trimmed(last) + unit).font(Theme.Font.num(15)).foregroundStyle(Theme.Palette.text)
                }
            }
            if pts.count >= 2 {
                Sparkline(points: pts).frame(height: 40)
            } else {
                Text("Not enough data to chart yet").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
            }
        }
        .coachCard()
    }

    private func trimmed(_ v: Double) -> String {
        v == v.rounded() ? String(Int(v)) : String(format: "%.1f", v)
    }
}

/// The line itself: a normalized polyline with a soft area fill + an end dot.
private struct Sparkline: View {
    let points: [Double]
    var body: some View {
        GeometryReader { geo in
            let w = geo.size.width, h = geo.size.height
            let lo = points.min() ?? 0, hi = points.max() ?? 1
            let span = hi - lo == 0 ? 1 : hi - lo
            let step = points.count > 1 ? w / CGFloat(points.count - 1) : w
            let xy: (Int) -> CGPoint = { i in
                CGPoint(x: CGFloat(i) * step, y: h - CGFloat((points[i] - lo) / span) * h)
            }
            ZStack {
                // Area.
                Path { p in
                    p.move(to: CGPoint(x: 0, y: h))
                    for i in points.indices { p.addLine(to: xy(i)) }
                    p.addLine(to: CGPoint(x: w, y: h))
                    p.closeSubpath()
                }
                .fill(LinearGradient(colors: [Theme.Palette.cyan.opacity(0.28), Theme.Palette.cyan.opacity(0.02)],
                                     startPoint: .top, endPoint: .bottom))
                // Line.
                Path { p in
                    p.move(to: xy(0))
                    for i in points.indices.dropFirst() { p.addLine(to: xy(i)) }
                }
                .stroke(Theme.Palette.cyan, style: StrokeStyle(lineWidth: 2, lineCap: .round, lineJoin: .round))
                // End dot.
                Circle().fill(Theme.Palette.cyan).frame(width: 6, height: 6)
                    .position(xy(points.count - 1))
            }
        }
    }
}

// MARK: - Stat (one metric tile)

struct StatCard: View {
    let json: [String: Any]
    var body: some View {
        let unit = (json["unit"] as? String).map { " \($0)" } ?? ""
        VStack(alignment: .leading, spacing: 4) {
            if let label = json["label"] as? String, !label.isEmpty {
                Text(label).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
            }
            Text(valueString + unit).font(Theme.Font.num(26)).foregroundStyle(Theme.Palette.text)
            if let sub = json["sub"] as? String, !sub.isEmpty {
                Text(sub).font(Theme.Font.micro).foregroundStyle(Theme.Palette.mint)
            }
        }
        .coachCard()
    }

    private var valueString: String {
        if let n = jsonNum(json["value"]) { return n == n.rounded() ? String(Int(n)) : String(format: "%.1f", n) }
        return (json["value"] as? String) ?? "–"
    }
}

// MARK: - Stats (vitals grid with flags)

struct StatsCard: View {
    let json: [String: Any]
    private var items: [[String: Any]] { json["items"] as? [[String: Any]] ?? [] }
    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            if let title = json["title"] as? String, !title.isEmpty { cardTitle(verbatim: title) }
            let cols = [GridItem(.flexible(), spacing: 10), GridItem(.flexible(), spacing: 10)]
            LazyVGrid(columns: cols, alignment: .leading, spacing: 10) {
                ForEach(Array(items.enumerated()), id: \.offset) { _, it in
                    let unit = (it["unit"] as? String).map { " \($0)" } ?? ""
                    let val = jsonNum(it["value"]).map { $0 == $0.rounded() ? String(Int($0)) : String(format: "%.1f", $0) }
                        ?? (it["value"] as? String) ?? "–"
                    VStack(alignment: .leading, spacing: 2) {
                        Text((it["label"] as? String) ?? "").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                        HStack(spacing: 5) {
                            Circle().fill(coachFlagColor(it["flag"] as? String)).frame(width: 6, height: 6)
                            Text(val + unit).font(Theme.Font.num(15)).foregroundStyle(Theme.Palette.text)
                        }
                    }
                }
            }
            cardCaption(json["caption"] as? String)
        }
        .coachCard()
    }
}

// MARK: - Markers (biomarker panel)

struct MarkersCard: View {
    let json: [String: Any]
    private var items: [[String: Any]] { json["items"] as? [[String: Any]] ?? [] }
    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            if let t = json["title"] as? String, !t.isEmpty { cardTitle(verbatim: t) } else { cardTitle("Bloodwork") }
            ForEach(Array(items.enumerated()), id: \.offset) { _, it in
                HStack(spacing: 8) {
                    Circle().fill(coachFlagColor(it["flag"] as? String)).frame(width: 7, height: 7)
                    Text((it["label"] as? String) ?? "").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                    Spacer()
                    Text((it["value"].map { "\($0)" }) ?? "–").font(Theme.Font.num(13))
                        .foregroundStyle(coachFlagColor(it["flag"] as? String) == Theme.Palette.textDim ? Theme.Palette.text : coachFlagColor(it["flag"] as? String))
                }
            }
            cardCaption(json["caption"] as? String)
        }
        .coachCard()
    }
}

// MARK: - Bloodwork panel (COACH CARDS v2 · Thrust B7)

/// The elevated bloodwork card: markers GROUPED by body system (Hormones / Lipids / Metabolic / …), each
/// with an in-range/flagged chip, its optimal range, and a trend arrow vs the previous reading (green when
/// the move is in the healthy direction, pink when it's the wrong way). Replaces the flat `markers` list.
struct BioPanelCard: View {
    let json: [String: Any]
    private var groups: [[String: Any]] { json["groups"] as? [[String: Any]] ?? [] }

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            HStack(alignment: .firstTextBaseline) {
                if let t = json["title"] as? String, !t.isEmpty { cardTitle(verbatim: t) } else { cardTitle("Bloodwork") }
                Spacer()
                if let taken = json["taken_at"] as? String {
                    Text(verbatim: taken).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
                }
            }
            ForEach(Array(groups.enumerated()), id: \.offset) { _, g in
                let markers = g["markers"] as? [[String: Any]] ?? []
                if !markers.isEmpty {
                    VStack(alignment: .leading, spacing: 6) {
                        Text((g["name"] as? String) ?? "").textCase(.uppercase)
                            .font(Theme.Font.micro).tracking(0.6).foregroundStyle(Theme.Palette.textFaint)
                        ForEach(Array(markers.enumerated()), id: \.offset) { _, m in
                            markerRow(m)
                        }
                    }
                }
            }
            cardCaption(json["caption"] as? String)
        }
        .coachCard()
    }

    private func markerRow(_ m: [String: Any]) -> some View {
        let flag = m["flag"] as? String
        let color = coachFlagColor(flag)
        let valueColor = color == Theme.Palette.textDim ? Theme.Palette.text : color
        return HStack(spacing: 8) {
            Circle().fill(color).frame(width: 7, height: 7)
            VStack(alignment: .leading, spacing: 1) {
                Text((m["label"] as? String) ?? "").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                if let range = m["range"] as? String, !range.isEmpty {
                    Text(verbatim: range).font(.system(size: 9, design: .rounded)).foregroundStyle(Theme.Palette.textFaint)
                }
            }
            Spacer(minLength: 6)
            trendArrow(m)
            Text((m["value"].map { "\($0)" }) ?? "–").font(Theme.Font.num(13)).foregroundStyle(valueColor)
                .monospacedDigit().lineLimit(1)
        }
    }

    @ViewBuilder private func trendArrow(_ m: [String: Any]) -> some View {
        if let trend = m["trend"] as? String, trend == "up" || trend == "down" {
            // trend_good may be absent (unknown direction) → neutral gray.
            let good = m["trend_good"] as? Bool
            let tint = good == nil ? Theme.Palette.textFaint : (good == true ? Theme.Palette.mint : Theme.Palette.pink)
            Image(systemName: trend == "up" ? "arrow.up.right" : "arrow.down.right")
                .font(.system(size: 10, weight: .bold)).foregroundStyle(tint)
        }
    }
}

// MARK: - Weight (trend + rate)

struct WeightTrendCard: View {
    let json: [String: Any]
    var body: some View {
        let latest = jsonNum(json["latest_kg"]) ?? jsonNum(json["trend_kg"])
        let rate = jsonNum(json["rate_kg_wk"])
        VStack(alignment: .leading, spacing: 6) {
            cardTitle("Weight")
            HStack(alignment: .firstTextBaseline, spacing: 6) {
                Text(latest.map { String(format: "%.1f", $0) } ?? "–").font(Theme.Font.num(26)).foregroundStyle(Theme.Palette.text)
                Text("kg").font(Theme.Font.num(13)).foregroundStyle(Theme.Palette.textDim)
                Spacer()
                if let rate, abs(rate) >= 0.01 {
                    let up = rate > 0
                    Text("\(up ? "↑" : "↓")\(String(format: "%.2f", abs(rate)))/wk")
                        .font(Theme.Font.num(13)).foregroundStyle(up ? Theme.Palette.amber : Theme.Palette.mint)
                }
            }
            cardCaption(json["caption"] as? String)
        }
        .coachCard()
    }
}

// MARK: - Biological age

struct BioAgeCard: View {
    let json: [String: Any]
    private var drivers: [[String: Any]] { json["drivers"] as? [[String: Any]] ?? [] }
    var body: some View {
        let bio = jsonNum(json["bio_age"])
        let chrono = jsonNum(json["chrono_age"])
        VStack(alignment: .leading, spacing: 10) {
            HStack(alignment: .firstTextBaseline, spacing: 8) {
                VStack(alignment: .leading, spacing: 1) {
                    Text("Biological age").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                    Text(bio.map { String(Int($0.rounded())) } ?? "–").font(Theme.Font.num(26))
                        .foregroundStyle(youngerColor(bio: bio, chrono: chrono))
                }
                Spacer()
                if let chrono {
                    VStack(alignment: .trailing, spacing: 1) {
                        Text("Actual").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                        Text(String(Int(chrono.rounded()))).font(Theme.Font.num(20)).foregroundStyle(Theme.Palette.text)
                    }
                }
            }
            ForEach(Array(drivers.prefix(5).enumerated()), id: \.offset) { _, d in
                HStack(spacing: 8) {
                    Circle().fill((d["good"] as? Bool ?? false) ? Theme.Palette.mint : Theme.Palette.amber).frame(width: 6, height: 6)
                    Text((d["label"] as? String) ?? "").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                    Spacer()
                    Text((d["value"].map { "\($0)" }) ?? "").font(Theme.Font.num(12)).foregroundStyle(Theme.Palette.text)
                }
            }
            cardCaption(json["caption"] as? String)
        }
        .coachCard()
    }

    private func youngerColor(bio: Double?, chrono: Double?) -> Color {
        guard let bio, let chrono else { return Theme.Palette.text }
        return bio < chrono ? Theme.Palette.mint : (bio > chrono ? Theme.Palette.amber : Theme.Palette.text)
    }
}

// MARK: - Longevity (Titan Age + pace-of-aging + levers)

struct LongevityCard: View {
    let json: [String: Any]
    private var younger: [[String: Any]] { json["younger"] as? [[String: Any]] ?? [] }
    private var older: [[String: Any]] { json["older"] as? [[String: Any]] ?? [] }

    var body: some View {
        let titan = jsonNum(json["titan_age"])
        let chrono = jsonNum(json["chronological_age"])
        let color = ageColor(titan: titan, chrono: chrono)
        VStack(alignment: .leading, spacing: 10) {
            HStack(alignment: .firstTextBaseline, spacing: 8) {
                VStack(alignment: .leading, spacing: 1) {
                    Text("Titan Age").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                    Text(titan.map { String(Int($0.rounded())) } ?? "–").font(Theme.Font.num(26)).foregroundStyle(color)
                }
                Spacer()
                if let chrono {
                    VStack(alignment: .trailing, spacing: 1) {
                        Text("Actual").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                        Text(String(Int(chrono.rounded()))).font(Theme.Font.num(20)).foregroundStyle(Theme.Palette.text)
                    }
                }
            }
            // Pace label is computed on-device (localizable) rather than shown from the server string.
            if json["pace"] != nil {
                HStack(spacing: 6) {
                    Image(systemName: pace <= 0.85 ? "arrow.down.right" : (pace >= 1.15 ? "arrow.up.right" : "arrow.right"))
                        .font(.caption2).foregroundStyle(color)
                    Text(paceLabel).font(Theme.Font.micro.weight(.semibold)).foregroundStyle(Theme.Palette.text)
                }
            } else {
                Text("Building your aging trend").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
            }
            // Top levers pulling younger (mint) / older (amber) — the actionable "why".
            ForEach(Array(younger.prefix(2).enumerated()), id: \.offset) { _, l in leverRow(l, good: true) }
            ForEach(Array(older.prefix(2).enumerated()), id: \.offset) { _, l in leverRow(l, good: false) }
            if json["partial"] as? Bool == true {
                Text("Fitness-based estimate — a blood panel would sharpen it.")
                    .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
            }
        }
        .coachCard()
    }

    private var pace: Double { jsonNum(json["pace"]) ?? 1.0 }
    private var paceLabel: LocalizedStringKey {
        pace <= 0.85 ? "aging slower than the clock" : (pace >= 1.15 ? "aging faster than the clock" : "aging with the clock")
    }

    private func leverRow(_ l: [String: Any], good: Bool) -> some View {
        let years = jsonNum(l["years"]) ?? 0
        return HStack(spacing: 8) {
            Circle().fill(good ? Theme.Palette.mint : Theme.Palette.amber).frame(width: 6, height: 6)
            Text((l["label"] as? String) ?? "").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
            Spacer()
            Text(String(format: "%+.1f yr", years)).font(Theme.Font.num(12)).foregroundStyle(Theme.Palette.text)
        }
    }

    private func ageColor(titan: Double?, chrono: Double?) -> Color {
        guard let titan, let chrono else { return Theme.Palette.text }
        return titan < chrono ? Theme.Palette.mint : (titan > chrono ? Theme.Palette.amber : Theme.Palette.text)
    }
}

// MARK: - Fitness (score + pillars)

struct FitnessCard: View {
    let json: [String: Any]
    private var pillars: [[String: Any]] { json["pillars"] as? [[String: Any]] ?? [] }
    var body: some View {
        let score = jsonNum(json["score"])
        VStack(alignment: .leading, spacing: 10) {
            HStack(alignment: .firstTextBaseline, spacing: 8) {
                Text(score.map { String(Int($0.rounded())) } ?? "–").font(Theme.Font.num(28))
                    .foregroundStyle(Theme.Palette.recovery(score.map { Int($0) }))
                if let grade = json["grade"] as? String, !grade.isEmpty {
                    Text(grade).font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.text)
                }
                Spacer()
                if let vo2 = jsonNum(json["vo2max"]) {
                    VStack(alignment: .trailing, spacing: 1) {
                        Text("VO₂max").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                        Text(String(format: "%.0f", vo2)).font(Theme.Font.num(15)).foregroundStyle(Theme.Palette.text)
                    }
                }
            }
            ForEach(Array(pillars.prefix(5).enumerated()), id: \.offset) { _, p in
                let s = jsonNum(p["score"]) ?? 0
                VStack(alignment: .leading, spacing: 3) {
                    HStack {
                        Text((p["label"] as? String) ?? "").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                        Spacer()
                        Text("\(Int(s))").font(Theme.Font.num(12)).foregroundStyle(Theme.Palette.text)
                    }
                    CardBar(value: s, target: 100, color: Theme.Palette.recovery(Int(s)))
                }
            }
            cardCaption(json["caption"] as? String)
        }
        .coachCard()
    }
}

// MARK: - Protocol / plan (a followable checklist)

struct ProtocolCard: View {
    let json: [String: Any]
    private var items: [Any] { (json["items"] as? [Any]) ?? [] }
    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            if let t = json["title"] as? String, !t.isEmpty { cardTitle(verbatim: t) } else { cardTitle("Protocol") }
            if let sub = json["subtitle"] as? String, !sub.isEmpty {
                Text(sub).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
            }
            ForEach(Array(items.enumerated()), id: \.offset) { _, raw in
                let (text, detail) = itemParts(raw)
                HStack(alignment: .top, spacing: 8) {
                    Image(systemName: "circle").font(.system(size: 11)).foregroundStyle(Theme.Palette.cyan).padding(.top, 2)
                    VStack(alignment: .leading, spacing: 1) {
                        Text(text).font(Theme.Font.body).foregroundStyle(Theme.Palette.text)
                        if let detail, !detail.isEmpty {
                            Text(detail).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                        }
                    }
                }
            }
            cardCaption(json["caption"] as? String)
        }
        .coachCard()
    }

    /// A checklist item can be a plain string or {label/name/text, detail/sub/dose}.
    private func itemParts(_ raw: Any) -> (String, String?) {
        if let s = raw as? String { return (s, nil) }
        if let d = raw as? [String: Any] {
            let text = (d["label"] as? String) ?? (d["name"] as? String) ?? (d["text"] as? String) ?? ""
            let detail = (d["detail"] as? String) ?? (d["sub"] as? String) ?? (d["dose"] as? String)
            return (text, detail)
        }
        return ("\(raw)", nil)
    }
}
