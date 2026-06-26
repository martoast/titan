# Titan Community — Strava-style opt-in social + leaderboards

A community layer so friends & family can follow each other, see each other's runs/workouts
and routes, cheer them on, and compete on leaderboards. **Opt-in**: you're invisible until you
enable it. Modeled on Strava.

## Product decisions (locked with the user)
- **Follow-based graph** — you follow athletes; private accounts approve followers; your feed is
  the people you follow (+ yourself). Not a global feed.
- **Full routes published** — exact GPS, no privacy-zone trimming. (User accepted the home-location
  trade-off; revisit if the group grows.)
- **Engagement v1 = everything**: kudos (likes), comments, badges/achievements, weekly recap.
- **Leaderboards**: headline = **Effort score** (Σ relative_effort, HR-zone-weighted load — fair
  across fitness levels). Switchable tabs: **Distance** (Σ km) and **Activity points** (blended
  game score). Windows: **This week / This month / All time**.

## Visibility model
- `profiles.community_enabled` (bool, default false) — the master opt-in. Off ⇒ you don't appear
  anywhere, can't be followed, your activities are never shared.
- `profiles.followers_require_approval` (bool, default true) — private account ⇒ follow requests
  are `pending` until you accept.
- `profiles.default_activity_visibility` ∈ {private, followers, public} default `followers`.
- `activity_sessions.visibility` ∈ {private, followers, public} nullable ⇒ null inherits the
  profile default. Effective = coalesce(session.visibility, profile.default_activity_visibility).
- An activity is visible to viewer V iff: owner == V, OR (owner.community_enabled AND effective ∈
  {public} ) OR (effective == followers AND V follows owner with status=accepted).

## Schema (new migrations)
- alter `profiles`: community_enabled, username (unique, nullable), bio, avatar_path,
  followers_require_approval, default_activity_visibility.
- alter `activity_sessions`: visibility (nullable enum).
- `follows`: follower_id, followee_id (both → profiles), status {pending,accepted}, accepted_at.
  unique(follower_id, followee_id).
- `kudos`: profile_id, activity_session_id, unique pair.
- `activity_comments`: profile_id, activity_session_id, body, timestamps.
- `achievements`: profile_id, key, awarded_at, meta json. unique(profile_id, key).

## Models / relationships
- `Follow`, `Kudo`, `ActivityComment`, `Achievement`.
- Profile: following(), followers(), followingAccepted(), isFollowing(), achievements().
- ActivitySession: kudos(), comments(), scopeVisibleTo($viewer), effectiveVisibility().

## Services
- `LeaderboardService` — aggregate Σmetric over (you + accepted-following) within a window, ranked.
- `AchievementEngine` — evaluate + award after each seal and on a nightly pass (time-window badges).
- `WeeklyRecap` — Sunday: per opted-in profile, week effort/distance + rank + group top → push.

## Mobile API (auth.any, prefix /me + /community)
- GET  /community/feed                       — followed athletes' activities (paginated)
- GET  /community/leaderboard?metric&window  — ranked rows, you flagged
- GET  /community/settings  / PATCH          — opt-in, username, bio, default visibility
- GET  /community/requests                   — pending follow requests
- GET  /community/recap                      — this week's recap
- GET  /athletes/search?q=                   — discover people
- GET  /athletes/{profile}                   — athlete profile + recent activities + follow state
- POST /athletes/{profile}/follow  / DELETE  — follow / unfollow
- POST /community/requests/{follow}/accept|decline
- POST /activities/{session}/kudos  / DELETE
- GET  /activities/{session}/comments  / POST  / DELETE {comment}
- GET  /me/achievements
- PATCH /activities/{session}/visibility

## iOS
- New **Community** tab (`person.2.fill`). Segments: Feed · Leaderboard · Find.
- Feed cards (athlete, map thumb, stats, kudos+comment). Activity detail w/ kudos+comments.
- Leaderboard: metric × window switcher, ranked rows, you highlighted, podium top-3.
- Athlete profile, follow/approve, find people, follow-requests inbox, badges, weekly recap card.
- Community settings sheet in ProfileView (opt-in, @username, bio, default visibility).

## Web (Blade, lighter)
- /community (feed), /community/leaderboard, /athletes/{username}. Link from /fitness.

## Build phases (commit per phase)
1. ✅ **Schema + models + visibility** (+ tests — CommunityVisibilityTest 3/3)
2. ✅ **Follow graph + settings** API
3. ✅ **Feed + kudos + comments** API
4. ✅ **Leaderboard service + endpoint**
5. ✅ **Achievements + weekly recap** (+ scheduler: weekly-recap, badge-sweep). API tests 6/6.
6. ✅ **iOS**: models, APIClient, AppModel, Community tab (feed/leaderboard/find), athlete
   profile, activity detail (kudos+comments), settings sheet, **avatar upload + unique @username**.
   Chesky polish pass done (poster feed, crowned podium, your-standing card, kudos delight).
7. ⏳ **Web**: community pages (Blade) — DEFERRED (iOS is the primary surface).

## Follow-ups requested by the user (in progress / queued)
- ✅ Avatar upload + unique public @username (you're found by handle, seen by name + photo).
- ⏳ **Live run feed** — when a run starts on the band's Run face, show it tracking live in the
  app (distance / elapsed / pace / live HR counting up), mirroring the live-HR feed. Motivational.
  (Separate feature; see tasks/live-run/.)
- Web community pages (phase 7).
