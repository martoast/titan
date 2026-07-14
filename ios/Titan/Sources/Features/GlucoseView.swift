import SwiftUI

/// Continuous glucose (CGM_INTEGRATION P1). The day's curve with the time-in-range band shaded and honest
/// gaps (no sensor = no line), the headline metrics (average, TIR %, variability, GMI), and — when no CGM
/// is connected — a connect flow (Nightscout / Apple Health). Wellness data from the user's own device,
/// never medical.
struct GlucoseView: View {
    @EnvironmentObject var model: AppModel
    @State private var showConnect = false

    var body: some View {
        VStack(spacing: Theme.Space.m) {
            if let g = model.glucose {
                if g.has_data {
                    curveCard(g)
                    metricsCard(g)
                    if let o = g.overnight_mg_dl {
                        GlassCard {
                            HStack {
                                Label("Overnight glucose", systemImage: "moon.stars.fill").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                                Spacer()
                                Text("\(o) mg/dL").font(Theme.Font.num(16)).foregroundStyle(Theme.Palette.text)
                            }
                        }
                    }
                    statusFooter(g)
                } else {
                    connectPrompt(g)
                }
            } else {
                ProgressView().tint(Theme.Palette.cyan).frame(maxWidth: .infinity, minHeight: 220)
            }
            Color.clear.frame(height: 8)
        }
        .titanDetail("Glucose", glow: Theme.Palette.cyan)
        .task { await model.loadGlucose() }
        .sheet(isPresented: $showConnect) { GlucoseConnectSheet() }
    }

    // MARK: Curve

    private func curveCard(_ g: GlucoseDay) -> some View {
        GlassCard {
            VStack(alignment: .leading, spacing: Theme.Space.s) {
                HStack(alignment: .firstTextBaseline) {
                    SectionHeader(title: "Today")
                    Spacer()
                    if let avg = g.summary?.average_mg_dl {
                        Text("\(avg)").font(Theme.Font.num(20)).foregroundStyle(Theme.Palette.cyan)
                        Text("mg/dL avg").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
                    }
                }
                GlucoseCurve(points: g.points, low: g.range_low ?? 70, high: g.range_high ?? 140)
                    .frame(height: 150)
                HStack {
                    Text("12a").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint); Spacer()
                    Text("6a").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint); Spacer()
                    Text("12p").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint); Spacer()
                    Text("6p").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint); Spacer()
                    Text("12a").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
                }
                Text("Shaded band = in range (\(g.range_low ?? 70)–\(g.range_high ?? 140) mg/dL)")
                    .font(.system(size: 10, design: .rounded)).foregroundStyle(Theme.Palette.textFaint)
            }
        }
    }

    // MARK: Metrics

    private func metricsCard(_ g: GlucoseDay) -> some View {
        let s = g.summary
        return GlassCard {
            VStack(alignment: .leading, spacing: Theme.Space.m) {
                SectionHeader(title: "Metrics")
                let cols = [GridItem(.flexible()), GridItem(.flexible())]
                LazyVGrid(columns: cols, spacing: Theme.Space.l) {
                    metric("Time in range", s?.time_in_range_pct.map { "\($0)" }, "%", Theme.Palette.mint)
                    metric("Variability (CV)", s?.cv_pct.map { String(format: "%.1f", $0) }, "%", (s?.stable == true) ? Theme.Palette.mint : Theme.Palette.amber)
                    metric("Est. A1c (GMI)", s?.gmi_pct.map { String(format: "%.1f", $0) }, "%", Theme.Palette.cyan)
                    metric("Range", (s?.min_mg_dl).flatMap { lo in (s?.max_mg_dl).map { "\(lo)–\($0)" } }, "mg/dL", Theme.Palette.violet)
                }
            }
        }
    }

    private func metric(_ label: LocalizedStringKey, _ value: String?, _ unit: String, _ color: Color) -> some View {
        VStack(spacing: 4) {
            HStack(alignment: .firstTextBaseline, spacing: 2) {
                Text(value ?? "—").font(Theme.Font.num(22)).foregroundStyle(color)
                if value != nil { Text(unit).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint) }
            }
            Text(label).textCase(.uppercase).font(Theme.Font.micro).tracking(0.4).foregroundStyle(Theme.Palette.textDim)
        }.frame(maxWidth: .infinity)
    }

    // MARK: Connect + status

    private func connectPrompt(_ g: GlucoseDay) -> some View {
        GlassCard {
            VStack(spacing: Theme.Space.s) {
                Image(systemName: "drop.fill").font(.system(size: 34)).foregroundStyle(Theme.Palette.cyan)
                Text("See your glucose").font(Theme.Font.title).foregroundStyle(Theme.Palette.text)
                Text("Connect your CGM — Nightscout (open, self-hosted) or Apple Health — to see your day's curve, time-in-range, and how meals affect you.")
                    .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim).multilineTextAlignment(.center)
                Button {
                    Haptic.tap(); showConnect = true
                } label: {
                    Text("Connect a CGM").font(Theme.Font.body.weight(.semibold)).foregroundStyle(.white)
                        .frame(maxWidth: .infinity).padding(.vertical, 12)
                        .background(Theme.Grad.brand, in: RoundedRectangle(cornerRadius: Theme.Radius.chip))
                }.padding(.top, Theme.Space.xs)
                if let d = g.disclaimer {
                    Text(verbatim: d).font(.system(size: 10, design: .rounded)).foregroundStyle(Theme.Palette.textFaint)
                        .multilineTextAlignment(.center)
                }
            }.frame(maxWidth: .infinity)
        }
    }

    private func statusFooter(_ g: GlucoseDay) -> some View {
        VStack(spacing: 4) {
            if let s = g.status {
                HStack(spacing: 6) {
                    Circle().fill(s.fresh == true ? Theme.Palette.mint : Theme.Palette.textFaint).frame(width: 6, height: 6)
                    Text(freshnessLabel(s)).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
                    Spacer()
                    Button("Manage") { showConnect = true }.font(Theme.Font.micro).foregroundStyle(Theme.Palette.cyan)
                }
            }
            if let d = g.disclaimer {
                Text(verbatim: d).font(.system(size: 10, design: .rounded)).foregroundStyle(Theme.Palette.textFaint)
                    .fixedSize(horizontal: false, vertical: true).frame(maxWidth: .infinity, alignment: .leading)
            }
        }
        .padding(.horizontal, Theme.Space.xs)
    }

    private func freshnessLabel(_ s: GlucoseDay.Status) -> String {
        let src = (s.provider ?? "").capitalized
        if s.fresh == true { return "\(src) · live" }
        if let at = s.last_reading_at.flatMap({ ISO8601DateFormatter().date(from: $0) }) {
            let mins = Int(Date().timeIntervalSince(at) / 60)
            return "\(src) · last reading \(mins < 120 ? "\(mins)m" : "\(mins/60)h") ago"
        }
        return src.isEmpty ? "Not connected" : src
    }
}

