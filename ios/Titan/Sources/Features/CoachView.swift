import SwiftUI
import UIKit

@MainActor
final class CoachViewModel: ObservableObject {
    @Published var messages: [ChatMessage] = []
    @Published var input = ""
    @Published var toolStatus: String?
    @Published var sending = false
    @Published var transcribing = false        // voice clip uploading → text
    @Published var pendingImage: Data?         // photo staged in the composer, awaiting send

    // The active thread — persisted so a cold relaunch can reload it and surface any reply that
    // finished while the app was away.
    private let convKey = "coach.conversationId"
    private var conversationId: Int? {
        get { let v = UserDefaults.standard.integer(forKey: convKey); return v == 0 ? nil : v }
        set { UserDefaults.standard.set(newValue ?? 0, forKey: convKey) }
    }
    private var pollTask: Task<Void, Never>?

    /// Shrink a captured photo to a chat-bubble thumbnail (≤480 px, modest JPEG). Used only for the copy
    /// retained in `messages`; the original full-res Data is what gets uploaded to the coach.
    static func bubbleThumbnail(_ data: Data, maxDim: CGFloat = 480) -> Data {
        guard let img = UIImage(data: data) else { return data }
        let longest = max(img.size.width, img.size.height)
        guard longest > maxDim else {
            // already small enough in dimensions; still recompress if the byte payload is large
            return data.count > 200_000 ? (img.jpegData(compressionQuality: 0.6) ?? data) : data
        }
        let scale = maxDim / longest
        let size = CGSize(width: img.size.width * scale, height: img.size.height * scale)
        let fmt = UIGraphicsImageRendererFormat.default(); fmt.scale = 1; fmt.opaque = true
        let scaled = UIGraphicsImageRenderer(size: size, format: fmt).image { _ in
            img.draw(in: CGRect(origin: .zero, size: size))
        }
        return scaled.jpegData(compressionQuality: 0.6) ?? data
    }

    /// Send button entry point. Text and/or a staged photo go through ONE durable path — the server
    /// persists the turn and generates the reply on a queue, so you can send and lock the phone and the
    /// coach still finishes. Chips pass their text via `text`.
    func submit(api: APIClient, text overrideText: String? = nil) {
        let text = (overrideText ?? input).trimmingCharacters(in: .whitespacesAndNewlines)
        let image = pendingImage
        guard (!text.isEmpty || image != nil), !sending else { return }
        Haptic.tap()
        input = ""; pendingImage = nil; sending = true

        // Optimistic bubbles: the user's message (thumbnail for a photo) + a pending assistant bubble.
        messages.append(ChatMessage(role: .user, text: text, imageData: image.map { Self.bubbleThumbnail($0) }))
        let assistant = ChatMessage(role: .assistant, text: "", streaming: true)
        messages.append(assistant)

        pollTask?.cancel()
        pollTask = Task { await deliver(api: api, text: text, image: image, localId: assistant.id) }
    }

    /// Chip / programmatic send.
    func send(api: APIClient, text: String) { submit(api: api, text: text) }

    /// POST the message durably (kept alive briefly so a photo upload lands even if the phone locks),
    /// then poll the pending assistant row until the reply is complete.
    private func deliver(api: APIClient, text: String, image: Data?, localId: UUID) async {
        let bg = UIApplication.shared.beginBackgroundTask(withName: "coach-send")
        func endBG() { if bg != .invalid { UIApplication.shared.endBackgroundTask(bg) } }

        do {
            let res = try await api.coachSendAsync(message: text, imageData: image, conversationId: conversationId)
            endBG()
            if let cid = res.conversation_id { conversationId = cid }
            guard let pid = res.pending_message_id else {
                failBubble(localId, String(localized: "Couldn't reach your coach — please try again.")); sending = false; return
            }
            setServerId(localId, pid)
            await pollReply(api: api, messageId: pid, localId: localId)
        } catch {
            endBG()
            failBubble(localId, "⚠️ \((error as? APIError)?.errorDescription ?? error.localizedDescription)")
        }
        sending = false
        Haptic.soft()
    }

