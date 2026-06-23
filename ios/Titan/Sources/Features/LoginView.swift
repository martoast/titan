import SwiftUI

struct LoginView: View {
    @EnvironmentObject var model: AppModel
    @State private var email = ""
    @State private var password = ""

    var body: some View {
        VStack(spacing: 24) {
            Spacer()
            VStack(spacing: 8) {
                Image(systemName: "bolt.heart.fill").font(.system(size: 48)).foregroundStyle(.indigo)
                Text("Titan").font(.system(size: 40, design: .rounded).weight(.heavy))
                Text("Your open-source recovery OS").foregroundStyle(.secondary)
            }

            VStack(spacing: 12) {
                TextField("Email", text: $email)
                    .textContentType(.emailAddress).keyboardType(.emailAddress)
                    .textInputAutocapitalization(.never).autocorrectionDisabled()
                SecureField("Password", text: $password).textContentType(.password)
            }
            .textFieldStyle(.plain)
            .padding(14)
            .background(.white.opacity(0.05), in: RoundedRectangle(cornerRadius: 14))

            if let err = model.error {
                Text(err).font(.footnote).foregroundStyle(.red).multilineTextAlignment(.center)
            }

            Button {
                Task { await model.login(email: email, password: password) }
            } label: {
                HStack { if model.loading { ProgressView().tint(.black) }; Text("Sign in").bold() }
                    .frame(maxWidth: .infinity).padding(.vertical, 14)
            }
            .background(.indigo, in: RoundedRectangle(cornerRadius: 14))
            .foregroundStyle(.black)
            .disabled(model.loading || email.isEmpty || password.isEmpty)

            Spacer()
            Text("Free forever. No subscription.").font(.caption).foregroundStyle(.tertiary)
        }
        .padding(24)
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .background(Color.black.ignoresSafeArea())
    }
}
