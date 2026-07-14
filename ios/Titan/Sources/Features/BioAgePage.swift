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
                    BioAgeHero(page: p, titan: titan)
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

    private func yearsLabel(_ y: Double) -> String { (y < 0 ? "−" : "+") + String(format: "%.1fy", abs(y)) }
    private func tipIcon(_ kind: String?) -> String {
        switch kind { case "protect": return "shield.fill"; case "improve": return "arrow.up.circle.fill"; default: return "star.fill" }
    }
    private func tipColor(_ kind: String?) -> Color {
        switch kind { case "protect": return Theme.Palette.mint; case "improve": return Theme.Palette.amber; default: return Theme.Palette.violet }
    }
}

// MARK: - Cinematic animated hero (BIO_AGE_PREMIUM — the screenshot moment)

/// The Bio Age hero, animated: on open a radial age dial draws while the big number morphs from the
/// user's CHRONOLOGICAL age DOWN to their Titan Age, then "N YEARS YOUNGER" springs in as the emotional
/// hit — it literally animates "you're younger than your age." Reduced-motion renders the final state
/// instantly. Share hands off a rendered IMAGE of the hero. Reuses Theme.Motion / Grad / Haptic.
private struct BioAgeHero: View {
    let page: LongevityPage
    let titan: Double
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    // Animated state (starts at the "before" pose, drives to the target on reveal).
    @State private var ageValue: Double
    @State private var deltaValue: Double = 0
    @State private var ringProgress: CGFloat = 0
    @State private var showDelta = false
    @State private var shareImage: UIImage?

    init(page: LongevityPage, titan: Double) {
        self.page = page
        self.titan = titan
        // Number begins at the chronological age (or the Titan age if we don't know it), then animates.
        _ageValue = State(initialValue: page.chronological_age ?? titan)
    }

    private var delta: Double { page.delta ?? ((titan) - (page.chronological_age ?? titan)) }
    private var younger: Bool { delta < 0 }
    private var accent: Color { younger ? Theme.Palette.mint : Theme.Palette.amber }
    /// The dial fills proportional to |delta| on a 0–12y scale — a bigger gap draws a fuller arc.
    private var ringTarget: CGFloat { min(1, CGFloat(abs(delta) / 12)) }

    var body: some View {
        GlassCard {
            VStack(spacing: Theme.Space.s) {
                if let conf = page.confidence {
                    Text(confidenceLabel(conf, partial: page.partial == true))
                        .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
                        .frame(maxWidth: .infinity, alignment: .trailing)
                }

                // The dial + the morphing age number.
                ZStack {
                    Circle().stroke(Color.white.opacity(0.06), lineWidth: 14)
                    Circle().trim(from: 0, to: ringProgress)
                        .stroke(Theme.Grad.ring(accent), style: StrokeStyle(lineWidth: 14, lineCap: .round))
                        .rotationEffect(.degrees(-90))
                        .shadow(color: accent.opacity(0.6), radius: 10)
                    VStack(spacing: 0) {
                        AnimatedNumber(value: ageValue, font: Theme.Font.num(60), color: Theme.Grad.brand)
                        Text("TITAN AGE").font(Theme.Font.micro).tracking(2).foregroundStyle(Theme.Palette.textDim)
                    }
                }
                .frame(width: 200, height: 200)
                .background(Theme.Grad.glow(accent).scaleEffect(1.25).opacity(0.7))

                // The delta — the screenshot beat.
                if let d = page.delta {
                    Text(deltaHeadline(d, animated: deltaValue))
                        .font(Theme.Font.title).foregroundStyle(accent).multilineTextAlignment(.center)
                        .scaleEffect(showDelta ? 1 : 0.9).opacity(showDelta ? 1 : 0)
                }
                if let chrono = page.chronological_age {
                    Text("Your real age is \(String(format: "%.1f", chrono))")
                        .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                }
                if let pace = page.pace, let label = pace.label {
                    HStack(spacing: 5) {
                        Image(systemName: paceIcon(pace.direction)).font(.caption2).foregroundStyle(accent)
                        Text(verbatim: label.capitalizedFirst).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                    }
                }
                shareButton
            }
        }
        .onAppear { runReveal(); renderShareImage() }
    }