    /// Poll a message until it's `complete`/`failed`, updating its bubble as the text grows. Transient
    /// errors (e.g. the app suspended) don't fail the bubble — `reconcile()` resumes on reopen.
    private func pollReply(api: APIClient, messageId: Int, localId: UUID) async {
        while !Task.isCancelled {
            if let st = try? await api.coachMessage(messageId) {
                applyState(localId, content: st.content, status: st.status)
                if st.status == "complete" || st.status == "failed" { return }
            }
            try? await Task.sleep(nanoseconds: 800_000_000)
        }
    }

    // MARK: reconcile-on-reopen

    /// Called on appear + when the app returns to the foreground. On a cold launch it reloads the
    /// thread from the server (so a reply finished while away is just there); otherwise it resumes
    /// polling a reply that was still generating when we last backgrounded.
    func reconcile(api: APIClient) {
        if messages.isEmpty, conversationId != nil {
            pollTask?.cancel()
            pollTask = Task { await loadHistory(api: api) }
            return
        }
        resumePendingPoll(api: api)
    }

    /// Stop polling while backgrounded (requests would just fail); reconcile() restarts it.
    func pause() { pollTask?.cancel() }

    private func loadHistory(api: APIClient) async {
        guard let cid = conversationId, let rows = try? await api.coachHistory(conversationId: cid) else { return }
        messages = rows.filter { $0.role == "user" || $0.role == "assistant" }.map { r in
            let (body, url) = Self.splitPhoto(r.content)
            var m = ChatMessage(role: r.role == "user" ? .user : .assistant, text: body)
            m.imageURL = url
            m.serverId = r.id
            m.streaming = (r.status == "pending" || r.status == "streaming")
            return m
        }
        resumePendingPoll(api: api)
    }

    private func resumePendingPoll(api: APIClient) {
        guard let m = messages.last, m.role == .assistant, m.streaming, let sid = m.serverId else { return }
        sending = true
        pollTask?.cancel()
        pollTask = Task { await pollReply(api: api, messageId: sid, localId: m.id); sending = false }
    }

    // MARK: bubble mutation by local id (robust to the array being rebuilt)

    private func index(_ id: UUID) -> Int? { messages.firstIndex { $0.id == id } }
    private func setServerId(_ id: UUID, _ sid: Int) { if let i = index(id) { messages[i].serverId = sid } }
    private func failBubble(_ id: UUID, _ text: String) {
        guard let i = index(id) else { return }
        messages[i].text = text; messages[i].streaming = false
    }
    private func applyState(_ id: UUID, content: String, status: String?) {
        guard let i = index(id) else { return }
        if !content.isEmpty { messages[i].text = content }
        messages[i].streaming = (status == "pending" || status == "streaming")
    }

    /// Pull a `![photo](url)` out of a stored message → (remaining text, image url).
    static func splitPhoto(_ content: String) -> (String, String?) {
        guard let open = content.range(of: "]("), let close = content.range(of: ")", range: open.upperBound..<content.endIndex),
              content[content.startIndex...].contains("![") else { return (content, nil) }
        let url = String(content[open.upperBound..<close.lowerBound])
        var text = content
        if let mark = content.range(of: "![") { text.removeSubrange(mark.lowerBound..<close.upperBound) }
        return (text.trimmingCharacters(in: .whitespacesAndNewlines), url.isEmpty ? nil : url)
    }

    /// Upload a recorded voice clip → transcription → append into the composer (we don't auto-send,
    /// so the user can review/edit first, exactly like the web app).
    func transcribe(_ audio: Data, api: APIClient) {
        guard !transcribing else { return }
        transcribing = true
        Task {
            defer { transcribing = false }
            if let text = try? await api.transcribe(audio) {
                input += (input.isEmpty ? "" : " ") + text
                Haptic.soft()
            }
        }
    }
}

