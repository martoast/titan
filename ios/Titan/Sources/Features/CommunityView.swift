import PhotosUI
import SwiftUI

// MARK: - Community tab — opt-in social: feed · leaderboard · find people

struct CommunityView: View {
    @EnvironmentObject var model: AppModel
    @State private var tab: Tab = .feed
    @State private var showSettings = false

    enum Tab: String, CaseIterable { case feed = "Feed", board = "Leaders", find = "Find" }

    var body: some View {
        Group {
            if model.communitySettings == nil {
                VStack { ProgressView().tint(Theme.Palette.indigo).padding(.top, 60) }
            } else if !model.communityEnabled {
                CommunityOptInCard { showSettings = true }
            } else {
                VStack(spacing: Theme.Space.m) {
                    PillSwitch(options: Tab.allCases.map { ($0, $0.rawValue) }, selection: $tab)
                    switch tab {
                    case .feed: CommunityFeed()
                    case .board: LeaderboardSection()
                    case .find: FindAthletes()
                    }
                    Color.clear.frame(height: 8)
                }
            }
        }
        .titanScreen("Community", glow: Theme.Palette.mint)
        .toolbar {
            if model.communityEnabled {
                ToolbarItem(placement: .topBarTrailing) {
                    Button { Haptic.tap(); showSettings = true } label: { Image(systemName: "gearshape") }
                        .tint(Theme.Palette.textDim)
                }
            }
        }
        .sheet(isPresented: $showSettings) { CommunitySettingsView() }
        .task {
            await model.loadCommunitySettings()
            if model.communityEnabled {
                await model.loadFeed(); await model.loadRecap(); await model.loadFollowRequests()
            }
        }
    }
}

// MARK: Opt-in gate

private struct CommunityOptInCard: View {
    let onJoin: () -> Void
    var body: some View {
        VStack(spacing: Theme.Space.l) {
            ZStack {
                Circle().fill(Theme.Grad.brand).frame(width: 88, height: 88)
                    .shadow(color: Theme.Palette.indigo.opacity(0.5), radius: 18)
                Image(systemName: "person.2.fill").font(.system(size: 38)).foregroundStyle(.white)
            }
            .padding(.top, 40)
            VStack(spacing: Theme.Space.s) {
                Text("Train together").font(Theme.Font.display(30)).foregroundStyle(Theme.Palette.text)
                Text("Follow friends and family, cheer each other on, and climb the leaderboard. You're invisible until you join — and you choose what you share.")
                    .font(Theme.Font.body).foregroundStyle(Theme.Palette.textDim)
                    .multilineTextAlignment(.center).padding(.horizontal, Theme.Space.s)
            }
            Button { Haptic.rigid(); onJoin() } label: {
                Text("Join the community").font(Theme.Font.body.weight(.bold))
                    .frame(maxWidth: .infinity).padding(.vertical, 15)
                    .background(Theme.Grad.brand, in: RoundedRectangle(cornerRadius: Theme.Radius.chip))
                    .foregroundStyle(.white)
            }
            .padding(.top, Theme.Space.s)
        }
    }
}

// MARK: Feed

private struct CommunityFeed: View {
    @EnvironmentObject var model: AppModel
    var body: some View {
        VStack(spacing: Theme.Space.m) {
            if let r = model.recap, r.your_activities > 0 { RecapCard(recap: r) }

            if !model.followRequests.isEmpty {
                NavigationLink { RequestsList() } label: {
                    GlassCard {
                        HStack(spacing: Theme.Space.m) {
                            Image(systemName: "person.badge.clock.fill").foregroundStyle(Theme.Palette.amber)
                            Text(model.followRequests.count == 1 ? "1 follow request" : "\(model.followRequests.count) follow requests")
                                .font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.text)
                            Spacer()
                            Image(systemName: "chevron.right").font(.caption2).foregroundStyle(Theme.Palette.textFaint)
                        }
                    }
                }.buttonStyle(PressCard())
            }

            switch model.feedPhase {
            case .loading where model.feed.isEmpty:
                ForEach(0..<3, id: \.self) { _ in SkeletonCard() }
            case .failed where model.feed.isEmpty:
                SyncErrorRow(message: "Couldn't load the feed") { await model.loadFeed() }
            default:
                if model.feed.isEmpty {
                    FeedEmptyState()
                } else {
                    ForEach(model.feed) { card in FeedCard(card: card) }
                }
            }
        }
        .animation(Theme.Motion.snappy, value: model.feedPhase)
        .refreshable { await model.loadFeed(); await model.loadRecap() }
    }
}

private struct FeedEmptyState: View {
    var body: some View {
        VStack(spacing: Theme.Space.m) {
            Image(systemName: "figure.run.circle").font(.system(size: 40)).foregroundStyle(Theme.Palette.textFaint)
            Text("Your feed is quiet").font(Theme.Font.title).foregroundStyle(Theme.Palette.text)
            Text("Find friends in the Find tab — their runs and rides will show up here.")
                .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim).multilineTextAlignment(.center)
        }.frame(maxWidth: .infinity).padding(.vertical, Theme.Space.xl)
    }
}

