import SwiftUI

/// The full, transparent Bio Age page (BIO_AGE_PAGE) — Titan's screenshot surface. Tap the Today
/// Biological-Age tile → land here: a shareable hero (Titan Age vs chrono + delta + pace + confidence),
/// the "how we got your age" contribution waterfall (every marker's ± years, tappable for the value +
/// methodology), youth/older levers, an honest "add bloodwork to sharpen" path, personalized coach tips,
/// and a plain-language methodology footer. Wellness estimate, never "reverse aging".
struct BioAgePage: View {
    @EnvironmentObject var model: AppModel

    var body: some View {
        VStack(spacing: Theme.Space.m) {
            if let p = model.longevity {
                if p.available, let titan = p.titan_age {
                    hero(p, titan)
                    waterfall(p)
                    leversCard(p)
                    if p.partial == true { sharpenCard(p) }
                    if !p.tips.isEmpty { tipsCard(p) }
                    methodologyCard(p)
                } else {
                    unavailable(p.reason)
                }
            } else {
                ProgressView().tint(Theme.Palette.violet).frame(maxWidth: .infinity, minHeight: 240)
            }
            Color.clear.frame(height: 8)
        }
        .titanDetail("Titan Age", glow: Theme.Palette.violet)
        .task { await model.loadLongevity() }
    }

    // MARK: Hero

    private func hero(_ p: LongevityPage, _ titan: Double) -> some View {
        let younger = (p.delta ?? 0) < 0
        let accent = younger ? Theme.Palette.mint : Theme.Palette.amber
        return GlassCard {
            VStack(spacing: Theme.Space.s) {
                if let conf = p.confidence {
                    Text(confidenceLabel(conf, partial: p.partial == true))
                        .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
                        .frame(maxWidth: .infinity, alignment: .trailing)
                }
                Text(String(format: "%.1f", titan)).font(Theme.Font.num(76)).foregroundStyle(Theme.Grad.brand)
                Text("TITAN AGE").font(Theme.Font.micro).tracking(2).foregroundStyle(Theme.Palette.textDim)
                if let delta = p.delta {
                    Text(deltaHeadline(delta)).font(Theme.Font.title).foregroundStyle(accent)
                        .multilineTextAlignment(.center)
                }
                if let chrono = p.chronological_age {
                    Text("Your real age is \(String(format: "%.1f", chrono))")
                        .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                }
                if let pace = p.pace, let label = pace.label {
                    HStack(spacing: 5) {
                        Image(systemName: paceIcon(pace.direction)).font(.caption2).foregroundStyle(accent)
                        Text(verbatim: label.capitalizedFirst).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                    }
                }
                ShareLink(item: shareText(p)) {
                    Label("Share", systemImage: "square.and.arrow.up")
                        .font(Theme.Font.body.weight(.semibold)).foregroundStyle(.white)
                        .frame(maxWidth: .infinity).padding(.vertical, 12)
                        .background(Theme.Grad.brand, in: RoundedRectangle(cornerRadius: Theme.Radius.chip))
                }.padding(.top, Theme.Space.xs)
            }
        }
    }

    // MARK: Contribution waterfall

    private func waterfall(_ p: LongevityPage) -> some View {
        let maxYears = max(0.1, p.components.compactMap { $0.years.map(abs) }.max() ?? 0.1)
        return GlassCard {
            VStack(alignment: .leading, spacing: Theme.Space.s) {
                SectionHeader(title: "How we got your age")
                if let chrono = p.chronological_age {
                    anchorRow("Chronological age", String(format: "%.1f", chrono))
                }
                ForEach(p.components) { c in
                    WaterfallRow(component: c, maxYears: maxYears)
                }
                if let titan = p.titan_age {
                    Divider().overlay(Theme.Palette.cardStroke)
                    anchorRow("Titan Age", String(format: "%.1f", titan), bold: true)
                }
            }
        }
    }

    private func anchorRow(_ label: LocalizedStringKey, _ value: String, bold: Bool = false) -> some View {
        HStack {
            Text(label).font(bold ? Theme.Font.body.weight(.bold) : Theme.Font.body).foregroundStyle(Theme.Palette.text)
            Spacer()
            Text(value).font(Theme.Font.num(bold ? 18 : 15)).foregroundStyle(bold ? Theme.Palette.violet : Theme.Palette.textDim)
        }
    }