/// The chat — primary surface. Streams the coach token-by-token, shows tool pills, renders
/// markdown. Built to feel like a premium messaging app.
struct CoachView: View {
    @EnvironmentObject var model: AppModel
    @StateObject private var vm = CoachViewModel()
    @StateObject private var recorder = AudioRecorder()
    @FocusState private var focused: Bool
    @State private var pulse = false
    @State private var cardBreathe: BreathPattern?   // a card tap that launches the breathing intervention
    @State private var cardNight: SleepNightRef?     // a Sleep Week night tap → that night's hero
    @Environment(\.scenePhase) private var scenePhase

    /// Dispatch a tap on an interactive card (COACH CARDS v2). Prompt → a canned coach turn; intent →
    /// a client move (launch breathing); every action also has a typed-text path, so this is a shortcut.
    private func runCardAction(_ action: CardAction) {
        switch action {
        case .prompt(let text):
            vm.send(api: model.api, text: text)
        case .intent(let name, let args):
            switch name {
            case "breathe": cardBreathe = (args["pattern"] as? String == "box") ? .box : .physiologicalSigh
            case "open_sleep_night": if let date = args["date"] as? String { cardNight = SleepNightRef(date: date) }
            default: break
            }
        case .tool(let name, _, _):
            // v1: route a write through the coach (it confirms) rather than a silent direct call.
            vm.send(api: model.api, text: "Use \(name).")
        }
    }

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
                        .environment(\.cardAction, runCardAction)   // interactive coach cards
                        .scrollIndicators(.hidden)
                        .scrollDismissesKeyboard(.interactively)
                        // Tap anywhere in the conversation to dismiss the keyboard (buttons/chips
                        // still get their taps first).
                        .onTapGesture { focused = false }
                        .onChange(of: vm.messages.last?.text) { _, _ in withAnimation(.easeOut(duration: 0.2)) { proxy.scrollTo("bottom", anchor: .bottom) } }
                        .onChange(of: vm.toolStatus) { _, _ in withAnimation { proxy.scrollTo("bottom", anchor: .bottom) } }
                    }
                    if recorder.isRecording { recordingBar }
                    composer
                }
            }
            .navigationTitle("Coach")
            .toolbarColorScheme(.dark, for: .navigationBar)
            .fullScreenCover(item: $cardBreathe) { BreathingView(pattern: $0) }
            .fullScreenCover(item: $cardNight) { SleepNightSheet(date: $0.date) }
            // Surface a reply that generated while away: reload the thread on open, resume any
            // still-cooking reply when returning to the foreground, pause polling in the background.
            .task { vm.reconcile(api: model.api) }
            .onChange(of: scenePhase) { _, phase in
                switch phase {
                case .active: vm.reconcile(api: model.api)
                case .background: vm.pause()
                default: break
                }
            }
            .alert("Microphone access needed", isPresented: $recorder.denied) {
                Button("Open Settings") {
                    if let u = URL(string: UIApplication.openSettingsURLString) { UIApplication.shared.open(u) }
                }
                Button("Not now", role: .cancel) {}
            } message: {
                Text("Enable the microphone in Settings to dictate messages to your coach.")
            }
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
        VStack(spacing: 8) {
            if let data = vm.pendingImage, let ui = UIImage(data: data) {
                attachmentPreview(ui)
            }
            HStack(spacing: 8) {
                // Attach a photo (camera or library) — it stages in the composer so you can add a
                // note before sending, just like attaching a meal photo.
                PhotoSourceButton { data in withAnimation(Theme.Motion.snappy) { vm.pendingImage = data }; Haptic.soft() } label: {
                    circleIcon("camera.fill")
                }
                .disabled(vm.sending)

                micButton

                TextField(vm.pendingImage == nil ? "Message" : "Add a note…", text: $vm.input, axis: .vertical)
                    .focused($focused).lineLimit(1...5).font(Theme.Font.body)
                    .padding(.horizontal, Theme.Space.m).padding(.vertical, 11)
                    .background(Theme.Palette.bg2, in: Capsule())
                    .overlay(Capsule().strokeBorder(Theme.Palette.cardStroke))

                Button { vm.submit(api: model.api); focused = false } label: {
                    Image(systemName: "arrow.up").font(.system(size: 17, weight: .bold)).foregroundStyle(.white)
                        .frame(width: 38, height: 38)
                        .background(canSend ? AnyShapeStyle(Theme.Grad.brand) : AnyShapeStyle(Theme.Palette.card), in: Circle())
                }
                .disabled(!canSend).scaleEffect(canSend ? 1 : 0.9).animation(Theme.Motion.snappy, value: canSend)
            }
        }
        .padding(Theme.Space.s)
        .background(.ultraThinMaterial)
        .overlay(Rectangle().fill(Theme.Palette.cardStroke).frame(height: 0.5), alignment: .top)
    }

    // Staged photo preview — sits above the input row with a tap-to-remove control.
    private func attachmentPreview(_ ui: UIImage) -> some View {
        HStack(spacing: Theme.Space.s) {
            ZStack(alignment: .topTrailing) {
                Image(uiImage: ui).resizable().scaledToFill()
                    .frame(width: 56, height: 56)
                    .clipShape(RoundedRectangle(cornerRadius: 12, style: .continuous))
                    .overlay(RoundedRectangle(cornerRadius: 12).strokeBorder(Theme.Palette.cardStroke))
                Button { withAnimation(Theme.Motion.snappy) { vm.pendingImage = nil }; Haptic.soft() } label: {
                    Image(systemName: "xmark.circle.fill").font(.system(size: 18))
                        .foregroundStyle(.white, .black.opacity(0.5))
                }
                .offset(x: 6, y: -6)
            }
            Text("Photo ready — add a note or send").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
            Spacer()
        }
        .padding(.horizontal, 4)
        .transition(.opacity.combined(with: .move(edge: .bottom)))
    }

    // Tap to start dictating; tap again to stop → the clip transcribes into the text field.
    private var micButton: some View {
        Button {
            Task {
                if recorder.isRecording {
                    if let audio = recorder.stop() { vm.transcribe(audio, api: model.api) }
                } else {
                    focused = false
                    await recorder.start()
                }
            }
        } label: {
            ZStack {
                if vm.transcribing {
                    ProgressView().controlSize(.small).tint(Theme.Palette.cyan)
                } else {
                    Image(systemName: recorder.isRecording ? "stop.fill" : "mic.fill")
                        .font(.system(size: 16, weight: .semibold))
                        .foregroundStyle(recorder.isRecording ? .white : Theme.Palette.textDim)
                }
            }
            .frame(width: 38, height: 38)
            .background(recorder.isRecording ? AnyShapeStyle(Color.red) : AnyShapeStyle(Theme.Palette.bg2), in: Circle())
            .overlay(Circle().strokeBorder(Theme.Palette.cardStroke))
        }
        .disabled(vm.transcribing || vm.sending)
    }

    private var recordingBar: some View {
        HStack(spacing: 8) {
            Circle().fill(.red).frame(width: 8, height: 8)
                .opacity(pulse ? 1 : 0.25)
                .animation(.easeInOut(duration: 0.7).repeatForever(autoreverses: true), value: pulse)
            Text("Recording… tap stop when you're done").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
            Spacer()
            Button("Cancel") { recorder.cancel() }.font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
        }
        .padding(.horizontal, Theme.Space.m).padding(.vertical, 8)
        .background(.ultraThinMaterial)
        .onAppear { pulse = true }
    }

    private func circleIcon(_ name: String) -> some View {
        Image(systemName: name).font(.system(size: 16, weight: .semibold))
            .foregroundStyle(Theme.Palette.textDim)
            .frame(width: 38, height: 38)
            .background(Theme.Palette.bg2, in: Circle())
            .overlay(Circle().strokeBorder(Theme.Palette.cardStroke))
    }

    // Sendable when there's text OR a staged photo (a photo with no caption is valid — the coach
    // reads the image either way).
    private var canSend: Bool {
        (!vm.input.trimmingCharacters(in: .whitespaces).isEmpty || vm.pendingImage != nil) && !vm.sending
    }
}