/// Self-navigating feed card: the athlete header opens their profile; the map+stats open the
/// activity detail; the kudos/comment bar are in-place actions. (Never wrap this in a NavigationLink.)
private struct FeedCard: View {
    @EnvironmentObject var model: AppModel
    let card: ActivityCard
    @State private var clap = false

    private var liveCard: ActivityCard { model.feed.first(where: { $0.id == card.id }) ?? card }

    var body: some View {
        GlassCard(padding: Theme.Space.m) {
            VStack(alignment: .leading, spacing: Theme.Space.s) {
                NavigationLink { AthleteProfileView(athleteId: card.athlete.id) } label: {
                    HStack(spacing: Theme.Space.s) {
                        AthleteAvatar(name: card.athlete.name, url: card.athlete.avatar_url, size: 42)
                        VStack(alignment: .leading, spacing: 1) {
                            Text(card.athlete.name).font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.text)
                            Text("\(card.title) · \(relativeTime(card.started_at))").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                        }
                        Spacer()
                        Image(systemName: "chevron.right").font(.caption2).foregroundStyle(Theme.Palette.textFaint)
                    }
                }.buttonStyle(.plain)

                NavigationLink { ActivityDetailView(card: card) } label: {
                    VStack(alignment: .leading, spacing: Theme.Space.s) {
                        if let url = card.map_thumb_url, let u = URL(string: url) {
                            ZStack(alignment: .bottomLeading) {
                                AsyncImage(url: u) { img in img.resizable().scaledToFill() } placeholder: {
                                    Shimmer().frame(height: 168)
                                }
                                .frame(height: 168).frame(maxWidth: .infinity).clipped()
                                LinearGradient(colors: [.black.opacity(0.65), .clear], startPoint: .bottom, endPoint: .center)
                                HStack(alignment: .firstTextBaseline, spacing: 4) {
                                    Text(distanceStr(card.distance_km)).font(Theme.Font.num(30)).foregroundStyle(.white)
                                    Text("km").font(Theme.Font.label).foregroundStyle(.white.opacity(0.8))
                                    if let p = card.avg_pace_s_per_km {
                                        Text("· \(paceStr(p))").font(Theme.Font.label.weight(.semibold)).foregroundStyle(.white.opacity(0.9)).padding(.leading, 4)
                                    }
                                }.padding(Theme.Space.m)
                            }
                            .clipShape(RoundedRectangle(cornerRadius: Theme.Radius.chip))
                        } else {
                            HStack(spacing: Theme.Space.l) {
                                statBlock(distanceStr(card.distance_km), "km")
                                if let p = card.avg_pace_s_per_km { statBlock(paceStr(p), "Pace") }
                                if let e = card.relative_effort { statBlock("\(e)", "Effort") }
                            }.padding(.vertical, 4)
                        }
                    }
                }.buttonStyle(.plain)

                Divider().overlay(Theme.Palette.cardStroke)

                HStack(spacing: Theme.Space.l) {
                    Button {
                        withAnimation(Theme.Motion.snappy) { clap = true }
                        Task {
                            await model.toggleKudos(card)
                            withAnimation(Theme.Motion.snappy) { clap = false }
                        }
                    } label: {
                        HStack(spacing: 6) {
                            Image(systemName: liveCard.did_kudos ? "hands.clap.fill" : "hands.clap")
                                .foregroundStyle(liveCard.did_kudos ? Theme.Palette.amber : Theme.Palette.textDim)
                                .scaleEffect(clap ? 1.3 : 1)
                                .symbolEffect(.bounce, value: liveCard.did_kudos)
                            Text("\(liveCard.kudos_count)").font(Theme.Font.label.weight(.semibold)).foregroundStyle(Theme.Palette.textDim)
                        }
                    }.buttonStyle(.plain)
                    HStack(spacing: 6) {
                        Image(systemName: "bubble.left").foregroundStyle(Theme.Palette.textDim)
                        Text("\(liveCard.comment_count)").font(Theme.Font.label).foregroundStyle(Theme.Palette.textDim)
                    }
                    Spacer()
                    if let e = card.relative_effort, card.map_thumb_url != nil {
                        Label("\(e)", systemImage: "bolt.fill").font(Theme.Font.micro.weight(.semibold))
                            .foregroundStyle(Theme.Palette.mint)
                    }
                }
            }
        }
    }

    private func statBlock(_ v: String, _ l: String) -> some View {
        VStack(alignment: .leading, spacing: 2) {
            Text(v).font(Theme.Font.num(18)).foregroundStyle(Theme.Palette.text).monospacedDigit()
            Text(LocalizedStringKey(l)).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim).textCase(.uppercase)
        }
    }
}

private struct RecapCard: View {
    let recap: CommunityRecap
    var body: some View {
        GlassCard(padding: Theme.Space.l) {
            VStack(alignment: .leading, spacing: Theme.Space.s) {
                SectionHeader(title: "Your week", trailing: recap.your_rank.map { "Rank #\($0)" })
                HStack(spacing: Theme.Space.l) {
                    Metric(value: "\(recap.your_effort)", label: "Effort", color: Theme.Palette.mint, icon: "bolt.fill")
                    Metric(value: String(format: "%.1f", recap.your_distance_km), unit: "km", label: "Distance", color: Theme.Palette.cyan, icon: "figure.run")
                    Metric(value: "\(recap.your_activities)", label: "Activities", color: Theme.Palette.indigo, icon: "checkmark.seal.fill")
                }
                if let top = recap.top_performer, !top.is_you {
                    Text("\(top.name) is leading the group this week — \(Int(top.value)) effort.")
                        .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                } else if recap.top_performer?.is_you == true {
                    Text("You're leading the group this week 🏆").font(Theme.Font.micro).foregroundStyle(Theme.Palette.amber)
                }
            }
        }
    }
}

