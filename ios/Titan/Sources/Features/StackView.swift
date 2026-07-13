import SwiftUI

// MARK: - "What you take" — supplements & medications
//
// Three surfaces (design §8): the daily checklist CARD (StackSection, embedded in the Daily › Fuel
// segment), the full protocol + "worth knowing" SCREEN (StackView, pushed from the card), and the
// two-path ADD sheet (AddToStackSheet). Calm, dignified — covers a vitamin and a prescription
// equally. No "x of 6" scoreboard, no alarming red; a quiet ℞ marks medications.

// MARK: - Surface 1: today's checklist card (lives in DailyView's Fuel segment)

struct StackSection: View {
    @EnvironmentObject var model: AppModel
    @State private var showAdd = false

    var body: some View {
        GlassCard {
            VStack(alignment: .leading, spacing: Theme.Space.m) {
                header
                if let today = model.stackToday, !visibleSlots(today).isEmpty {
                    ForEach(visibleSlots(today)) { slot in slotView(slot) }
                    if let footer = today.footer, !footer.isEmpty {
                        Text(footer).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                    }
                    if (today.worth_knowing ?? 0) > 0 { worthKnowingChip(today.worth_knowing ?? 0) }
                } else {
                    emptyState
                }
            }
        }
        .task { await model.loadStack() }
        .sheet(isPresented: $showAdd) { AddToStackSheet() }
    }

    private var header: some View {
        HStack {
            NavigationLink { StackView() } label: {
                HStack(spacing: 5) {
                    Text("WHAT YOU TAKE").font(Theme.Font.label).tracking(1.2).foregroundStyle(Theme.Palette.textDim)
                    Image(systemName: "chevron.right").font(.system(size: 9, weight: .bold)).foregroundStyle(Theme.Palette.textFaint)
                }
            }
            .buttonStyle(.plain)
            Spacer()
            Button { Haptic.tap(); showAdd = true } label: {
                Image(systemName: "plus").font(.system(size: 15, weight: .semibold)).foregroundStyle(Theme.Palette.indigo)
                    .frame(width: 30, height: 30).background(Theme.Palette.indigo.opacity(0.14), in: Circle())
            }
            .buttonStyle(.plain)
        }
    }

    private func slotView(_ slot: StackSlot) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            HStack(spacing: 6) {
                Image(systemName: slotIcon(slot.key)).font(.system(size: 12)).foregroundStyle(Theme.Palette.textDim)
                Text(slot.label).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
            }
            ForEach(slot.items) { item in doseRow(item) }
            if !slot.items.isEmpty && slot.items.allSatisfy({ $0.taken }) {
                HStack(spacing: 5) {
                    Text("\(slot.label) — done \(slotClosureGlyph(slot.key))")
                        .font(Theme.Font.micro).foregroundStyle(Theme.Palette.mint)
                }
                .transition(.opacity)
            }
        }
    }

    private func doseRow(_ item: StackTodayItem) -> some View {
        HStack(spacing: Theme.Space.s) {
            Button {
                if item.taken { if let eid = item.event_id { Task { await model.undoStackIntake(eid) } } }
                else { Task { await model.markStackTaken(item) } }
            } label: {
                Image(systemName: item.taken ? "checkmark.circle.fill" : "circle")
                    .font(.system(size: 22)).foregroundStyle(item.taken ? Theme.Palette.mint : Theme.Palette.textFaint)
                    .frame(minWidth: 44, minHeight: 44)
                    .contentShape(Rectangle())
            }
            .buttonStyle(.plain)

            VStack(alignment: .leading, spacing: 1) {
                HStack(spacing: 5) {
                    Text(item.name).font(Theme.Font.body.weight(.semibold))
                        .foregroundStyle(item.taken ? Theme.Palette.textDim : Theme.Palette.text)
                        .strikethrough(item.taken, color: Theme.Palette.textFaint)
                        .lineLimit(1)
                    if item.kind == "medication" { RxBadge() }
                }
                if let dose = item.dose, !dose.isEmpty {
                    Text(dose).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
                }
            }
            Spacer(minLength: 0)
            if !item.taken {
                Button { Task { await model.markStackTaken(item) } } label: {
                    Text("take").font(Theme.Font.micro.weight(.bold)).foregroundStyle(Theme.Palette.indigo)
                        .padding(.horizontal, 12).padding(.vertical, 6)
                        .background(Theme.Palette.indigo.opacity(0.14), in: Capsule())
                }
                .buttonStyle(.plain)
            }
        }
        .opacity(item.taken ? 0.55 : 1)
        .contentShape(Rectangle())
        .contextMenu {
            if item.taken, let eid = item.event_id {
                Button { Task { await model.undoStackIntake(eid) } } label: { Label("Undo", systemImage: "arrow.uturn.backward") }
            } else {
                Button { Task { await model.markStackTaken(item) } } label: { Label("Mark taken", systemImage: "checkmark") }
                Button { Task { await model.skipStackDose(item) } } label: { Label("Skip today", systemImage: "xmark") }
            }
        }
        .animation(Theme.Motion.snappy, value: item.taken)
    }

    private func worthKnowingChip(_ n: Int) -> some View {
        NavigationLink { StackView() } label: {
            HStack(spacing: 8) {
                Image(systemName: "info.circle.fill").font(.system(size: 13)).foregroundStyle(Theme.Palette.indigo)
                (n == 1 ? Text("1 thing worth knowing") : Text("\(n) things worth knowing"))
                    .font(Theme.Font.micro).foregroundStyle(Theme.Palette.text)
                Spacer(minLength: 0)
                Image(systemName: "chevron.right").font(.system(size: 10, weight: .bold)).foregroundStyle(Theme.Palette.textFaint)
            }
            .padding(.horizontal, 12).padding(.vertical, 10)
            .background(Theme.Palette.indigo.opacity(0.10), in: RoundedRectangle(cornerRadius: Theme.Radius.chip))
        }
        .buttonStyle(.plain)
    }

    private var emptyState: some View {
        Button { Haptic.tap(); showAdd = true } label: {
            HStack(spacing: Theme.Space.m) {
                ZStack {
                    Circle().fill(Theme.Palette.indigo.opacity(0.16)).frame(width: 44, height: 44)
                    Image(systemName: "pills.fill").font(.system(size: 18, weight: .semibold)).foregroundStyle(Theme.Palette.indigo)
                }
                VStack(alignment: .leading, spacing: 2) {
                    Text("Track what you take").font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.text)
                    Text("Add a supplement or medication — your coach factors it in.")
                        .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                }
                Spacer(minLength: 0)
                Image(systemName: "chevron.right").font(.caption).foregroundStyle(Theme.Palette.textFaint)
            }
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }

    /// Collapse later-in-the-day slots until their time arrives (a slot stays visible once it has a
    /// taken dose). At 7am you see your morning items and nothing else.
    private func visibleSlots(_ today: StackToday) -> [StackSlot] {
        let hour = Calendar.current.component(.hour, from: Date())
        let withItems = today.slots.filter { !$0.items.isEmpty }
        let visible = withItems.filter { hour >= revealHour($0.key) || $0.items.contains { $0.taken } }
        return visible.isEmpty ? withItems : visible
    }
}