private struct Bubble: View {
    let msg: ChatMessage
    var isUser: Bool { msg.role == .user }
    var body: some View {
        HStack {
            if isUser { Spacer(minLength: 44) }
            VStack(alignment: isUser ? .trailing : .leading, spacing: 6) {
                if let data = msg.imageData, let ui = UIImage(data: data) {
                    Image(uiImage: ui).resizable().scaledToFill()
                        .frame(maxWidth: 220, maxHeight: 260)
                        .clipShape(RoundedRectangle(cornerRadius: 18, style: .continuous))
                        .overlay(RoundedRectangle(cornerRadius: 18).strokeBorder(Theme.Palette.cardStroke))
                } else if let urlStr = msg.imageURL, let url = URL(string: urlStr) {
                    // A photo reconciled from server history (cold relaunch) — loaded lazily.
                    AsyncImage(url: url) { img in img.resizable().scaledToFill() } placeholder: { Shimmer() }
                        .frame(maxWidth: 220, maxHeight: 260)
                        .clipShape(RoundedRectangle(cornerRadius: 18, style: .continuous))
                        .overlay(RoundedRectangle(cornerRadius: 18).strokeBorder(Theme.Palette.cardStroke))
                }
                if isUser {
                    if !msg.text.isEmpty { textBubble(msg.text) }
                } else if msg.text.isEmpty && msg.streaming {
                    TypingDots()
                        .padding(.horizontal, 14).padding(.vertical, 10)
                        .background(Theme.Palette.card, in: RoundedRectangle(cornerRadius: 20, style: .continuous))
                        .overlay(RoundedRectangle(cornerRadius: 20).strokeBorder(Theme.Palette.cardStroke))
                } else {
                    // The coach can emit a ```titan-card {json} block (e.g. a macros card after logging
                    // a meal). Render those as native cards instead of leaking raw JSON into the chat.
                    let segments = CoachSegment.parse(msg.text, streaming: msg.streaming)
                    ForEach(Array(segments.enumerated()), id: \.offset) { _, seg in
                        switch seg {
                        case .text(let t): if !t.isEmpty { markdownBubble(t) }
                        case .card(let json): TitanCardView(json: json)
                        }
                    }
                }
            }
            if !isUser { Spacer(minLength: 44) }
        }
        .transition(.asymmetric(insertion: .scale(scale: 0.9).combined(with: .opacity), removal: .opacity))
    }

