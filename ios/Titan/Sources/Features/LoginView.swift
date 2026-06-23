import SwiftUI

struct LoginView: View {
    @EnvironmentObject var model: AppModel
    @State private var email = ""
    @State private var password = ""
    @State private var appeared = false
    @FocusState private var focus: Field?
    enum Field { case email, password }

    var body: some View {
        ZStack {
            Theme.Palette.bg.ignoresSafeArea()
            Theme.Grad.glow(Theme.Palette.indigo).frame(height: 460).opacity(0.6)
                .offset(y: -180).ignoresSafeArea()

            VStack(spacing: Theme.Space.l) {
                Spacer()
                VStack(spacing: Theme.Space.m) {
                    ZStack {
                        Circle().fill(Theme.Grad.brand).frame(width: 84, height: 84)
                            .shadow(color: Theme.Palette.indigo.opacity(0.7), radius: 24)
                        Image(systemName: "bolt.heart.fill").font(.system(size: 38, weight: .bold)).foregroundStyle(.white)
                    }
                    .scaleEffect(appeared ? 1 : 0.7)
                    Text("Titan").font(Theme.Font.display(44)).foregroundStyle(Theme.Palette.text)
                    Text("Your open-source recovery OS").font(Theme.Font.body).foregroundStyle(Theme.Palette.textDim)
                }
                .opacity(appeared ? 1 : 0).offset(y: appeared ? 0 : 20)

                VStack(spacing: Theme.Space.s) {
                    field("Email", text: $email, field: .email)
                        .keyboardType(.emailAddress).textContentType(.emailAddress)
                        .textInputAutocapitalization(.never).autocorrectionDisabled()
                    field("Password", text: $password, field: .password, secure: true)
                        .textContentType(.password)
                }

                if let err = model.error {
                    Text(err).font(Theme.Font.micro).foregroundStyle(Theme.Palette.pink).multilineTextAlignment(.center)
                        .transition(.opacity)
                }

                Button {
                    Haptic.tap(); focus = nil
                    Task { await model.login(email: email, password: password) }
                } label: {
                    HStack {
                        if model.loading { ProgressView().tint(.white) }
                        Text(model.loading ? "Signing in…" : "Sign in").font(Theme.Font.body.weight(.bold))
                    }
                    .frame(maxWidth: .infinity).padding(.vertical, 15)
                    .background(canSubmit ? AnyShapeStyle(Theme.Grad.brand) : AnyShapeStyle(Theme.Palette.card),
                                in: RoundedRectangle(cornerRadius: Theme.Radius.chip))
                    .foregroundStyle(.white)
                }
                .disabled(!canSubmit)

                Spacer()
                Label("Free forever · no subscription", systemImage: "heart.fill")
                    .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
            }
            .padding(Theme.Space.l)
        }
        .animation(Theme.Motion.spring, value: appeared)
        .animation(Theme.Motion.snappy, value: model.error)
        .onAppear { appeared = true }
    }

    private var canSubmit: Bool { !model.loading && !email.isEmpty && !password.isEmpty }

    @ViewBuilder
    private func field(_ placeholder: String, text: Binding<String>, field: Field, secure: Bool = false) -> some View {
        Group {
            if secure { SecureField(placeholder, text: text) } else { TextField(placeholder, text: text) }
        }
        .font(Theme.Font.body).focused($focus, equals: field)
        .padding(Theme.Space.m)
        .background(Theme.Palette.bg2, in: RoundedRectangle(cornerRadius: Theme.Radius.chip))
        .overlay(RoundedRectangle(cornerRadius: Theme.Radius.chip)
            .strokeBorder(focus == field ? Theme.Palette.indigo : Theme.Palette.cardStroke, lineWidth: focus == field ? 1.5 : 1))
        .animation(Theme.Motion.snappy, value: focus)
    }
}