// MARK: - Surface 3: full protocol + "worth knowing" (pushed from the card)

struct StackView: View {
    @EnvironmentObject var model: AppModel
    @State private var showAdd = false
    @State private var editing: StackItem?
    @State private var pendingDelete: StackItem?

    var body: some View {
        ScrollView {
            VStack(spacing: Theme.Space.m) {
                let supps = model.stackItems.filter { $0.kind != "medication" }
                let meds = model.stackItems.filter { $0.kind == "medication" }

                if model.stackItems.isEmpty {
                    emptyState
                } else {
                    if !supps.isEmpty { group(String(localized: "Supplements"), supps) }
                    if !meds.isEmpty { group(String(localized: "Medications"), meds) }
                }
                if !model.stackFlags.isEmpty { worthKnowing }
                disclaimer
                Color.clear.frame(height: 8)
            }
            .padding(.horizontal, Theme.Space.m).padding(.top, Theme.Space.s)
        }
        .scrollIndicators(.hidden)
        .background(Theme.Palette.bg.ignoresSafeArea())
        .navigationTitle("What you take")
        .navigationBarTitleDisplayMode(.large)
        .toolbarColorScheme(.dark, for: .navigationBar)
        .toolbar {
            ToolbarItem(placement: .topBarTrailing) {
                Button { Haptic.tap(); showAdd = true } label: { Image(systemName: "plus") }.tint(Theme.Palette.indigo)
            }
        }
        .task { await model.loadStack() }
        .sheet(isPresented: $showAdd) { AddToStackSheet() }
        .sheet(item: $editing) { EditStackItemSheet(item: $0) }
        .confirmationDialog("Stop taking \(pendingDelete?.name ?? String(localized: "this"))?",
                            isPresented: Binding(get: { pendingDelete != nil }, set: { if !$0 { pendingDelete = nil } }),
                            titleVisibility: .visible) {
            Button("Remove it and its history", role: .destructive) {
                if let item = pendingDelete { Haptic.warning(); Task { await model.deleteStackItem(item.id) } }
            }
            Button("Cancel", role: .cancel) {}
        } message: {
            Text("This permanently removes it and your logged history. To keep the record, choose Pause instead.")
        }
    }