// MARK: Leaderboard

private struct LeaderboardSection: View {
    @EnvironmentObject var model: AppModel
    var body: some View {
        VStack(spacing: Theme.Space.m) {
            PillSwitch(options: [("effort", "Effort"), ("distance", "Distance"), ("points", "Points")],
                       selection: Binding(get: { model.boardMetric }, set: { m in Task { await model.setBoard(metric: m) } }))
            PillSwitch(options: [("week", "Week"), ("month", "Month"), ("all", "All time")],
                       selection: Binding(get: { model.boardWindow }, set: { w in Task { await model.setBoard(window: w) } }))

            switch model.boardPhase {
            case .loading where model.board == nil:
                ForEach(0..<4, id: \.self) { _ in SkeletonCard() }
            case .failed where model.board == nil:
                SyncErrorRow(message: "Couldn't load the leaderboard") { await model.loadBoard() }
            default:
                if let board = model.board, !board.athletes.isEmpty {
                    if let you = board.you { YourStandingCard(you: you, unit: board.unit, total: board.athletes.count) }
                    Podium(board: board)
                    GlassCard(padding: Theme.Space.s) {
                        VStack(spacing: 0) {
                            ForEach(Array(board.athletes.enumerated()), id: \.element.id) { i, row in
                                LeaderRow(row: row, unit: board.unit)
                                if i < board.athletes.count - 1 { Divider().overlay(Theme.Palette.cardStroke) }
                            }
                        }
                    }
                } else {
                    VStack(spacing: Theme.Space.s) {
                        Image(systemName: "trophy").font(.system(size: 36)).foregroundStyle(Theme.Palette.textFaint)
                        Text("No standings yet").font(Theme.Font.title).foregroundStyle(Theme.Palette.text)
                        Text("Log an activity — or follow friends — to start competing.")
                            .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim).multilineTextAlignment(.center)
                    }.frame(maxWidth: .infinity).padding(.vertical, Theme.Space.xl)
                }
            }
        }
        .animation(Theme.Motion.snappy, value: model.boardPhase)
        .task { if model.board == nil { await model.loadBoard() } }
    }
}

/// The viewer's own standing — always visible so you know where you sit, even outside the top rows.
private struct YourStandingCard: View {
    let you: LeaderboardRow
    let unit: String
    let total: Int
    var body: some View {
        GlassCard(padding: Theme.Space.m) {
            HStack(spacing: Theme.Space.m) {
                VStack(spacing: 0) {
                    Text("#\(you.rank)").font(Theme.Font.num(28)).foregroundStyle(Theme.Palette.mint)
                    Text("of \(total)").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
                }
                .frame(width: 64)
                Rectangle().fill(Theme.Palette.cardStroke).frame(width: 1, height: 38)
                VStack(alignment: .leading, spacing: 2) {
                    Text("Your standing").font(Theme.Font.label).foregroundStyle(Theme.Palette.textDim)
                    Text(you.rank == 1 ? "Leading the group 🏆" : "Keep pushing").font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.text)
                }
                Spacer()
                HStack(alignment: .firstTextBaseline, spacing: 3) {
                    Text(boardValue(you.value, unit: unit)).font(Theme.Font.num(24)).foregroundStyle(Theme.Palette.text).monospacedDigit()
                    Text(unit).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
                }
            }
        }
    }
}

private struct Podium: View {
    let board: LeaderboardResponse
    var body: some View {
        let top = Array(board.athletes.prefix(3))
        HStack(alignment: .bottom, spacing: Theme.Space.s) {
            if top.count > 1 { podiumCol(top[1], height: 78, medal: "2") }
            if top.count > 0 { podiumCol(top[0], height: 108, medal: "1") }
            if top.count > 2 { podiumCol(top[2], height: 60, medal: "3") }
        }.frame(maxWidth: .infinity)
    }

    private func podiumCol(_ r: LeaderboardRow, height: CGFloat, medal: String) -> some View {
        let color = medal == "1" ? Theme.Palette.amber : (medal == "2" ? Theme.Palette.textDim : Theme.Palette.cyan)
        return VStack(spacing: 6) {
            ZStack(alignment: .top) {
                AthleteAvatar(name: r.name, url: r.avatar_url, size: medal == "1" ? 58 : 44)
                    .overlay(Circle().stroke(color, lineWidth: 2))
                if medal == "1" {
                    Image(systemName: "crown.fill").font(.system(size: 18)).foregroundStyle(Theme.Palette.amber)
                        .offset(y: -14)
                }
            }
            .padding(.top, medal == "1" ? 14 : 0)
            Text(r.name).font(Theme.Font.micro.weight(.semibold)).foregroundStyle(Theme.Palette.text).lineLimit(1)
            Text(boardValue(r.value, unit: board.unit)).font(Theme.Font.num(16)).foregroundStyle(color).monospacedDigit()
            RoundedRectangle(cornerRadius: 8)
                .fill(LinearGradient(colors: [color.opacity(0.5), color.opacity(0.12)], startPoint: .top, endPoint: .bottom))
                .frame(height: height)
                .overlay(Text(medal).font(Theme.Font.num(22)).foregroundStyle(.white))
        }.frame(maxWidth: .infinity)
    }
}