    // The user's own bubble: their typed text is almost always plain, so inline markdown is enough.
    private func textBubble(_ text: String) -> some View {
        Text(.init(text)).font(Theme.Font.body)
            .padding(.horizontal, 14).padding(.vertical, 10)
            .foregroundStyle(isUser ? .white : Theme.Palette.text)
            .background(
                isUser ? AnyShapeStyle(Theme.Grad.brand) : AnyShapeStyle(Theme.Palette.card),
                in: RoundedRectangle(cornerRadius: 20, style: .continuous)
            )
            .overlay(isUser ? nil : RoundedRectangle(cornerRadius: 20).strokeBorder(Theme.Palette.cardStroke))
    }

    // The coach's bubble: full block markdown (headings, lists, tables, quotes, code, images) — the coach
    // is told to use exactly these, and SwiftUI's inline-only Text would leak them as raw `#`/`-`/`|`.
    private func markdownBubble(_ text: String) -> some View {
        MarkdownText(markdown: text)
            .foregroundStyle(Theme.Palette.text)
            .padding(.horizontal, 14).padding(.vertical, 10)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(Theme.Palette.card, in: RoundedRectangle(cornerRadius: 20, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 20).strokeBorder(Theme.Palette.cardStroke))
    }
}

// MARK: - titan-card parsing + native rendering

/// One piece of an assistant message: either markdown text or a parsed titan-card payload.
private enum CoachSegment {
    case text(String)
    case card([String: Any])