    private func group(_ title: String, _ items: [StackItem]) -> some View {
        GlassCard {
            VStack(spacing: 0) {
                SectionHeader(title: title, trailing: "\(items.count)")
                ForEach(items) { item in
                    Button { Haptic.tap(); editing = item } label: { itemRow(item) }
                        .buttonStyle(.plain)
                        .contextMenu {
                            Button { Haptic.tap(); editing = item } label: { Label("Edit", systemImage: "slider.horizontal.3") }
                            Button { Task { await model.updateStackItem(item.id, fields: ["active": !item.active]) } } label: {
                                if item.active { Label("Pause", systemImage: "pause.circle") }
                                else { Label("Resume", systemImage: "play.circle") }
                            }
                            Button(role: .destructive) { Haptic.tap(); pendingDelete = item } label: {
                                Label("Stop", systemImage: "stop.circle")
                            }
                        }
                    if item.id != items.last?.id { Divider().overlay(Theme.Palette.cardStroke) }
                }
            }
        }
    }

    private func itemRow(_ item: StackItem) -> some View {
        HStack(spacing: Theme.Space.m) {
            VStack(alignment: .leading, spacing: 3) {
                HStack(spacing: 5) {
                    Text(item.name).font(Theme.Font.body.weight(.semibold))
                        .foregroundStyle(item.active ? Theme.Palette.text : Theme.Palette.textDim).lineLimit(1)
                    if item.kind == "medication" { RxBadge() }
                    if !item.active { pausedBadge }
                }
                Text(scheduleLine(item)).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim).lineLimit(1)
            }
            Spacer(minLength: 0)
            if let a = item.adherence { adherenceView(a) }
        }
        .padding(.vertical, 10).contentShape(Rectangle())
    }

    private func adherenceView(_ pct: Int) -> some View {
        VStack(alignment: .trailing, spacing: 4) {
            Text("\(pct)%").font(Theme.Font.num(14)).foregroundStyle(adherenceColor(pct)).monospacedDigit()
            Capsule().fill(Color.white.opacity(0.08)).frame(width: 50, height: 5)
                .overlay(alignment: .leading) {
                    Capsule().fill(adherenceColor(pct)).frame(width: 50 * CGFloat(min(100, max(0, pct))) / 100, height: 5)
                }
        }
    }

    private var pausedBadge: some View {
        Text("PAUSED").font(.system(size: 9, weight: .bold, design: .rounded)).foregroundStyle(Theme.Palette.textFaint)
            .padding(.horizontal, 5).padding(.vertical, 1)
            .background(Color.white.opacity(0.06), in: RoundedRectangle(cornerRadius: 4))
    }

    private var worthKnowing: some View {
        GlassCard {
            VStack(alignment: .leading, spacing: Theme.Space.s) {
                SectionHeader(title: String(localized: "Worth knowing"))
                ForEach(model.stackFlags) { flag in flagRow(flag) }
            }
        }
    }

    private func flagRow(_ f: InteractionFlag) -> some View {
        HStack(alignment: .top, spacing: 10) {
            Circle().fill(severityColor(f.severity)).frame(width: 8, height: 8).padding(.top, 5)
            VStack(alignment: .leading, spacing: 3) {
                HStack(spacing: 6) {
                    Text(pairTitle(f)).font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.text)
                    if let sev = f.severity, !sev.isEmpty {
                        Text(severityLabel(sev)).font(Theme.Font.micro).foregroundStyle(severityColor(sev))
                            .padding(.horizontal, 6).padding(.vertical, 1)
                            .background(severityColor(sev).opacity(0.16), in: Capsule())
                    }
                }
                if let s = f.summary, !s.isEmpty { Text(s).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim) }
                if let src = f.source, !src.isEmpty { Text("Source: \(src)").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint) }
            }
            Spacer(minLength: 0)
        }
        .padding(.vertical, 5)
    }

    private var disclaimer: some View {
        HStack(alignment: .top, spacing: 6) {
            Image(systemName: "info.circle").font(.system(size: 11)).foregroundStyle(Theme.Palette.textFaint)
            Text(model.stackDisclaimer ?? String(localized: "Informational, not medical advice — check with your pharmacist or clinician."))
                .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
        }
        .frame(maxWidth: .infinity, alignment: .leading).padding(.top, 4)
    }

    private var emptyState: some View {
        GlassCard(padding: Theme.Space.l) {
            VStack(spacing: Theme.Space.s) {
                Image(systemName: "pills.fill").font(.system(size: 30)).foregroundStyle(Theme.Palette.indigo)
                Text("Nothing here yet").font(Theme.Font.title).foregroundStyle(Theme.Palette.text)
                Text("Add a supplement or medication — search it, or just snap the bottle.")
                    .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim).multilineTextAlignment(.center)
                Button { Haptic.tap(); showAdd = true } label: {
                    Label("Add your first", systemImage: "plus").font(Theme.Font.body.weight(.semibold))
                        .frame(maxWidth: .infinity).padding(.vertical, 13)
                        .background(Theme.Grad.brand, in: RoundedRectangle(cornerRadius: Theme.Radius.chip)).foregroundStyle(.white)
                }
            }.frame(maxWidth: .infinity)
        }
    }

    private func scheduleLine(_ item: StackItem) -> String {
        var parts: [String] = []
        if let d = item.dose_label, !d.isEmpty { parts.append(d) }
        let glyphs = item.slots.map { slotGlyph($0) }.filter { !$0.isEmpty }.joined(separator: " ")
        var sched = glyphs
        if let freq = item.schedule?.frequency, !freq.isEmpty {
            sched += sched.isEmpty ? freqLabel(freq) : " \(freqLabel(freq))"
        }
        if !sched.isEmpty { parts.append(sched) }
        return parts.joined(separator: " · ")
    }
}