/// The glucose day curve: a Canvas line across the 24h day, the in-range band shaded, and honest gaps
/// (the line breaks where readings are >~20 min apart — no interpolation over a sensor gap).
private struct GlucoseCurve: View {
    let points: [GlucoseDay.Point]
    let low: Int
    let high: Int

    var body: some View {
        GeometryReader { geo in
            let w = geo.size.width, h = geo.size.height
            let mgs = points.map { $0.mg }
            let yLo = 40.0
            let yHi = Double(max(200, (mgs.max() ?? 180) + 20))
            let span = yHi - yLo
            let y: (Double) -> CGFloat = { mg in h - CGFloat((mg - yLo) / span) * h }
            let x: (GlucoseDay.Point) -> CGFloat = { p in CGFloat(Self.dayFraction(p.t)) * w }

            ZStack {
                // In-range band.
                let bandTop = y(Double(high)), bandBottom = y(Double(low))
                Rectangle().fill(Theme.Palette.mint.opacity(0.12))
                    .frame(height: max(0, bandBottom - bandTop)).position(x: w / 2, y: (bandTop + bandBottom) / 2)

                if points.count >= 2 {
                    // Line, broken across gaps (>20 min between consecutive readings).
                    Path { path in
                        var started = false
                        for i in points.indices {
                            let pt = CGPoint(x: x(points[i]), y: y(Double(points[i].mg)))
                            if !started { path.move(to: pt); started = true }
                            else {
                                let gap = Self.dayFraction(points[i].t) - Self.dayFraction(points[i - 1].t)
                                if gap > (20.0 / (24 * 60)) { path.move(to: pt) } else { path.addLine(to: pt) }
                            }
                        }
                    }
                    .stroke(Theme.Palette.cyan, style: StrokeStyle(lineWidth: 2, lineCap: .round, lineJoin: .round))

                    // Out-of-range readings as small dots (amber low/high) so spikes stand out.
                    ForEach(points.filter { $0.mg < low || $0.mg > high }) { p in
                        Circle().fill(p.mg > high ? Theme.Palette.amber : Theme.Palette.pink)
                            .frame(width: 4, height: 4)
                            .position(x: x(p), y: y(Double(p.mg)))
                    }
                }
            }
        }
    }

