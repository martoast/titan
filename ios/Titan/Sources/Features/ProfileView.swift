import SwiftUI

struct ProfileView: View {
    @EnvironmentObject var model: AppModel
    @State private var confirmLogout = false
    @State private var showDeleteAccount = false
    @State private var showBand = false
    @State private var showEditProfile = false
    @State private var showBody = false
    @State private var showCommunity = false
    @AppStorage("barcodeScanEnabled") private var barcodeEnabled = true

    var body: some View {
        VStack(spacing: Theme.Space.m) {
            GlassCard(padding: Theme.Space.l) {
                HStack(spacing: Theme.Space.m) {
                    ZStack {
                        Circle().fill(Theme.Grad.brand).frame(width: 60, height: 60)
                        Text(initials).font(Theme.Font.num(22)).foregroundStyle(.white)
                    }
                    VStack(alignment: .leading, spacing: 3) {
                        Text(model.user?.name ?? String(localized: "Titan user")).font(Theme.Font.title).foregroundStyle(Theme.Palette.text)
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
                        Label("Your band", systemImage: "applewatch").font(Theme.Font.body).foregroundStyle(Theme.Palette.text)
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

            LanguageRow()

            if AppFeatures.community {
                Button { Haptic.tap(); showCommunity = true } label: {
                    GlassCard {
                        HStack {
                            Label("Community & sharing", systemImage: "person.2.fill").font(Theme.Font.body).foregroundStyle(Theme.Palette.text)
                            Spacer()
                            HStack(spacing: 7) {
                                PulseDot(on: model.communityEnabled)
                                Text(model.communityEnabled ? "Public" : "Private")
                                    .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                                Image(systemName: "chevron.right").font(.caption2).foregroundStyle(Theme.Palette.textFaint)
                            }
                        }
                    }
                }
                .buttonStyle(PressCard())
                .sheet(isPresented: $showCommunity) { CommunitySettingsView() }
                .task { await model.loadCommunitySettings() }
            }

            GlassCard {
                Toggle(isOn: $barcodeEnabled) {
                    HStack(spacing: Theme.Space.s) {
                        Image(systemName: "barcode.viewfinder").foregroundStyle(Theme.Palette.cyan)
                        VStack(alignment: .leading, spacing: 1) {
                            Text("Barcode scanning").font(Theme.Font.body).foregroundStyle(Theme.Palette.text)
                            Text("Scan packaged food for exact macros").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                        }
                    }
                }
                .tint(Theme.Palette.cyan)
            }

            #if DEBUG
            Button { Haptic.tap(); model.simulateLiveRun() } label: {
                GlassCard {
                    HStack {
                        Label("Simulate live run", systemImage: "figure.run.circle.fill").font(Theme.Font.body).foregroundStyle(Theme.Palette.mint)
                        Spacer()
                        Text("DEBUG").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
                    }
                }
            }
            .buttonStyle(PressCard())
            #endif

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

            // App Store Guideline 5.1.1(v): an app that can create accounts must let a person
            // delete theirs from INSIDE the app, and deactivation is explicitly not enough.
            // Deliberately quiet — a destructive action should be findable, not inviting.
            Button { Haptic.warning(); showDeleteAccount = true } label: {
                Text("Delete account").font(Theme.Font.micro.weight(.semibold))
                    .foregroundStyle(Theme.Palette.textFaint)
                    .frame(maxWidth: .infinity).padding(.vertical, 10)
            }
            .sheet(isPresented: $showDeleteAccount) { DeleteAccountSheet().environmentObject(model) }

            Color.clear.frame(height: 8)
        }
        .titanScreen(String(localized: "You"))
    }

    private var initials: String {
        let parts = (model.user?.name ?? "T").split(separator: " ")
        return parts.prefix(2).compactMap { $0.first.map(String.init) }.joined().uppercased()
    }
}

/// Confirm-and-erase sheet for account deletion (App Store 5.1.1(v)).
///
/// It asks for the password because this cannot be undone and a borrowed or stolen phone must not
/// be able to wipe somebody's entire health history with two taps. It also states plainly what
/// goes, since Titan holds sleep, heart rate, cycle and body photos rather than a shopping list.
struct DeleteAccountSheet: View {
    @EnvironmentObject var model: AppModel
    @Environment(\.dismiss) private var dismiss
    @State private var password = ""
    @State private var confirming = false
    @FocusState private var focused: Bool

    var body: some View {
        NavigationStack {
            ZStack {
                Theme.Palette.bg.ignoresSafeArea()
                VStack(alignment: .leading, spacing: Theme.Space.m) {
                    Text("This erases your account and every record in it — sleep, recovery, workouts, meals, photos and your coach history. It cannot be undone.")
                        .font(Theme.Font.body).foregroundStyle(Theme.Palette.textDim)

                    SecureField("Password", text: $password)
                        .font(Theme.Font.body).textContentType(.password).focused($focused)
                        .padding(Theme.Space.m)
                        .background(Theme.Palette.bg2, in: RoundedRectangle(cornerRadius: Theme.Radius.chip))
                        .overlay(RoundedRectangle(cornerRadius: Theme.Radius.chip)
                            .strokeBorder(Theme.Palette.cardStroke, lineWidth: 1))

                    if let err = model.error {
                        Text(err).font(Theme.Font.micro).foregroundStyle(Theme.Palette.pink)
                    }

                    Button(role: .destructive) {
                        Haptic.warning()
                        focused = false
                        confirming = true
                    } label: {
                        HStack {
                            if model.loading { ProgressView().tint(.white) }
                            Text(model.loading ? "Deleting…" : "Delete my account").font(Theme.Font.body.weight(.bold))
                        }
                        .frame(maxWidth: .infinity).padding(.vertical, 15)
                        .background(password.isEmpty ? AnyShapeStyle(Theme.Palette.card) : AnyShapeStyle(Theme.Palette.pink),
                                    in: RoundedRectangle(cornerRadius: Theme.Radius.chip))
                        .foregroundStyle(.white)
                    }
                    .disabled(password.isEmpty || model.loading)
                    .confirmationDialog("Delete your account for good?", isPresented: $confirming, titleVisibility: .visible) {
                        Button("Delete forever", role: .destructive) {
                            Task {
                                // On success the model clears the session, which swaps the whole
                                // UI back to LoginView — so only dismiss on failure, where the
                                // person needs to see why and try again.
                                if await model.deleteAccount(password: password) { dismiss() }
                            }
                        }
                        Button("Keep my account", role: .cancel) {}
                    }

                    Spacer()
                }
                .padding(Theme.Space.l)
            }
            .navigationTitle(String(localized: "Delete account"))
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { model.error = nil; dismiss() }
                }
            }
        }
        .onAppear { model.error = nil; focused = true }
    }
}