// MARK: - Surface 2: Add (two first-class paths in one sheet)

struct AddToStackSheet: View {
    @EnvironmentObject var model: AppModel
    @Environment(\.dismiss) private var dismiss

    @State private var query = ""
    @State private var searchTask: Task<Void, Never>?
    @State private var draft: StackDraft?                 // → confirm-one push
    @State private var shelf: [StackScanCandidate]?       // → shelf review push
    @State private var shelfNote: String?

    var body: some View {
        NavigationStack {
            ZStack {
                Theme.Palette.bg.ignoresSafeArea()
                ScrollView {
                    VStack(spacing: Theme.Space.m) {
                        addOneCard
                        if model.stackSearching {
                            HStack { ProgressView().tint(Theme.Palette.textFaint); Text("Searching…").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim) }
                                .frame(maxWidth: .infinity, alignment: .leading)
                        } else if !model.stackSearchResults.isEmpty {
                            resultsList
                        }
                        dividerLabel
                        shelfCard
                    }
                    .padding(Theme.Space.m)
                }
            }
            .navigationTitle("Add to what you take").navigationBarTitleDisplayMode(.inline)
            .toolbar { ToolbarItem(placement: .cancellationAction) { Button("Cancel") { dismiss() } } }
            .toolbarColorScheme(.dark, for: .navigationBar)
            .navigationDestination(isPresented: Binding(get: { draft != nil }, set: { if !$0 { draft = nil } })) {
                if let d = draft {
                    StackEditor(draft: d, title: String(localized: "Confirm"), saveLabel: String(localized: "Add"),
                                onSave: { fields in if await model.addStackItem(fields) { dismiss() } }, onDelete: nil)
                }
            }
            .navigationDestination(isPresented: Binding(get: { shelf != nil }, set: { if !$0 { shelf = nil } })) {
                if let candidates = shelf {
                    ShelfReview(candidates: candidates, note: shelfNote, onDone: { dismiss() })
                }
            }
        }
        .onDisappear { model.stackSearchResults = []; model.stackScan = nil }
    }

    private var addOneCard: some View {
        GlassCard {
            HStack(spacing: Theme.Space.s) {
                Image(systemName: "magnifyingglass").font(.system(size: 15)).foregroundStyle(Theme.Palette.textFaint)
                TextField("", text: $query, prompt: Text("Add one — type a name").foregroundColor(Theme.Palette.textFaint))
                    .font(Theme.Font.body).foregroundStyle(Theme.Palette.text)
                    .autocorrectionDisabled().textInputAutocapitalization(.never)
                    .onChange(of: query) { _, q in scheduleSearch(q) }
                PhotoSourceButton(onImage: { d in runScan(d, mode: "single") }) {
                    Image(systemName: model.stackScanning ? "sparkles" : "camera.fill")
                        .font(.system(size: 17, weight: .semibold)).foregroundStyle(Theme.Palette.indigo)
                        .symbolEffect(.pulse, options: model.stackScanning ? .repeating : .nonRepeating)
                }
            }
        }
    }

    private var resultsList: some View {
        GlassCard {
            VStack(spacing: 0) {
                ForEach(model.stackSearchResults) { r in
                    Button { Haptic.tap(); draft = StackDraft(catalog: r) } label: {
                        HStack(spacing: Theme.Space.s) {
                            VStack(alignment: .leading, spacing: 2) {
                                HStack(spacing: 5) {
                                    Text(r.name).font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.text).lineLimit(1)
                                    if r.kind == "medication" { RxBadge() }
                                }
                                Text(catalogSubtitle(r)).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim).lineLimit(1)
                            }
                            Spacer(minLength: 0)
                            Image(systemName: "plus.circle.fill").font(.system(size: 20)).foregroundStyle(Theme.Palette.indigo)
                        }
                        .padding(.vertical, 10).contentShape(Rectangle())
                    }
                    .buttonStyle(.plain)
                    if r.id != model.stackSearchResults.last?.id { Divider().overlay(Theme.Palette.cardStroke) }
                }
            }
        }
    }

    private var dividerLabel: some View {
        HStack(spacing: Theme.Space.s) {
            Rectangle().fill(Theme.Palette.cardStroke).frame(height: 1)
            Text("or").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
            Rectangle().fill(Theme.Palette.cardStroke).frame(height: 1)
        }
    }

    private var shelfCard: some View {
        PhotoSourceButton(onImage: { d in runScan(d, mode: "shelf") }) {
            GlassCard {
                HStack(spacing: Theme.Space.m) {
                    ZStack {
                        Circle().fill(Theme.Palette.cyan.opacity(0.16)).frame(width: 44, height: 44)
                        Image(systemName: "square.grid.2x2.fill").font(.system(size: 18, weight: .semibold)).foregroundStyle(Theme.Palette.cyan)
                    }
                    VStack(alignment: .leading, spacing: 2) {
                        Text("Add my whole shelf").font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.text)
                        Text("Lay the bottles out, take one photo — I'll read every label I can.")
                            .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                    }
                    Spacer(minLength: 0)
                    Image(systemName: "chevron.right").font(.caption).foregroundStyle(Theme.Palette.textFaint)
                }
            }
        }
        .buttonStyle(PressCard())
    }

    private func scheduleSearch(_ q: String) {
        searchTask?.cancel()
        searchTask = Task {
            try? await Task.sleep(nanoseconds: 300_000_000)   // debounce
            if Task.isCancelled { return }
            await model.searchStack(q)
        }
    }

    private func runScan(_ data: Data, mode: String) {
        Task {
            await model.scanStack(data, mode: mode)
            guard let scan = model.stackScan else { return }
            if mode == "single", scan.candidates.count == 1 {
                draft = StackDraft(candidate: scan.candidates[0])
            } else if !scan.candidates.isEmpty {
                shelfNote = scan.note
                shelf = scan.candidates
            }
            model.stackScan = nil
        }
    }
}

