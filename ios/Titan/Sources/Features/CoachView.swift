import SwiftUI

@MainActor
final class CoachViewModel: ObservableObject {
    @Published var messages: [ChatMessage] = []
    @Published var input = ""
    @Published var toolStatus: String?
    @Published var sending = false
    private var conversationId: Int?

    func send(api: APIClient) {
        let text = input.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !text.isEmpty, !sending else { return }
        input = ""; sending = true
        messages.append(ChatMessage(role: .user, text: text))
        var assistant = ChatMessage(role: .assistant, text: "", streaming: true)
        messages.append(assistant)
        let idx = messages.count - 1

        Task {
            do {
                for try await ev in api.coachStream(message: text, conversationId: conversationId) {
                    switch ev {
                    case .delta(let t):
                        assistant.text += t; assistant.streaming = true; messages[idx] = assistant
                    case .tool(let label):
                        toolStatus = label
                    case .done(let cid):
                        conversationId = cid ?? conversationId
                    }
                }
            } catch {
                assistant.text += (assistant.text.isEmpty ? "" : "\n\n") + "⚠️ \((error as? APIError)?.errorDescription ?? error.localizedDescription)"
            }
            assistant.streaming = false; messages[idx] = assistant
            toolStatus = nil; sending = false
        }
    }
}

/// The chat — primary surface ("the chat is how you run all of Titan"). Streams the coach's
/// reply token-by-token via SSE, shows tool-status pills, renders markdown.
struct CoachView: View {
    @EnvironmentObject var model: AppModel
    @StateObject private var vm = CoachViewModel()

    var body: some View {
        NavigationStack {
            VStack(spacing: 0) {
                ScrollViewReader { proxy in
                    ScrollView {
                        LazyVStack(spacing: 12) {
                            if vm.messages.isEmpty { greeting }
                            ForEach(vm.messages) { msg in bubble(msg).id(msg.id) }
                            if let tool = vm.toolStatus {
                                Label(tool, systemImage: "gearshape.2.fill")
                                    .font(.caption).foregroundStyle(.secondary)
                                    .frame(maxWidth: .infinity, alignment: .leading)
                            }
                        }
                        .padding(16)
                    }
                    .onChange(of: vm.messages.count) { _, _ in
                        if let last = vm.messages.last { withAnimation { proxy.scrollTo(last.id, anchor: .bottom) } }
                    }
                }
                composer
            }
            .navigationTitle("Coach")
            .background(Color.black.ignoresSafeArea())
        }
    }

    private var greeting: some View {
        VStack(spacing: 8) {
            Image(systemName: "bubble.left.and.text.bubble.right.fill").font(.largeTitle).foregroundStyle(.indigo)
            Text("Ask me anything").font(.headline)
            Text("\"How's my recovery?\" · \"Plan my workout\" · \"Log my breakfast\"")
                .font(.caption).foregroundStyle(.secondary).multilineTextAlignment(.center)
        }
        .padding(.top, 60)
    }

    private func bubble(_ msg: ChatMessage) -> some View {
        let isUser = msg.role == .user
        return HStack {
            if isUser { Spacer(minLength: 40) }
            Text(LocalizedStringKey(msg.text.isEmpty && msg.streaming ? "…" : msg.text))
                .padding(.horizontal, 14).padding(.vertical, 10)
                .background(isUser ? Color.indigo : Color.white.opacity(0.06),
                            in: RoundedRectangle(cornerRadius: 16))
                .foregroundStyle(isUser ? .black : .primary)
            if !isUser { Spacer(minLength: 40) }
        }
    }

    private var composer: some View {
        HStack(spacing: 10) {
            TextField("Message the coach", text: $vm.input, axis: .vertical)
                .lineLimit(1...4)
                .padding(.horizontal, 14).padding(.vertical, 10)
                .background(.white.opacity(0.06), in: Capsule())
            Button {
                vm.send(api: model.api)
            } label: {
                Image(systemName: "arrow.up.circle.fill").font(.system(size: 32))
            }
            .foregroundStyle(.indigo)
            .disabled(vm.input.trimmingCharacters(in: .whitespaces).isEmpty || vm.sending)
        }
        .padding(12)
        .background(.ultraThinMaterial)
    }
}
