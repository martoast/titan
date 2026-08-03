# iOS day-chat picker — build & test notes

**Status: written, NOT compiled.** There is no Swift toolchain on the server, so every line here is
unverified against a compiler. The PHP side it talks to *is* tested (7 new tests, all green) and
deployed. Treat the Swift as a reviewed patch, not as working code.

## What changed

| File | Change |
|---|---|
| `Titan/Sources/App/Models.swift` | `CoachHistoryResponse` gains `day`/`day_label`/`day_full`/`read_only`; `CoachHistoryMessage` gains `kind`/`at`; new `CoachDaysResponse` + `CoachDay`; `ChatMessage` gains `kind`/`at` + `isProactive` |
| `Titan/Sources/App/APIClient.swift` | new `coachDays()` and `coachDay(conversationId:)`; `coachHistory` now delegates to `coachDay` |
| `Titan/Sources/Features/CoachView.swift` | day state on the view model (`days`, `dayLabel`, `readOnly`, `viewingDayId`), `loadDays` / `openDay` / `goToday`; calendar toolbar button → `CoachDayPicker` sheet; past-day banner; read-only composer; proactive tag + timestamp on bubbles; new `CoachDayPicker` view at the bottom of the file |

No new files, no `project.yml` change — everything lives in files the target already compiles.

## The endpoints (live in production now)

```
GET /api/coach/days
  → { today: "2026-08-02",
      days: [ { id, day, label, full, message_count, is_today }, ... ] }   // newest first

GET /api/coach/{id}/messages
  → { day, day_label, day_full, read_only, messages: [ {id, role, kind, content, at, status} ],
      has_more, oldest_id }
```

Try them with a bearer token before building, so you know the app is talking to something real:

```bash
curl -s -H "Authorization: Bearer $TOKEN" https://titan.fullstacklabs.org/api/coach/days | jq '.days[:3]'
```

## The design decisions worth reviewing

- **`conversationId` vs `viewingDayId`.** `conversationId` (persisted in UserDefaults) stays pointed
  at the LIVE thread — it is what sends and reply-polling use. `viewingDayId` is only what's on
  screen. Browsing an old day must never re-point the composer at the past, which is why they're
  separate.
- **`reconcile()` bails when a past day is open**, so returning from the background doesn't yank you
  back to today mid-read.
- **`openDay` cancels `pollTask`** — a reply still streaming into the live thread would otherwise
  write into whatever transcript is now displayed.
- **The server is the authority on read-only.** `submit()` also guards on it, but that's belt and
  braces; the composer is hidden entirely for a past day.
- **Stale-id tolerance is server-side** (`apiMessages`), so `loadHistory` adopts whatever day the
  server actually returned rather than trusting the cached id. This is what fixes the blank-coach
  bug after the demo seed replaced every conversation.

## Things I'd check first when it doesn't compile

1. `CoachDayPicker` is declared `struct` (not `private`) because it's referenced from `CoachView`'s
   `.sheet`. If you prefer, move it into its own file.
   (`PressCard` in `DashboardView.swift:368` and `Shimmer` in `Components.swift:203` are both
   internal, so they're visible from here — I checked.)
3. `String(localized:)` needs iOS 15+, `presentationDetents` needs 16+ — the target is 17, so both
   are fine, but `presentationDetents` isn't used anywhere else in the app yet.
4. `Section { } header: { }` inside `LazyVStack` with `pinnedViews: [.sectionHeaders]` — valid, but
   if the sticky month headers misbehave, drop `pinnedViews` and it degrades to plain rows.
5. The month grouping parses `day` ("yyyy-MM-dd") in **UTC deliberately** — it's a calendar label,
   not an instant. Don't "fix" it to local or days will shift for anyone west of UTC.

## Manual test pass

- [ ] Open Coach → calendar button top-right → sheet lists days grouped by month, newest first, today tagged **LIVE**
- [ ] Tap a past day → transcript loads, banner shows the full date, composer is replaced by "Back to today"
- [ ] Background the app on a past day, return → still on that day (doesn't jump to today)
- [ ] "Back to today" → composer returns, sending works
- [ ] Send a message → lands in today, day list shows the bumped count on reopen
- [ ] A briefing/reaction bubble shows the cyan **Briefing** / **Coach update** tag and a timestamp
- [ ] Delete the app and reinstall (clears the cached conversation id) → coach still loads today
