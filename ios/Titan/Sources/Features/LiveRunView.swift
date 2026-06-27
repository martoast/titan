import SwiftUI
import MapKit

// MARK: - Live run — see the band's GPS run tracking in the app, in real time

/// Compact "a run is live" banner for the Today screen — pulsing, tappable to open the full tracker.
struct LiveRunBanner: View {
    @EnvironmentObject var model: AppModel
    let onTap: () -> Void
    @State private var pulse = false
    var body: some View {
        Button(action: onTap) {
            HStack(spacing: Theme.Space.m) {
                ZStack {
                    Circle().fill(Theme.Palette.mint.opacity(0.18)).frame(width: 44, height: 44)
                    Image(systemName: "figure.run").font(.title3).foregroundStyle(Theme.Palette.mint)
                }
                VStack(alignment: .leading, spacing: 2) {
                    HStack(spacing: 6) {
                        Circle().fill(Theme.Palette.pink).frame(width: 7, height: 7).opacity(pulse ? 0.3 : 1)
                        Text("LIVE RUN").font(Theme.Font.label.weight(.bold)).tracking(1).foregroundStyle(Theme.Palette.pink)
                    }
                    Text("\(fmtElapsed(model.runElapsedSec)) · \(distLive(model.runDistanceKm)) km")
                        .font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.text).monospacedDigit()
                }
                Spacer()
                if let bpm = model.runLiveBpm {
                    HStack(spacing: 4) {
                        Image(systemName: "heart.fill").font(.caption).foregroundStyle(Theme.Palette.pink).symbolEffect(.pulse, options: .repeating)
                        Text("\(bpm)").font(Theme.Font.num(18)).foregroundStyle(Theme.Palette.text).monospacedDigit()
                    }
                }
                Image(systemName: "chevron.right").font(.caption2).foregroundStyle(Theme.Palette.textFaint)
            }
            .padding(Theme.Space.m)
            .background(Theme.Palette.card, in: RoundedRectangle(cornerRadius: Theme.Radius.card))
            .overlay(RoundedRectangle(cornerRadius: Theme.Radius.card).stroke(Theme.Palette.mint.opacity(0.4), lineWidth: 1))
        }
        .buttonStyle(PressCard())
        .onAppear { withAnimation(.easeInOut(duration: 0.9).repeatForever(autoreverses: true)) { pulse = true } }
    }
}

/// The full live-run tracker — big elapsed/distance/pace, live HR, and the route drawing itself live.
struct LiveRunView: View {
    @EnvironmentObject var model: AppModel
    @Environment(\.dismiss) private var dismiss
    @State private var confirmEnd = false