// MARK: - Shelf review (batch confirm from one photo)

private struct ShelfReview: View {
    let candidates: [StackScanCandidate]
    let note: String?
    let onDone: () -> Void
    @EnvironmentObject var model: AppModel
    @State private var included: Set<UUID>
    @State private var adding = false

    init(candidates: [StackScanCandidate], note: String?, onDone: @escaping () -> Void) {
        self.candidates = candidates; self.note = note; self.onDone = onDone
        _included = State(initialValue: Set(candidates.map { $0.id }))
    }

    var body: some View {
        ScrollView {
            VStack(spacing: Theme.Space.m) {
                Text(note ?? String(localized: "Found these on your shelf. Untick anything I misread — you can fine-tune doses & timing later."))
                    .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                    .frame(maxWidth: .infinity, alignment: .leading)

                GlassCard {
                    VStack(spacing: 0) {
                        ForEach(candidates) { c in row(c) }
                    }
                }

                Button(action: addAll) {
                    HStack {
                        if adding { ProgressView().tint(.white) }
                        (adding ? Text("Adding…") : Text("Add \(included.count) to what you take")).font(Theme.Font.body.weight(.semibold))
                    }
                    .frame(maxWidth: .infinity).padding(.vertical, 14)
                    .background(Theme.Grad.brand, in: RoundedRectangle(cornerRadius: Theme.Radius.chip)).foregroundStyle(.white)
                }
                .disabled(included.isEmpty || adding)
                .opacity(included.isEmpty ? 0.5 : 1)
                Color.clear.frame(height: 8)
            }
            .padding(Theme.Space.m)
        }
        .scrollIndicators(.hidden)
        .background(Theme.Palette.bg.ignoresSafeArea())
        .navigationTitle("Your shelf").navigationBarTitleDisplayMode(.inline)
        .toolbarColorScheme(.dark, for: .navigationBar)
    }

    private func row(_ c: StackScanCandidate) -> some View {
        let on = included.contains(c.id)
        return Button {
            Haptic.tap(); if on { included.remove(c.id) } else { included.insert(c.id) }
        } label: {
            HStack(spacing: Theme.Space.s) {
                Image(systemName: on ? "checkmark.circle.fill" : "circle")
                    .font(.system(size: 22)).foregroundStyle(on ? Theme.Palette.mint : Theme.Palette.textFaint)
                VStack(alignment: .leading, spacing: 2) {
                    HStack(spacing: 5) {
                        Text(c.name).font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.text).lineLimit(1)
                        if c.kind == "medication" { RxBadge() }
                    }
                    Text(candidateSubtitle(c)).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim).lineLimit(1)
                }
                Spacer(minLength: 0)
            }
            .padding(.vertical, 10).contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }

    private func addAll() {
        Haptic.success(); adding = true
        Task {
            for c in candidates where included.contains(c.id) {
                var d = StackDraft(candidate: c); d.slots = ["morning"]; d.frequency = "daily"
                _ = await model.addStackItem(d.apiFields())
            }
            adding = false; onDone()
        }
    }
}

