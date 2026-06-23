import SwiftUI

struct ProfileView: View {
    @EnvironmentObject var model: AppModel
    @State private var confirmLogout = false

    var body: some View {
        VStack(spacing: 16) {
            Card {
                HStack(spacing: 14) {
                    Image(systemName: "person.crop.circle.fill").font(.system(size: 44)).foregroundStyle(.indigo)
                    VStack(alignment: .leading) {
                        Text(model.user?.name ?? "Titan user").font(.headline)
                        if let email = model.user?.email { Text(email).font(.caption).foregroundStyle(.secondary) }
                    }
                }
            }

            Card("Band") {
                HStack {
                    Label(model.isBandPaired ? "Paired" : "Not paired", systemImage: "applewatch")
                    Spacer()
                    Circle().fill(model.bandConnected ? .green : .gray).frame(width: 10, height: 10)
                }
                .font(.subheadline)
            }

            Card("About") {
                Label("Open-source · free forever", systemImage: "heart.fill").foregroundStyle(.pink)
                Text("Titan is a subscription-free recovery OS. Your data is yours.")
                    .font(.caption).foregroundStyle(.secondary)
            }

            Button(role: .destructive) { confirmLogout = true } label: {
                Text("Sign out").frame(maxWidth: .infinity).padding(.vertical, 12)
            }
            .background(.white.opacity(0.05), in: RoundedRectangle(cornerRadius: 12))
            .confirmationDialog("Sign out?", isPresented: $confirmLogout, titleVisibility: .visible) {
                Button("Sign out", role: .destructive) { Task { await model.logout() } }
            }
        }
        .screen("You")
    }
}