private struct LeaderRow: View {
    let row: LeaderboardRow
    let unit: String
    var body: some View {
        NavigationLink { AthleteProfileView(athleteId: row.profile_id) } label: {
            HStack(spacing: Theme.Space.m) {
                Text("\(row.rank)").font(Theme.Font.num(16)).foregroundStyle(Theme.Palette.textDim)
                    .frame(width: 26, alignment: .center).monospacedDigit()
                AthleteAvatar(name: row.name, url: row.avatar_url, size: 36)
                VStack(alignment: .leading, spacing: 1) {
                    Text(row.name).font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.text)
                    Text(row.activity_count == 1 ? "1 activity" : "\(row.activity_count) activities").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
                }
                Spacer()
                HStack(alignment: .firstTextBaseline, spacing: 3) {
                    Text(boardValue(row.value, unit: unit)).font(Theme.Font.num(19)).foregroundStyle(row.is_you ? Theme.Palette.mint : Theme.Palette.text).monospacedDigit()
                    Text(unit).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
                }
            }
            .padding(.vertical, 10).padding(.horizontal, Theme.Space.s)
            .background(row.is_you ? Theme.Palette.mint.opacity(0.08) : .clear)
        }.buttonStyle(.plain)
    }
}

// MARK: Find people + requests

private struct FindAthletes: View {
    @EnvironmentObject var model: AppModel
    @State private var query = ""
    @State private var results: [Athlete] = []
    @State private var searching = false
    @State private var searchTask: Task<Void, Never>?
    var body: some View {
        VStack(spacing: Theme.Space.m) {
            HStack(spacing: Theme.Space.s) {
                Image(systemName: "magnifyingglass").foregroundStyle(Theme.Palette.textDim)
                TextField("Find by name or @username", text: $query)
                    .textInputAutocapitalization(.never).autocorrectionDisabled()
                    .foregroundStyle(Theme.Palette.text)
                    .onSubmit { searchTask?.cancel(); searchTask = Task { await runSearch(for: query) } }
                if searching { ProgressView().controlSize(.mini) }
            }
            .padding(.vertical, 12).padding(.horizontal, Theme.Space.m)
            .background(Theme.Palette.card, in: Capsule()).overlay(Capsule().stroke(Theme.Palette.cardStroke))

            if !model.followRequests.isEmpty {
                NavigationLink { RequestsList() } label: {
                    GlassCard {
                        HStack {
                            Image(systemName: "person.badge.clock.fill").foregroundStyle(Theme.Palette.amber)
                            Text(model.followRequests.count == 1 ? "1 follow request" : "\(model.followRequests.count) follow requests")
                                .font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.text)
                            Spacer()
                            Image(systemName: "chevron.right").font(.caption2).foregroundStyle(Theme.Palette.textFaint)
                        }
                    }
                }.buttonStyle(PressCard())
            }

            if results.isEmpty && !query.isEmpty && !searching {
                Text("No one found for “\(query)”.").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim).padding(.top, Theme.Space.l)
            }
            ForEach(results) { a in AthleteRow(athlete: a) }
        }
        .onChange(of: query) { _, q in
            searchTask?.cancel()                       // cancel the in-flight search for the old query
            guard q.count >= 2 else { results = []; searching = false; return }
            searchTask = Task { await runSearch(for: q) }
        }
    }

    /// Debounced + cancellable + query-pinned: only the latest query's results are shown. Without this,
    /// fast typing raced N requests and an earlier (slower) one could overwrite the latest with stale hits.
    private func runSearch(for q: String) async {
        try? await Task.sleep(nanoseconds: 300_000_000)   // debounce keystrokes
        if Task.isCancelled { return }
        searching = true
        let found = (try? await model.api.searchAthletes(q)) ?? []
        if Task.isCancelled || q != query { return }      // a newer query superseded this one — drop it
        results = found
        searching = false
    }
}