    /// Split a message on ```titan-card fences. A fence that hasn't closed yet (mid-stream) or whose
    /// JSON doesn't parse is dropped rather than shown raw — the user never sees code/JSON.
    static func parse(_ raw: String, streaming: Bool) -> [CoachSegment] {
        let fence = "```titan-card"
        guard raw.contains(fence) else { return [.text(raw.trimmingCharacters(in: .whitespacesAndNewlines))] }

        var segments: [CoachSegment] = []
        var rest = Substring(raw)
        while let open = rest.range(of: fence) {
            let before = String(rest[rest.startIndex..<open.lowerBound]).trimmingCharacters(in: .whitespacesAndNewlines)
            if !before.isEmpty { segments.append(.text(before)) }
            let afterOpen = rest[open.upperBound...]
            guard let close = afterOpen.range(of: "```") else {
                rest = ""   // unclosed fence (still streaming) — suppress the partial block
                break
            }
            let jsonStr = String(afterOpen[afterOpen.startIndex..<close.lowerBound])
            if let card = decode(jsonStr) { segments.append(.card(card)) }
            rest = afterOpen[close.upperBound...]
        }
        let tail = String(rest).trimmingCharacters(in: .whitespacesAndNewlines)
        if !tail.isEmpty { segments.append(.text(tail)) }
        return segments.isEmpty ? [.text("")] : segments
    }

    private static func decode(_ s: String) -> [String: Any]? {
        let trimmed = s.trimmingCharacters(in: .whitespacesAndNewlines)
        guard let data = trimmed.data(using: .utf8),
              let obj = try? JSONSerialization.jsonObject(with: data) as? [String: Any] else { return nil }
        return obj
    }
}

/// Coerce a JSON value (NSNumber/Int/Double/String) to a Double.
func jsonNum(_ any: Any?) -> Double? {
    switch any {
    case let d as Double: return d
    case let i as Int: return Double(i)
    case let n as NSNumber: return n.doubleValue
    case let s as String: return Double(s)
    default: return nil
    }
}

/// Renders a parsed titan-card. THE REGISTRY: one switch mapping a card `type` to a first-class native
/// widget — add a new type here and NOWHERE else. An unknown type falls through to `GenericCard` (a
/// clean title + key/value list), so a type we don't draw yet degrades gracefully but never shows raw
/// JSON. Widgets live in CoachCards.swift; `macros`/`GenericCard` stay here (the original pair).
private struct TitanCardView: View {
    let json: [String: Any]
    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
        Group {
            switch json["type"] as? String {
            case "macros":                MacrosCard(json: json)
            case "meal":                  MealCard(json: json)
            case "fasting":               FastingCoachCard(json: json)
            case "fastingweek":           FastingWeekCard(json: json)
            case "readiness":             ReadinessCard(json: json)
            case "strain":                StrainCard(json: json)
            case "stress_now":            StressCard(json: json)
            case "sleep":                 SleepCard(json: json)
            case "nightstory":            NightStoryCard(json: json)
            case "sleepweek":             SleepWeekCard(json: json)
            case "lesson":                LessonCard(json: json)
            case "streak":                StreakCard(json: json)
            case "sleep_plan":            SleepPlanCard(json: json)
            case "sleep_debt":            SleepDebtCard(json: json)
            case "sparkline", "trend":    SparklineCard(json: json)
            case "compare":               CompareCard(json: json)
            case "stat":                  StatCard(json: json)
            case "stats", "vitals":       StatsCard(json: json)
            case "markers":               MarkersCard(json: json)
            case "biopanel":              BioPanelCard(json: json)
            case "weight":                WeightTrendCard(json: json)
            case "bioage":                BioAgeCard(json: json)
            case "longevity":             LongevityCard(json: json)
            case "fitness":               FitnessCard(json: json)
            case "protocol", "plan":      ProtocolCard(json: json)
            default:                      GenericCard(json: json)
            }
        }
        // COACH CARDS v2: any card carrying `actions[]` gets a tap-target row — act from the chat.
        CardActionsRow(json: json)
        }
        .frame(maxWidth: 300, alignment: .leading)
    }
}

