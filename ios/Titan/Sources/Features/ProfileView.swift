import SwiftUI

struct ProfileView: View {
    @EnvironmentObject var model: AppModel
    @State private var confirmLogout = false
    @State private var showBand = false
    @State private var showEditProfile = false
    @State private var showBody = false

    var body: some View {
        VStack(spacing: Theme.Space.m) {
            GlassCard(padding: Theme.Space.l) {
                HStack(spacing: Theme.Space.m) {
                    ZStack {
                        Circle().fill(Theme.Grad.brand).frame(width: 60, height: 60)
                        Text(initials).font(Theme.Font.num(22)).foregroundStyle(.white)
                    }
                    VStack(alignment: .leading, spacing: 3) {
                        Text(model.user?.name ?? "Titan user").font(Theme.Font.title).foregroundStyle(Theme.Palette.text)
                        if let email = model.user?.email { Text(email).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim) }
                    }
                    Spacer()
                }
            }

            Button { Haptic.tap(); showEditProfile = true } label: {
                GlassCard {
                    HStack {
                        Label("Edit profile", systemImage: "person.text.rectangle").font(Theme.Font.body).foregroundStyle(Theme.Palette.text)
                        Spacer()
                        Text("Goal · coaching · diet · cycle").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
                        Image(systemName: "chevron.right").font(.caption2).foregroundStyle(Theme.Palette.textFaint)
                    }
                }
            }
            .buttonStyle(PressCard())
            .sheet(isPresented: $showEditProfile) { EditProfileView() }

            Button { Haptic.tap(); showBody = true } label: {
                GlassCard {
                    HStack {
                        Label("Body & progress", systemImage: "figure.stand").font(Theme.Font.body).foregroundStyle(Theme.Palette.text)
                        Spacer()
                        Text("Weight trend · progress photos").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
                        Image(systemName: "chevron.right").font(.caption2).foregroundStyle(Theme.Palette.textFaint)
                    }
                }
            }
            .buttonStyle(PressCard())
            .sheet(isPresented: $showBody) { BodyView() }

            Button { Haptic.tap(); showBand = true } label: {
                GlassCard {
                    HStack {
                        Label("Your Titan band", systemImage: "applewatch").font(Theme.Font.body).foregroundStyle(Theme.Palette.text)
                        Spacer()
                        HStack(spacing: 7) {
                            PulseDot(on: model.bandConnected)
                            Text(model.bandConnected ? "Connected" : (model.isBandPaired ? "Paired" : "Not paired"))
                                .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                            Image(systemName: "chevron.right").font(.caption2).foregroundStyle(Theme.Palette.textFaint)
                        }
                    }
                }
            }
            .buttonStyle(PressCard())
            .sheet(isPresented: $showBand) { DevicesView() }

            // Apple Health
            Button { Haptic.tap(); Task { model.healthConnected ? await model.syncAppleHealth(days: 30) : await model.connectAppleHealth() } } label: {
                GlassCard {
                    VStack(alignment: .leading, spacing: model.healthConnected ? 6 : 0) {
                        HStack {
                            Label("Apple Health", systemImage: "heart.text.square.fill").font(Theme.Font.body).foregroundStyle(Theme.Palette.text)
                            Spacer()
                            HStack(spacing: 7) {
                                if model.healthSyncing { ProgressView().controlSize(.mini).tint(Theme.Palette.pink) }
                                else { PulseDot(on: model.healthConnected) }
                                Text(model.healthConnected ? "Synced" : "Connect").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                                Image(systemName: "chevron.right").font(.caption2).foregroundStyle(Theme.Palette.textFaint)
                            }
                        }
                        if model.healthConnected {
                            Text("Tip: enable Health → Heart → AFib History to record HRV through the night — it makes your recovery score far more accurate.")
                                .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
                        }
                    }
                }
            }
            .buttonStyle(PressCard())
            .task { await model.loadHealthStatus() }

            GlassCard {
                VStack(alignment: .leading, spacing: Theme.Space.s) {
                    Label("Open-source · free forever", systemImage: "heart.fill").font(Theme.Font.body).foregroundStyle(Theme.Palette.pink)
                    Text("Titan is a subscription-free recovery OS. Your data is yours, always.")
                        .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                }
            }

            Button(role: .destructive) { Haptic.warning(); confirmLogout = true } label: {
                Text("Sign out").font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.pink)
                    .frame(maxWidth: .infinity).padding(.vertical, 14)
                    .background(Theme.Palette.card, in: RoundedRectangle(cornerRadius: Theme.Radius.chip))
            }
            .confirmationDialog("Sign out?", isPresented: $confirmLogout, titleVisibility: .visible) {
                Button("Sign out", role: .destructive) { Task { await model.logout() } }
            }
            Color.clear.frame(height: 8)
        }
        .titanScreen("You")
    }

    private var initials: String {
        let parts = (model.user?.name ?? "T").split(separator: " ")
        return parts.prefix(2).compactMap { $0.first.map(String.init) }.joined().uppercased()
    }
}