private struct RequestsList: View {
    @EnvironmentObject var model: AppModel
    var body: some View {
        ScrollView {
            VStack(spacing: Theme.Space.m) {
                if model.followRequests.isEmpty {
                    Text("No pending requests.").font(Theme.Font.body).foregroundStyle(Theme.Palette.textDim).padding(.top, 60)
                }
                ForEach(model.followRequests) { req in
                    GlassCard {
                        HStack(spacing: Theme.Space.m) {
                            AthleteAvatar(name: req.athlete.name, url: req.athlete.avatar_url, size: 44)
                            VStack(alignment: .leading, spacing: 1) {
                                Text(req.athlete.name).font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.text)
                                if let u = req.athlete.username { Text("@\(u)").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim) }
                            }
                            Spacer()
                            Button { Task { await model.accept(req) } } label: {
                                Text("Accept").font(Theme.Font.label.weight(.bold)).foregroundStyle(.white)
                                    .padding(.horizontal, 14).padding(.vertical, 8)
                                    .background(Theme.Grad.brand, in: Capsule())
                            }.buttonStyle(.plain)
                            Button { Task { await model.decline(req) } } label: {
                                Image(systemName: "xmark").font(.caption.weight(.bold)).foregroundStyle(Theme.Palette.textDim)
                                    .padding(8).background(Theme.Palette.card, in: Circle())
                            }.buttonStyle(.plain)
                        }
                    }
                }
            }.padding(Theme.Space.m)
        }
        .background(Theme.Palette.bg.ignoresSafeArea())
        .navigationTitle("Requests")
        .task { await model.loadFollowRequests() }
    }
}

private struct AthleteRow: View {
    @EnvironmentObject var model: AppModel
    @State var athlete: Athlete
    @State private var busy = false
    var body: some View {
        NavigationLink { AthleteProfileView(athleteId: athlete.id) } label: {
            GlassCard {
                HStack(spacing: Theme.Space.m) {
                    AthleteAvatar(name: athlete.name, url: athlete.avatar_url, size: 44)
                    VStack(alignment: .leading, spacing: 1) {
                        Text(athlete.name).font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.text)
                        Group {
                            if let u = athlete.username { Text(verbatim: "@\(u)") }
                            else { Text("\(athlete.total_activities) activities") }
                        }
                        .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                    }
                    Spacer()
                    FollowButton(athlete: $athlete, busy: $busy)
                }
            }
        }.buttonStyle(PressCard())
    }
}

private struct FollowButton: View {
    @EnvironmentObject var model: AppModel
    @Binding var athlete: Athlete
    @Binding var busy: Bool
    var body: some View {
        let state = athlete.follow_state
        Button {
            Haptic.tap()
            Task {
                busy = true; defer { busy = false }
                let res: FollowResult? = state == nil
                    ? try? await model.api.follow(athlete.id)
                    : try? await model.api.unfollow(athlete.id)
                if let res { athlete = res.athlete }
            }
        } label: {
            Group {
                if busy { ProgressView().controlSize(.mini).tint(Theme.Palette.text) }
                else {
                    Text(state == "accepted" ? "Following" : (state == "pending" ? "Requested" : "Follow"))
                        .font(Theme.Font.label.weight(.bold))
                }
            }
            .foregroundStyle(state == nil ? .white : Theme.Palette.textDim)
            .frame(minWidth: 84).padding(.vertical, 8).padding(.horizontal, 12)
            .background {
                if state == nil { Capsule().fill(Theme.Grad.brand) }
                else { Capsule().fill(Theme.Palette.card).overlay(Capsule().stroke(Theme.Palette.cardStroke)) }
            }
        }.buttonStyle(.plain)
    }
}

// MARK: Athlete profile

struct AthleteProfileView: View {
    @EnvironmentObject var model: AppModel
    let athleteId: Int
    @State private var data: AthleteProfileResponse?
    @State private var busy = false
    @State private var phase: AppModel.LoadPhase = .loading

    var body: some View {
        ScrollView {
            VStack(spacing: Theme.Space.m) {
                if let d = data {
                    header(d.athlete)
                    if d.achievements.contains(where: { $0.earned }) { BadgeWall(achievements: d.achievements) }
                    if !d.activities.isEmpty {
                        SectionHeader(title: "Recent").frame(maxWidth: .infinity, alignment: .leading)
                        ForEach(d.activities) { card in FeedCard(card: card) }
                    }
                } else if phase == .failed {
                    SyncErrorRow(message: "Couldn't load this athlete") { await load() }
                } else {
                    SkeletonCard().padding(.top, Theme.Space.l)
                }
            }.padding(Theme.Space.m)
        }
        .background(Theme.Palette.bg.ignoresSafeArea())
        .navigationTitle(data?.athlete.name ?? "Athlete")
        .navigationBarTitleDisplayMode(.inline)
        .task { await load() }
    }

    private func header(_ a: Athlete) -> some View {
        GlassCard(padding: Theme.Space.l) {
            VStack(spacing: Theme.Space.m) {
                AthleteAvatar(name: a.name, url: a.avatar_url, size: 76)
                VStack(spacing: 3) {
                    Text(a.name).font(Theme.Font.display(24)).foregroundStyle(Theme.Palette.text)
                    if let u = a.username { Text("@\(u)").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim) }
                }
                if let bio = a.bio, !bio.isEmpty {
                    Text(bio).font(Theme.Font.body).foregroundStyle(Theme.Palette.textDim).multilineTextAlignment(.center)
                }
                HStack(spacing: Theme.Space.l) {
                    stat("\(a.total_activities)", "Activities")
                    stat(String(format: "%.0f", a.total_distance_km), "km")
                    stat("\(a.follower_count)", "Followers")
                }
                if !a.is_you {
                    var binding = a
                    FollowButton(athlete: Binding(get: { data?.athlete ?? binding }, set: { v in
                        if var d = data { d = AthleteProfileResponse(athlete: v, activities: d.activities, achievements: d.achievements); data = d }
                        binding = v
                    }), busy: $busy)
                    .frame(maxWidth: .infinity)
                }
            }
        }
    }

    private func stat(_ v: String, _ l: String) -> some View {
        VStack(spacing: 2) {
            Text(v).font(Theme.Font.num(20)).foregroundStyle(Theme.Palette.text).monospacedDigit()
            Text(LocalizedStringKey(l)).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim).textCase(.uppercase)
        }
    }

    private func load() async {
        phase = .loading
        do { data = try await model.api.athlete(athleteId); phase = .loaded }
        catch { phase = data == nil ? .failed : .loaded }
    }
}