    // MARK: Levers

    private func leversCard(_ p: LongevityPage) -> some View {
        GlassCard {
            VStack(alignment: .leading, spacing: Theme.Space.s) {
                SectionHeader(title: "Your levers")
                if !p.younger_levers.isEmpty {
                    Text("KEEPING YOU YOUNG").font(Theme.Font.micro).tracking(0.6).foregroundStyle(Theme.Palette.mint)
                    ForEach(p.younger_levers) { leverRow($0, Theme.Palette.mint) }
                }
                if !p.older_levers.isEmpty {
                    Text("ADDING YEARS").font(Theme.Font.micro).tracking(0.6).foregroundStyle(Theme.Palette.amber).padding(.top, 4)
                    ForEach(p.older_levers) { leverRow($0, Theme.Palette.amber) }
                } else {
                    Text("Nothing is aging you faster than your years — rare. Keep it up.")
                        .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim).padding(.top, 2)
                }
            }
        }
    }

    private func leverRow(_ l: LongevityPage.Lever, _ color: Color) -> some View {
        HStack {
            Circle().fill(color).frame(width: 7, height: 7)
            Text(verbatim: l.label ?? "").font(Theme.Font.micro).foregroundStyle(Theme.Palette.text)
            Spacer()
            if let y = l.years { Text(yearsLabel(y)).font(Theme.Font.num(13)).foregroundStyle(color) }
        }
    }

    // MARK: Sharpen (honest, drives bloodwork)

    private func sharpenCard(_ p: LongevityPage) -> some View {
        GlassCard {
            VStack(alignment: .leading, spacing: 6) {
                Label("Sharpen your Titan Age", systemImage: "drop.fill").font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.pink)
                let n = p.missing.count
                Text(n > 0
                     ? "This is estimated from your fitness data. Add a blood panel (\(n) markers) and it sharpens to a full, clinical-grade age."
                     : "Add a blood panel and your Titan Age sharpens to a full, clinical-grade age.")
                    .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim).fixedSize(horizontal: false, vertical: true)
                if !p.missing.isEmpty {
                    Text(verbatim: p.missing.prefix(6).joined(separator: " · ") + (p.missing.count > 6 ? " …" : ""))
                        .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
                }
            }
        }
    }

    // MARK: Tips

    private func tipsCard(_ p: LongevityPage) -> some View {
        GlassCard {
            VStack(alignment: .leading, spacing: Theme.Space.s) {
                SectionHeader(title: "How to get younger")
                ForEach(p.tips) { t in
                    HStack(alignment: .top, spacing: 8) {
                        Image(systemName: tipIcon(t.kind)).font(.caption2).foregroundStyle(tipColor(t.kind))
                        Text(verbatim: t.action ?? "").font(Theme.Font.micro).foregroundStyle(Theme.Palette.text)
                            .fixedSize(horizontal: false, vertical: true)
                    }
                }
            }
        }
    }

    // MARK: Methodology

    private func methodologyCard(_ p: LongevityPage) -> some View {
        GlassCard {
            VStack(alignment: .leading, spacing: 6) {
                SectionHeader(title: "How Titan Age works")
                if let m = p.methodology {
                    Text(verbatim: m).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim).fixedSize(horizontal: false, vertical: true)
                }
                if let d = p.disclaimer {
                    Text(verbatim: d).font(.system(size: 10, design: .rounded)).foregroundStyle(Theme.Palette.textFaint)
                        .fixedSize(horizontal: false, vertical: true)
                }
            }
        }
    }

    private func unavailable(_ reason: String?) -> some View {
        GlassCard {
            VStack(spacing: Theme.Space.s) {
                Image(systemName: "hourglass").font(.system(size: 32)).foregroundStyle(Theme.Palette.violet)
                Text("Titan Age not ready yet").font(Theme.Font.title).foregroundStyle(Theme.Palette.text)
                Text(verbatim: reason ?? "Log some recovery + fitness data and it'll unlock.")
                    .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim).multilineTextAlignment(.center)
            }.frame(maxWidth: .infinity)
        }
    }

    // MARK: Helpers

    private func deltaHeadline(_ delta: Double) -> String {
        let y = abs(delta)
        if y < 0.5 { return "Right on your age" }
        return delta < 0 ? "\(String(format: "%.1f", y)) years younger than your age"
                         : "\(String(format: "%.1f", y)) years older than your age"
    }
    private func yearsLabel(_ y: Double) -> String { (y < 0 ? "−" : "+") + String(format: "%.1fy", abs(y)) }
    private func confidenceLabel(_ c: String, partial: Bool) -> String {
        partial ? "\(c.capitalizedFirst) confidence · estimated from fitness — add bloodwork to sharpen"
                : "\(c.capitalizedFirst) confidence"
    }
    private func paceIcon(_ dir: String?) -> String {
        switch dir { case "younger": return "arrow.down.right"; case "older": return "arrow.up.right"; default: return "arrow.right" }
    }
    private func tipIcon(_ kind: String?) -> String {
        switch kind { case "protect": return "shield.fill"; case "improve": return "arrow.up.circle.fill"; default: return "star.fill" }
    }
    private func tipColor(_ kind: String?) -> Color {
        switch kind { case "protect": return Theme.Palette.mint; case "improve": return Theme.Palette.amber; default: return Theme.Palette.violet }
    }
    private func shareText(_ p: LongevityPage) -> String {
        guard let titan = p.titan_age else { return "My Titan Age — tracked on Titan." }
        let d = p.delta ?? 0
        let frame = abs(d) < 0.5 ? "right on my age" : (d < 0 ? "\(String(format: "%.1f", abs(d))) years younger than my age" : "\(String(format: "%.1f", abs(d))) years older than my age")
        return "My Titan Age is \(String(format: "%.1f", titan)) — \(frame). Tracked on Titan 🧬"
    }
}