private struct MacrosCard: View {
    let json: [String: Any]
    private func pair(_ key: String) -> (Double, Double) {
        let d = json[key] as? [String: Any]
        return (jsonNum(d?["value"]) ?? 0, jsonNum(d?["target"]) ?? 0)
    }
    private func macroRow(_ key: String, _ name: String, _ color: Color) -> some View {
        let (v, t) = pair(key)
        return VStack(alignment: .leading, spacing: 4) {
            HStack {
                Text(LocalizedStringKey(name)).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                Spacer()
                Text("\(Int(v))\(t > 0 ? "/\(Int(t))" : "")g").font(Theme.Font.num(13)).foregroundStyle(color)
            }
            CardBar(value: v, target: t, color: color)
        }
    }
    var body: some View {
        let cal = pair("calories")
        VStack(alignment: .leading, spacing: 12) {
            Text(json["title"] as? String ?? String(localized: "Today's fuel"))
                .font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.text)
            // Lead with what's left (the daily glance), red once over budget.
            if let line = json["remaining_line"] as? String, !line.isEmpty {
                Text(verbatim: line).font(Theme.Font.micro.weight(.semibold))
                    .foregroundStyle(json["over_budget"] as? Bool == true ? Theme.Palette.pink : Theme.Palette.mint)
            }
            VStack(alignment: .leading, spacing: 5) {
                HStack {
                    Text("Calories").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                    Spacer()
                    Text("\(Int(cal.0))\(cal.1 > 0 ? " / \(Int(cal.1))" : "") kcal")
                        .font(Theme.Font.num(15)).foregroundStyle(Theme.Palette.text)
                }
                if cal.1 > 0 { CardBar(value: cal.0, target: cal.1, color: cal.0 > cal.1 * 1.05 ? Theme.Palette.amber : Theme.Palette.cyan) }
            }
            macroRow("protein", "Protein", Theme.Palette.mint)
            macroRow("carbs", "Carbs", Theme.Palette.amber)
            macroRow("fat", "Fat", Theme.Palette.pink)
            if let footer = json["footer"] as? String, !footer.isEmpty {
                Text(footer).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
            }
        }
        .padding(14)
        .background(Theme.Palette.card, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
        .overlay(RoundedRectangle(cornerRadius: 18).strokeBorder(Theme.Palette.cardStroke))
    }
}

/// Clean fallback for any card type we don't draw natively yet — title + a compact list of fields
/// (scalars, or an `items` grid). Never raw JSON.
private struct GenericCard: View {
    let json: [String: Any]
    private var title: String? { (json["title"] as? String) ?? (json["label"] as? String) }
    private var rows: [(String, String)] {
        if let items = json["items"] as? [[String: Any]] {
            return items.map { it in
                let label = (it["label"] as? String) ?? ""
                let unit = (it["unit"] as? String).map { " \($0)" } ?? ""
                let val = it["value"].map { "\($0)" } ?? "–"
                return (label, val + unit)
            }
        }
        return json.compactMap { (k, v) -> (String, String)? in
            guard k != "type", k != "title", k != "label", k != "footer" else { return nil }
            if let s = v as? String { return (k.capitalized, s) }
            if let n = v as? NSNumber { return (k.capitalized, "\(n)") }
            return nil
        }.sorted { $0.0 < $1.0 }
    }
    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            if let title { Text(title).font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.text) }
            ForEach(Array(rows.enumerated()), id: \.offset) { _, row in
                HStack {
                    Text(row.0).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                    Spacer()
                    Text(row.1).font(Theme.Font.num(13)).foregroundStyle(Theme.Palette.text)
                }
            }
            if let footer = json["footer"] as? String, !footer.isEmpty {
                Text(footer).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
            }
        }
        .padding(14)
        .background(Theme.Palette.card, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
        .overlay(RoundedRectangle(cornerRadius: 18).strokeBorder(Theme.Palette.cardStroke))
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
                    Text(LocalizedStringKey(s)).font(Theme.Font.body).foregroundStyle(Theme.Palette.text)
                        .padding(.horizontal, Theme.Space.m).padding(.vertical, 10)
                        .frame(maxWidth: .infinity)
                        .background(Theme.Palette.card, in: Capsule())
                        .overlay(Capsule().strokeBorder(Theme.Palette.cardStroke))
                }.buttonStyle(PressCard())
            }
        }.padding(.horizontal, Theme.Space.xl)
    }
}