private struct BadgeWall: View {
    let achievements: [Achievement]
    private let cols = [GridItem(.adaptive(minimum: 72), spacing: Theme.Space.s)]
    var body: some View {
        GlassCard {
            VStack(alignment: .leading, spacing: Theme.Space.s) {
                SectionHeader(title: "Badges", trailing: "\(achievements.filter(\.earned).count) earned")
                LazyVGrid(columns: cols, spacing: Theme.Space.m) {
                    ForEach(achievements.filter(\.earned)) { b in
                        VStack(spacing: 5) {
                            Text(b.emoji).font(.system(size: 30))
                            Text(b.title).font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                                .multilineTextAlignment(.center).lineLimit(2)
                        }
                    }
                }
            }
        }
    }
}

// MARK: Activity detail (kudos + comments)

struct ActivityDetailView: View {
    @EnvironmentObject var model: AppModel
    let card: ActivityCard
    @State private var didKudos: Bool
    @State private var kudosCount: Int
    @State private var comments: [CommentItem] = []
    @State private var draft = ""
    @State private var posting = false

    init(card: ActivityCard) {
        self.card = card
        _didKudos = State(initialValue: card.did_kudos)
        _kudosCount = State(initialValue: card.kudos_count)
    }

    var body: some View {
        ScrollView {
            VStack(spacing: Theme.Space.m) {
                HStack(spacing: Theme.Space.s) {
                    AthleteAvatar(name: card.athlete.name, url: card.athlete.avatar_url, size: 44)
                    VStack(alignment: .leading, spacing: 1) {
                        Text(card.athlete.name).font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.text)
                        Text("\(card.title) · \(relativeTime(card.started_at))").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                    }
                    Spacer()
                }

                if let url = card.map_thumb_url, let u = URL(string: url) {
                    AsyncImage(url: u) { img in img.resizable().scaledToFill() } placeholder: { Shimmer().frame(height: 200) }
                        .frame(height: 200).frame(maxWidth: .infinity).clipped()
                        .clipShape(RoundedRectangle(cornerRadius: Theme.Radius.card))
                }

                GlassCard {
                    HStack(spacing: Theme.Space.l) {
                        Metric(value: distanceStr(card.distance_km), unit: "km", label: "Distance", color: Theme.Palette.text, icon: "figure.run")
                        if let p = card.avg_pace_s_per_km { Metric(value: paceStr(p), label: "Pace", color: Theme.Palette.cyan, icon: "speedometer") }
                        if let e = card.relative_effort { Metric(value: "\(e)", label: "Effort", color: Theme.Palette.mint, icon: "bolt.fill") }
                    }
                }

                Button { toggleKudos() } label: {
                    HStack(spacing: 8) {
                        Image(systemName: didKudos ? "hands.clap.fill" : "hands.clap")
                        Text(didKudos ? "Cheered · \(kudosCount)" : "Give kudos · \(kudosCount)").font(Theme.Font.body.weight(.semibold))
                    }
                    .foregroundStyle(didKudos ? .white : Theme.Palette.text)
                    .frame(maxWidth: .infinity).padding(.vertical, 13)
                    .background {
                        if didKudos { Capsule().fill(Theme.Palette.amber) }
                        else { Capsule().fill(Theme.Palette.card).overlay(Capsule().stroke(Theme.Palette.cardStroke)) }
                    }
                }.buttonStyle(.plain)

                VStack(alignment: .leading, spacing: Theme.Space.s) {
                    SectionHeader(title: "Comments").frame(maxWidth: .infinity, alignment: .leading)
                    ForEach(comments) { c in CommentRow(comment: c) { await delete(c) } }
                    if comments.isEmpty {
                        Text("Be the first to comment.").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
                    }
                }

                HStack(spacing: Theme.Space.s) {
                    TextField("Add a comment…", text: $draft, axis: .vertical)
                        .foregroundStyle(Theme.Palette.text)
                        .padding(.vertical, 10).padding(.horizontal, Theme.Space.m)
                        .background(Theme.Palette.card, in: Capsule()).overlay(Capsule().stroke(Theme.Palette.cardStroke))
                    Button { Task { await post() } } label: {
                        Image(systemName: "arrow.up").font(.body.weight(.bold)).foregroundStyle(.white)
                            .frame(width: 40, height: 40).background(Theme.Grad.brand, in: Circle())
                    }.buttonStyle(.plain).disabled(draft.trimmingCharacters(in: .whitespaces).isEmpty || posting)
                }
            }.padding(Theme.Space.m)
        }
        .background(Theme.Palette.bg.ignoresSafeArea())
        .navigationTitle(card.title)
        .navigationBarTitleDisplayMode(.inline)
        .task { comments = (try? await model.api.comments(card.id)) ?? [] }
    }

    private func toggleKudos() {
        Haptic.tap()
        let want = !didKudos
        didKudos = want; kudosCount = max(0, kudosCount + (want ? 1 : -1))
        Task {
            let st = want ? try? await model.api.kudos(card.id) : try? await model.api.unkudos(card.id)
            if let st { didKudos = st.did_kudos; kudosCount = st.kudos_count }
        }
    }

    private func post() async {
        let body = draft.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !body.isEmpty else { return }
        posting = true; defer { posting = false }
        if let c = try? await model.api.postComment(card.id, body: body) {
            comments.append(c); draft = ""; Haptic.soft()
        }
    }

    private func delete(_ c: CommentItem) async {
        try? await model.api.deleteComment(c.id)
        comments.removeAll { $0.id == c.id }
    }
}