// MARK: - Edit an existing item (PATCH / delete)

struct EditStackItemSheet: View {
    @EnvironmentObject var model: AppModel
    @Environment(\.dismiss) private var dismiss
    let item: StackItem

    var body: some View {
        NavigationStack {
            StackEditor(
                draft: StackDraft(item: item),
                title: String(localized: "Edit"),
                saveLabel: String(localized: "Save changes"),
                onSave: { fields in await model.updateStackItem(item.id, fields: fields); dismiss() },
                onDelete: { await model.deleteStackItem(item.id); dismiss() }
            )
            .navigationTitle("Edit").navigationBarTitleDisplayMode(.inline)
            .toolbar { ToolbarItem(placement: .cancellationAction) { Button("Cancel") { dismiss() } } }
            .toolbarColorScheme(.dark, for: .navigationBar)
        }
    }
}

// MARK: - Shared editor (dose + timing + frequency) — used by add-confirm and edit

private struct StackEditor: View {
    @State var draft: StackDraft
    let title: String
    let saveLabel: String
    let onSave: ([String: Any]) async -> Void
    let onDelete: (() async -> Void)?
    @State private var saving = false
    @State private var confirmingDelete = false

    /// Show the Type picker in the full edit sheet, or on the add path only when `kind` is still ambiguous.
    private var showType: Bool { onDelete != nil || !draft.kindKnown }

    private var foodToggle: some View {
        Toggle(isOn: $draft.withFood) {
            Text("Take with food").font(Theme.Font.body).foregroundStyle(Theme.Palette.text)
        }.tint(Theme.Palette.indigo)
    }

    private let slots: [(String, String)] = [("morning", String(localized: "Morning")), ("midday", String(localized: "Midday")), ("evening", String(localized: "Evening")), ("night", String(localized: "Night"))]
    private let weekdays: [(String, String)] = [("mon", "M"), ("tue", "T"), ("wed", "W"), ("thu", "T"), ("fri", "F"), ("sat", "S"), ("sun", "S")]

    var body: some View {
        ZStack {
            Theme.Palette.bg.ignoresSafeArea()
            ScrollView {
                VStack(spacing: Theme.Space.m) {
                    field(String(localized: "Name"), text: $draft.name, keyboard: .default)
                    HStack(spacing: Theme.Space.s) {
                        field(String(localized: "Dose"), text: $draft.doseAmount, keyboard: .decimalPad)
                        field(String(localized: "Unit"), text: $draft.doseUnit, keyboard: .default)
                        field(String(localized: "Form"), text: $draft.form, keyboard: .default)
                    }

                    card(String(localized: "When")) {
                        FlowChips(items: slots, isOn: { draft.slots.contains($0) }) { key in
                            Haptic.tap(); if draft.slots.contains(key) { draft.slots.remove(key) } else { draft.slots.insert(key) }
                        }
                    }

                    card(String(localized: "Repeat")) {
                        PillSwitch(options: [("daily", "Daily"), ("specific", "Specific days"), ("as_needed", "As needed")],
                                   selection: $draft.frequency)
                        if draft.frequency == "specific" {
                            HStack(spacing: 6) {
                                ForEach(Array(weekdays.enumerated()), id: \.offset) { _, d in
                                    let on = draft.days.contains(d.0)
                                    Button { Haptic.tap(); if on { draft.days.remove(d.0) } else { draft.days.insert(d.0) } } label: {
                                        Text(d.1).font(Theme.Font.micro.weight(.bold)).foregroundStyle(on ? .white : Theme.Palette.textDim)
                                            .frame(maxWidth: .infinity).padding(.vertical, 9)
                                            .background(on ? Theme.Palette.indigo : Theme.Palette.bg2, in: Circle())
                                            .overlay(Circle().strokeBorder(Theme.Palette.cardStroke))
                                    }.buttonStyle(.plain)
                                }
                            }.padding(.top, 4)
                        }
                    }

                    if showType {
                        card(String(localized: "Type")) {
                            PillSwitch(options: [("supplement", "Supplement"), ("medication", "Medication"), ("other", "Other")],
                                       selection: $draft.kind)
                            foodToggle.padding(.top, 4)
                        }
                    } else {
                        card(String(localized: "Food")) { foodToggle }
                    }

                    Button(action: save) {
                        HStack {
                            if saving { ProgressView().tint(.white) }
                            (saving ? Text("Saving…") : Text(saveLabel)).font(Theme.Font.body.weight(.semibold))
                        }
                        .frame(maxWidth: .infinity).padding(.vertical, 14)
                        .background(Theme.Grad.brand, in: RoundedRectangle(cornerRadius: Theme.Radius.chip)).foregroundStyle(.white)
                    }
                    .disabled(draft.name.trimmingCharacters(in: .whitespaces).isEmpty || saving)

                    if onDelete != nil {
                        Button(role: .destructive) {
                            Haptic.tap(); confirmingDelete = true
                        } label: {
                            Text("Stop taking this").font(Theme.Font.micro).foregroundStyle(Theme.Palette.pink)
                        }.frame(maxWidth: .infinity)
                    }
                    Color.clear.frame(height: 8)
                }
                .padding(Theme.Space.m)
            }
            .scrollIndicators(.hidden)
        }
        .navigationTitle(title).navigationBarTitleDisplayMode(.inline)
        .confirmationDialog("Stop taking \(draft.name.isEmpty ? String(localized: "this") : draft.name)?",
                            isPresented: $confirmingDelete, titleVisibility: .visible) {
            Button("Remove it and its history", role: .destructive) {
                Haptic.warning(); Task { await onDelete?() }
            }
            Button("Cancel", role: .cancel) {}
        } message: {
            Text("This permanently removes it and your logged history. To keep the record, close this and Pause it instead.")
        }
    }

