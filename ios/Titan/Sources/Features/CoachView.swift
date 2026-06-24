import SwiftUI

@MainActor
final class CoachViewModel: ObservableObject {
    @Published var messages: [ChatMessage] = []
    @Published var input = ""
    @Published var toolStatus: String?
    @Published var sending = false
    private var conversationId: Int?

    func send(api: APIClient, text overrideText: String? = nil) {
        let text = (overrideText ?? input).trimmingCharacters(in: .whitespacesAndNewlines)
        guard !text.isEmpty, !sending else { return }
        Haptic.tap()
        input = ""; sending = true
        messages.append(ChatMessage(role: .user, text: text))
        var assistant = ChatMessage(role: .assistant, text: "", streaming: true)
        messages.append(assistant)
        let idx = messages.count - 1

        Task {
            do {
                for try await ev in api.coachStream(message: text, conversationId: conversationId) {
                    switch ev {
                    case .delta(let t): assistant.text += t; messages[idx] = assistant
                    case .tool(let label): withAnimation(Theme.Motion.snappy) { toolStatus = label }
                    case .done(let cid): conversationId = cid ?? conversationId
                    }
                }
            } catch {
                assistant.text += (assistant.text.isEmpty ? "" : "\n\n") + "⚠️ \((error as? APIError)?.errorDescription ?? error.localizedDescription)"
            }
            assistant.streaming = false; messages[idx] = assistant
            toolStatus = nil; sending = false
            Haptic.soft()
        }
    }
}

/// The chat — primary surface. Streams the coach token-by-token, shows tool pills, renders
/// markdown. Built to feel like a premium messaging app.
struct CoachView: View {
    @EnvironmentObject var model: AppModel
    @StateObject private var vm = CoachViewModel()
    @FocusState private var focused: Bool

    private let suggestions = ["How's my recovery?", "Plan today's workout", "How did I sleep?", "Log my breakfast"]

    var body: some View {
        NavigationStack {
            ZStack(alignment: .top) {
                Theme.Palette.bg.ignoresSafeArea()
                Theme.Grad.glow(Theme.Palette.indigo).frame(height: 280).opacity(0.5).ignoresSafeArea(edges: .top)

                VStack(spacing: 0) {
                    ScrollViewReader { proxy in
                        ScrollView {
                            LazyVStack(spacing: Theme.Space.s) {
                                if vm.messages.isEmpty { empty }
                                ForEach(vm.messages) { Bubble(msg: $0).id($0.id) }
                                if let tool = vm.toolStatus { toolPill(tool).id("tool") }
                                Color.clear.frame(height: 4).id("bottom")
                            }
                            .padding(Theme.Space.m)
                        }
                        .scrollIndicators(.hidden)
                        .scrollDismissesKeyboard(.interactively)
                        // Tap anywhere in the conversation to dismiss the keyboard (buttons/chips
                        // still get their taps first).
                        .onTapGesture { focused = false }
                        .onChange(of: vm.messages.last?.text) { _, _ in withAnimation(.easeOut(duration: 0.2)) { proxy.scrollTo("bottom", anchor: .bottom) } }
                        .onChange(of: vm.toolStatus) { _, _ in withAnimation { proxy.scrollTo("bottom", anchor: .bottom) } }
                    }
                    composer
                }
            }
            .navigationTitle("Coach")
            .toolbarColorScheme(.dark, for: .navigationBar)
        }
    }

    private var empty: some View {
        VStack(spacing: Theme.Space.m) {
            ZStack {
                Circle().fill(Theme.Grad.brand).frame(width: 72, height: 72)
                    .shadow(color: Theme.Palette.indigo.opacity(0.6), radius: 16)
                Image(systemName: "sparkles").font(.system(size: 30, weight: .semibold)).foregroundStyle(.white)
            }
            Text("Your coach").font(Theme.Font.display(24)).foregroundStyle(Theme.Palette.text)
            Text("Run all of Titan from here.").font(Theme.Font.body).foregroundStyle(Theme.Palette.textDim)
            FlowChips(items: suggestions) { vm.send(api: model.api, text: $0) }
                .padding(.top, Theme.Space.s)
        }
        .frame(maxWidth: .infinity).padding(.top, 70)
    }