/// A single waterfall row — the marker, its ± year contribution as a proportional bar, tappable to reveal
/// the underlying value + the plain-language methodology ("how this maps to years").
private struct WaterfallRow: View {
    let component: LongevityPage.Component
    let maxYears: Double
    @State private var expanded = false

    var body: some View {
        let years = component.years ?? 0
        let younger = years < 0
        let color = abs(years) < 0.05 ? Theme.Palette.textFaint : (younger ? Theme.Palette.mint : Theme.Palette.amber)
        VStack(alignment: .leading, spacing: 6) {
            Button {
                withAnimation(.easeInOut(duration: 0.18)) { expanded.toggle() }
            } label: {
                HStack(spacing: Theme.Space.s) {
                    Text(verbatim: component.label ?? "").font(Theme.Font.micro).foregroundStyle(Theme.Palette.text)
                        .frame(width: 118, alignment: .leading)
                    GeometryReader { geo in
                        Capsule().fill(color)
                            .frame(width: max(2, geo.size.width * CGFloat(abs(years) / maxYears)), height: 10)
                    }.frame(height: 10)
                    Text(yearsLabel(years)).font(Theme.Font.num(13)).foregroundStyle(color).frame(width: 46, alignment: .trailing)
                    Image(systemName: expanded ? "chevron.up" : "chevron.down").font(.system(size: 10)).foregroundStyle(Theme.Palette.textFaint)
                }
            }.buttonStyle(.plain)
            if expanded {
                VStack(alignment: .leading, spacing: 3) {
                    if let v = component.value {
                        Text(verbatim: valueLabel(v, component.unit)).font(Theme.Font.num(14)).foregroundStyle(Theme.Palette.text)
                    }
                    if let ctx = component.context, !ctx.isEmpty {
                        Text(verbatim: ctx).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                    }
                    if let how = component.how {
                        Text(verbatim: how).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint).fixedSize(horizontal: false, vertical: true)
                    }
                }
                .padding(.leading, 4).padding(.bottom, 2)
            }
        }
    }

    private func yearsLabel(_ y: Double) -> String {
        abs(y) < 0.05 ? "0.0y" : (y < 0 ? "−" : "+") + String(format: "%.1fy", abs(y))
    }
    private func valueLabel(_ v: Double, _ unit: String?) -> String {
        let num = v == v.rounded() ? String(Int(v)) : String(format: "%.1f", v)
        let u = (unit ?? "").isEmpty ? "" : " \(unit!)"
        return num + u
    }
}

private extension String {
    var capitalizedFirst: String { isEmpty ? self : prefix(1).uppercased() + dropFirst() }
}