private struct CommentRow: View {
    let comment: CommentItem
    let onDelete: () async -> Void
    var body: some View {
        HStack(alignment: .top, spacing: Theme.Space.s) {
            AthleteAvatar(name: comment.athlete.name, url: comment.athlete.avatar_url, size: 32)
            VStack(alignment: .leading, spacing: 2) {
                Text(comment.athlete.name).font(Theme.Font.micro.weight(.semibold)).foregroundStyle(Theme.Palette.text)
                Text(comment.body).font(Theme.Font.body).foregroundStyle(Theme.Palette.textDim)
            }
            Spacer()
            if comment.is_mine {
                Button { Task { await onDelete() } } label: {
                    Image(systemName: "trash").font(.caption2).foregroundStyle(Theme.Palette.textFaint)
                }.buttonStyle(.plain)
            }
        }
        .padding(.vertical, 4)
    }
}

// MARK: - Community settings

struct CommunitySettingsView: View {
    @EnvironmentObject var model: AppModel
    @Environment(\.dismiss) private var dismiss
    @State private var enabled = false
    @State private var username = ""
    @State private var bio = ""
    @State private var requireApproval = true
    @State private var visibility = "followers"
    @State private var saving = false
    @State private var photoItem: PhotosPickerItem?
    @State private var uploadingAvatar = false

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(spacing: Theme.Space.m) {
                    GlassCard {
                        Toggle(isOn: $enabled) {
                            VStack(alignment: .leading, spacing: 2) {
                                Text("Join the community").font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.text)
                                Text("Be discoverable, follow people, and share your activities.").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                            }
                        }.tint(Theme.Palette.mint)
                    }

                    if enabled {
                        // Profile photo — how friends recognize you in the feed + leaderboard.
                        VStack(spacing: Theme.Space.s) {
                            PhotosPicker(selection: $photoItem, matching: .images) {
                                ZStack(alignment: .bottomTrailing) {
                                    AthleteAvatar(name: model.communitySettings?.display_name ?? model.user?.name ?? "You",
                                                  url: model.communitySettings?.avatar_url, size: 96)
                                        .overlay(Circle().stroke(Theme.Palette.cardStroke, lineWidth: 1))
                                    ZStack {
                                        Circle().fill(Theme.Grad.brand).frame(width: 30, height: 30)
                                        if uploadingAvatar { ProgressView().controlSize(.mini).tint(.white) }
                                        else { Image(systemName: "camera.fill").font(.caption2).foregroundStyle(.white) }
                                    }
                                }
                            }
                            Text("Tap to set your photo").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                        }
                        .frame(maxWidth: .infinity).padding(.vertical, Theme.Space.s)
                        GlassCard {
                            VStack(alignment: .leading, spacing: Theme.Space.s) {
                                field("Username", text: $username, prefix: "@", autocap: false)
                                Text("Your unique handle — friends find you by this. Letters, numbers, underscore.")
                                    .font(Theme.Font.micro).foregroundStyle(Theme.Palette.textFaint)
                                Divider().overlay(Theme.Palette.cardStroke)
                                field("Bio", text: $bio, prefix: nil, autocap: true)
                            }
                        }
                        GlassCard {
                            VStack(alignment: .leading, spacing: Theme.Space.m) {
                                Toggle(isOn: $requireApproval) {
                                    VStack(alignment: .leading, spacing: 2) {
                                        Text("Approve new followers").font(Theme.Font.body.weight(.semibold)).foregroundStyle(Theme.Palette.text)
                                        Text("Private account — review each follow request.").font(Theme.Font.micro).foregroundStyle(Theme.Palette.textDim)
                                    }
                                }.tint(Theme.Palette.indigo)
                                Divider().overlay(Theme.Palette.cardStroke)
                                VStack(alignment: .leading, spacing: Theme.Space.s) {
                                    Text("Who sees new activities").font(Theme.Font.label).foregroundStyle(Theme.Palette.textDim)
                                    PillSwitch(options: [("private", "Only me"), ("followers", "Followers"), ("public", "Everyone")], selection: $visibility)
                                }
                            }
                        }
                    }
                }.padding(Theme.Space.m)
            }
            .background(Theme.Palette.bg.ignoresSafeArea())
            .navigationTitle("Community")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .topBarLeading) { Button("Cancel") { dismiss() }.tint(Theme.Palette.textDim) }
                ToolbarItem(placement: .topBarTrailing) {
                    Button { Task { await save() } } label: {
                        if saving { ProgressView().controlSize(.mini) } else { Text("Save").bold() }
                    }.tint(Theme.Palette.mint)
                }
            }
        }
        .onAppear {
            let s = model.communitySettings
            enabled = s?.community_enabled ?? false
            username = s?.username ?? ""
            bio = s?.bio ?? ""
            requireApproval = s?.followers_require_approval ?? true
            visibility = s?.default_activity_visibility ?? "followers"
        }
        .onChange(of: photoItem) { _, item in
            guard let item else { return }
            Task {
                uploadingAvatar = true; defer { uploadingAvatar = false }
                if let data = try? await item.loadTransferable(type: Data.self) {
                    _ = await model.uploadAvatar(data); Haptic.success()
                }
            }
        }
    }

    private func field(_ label: String, text: Binding<String>, prefix: String?, autocap: Bool) -> some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(LocalizedStringKey(label)).font(Theme.Font.label).foregroundStyle(Theme.Palette.textDim)
            HStack(spacing: 2) {
                if let prefix { Text(prefix).foregroundStyle(Theme.Palette.textFaint) }
                TextField(LocalizedStringKey(label), text: text)
                    .textInputAutocapitalization(autocap ? .sentences : .never).autocorrectionDisabled(!autocap)
                    .foregroundStyle(Theme.Palette.text)
            }
        }
    }

    private func save() async {
        saving = true; defer { saving = false }
        var fields: [String: Any] = [
            "community_enabled": enabled,
            "followers_require_approval": requireApproval,
            "default_activity_visibility": visibility,
        ]
        let u = username.trimmingCharacters(in: .whitespaces)
        if !u.isEmpty { fields["username"] = u }
        fields["bio"] = bio.trimmingCharacters(in: .whitespaces)
        let ok = await model.updateCommunity(fields)
        if ok {
            if enabled { await model.loadFeed(); await model.loadBoard() }
            dismiss()
        }
    }
}