    private func toolPill(_ s: String) -> some View {
        HStack(spacing: 7) {
            ProgressView().controlSize(.mini).tint(Theme.Palette.cyan)
            Text(s).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
        }
        .padding(.horizontal, 12).padding(.vertical, 7)
        .background(Theme.Palette.card, in: Capsule())
        .frame(maxWidth: .infinity, alignment: .leading)
        .transition(.opacity.combined(with: .move(edge: .leading)))
    }

    private var composer: some View {
        HStack(spacing: Theme.Space.s) {
            TextField("Message", text: $vm.input, axis: .vertical)
                .focused($focused).lineLimit(1...5).font(Theme.Font.body)
                .padding(.horizontal, Theme.Space.m).padding(.vertical, 11)
                .background(Theme.Palette.bg2, in: Capsule())
                .overlay(Capsule().strokeBorder(Theme.Palette.cardStroke))
            Button { vm.send(api: model.api); focused = false } label: {
                Image(systemName: "arrow.up").font(.system(size: 17, weight: .bold)).foregroundStyle(.white)
                    .frame(width: 38, height: 38)
                    .background(canSend ? AnyShapeStyle(Theme.Grad.brand) : AnyShapeStyle(Theme.Palette.card), in: Circle())
            }
            .disabled(!canSend).scaleEffect(canSend ? 1 : 0.9).animation(Theme.Motion.snappy, value: canSend)
        }
        .padding(Theme.Space.s)
        .background(.ultraThinMaterial)
        .overlay(Rectangle().fill(Theme.Palette.cardStroke).frame(height: 0.5), alignment: .top)
    }
    private var canSend: Bool { !vm.input.trimmingCharacters(in: .whitespaces).isEmpty && !vm.sending }
}

private struct Bubble: View {
    let msg: ChatMessage
    var isUser: Bool { msg.role == .user }
    var body: some View {
        HStack {
            if isUser { Spacer(minLength: 44) }
            Group {
                if msg.text.isEmpty && msg.streaming { TypingDots() }
                else { Text(.init(msg.text)).font(Theme.Font.body) }
            }
            .padding(.horizontal, 14).padding(.vertical, 10)
            .foregroundStyle(isUser ? .white : Theme.Palette.text)
            .background(
                isUser ? AnyShapeStyle(Theme.Grad.brand) : AnyShapeStyle(Theme.Palette.card),
                in: RoundedRectangle(cornerRadius: 20, style: .continuous)
            )
            .overlay(isUser ? nil : RoundedRectangle(cornerRadius: 20).strokeBorder(Theme.Palette.cardStroke))
            if !isUser { Spacer(minLength: 44) }
        }
        .transition(.asymmetric(insertion: .scale(scale: 0.9).combined(with: .opacity), removal: .opacity))
    }
}

private struct TypingDots: View {
    @State private var t = false
    var body: some View {
        HStack(spacing: 4) {
            ForEach(0..<3) { i in
                Circle().fill(Theme.Palette.textDim).frame(width: 6, height: 6)
                    .scaleEffect(t ? 1 : 0.5).opacity(t ? 1 : 0.4)
                    .animation(.easeInOut(duration: 0.5).repeatForever().delay(Double(i) * 0.15), value: t)
            }
        }.onAppear { t = true }
    }
}

/// Simple wrapping chip row for suggestions.
private struct FlowChips: View {
    let items: [String]
    let onTap: (String) -> Void
    var body: some View {
        VStack(spacing: Theme.Space.s) {
            ForEach(items, id: \.self) { s in
                Button { Haptic.tap(); onTap(s) } label: {
                    Text(s).font(Theme.Font.body).foregroundStyle(Theme.Palette.text)
                        .padding(.horizontal, Theme.Space.m).padding(.vertical, 10)
                        .frame(maxWidth: .infinity)
                        .background(Theme.Palette.card, in: Capsule())
                        .overlay(Capsule().strokeBorder(Theme.Palette.cardStroke))
                }.buttonStyle(PressCard())
            }
        }.padding(.horizontal, Theme.Space.xl)
    }
}