    @ViewBuilder private var shareButton: some View {
        let label = Label("Share", systemImage: "square.and.arrow.up")
            .font(Theme.Font.body.weight(.semibold)).foregroundStyle(.white)
            .frame(maxWidth: .infinity).padding(.vertical, 12)
            .background(Theme.Grad.brand, in: RoundedRectangle(cornerRadius: Theme.Radius.chip))
        // Prefer a rendered image of the hero; fall back to text until it's ready.
        if let img = shareImage {
            ShareLink(item: Image(uiImage: img), preview: SharePreview("My Titan Age", image: Image(uiImage: img))) { label }
                .padding(.top, Theme.Space.xs)
        } else {
            ShareLink(item: shareText) { label }.padding(.top, Theme.Space.xs)
        }
    }

    private func runReveal() {
        guard !reduceMotion else {
            ageValue = titan; deltaValue = abs(delta); ringProgress = ringTarget; showDelta = true
            return
        }
        // Dial draws + number morphs chronological → Titan, together.
        withAnimation(.easeOut(duration: 1.2).delay(0.2)) {
            ageValue = titan; ringProgress = ringTarget; deltaValue = abs(delta)
        }
        // The delta springs in, with one settle haptic.
        withAnimation(.spring(response: 0.5, dampingFraction: 0.7).delay(1.4)) { showDelta = true }
        Task { try? await Task.sleep(nanoseconds: 1_400_000_000); Haptic.success() }
    }

    /// Render the final hero pose to an image for Share (BIO_AGE_PREMIUM — the shared artifact matches on-screen).
    private func renderShareImage() {
        let renderer = ImageRenderer(content: HeroShareCard(titan: titan, delta: delta, chrono: page.chronological_age, accent: accent))
        renderer.scale = 3
        shareImage = renderer.uiImage
    }

    // Helpers (hero-local).
    private func deltaHeadline(_ delta: Double, animated: Double) -> String {
        if abs(delta) < 0.5 { return "Right on your age" }
        return "\(String(format: "%.1f", animated)) YEARS \(delta < 0 ? "YOUNGER" : "OLDER")"
    }
    private func confidenceLabel(_ c: String, partial: Bool) -> String {
        partial ? "\(c.capitalizedFirst) confidence · estimated from fitness — add bloodwork to sharpen"
                : "\(c.capitalizedFirst) confidence"
    }
    private func paceIcon(_ dir: String?) -> String {
        switch dir { case "younger": return "arrow.down.right"; case "older": return "arrow.up.right"; default: return "arrow.right" }
    }
    private var shareText: String {
        let frame = abs(delta) < 0.5 ? "right on my age" : "\(String(format: "%.1f", abs(delta))) years \(younger ? "younger" : "older") than my age"
        return "My Titan Age is \(String(format: "%.1f", titan)) — \(frame). Tracked on Titan 🧬"
    }
}

/// A view whose number smoothly INTERPOLATES between values (SwiftUI Text doesn't animate its content, so
/// we drive it via `animatableData`). Used for the age morph + the "years younger" tick.
private struct AnimatedNumber: View, Animatable {
    var value: Double
    var font: Font
    var color: any ShapeStyle
    var animatableData: Double { get { value } set { value = newValue } }
    var body: some View {
        Text(String(format: "%.1f", value)).font(font).foregroundStyle(AnyShapeStyle(color)).monospacedDigit()
    }
}

/// The static, composed hero rendered to an image for Share — the dial + number + delta + Titan mark.
private struct HeroShareCard: View {
    let titan: Double
    let delta: Double
    let chrono: Double?
    let accent: Color

    var body: some View {
        let younger = delta < 0
        VStack(spacing: 12) {
            ZStack {
                Circle().stroke(Color.white.opacity(0.08), lineWidth: 16)
                Circle().trim(from: 0, to: min(1, CGFloat(abs(delta) / 12)))
                    .stroke(Theme.Grad.ring(accent), style: StrokeStyle(lineWidth: 16, lineCap: .round))
                    .rotationEffect(.degrees(-90))
                VStack(spacing: 0) {
                    Text(String(format: "%.1f", titan)).font(Theme.Font.num(64)).foregroundStyle(Theme.Grad.brand).monospacedDigit()
                    Text("TITAN AGE").font(Theme.Font.micro).tracking(2).foregroundStyle(Theme.Palette.textDim)
                }
            }.frame(width: 220, height: 220)
            if abs(delta) >= 0.5 {
                Text("\(String(format: "%.1f", abs(delta))) YEARS \(younger ? "YOUNGER" : "OLDER")")
                    .font(Theme.Font.title).foregroundStyle(accent)
            }
            if let chrono { Text("Real age \(String(format: "%.1f", chrono))").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim) }
            Text("TITAN").font(Theme.Font.label).tracking(4).foregroundStyle(Theme.Palette.textFaint)
        }
        .frame(width: 380, height: 480)
        .background(Theme.Palette.bg)
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