// MARK: - Shared community helpers

struct AthleteAvatar: View {
    let name: String
    let url: String?
    var size: CGFloat = 44
    var body: some View {
        ZStack {
            Circle().fill(Theme.Grad.brand)
            if let url, let u = URL(string: url) {
                AsyncImage(url: u) { img in img.resizable().scaledToFill() } placeholder: { Color.clear }
            } else {
                Text(initials).font(Theme.Font.num(size * 0.36)).foregroundStyle(.white)
            }
        }
        .frame(width: size, height: size).clipShape(Circle())
    }
    private var initials: String {
        name.split(separator: " ").prefix(2).compactMap { $0.first.map(String.init) }.joined().uppercased()
    }
}

/// A compact custom segmented control — the premium replacement for `.segmented` Picker.
struct PillSwitch<T: Hashable>: View {
    let options: [(T, String)]
    @Binding var selection: T
    var body: some View {
        HStack(spacing: 4) {
            ForEach(options, id: \.0) { opt in
                let on = selection == opt.0
                Text(LocalizedStringKey(opt.1)).font(Theme.Font.label.weight(.semibold))
                    .padding(.vertical, 9).frame(maxWidth: .infinity)
                    .foregroundStyle(on ? Theme.Palette.text : Theme.Palette.textDim)
                    .background {
                        if on { Capsule().fill(Theme.Palette.card).overlay(Capsule().stroke(Theme.Palette.cardStroke)) }
                    }
                    .contentShape(Capsule())
                    .onTapGesture { Haptic.tap(); withAnimation(Theme.Motion.snappy) { selection = opt.0 } }
            }
        }
        .padding(4)
        .background(Capsule().fill(Theme.Palette.bg2)).overlay(Capsule().stroke(Theme.Palette.cardStroke))
    }
}

private func boardValue(_ v: Double, unit: String) -> String {
    unit == "km" ? String(format: "%.1f", v) : String(Int(v.rounded()))
}

private func distanceStr(_ km: Double?) -> String {
    guard let km, km > 0 else { return "—" }
    return String(format: "%.2f", km)
}

private func paceStr(_ secPerKm: Int) -> String {
    guard secPerKm > 0 else { return "—" }
    return String(format: "%d:%02d /km", secPerKm / 60, secPerKm % 60)
}

private func activityIcon(_ type: String?) -> String {
    switch type {
    case "run": return "figure.run"
    case "walk": return "figure.walk"
    case "cycle": return "bicycle"
    case "stairs": return "figure.stairs"
    default: return "figure.mixed.cardio"
    }
}

private func relativeTime(_ iso: String?) -> String {
    guard let iso, let date = ISO8601DateFormatter().date(from: iso) else { return "" }
    let s = Int(Date().timeIntervalSince(date))
    if s < 3600 { return String(localized: "\(max(1, s / 60))m ago") }
    if s < 86400 { return String(localized: "\(s / 3600)h ago") }
    if s < 604800 { return String(localized: "\(s / 86400)d ago") }
    let f = DateFormatter(); f.dateFormat = "MMM d"; return f.string(from: date)
}