    var body: some View {
        ZStack(alignment: .top) {
            Theme.Palette.bg.ignoresSafeArea()
            Theme.Grad.glow(Theme.Palette.mint).frame(height: 360).opacity(0.5).ignoresSafeArea(edges: .top)

            ScrollView {
                VStack(spacing: Theme.Space.l) {
                    HStack {
                        HStack(spacing: 7) {
                            Circle().fill(Theme.Palette.pink).frame(width: 8, height: 8)
                            Text(model.runHasGps ? "LIVE RUN" : "LIVE WORKOUT").font(Theme.Font.label.weight(.bold)).tracking(1.5).foregroundStyle(Theme.Palette.pink)
                        }
                        Spacer()
                        Button { Haptic.tap(); dismiss() } label: {
                            Image(systemName: "chevron.down").font(.body.weight(.semibold)).foregroundStyle(Theme.Palette.textDim)
                        }
                    }
                    .padding(.top, Theme.Space.m)

                    // Hero — the clock you're racing.
                    VStack(spacing: 2) {
                        Text(fmtElapsed(model.runElapsedSec)).font(Theme.Font.num(72)).foregroundStyle(.white)
                            .monospacedDigit().contentTransition(.numericText())
                        Text("ELAPSED").font(Theme.Font.micro).tracking(2).foregroundStyle(Theme.Palette.textDim)
                    }
                    .padding(.top, Theme.Space.m)

                    HStack(spacing: Theme.Space.m) {
                        bigStat(distLive(model.runDistanceKm), "km", "Distance", Theme.Palette.mint)
                        bigStat(fmtPaceLive(model.runPaceSecPerKm), "/km", "Pace", Theme.Palette.cyan)
                    }

                    // The route, drawing itself on the map as you move.
                    if model.runHasGps {
                        GlassCard(padding: Theme.Space.s) {
                            VStack(alignment: .leading, spacing: Theme.Space.s) {
                                SectionHeader(title: "Your route", trailing: "\(model.runTrack.count) fixes")
                                LiveRouteMap(track: model.runTrack)
                                    .frame(height: 240)
                                    .clipShape(RoundedRectangle(cornerRadius: Theme.Radius.card))
                                    .padding(.horizontal, 2).padding(.bottom, 2)
                            }
                        }
                    } else {
                        GlassCard {
                            HStack(spacing: Theme.Space.m) {
                                Image(systemName: "location.magnifyingglass").foregroundStyle(Theme.Palette.amber)
                                Text("Searching for GPS… your phone maps the route once it gets a lock.")
                                    .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                            }
                        }
                    }

                    // Live heart.
                    GlassCard {
                        HStack(spacing: Theme.Space.l) {
                            HStack(spacing: 8) {
                                Image(systemName: "heart.fill").font(.title3).foregroundStyle(Theme.Palette.pink)
                                    .symbolEffect(.pulse, options: .repeating)
                                VStack(alignment: .leading, spacing: 0) {
                                    Text(model.runLiveBpm.map { "\($0)" } ?? "—").font(Theme.Font.num(28)).foregroundStyle(Theme.Palette.text).monospacedDigit().contentTransition(.numericText())
                                    Text("BPM").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                                }
                            }
                            Spacer()
                            VStack(alignment: .trailing, spacing: 0) {
                                Text(model.runMaxBpm > 0 ? "\(model.runMaxBpm)" : "—").font(Theme.Font.num(22)).foregroundStyle(Theme.Palette.pink).monospacedDigit()
                                Text("MAX").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                            }
                        }
                    }

                    Text("Tracking live from your band. Keep going — it seals to a full run summary when you finish.")
                        .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint).multilineTextAlignment(.center)

                    Button(role: .destructive) { Haptic.rigid(); confirmEnd = true } label: {
                        Text("End run").font(Theme.Font.body.weight(.semibold))
                            .frame(maxWidth: .infinity).padding(.vertical, 13)
                            .background(Theme.Palette.card, in: Capsule()).overlay(Capsule().stroke(Theme.Palette.cardStroke))
                            .foregroundStyle(Theme.Palette.pink)
                    }
                    .padding(.bottom, Theme.Space.l)
                }
                .padding(.horizontal, Theme.Space.m)
            }
        }
        .confirmationDialog("End this run? This finishes it on your band too and saves the summary.",
                            isPresented: $confirmEnd, titleVisibility: .visible) {
            Button("End run", role: .destructive) { model.endRun(); dismiss() }
            Button("Keep going", role: .cancel) {}
        }
    }

    private func bigStat(_ value: String, _ unit: String, _ label: String, _ color: Color) -> some View {
        GlassCard(padding: Theme.Space.l) {
            VStack(alignment: .leading, spacing: 2) {
                HStack(alignment: .firstTextBaseline, spacing: 3) {
                    Text(value).font(Theme.Font.num(34)).foregroundStyle(color).monospacedDigit().contentTransition(.numericText())
                    Text(unit).font(Theme.Font.label).foregroundStyle(Theme.Palette.textFaint)
                }
                Text(label.uppercased()).font(Theme.Font.micro).tracking(0.6).foregroundStyle(Theme.Palette.textDim)
            }
            .frame(maxWidth: .infinity, alignment: .leading)
        }
    }
}