    /// Fraction of the day (0–1) from a local ISO timestamp's wall-clock time (offset-safe: reads the
    /// HH:mm directly, no device-tz reconversion).
    static func dayFraction(_ iso: String) -> Double {
        guard let tIdx = iso.firstIndex(of: "T") else { return 0 }
        let time = iso[iso.index(after: tIdx)...]
        let parts = time.split(separator: ":")
        guard parts.count >= 2, let h = Int(parts[0]), let m = Int(parts[1]) else { return 0 }
        return (Double(h) * 60 + Double(m)) / (24 * 60)
    }
}

/// Connect a CGM: Nightscout (URL + optional token) — the open, self-hosted path — or Apple Health.
private struct GlucoseConnectSheet: View {
    @EnvironmentObject var model: AppModel
    @Environment(\.dismiss) private var dismiss
    @State private var url = ""
    @State private var token = ""
    @State private var saving = false

    var body: some View {
        NavigationStack {
            ZStack {
                Theme.Palette.bg.ignoresSafeArea()
                ScrollView {
                    VStack(alignment: .leading, spacing: Theme.Space.l) {
                        Text("Titan reads your own CGM data — it never doses insulin or diagnoses. Wellness insights only.")
                            .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)

                        VStack(alignment: .leading, spacing: Theme.Space.s) {
                            Text("NIGHTSCOUT").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                            Text("Open-source, self-hostable, works with any CGM. Paste your site URL and (optional) token.")
                                .font(.system(size: 11, design: .rounded)).foregroundStyle(Theme.Palette.textFaint)
                            field("Nightscout URL", text: $url, keyboard: .URL)
                            field("Token (optional)", text: $token, keyboard: .default)
                            Button { connect("nightscout") } label: {
                                buttonLabel("Connect Nightscout")
                            }.disabled(url.trimmingCharacters(in: .whitespaces).isEmpty || saving)
                        }

                        VStack(alignment: .leading, spacing: Theme.Space.s) {
                            Text("APPLE HEALTH").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                            Text("If your CGM app writes glucose to Apple Health, Titan reads it from there.")
                                .font(.system(size: 11, design: .rounded)).foregroundStyle(Theme.Palette.textFaint)
                            Button { connect("healthkit") } label: {
                                buttonLabel("Use Apple Health")
                            }.disabled(saving)
                        }
                    }.padding(Theme.Space.l)
                }
            }
            .navigationTitle("Connect a CGM").navigationBarTitleDisplayMode(.inline)
            .toolbar { ToolbarItem(placement: .cancellationAction) { Button("Cancel") { dismiss() } } }
            .toolbarColorScheme(.dark, for: .navigationBar)
        }
    }

    private func connect(_ provider: String) {
        saving = true
        Task {
            let ok = await model.connectGlucose(provider: provider,
                                                nightscoutUrl: provider == "nightscout" ? url.trimmingCharacters(in: .whitespaces) : nil,
                                                token: provider == "nightscout" ? token : nil)
            saving = false
            if ok { dismiss() }
        }
    }

    private func buttonLabel(_ text: LocalizedStringKey) -> some View {
        HStack(spacing: 8) {
            if saving { ProgressView().tint(.white) }
            Text(text).font(Theme.Font.body.weight(.semibold))
        }
        .frame(maxWidth: .infinity).padding(.vertical, 13)
        .background(Theme.Grad.brand, in: RoundedRectangle(cornerRadius: Theme.Radius.chip)).foregroundStyle(.white)
    }

    private func field(_ label: LocalizedStringKey, text: Binding<String>, keyboard: UIKeyboardType) -> some View {
        TextField("", text: text, prompt: Text(label).foregroundStyle(Theme.Palette.textFaint))
            .font(Theme.Font.body).foregroundStyle(Theme.Palette.text).keyboardType(keyboard)
            .autocorrectionDisabled().textInputAutocapitalization(.never)
            .padding(12).background(Theme.Palette.card, in: RoundedRectangle(cornerRadius: Theme.Radius.chip))
            .overlay(RoundedRectangle(cornerRadius: Theme.Radius.chip).strokeBorder(Theme.Palette.cardStroke))
    }
}