    private func save() {
        Haptic.success(); saving = true
        Task { await onSave(draft.apiFields()); saving = false }
    }

    private func card<Content: View>(_ title: String, @ViewBuilder content: () -> Content) -> some View {
        GlassCard {
            VStack(alignment: .leading, spacing: Theme.Space.s) {
                SectionHeader(title: title)
                content()
            }
        }
    }

    private func field(_ label: String, text: Binding<String>, keyboard: UIKeyboardType) -> some View {
        VStack(alignment: .leading, spacing: 5) {
            Text(label.uppercased()).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
            TextField("", text: text)
                .font(Theme.Font.body).foregroundStyle(Theme.Palette.text).keyboardType(keyboard)
                .padding(12).background(Theme.Palette.card, in: RoundedRectangle(cornerRadius: Theme.Radius.chip))
                .overlay(RoundedRectangle(cornerRadius: Theme.Radius.chip).strokeBorder(Theme.Palette.cardStroke))
        }
    }
}

/// A wrapping row of selectable pill chips (timing slots).
private struct FlowChips: View {
    let items: [(String, String)]
    let isOn: (String) -> Bool
    let toggle: (String) -> Void
    var body: some View {
        HStack(spacing: 8) {
            ForEach(Array(items.enumerated()), id: \.offset) { _, item in
                let on = isOn(item.0)
                Button { toggle(item.0) } label: {
                    Text(item.1).font(Theme.Font.micro.weight(.semibold)).foregroundStyle(on ? .white : Theme.Palette.textDim)
                        .frame(maxWidth: .infinity).padding(.vertical, 9)
                        .background(on ? Theme.Palette.indigo : Theme.Palette.bg2, in: Capsule())
                        .overlay(Capsule().strokeBorder(Theme.Palette.cardStroke))
                }.buttonStyle(.plain)
            }
        }
    }
}

// MARK: - The ℞ marker (quiet, dignified — never alarming)

private struct RxBadge: View {
    var body: some View {
        Text("℞").font(.system(size: 12, weight: .bold, design: .serif)).foregroundStyle(Theme.Palette.violet)
            .padding(.horizontal, 5).padding(.vertical, 1)
            .background(Theme.Palette.violet.opacity(0.16), in: RoundedRectangle(cornerRadius: 4))
    }
}

// MARK: - The draft (editable form state) + API mapping

struct StackDraft {
    var name = ""
    var brand: String?
    var doseAmount = ""
    var doseUnit = ""
    var form = ""
    var kind = "supplement"
    var kindKnown = false        // true when a catalog/scan/existing item already determined `kind`
    var slots: Set<String> = ["morning"]
    var frequency = "daily"
    var days: Set<String> = []
    var withFood = false
    var notes: String?
    var dsldID: String?
    var rxcui: String?

    func apiFields() -> [String: Any] {
        var schedule: [String: Any] = [
            "frequency": frequency,
            "times": Array(slots),
            "with_food": withFood,
        ]
        if frequency == "specific" { schedule["days"] = Array(days) }
        var f: [String: Any] = ["name": name.trimmingCharacters(in: .whitespaces), "kind": kind, "schedule": schedule]
        if let b = brand, !b.isEmpty { f["brand"] = b }
        if let amt = Double(doseAmount.replacingOccurrences(of: ",", with: ".")) { f["dose_amount"] = amt }
        if !doseUnit.isEmpty { f["dose_unit"] = doseUnit }
        if !form.isEmpty { f["form"] = form }
        if let d = dsldID, !d.isEmpty { f["dsld_id"] = d }
        if let r = rxcui, !r.isEmpty { f["rxcui"] = r }
        if let n = notes, !n.isEmpty { f["notes"] = n }
        return f
    }
}