/// The accumulating GPS trace, normalized + north-up, drawn as a live polyline.
struct RouteTrace: View {
    let points: [CGPoint]
    var color: Color = Theme.Palette.mint
    var body: some View {
        Canvas { ctx, size in
            guard points.count > 1 else { return }
            let xs = points.map(\.x), ys = points.map(\.y)
            let minX = xs.min()!, maxX = xs.max()!, minY = ys.min()!, maxY = ys.max()!
            let span = max(1e-6, max(maxX - minX, maxY - minY))   // square aspect → true route shape
            let pad: CGFloat = 18
            let w = size.width - pad * 2, h = size.height - pad * 2
            func project(_ p: CGPoint) -> CGPoint {
                let nx = (p.x - minX) / span, ny = (p.y - minY) / span
                return CGPoint(x: pad + nx * w, y: pad + (1 - ny) * h)   // flip y so north is up
            }
            var path = Path(); path.addLines(points.map(project))
            ctx.stroke(path, with: .color(color), style: StrokeStyle(lineWidth: 3.5, lineCap: .round, lineJoin: .round))
            if let f = points.first.map(project) {
                ctx.fill(Path(ellipseIn: CGRect(x: f.x - 4, y: f.y - 4, width: 8, height: 8)), with: .color(.white.opacity(0.75)))
            }
            if let l = points.last.map(project) {
                ctx.fill(Path(ellipseIn: CGRect(x: l.x - 6, y: l.y - 6, width: 12, height: 12)), with: .color(color))
                ctx.fill(Path(ellipseIn: CGRect(x: l.x - 3, y: l.y - 3, width: 6, height: 6)), with: .color(.white))
            }
        }
    }
}

/// A live MapKit route: the polyline grows + the camera follows your latest fix as the band streams
/// GPS. Used by the live run (the path drawing itself as you move) and the GPS test (a single pin =
/// "you are here"). track points are CGPoint(x: lon, y: lat).
struct LiveRouteMap: View {
    let track: [CGPoint]
    var interactive: Bool = true
    @State private var cam: MapCameraPosition = .automatic

    private var coords: [CLLocationCoordinate2D] {
        track.map { CLLocationCoordinate2D(latitude: $0.y, longitude: $0.x) }
    }

    var body: some View {
        Map(position: $cam, interactionModes: interactive ? .all : []) {
            if coords.count > 1 {
                MapPolyline(coordinates: coords)
                    .stroke(Theme.Palette.mint, style: StrokeStyle(lineWidth: 4, lineCap: .round, lineJoin: .round))
            }
            if let last = coords.last {
                Annotation("", coordinate: last) {
                    ZStack {
                        Circle().fill(Theme.Palette.mint.opacity(0.3)).frame(width: 24, height: 24)
                        Circle().fill(Theme.Palette.mint).frame(width: 13, height: 13)
                        Circle().stroke(.white, lineWidth: 2).frame(width: 13, height: 13)
                    }
                }
            }
        }
        .mapStyle(.standard(elevation: .flat))
        .onAppear { recenter() }
        .onChange(of: track.count) { _, _ in recenter() }
    }

    private func recenter() {
        guard let last = coords.last else { return }
        withAnimation(.easeInOut(duration: 0.4)) {
            cam = .region(MKCoordinateRegion(center: last, latitudinalMeters: 600, longitudinalMeters: 600))
        }
    }
}

// MARK: helpers

private func fmtElapsed(_ s: Int) -> String {
    let h = s / 3600, m = (s % 3600) / 60, sec = s % 60
    return h > 0 ? String(format: "%d:%02d:%02d", h, m, sec) : String(format: "%d:%02d", m, sec)
}

private func fmtPaceLive(_ secPerKm: Int) -> String {
    secPerKm > 0 ? String(format: "%d:%02d", secPerKm / 60, secPerKm % 60) : "—:—"
}

private func distLive(_ km: Double) -> String { String(format: "%.2f", km) }
