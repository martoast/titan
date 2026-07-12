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