extension StackDraft {
    init(catalog r: StackCatalogResult) {
        self.init()
        name = r.name; brand = r.brand
        doseAmount = r.dose_amount.map(trimNum) ?? ""
        doseUnit = r.dose_unit ?? ""
        form = r.form ?? ""
        kind = r.kind
        kindKnown = true
        dsldID = r.dsld_id?.value
        rxcui = r.rxcui?.value
    }
    init(candidate c: StackScanCandidate) {
        self.init()
        name = c.name; brand = c.brand
        doseAmount = c.dose_amount.map(trimNum) ?? ""
        doseUnit = c.dose_unit ?? ""
        form = c.form ?? ""
        kind = c.kind ?? "supplement"
        kindKnown = c.kind != nil
    }
    init(item: StackItem) {
        self.init()
        name = item.name; brand = item.brand
        doseAmount = item.dose_amount.map(trimNum) ?? ""
        doseUnit = item.dose_unit ?? ""
        form = item.form ?? ""
        kind = item.kind
        kindKnown = true
        slots = Set(item.slots)
        frequency = item.schedule?.frequency ?? "daily"
        days = Set(item.schedule?.days ?? [])
        withFood = item.schedule?.with_food ?? false
        notes = item.notes
    }
}

// MARK: - File-level helpers

private func trimNum(_ v: Double) -> String { v.truncatingRemainder(dividingBy: 1) == 0 ? String(Int(v)) : String(v) }

private func revealHour(_ key: String) -> Int {
    switch key.lowercased() {
    case "midday", "afternoon", "noon", "lunch": return 11
    case "evening", "dinner": return 16
    case "night", "bedtime": return 20
    default: return 0      // morning / anytime / unknown → always visible
    }
}

private func slotIcon(_ key: String) -> String {
    switch key.lowercased() {
    case "morning": return "sunrise.fill"
    case "midday", "afternoon", "noon", "lunch": return "sun.max.fill"
    case "evening", "dinner": return "sunset.fill"
    case "night", "bedtime": return "moon.stars.fill"
    default: return "pills.fill"
    }
}

private func slotGlyph(_ key: String) -> String {
    switch key.lowercased() {
    case "morning": return "☀"
    case "midday", "afternoon", "noon", "lunch": return "◐"
    case "evening", "dinner": return "☾"
    case "night", "bedtime": return "☾"
    default: return ""
    }
}

private func slotClosureGlyph(_ key: String) -> String {
    switch key.lowercased() {
    case "morning": return "☀"
    case "midday", "afternoon", "noon", "lunch": return "🌤"
    case "evening", "dinner": return "☾"
    case "night", "bedtime": return "🌙"
    default: return "•"
    }
}

private func freqLabel(_ f: String) -> String {
    switch f.lowercased() {
    case "daily": return String(localized: "daily")
    case "specific": return String(localized: "some days")
    case "as_needed", "as needed": return String(localized: "as needed")
    default: return f
    }
}

private func severityColor(_ s: String?) -> Color {
    switch (s ?? "").lowercased() {
    case "major": return Theme.Palette.amber
    case "moderate": return Theme.Palette.amber
    case "timing": return Theme.Palette.cyan
    case "info": return Theme.Palette.indigo
    default: return Theme.Palette.cyan
    }
}

private func severityLabel(_ s: String) -> String {
    switch s.lowercased() {
    case "info": return String(localized: "Good to know")
    case "timing": return String(localized: "Timing")
    case "moderate": return String(localized: "Moderate")
    case "major": return String(localized: "Major")
    default: return s.capitalized
    }
}

private func adherenceColor(_ pct: Int) -> Color {
    pct >= 80 ? Theme.Palette.mint : (pct >= 50 ? Theme.Palette.amber : Theme.Palette.textDim)
}

private func pairTitle(_ f: InteractionFlag) -> String {
    [f.a, f.b].compactMap { $0 }.filter { !$0.isEmpty }.joined(separator: " + ")
}

private func catalogSubtitle(_ r: StackCatalogResult) -> String {
    var parts: [String] = []
    if let amt = r.dose_amount { parts.append(trimNum(amt) + (r.dose_unit.map { " \($0)" } ?? "")) }
    else if let u = r.dose_unit, !u.isEmpty { parts.append(u) }
    if let b = r.brand, !b.isEmpty { parts.append(b) }
    if let f = r.form, !f.isEmpty { parts.append(f) }
    return parts.joined(separator: " · ")
}

private func candidateSubtitle(_ c: StackScanCandidate) -> String {
    var parts: [String] = []
    if let amt = c.dose_amount { parts.append(trimNum(amt) + (c.dose_unit.map { " \($0)" } ?? "")) }
    else if let u = c.dose_unit, !u.isEmpty { parts.append(u) }
    if let b = c.brand, !b.isEmpty { parts.append(b) }
    if let f = c.form, !f.isEmpty { parts.append(f) }
    return parts.joined(separator: " · ")
}
