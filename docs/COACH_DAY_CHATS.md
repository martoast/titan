# Coach chats are day-scoped

> Read this before touching `CoachController`, `CoachService::history()`, `Conversation`, or any of
> the eight proactive coach jobs.

## The model

**One conversation per profile per local calendar day.** `conversations.day` is the key, with a
unique index on `(profile_id, day)`. There are no titles and no "new chat" button — the date *is*
the thread's identity, so the chat list reads like a journal you scroll back through.

Everything the coach and the user exchanged on a day lives in that day's row:

| what | `chat_messages.kind` |
|---|---|
| the user's message, and the coach's reply to it | `NULL` |
| morning briefing / evening nudge | `briefing` |
| event-driven push (sleep sealed, workout sealed, meal logged, device sync, spot reading, first connection, behaviour impacts) | `reaction` |

Before this, the proactive messages went to a single hidden `Daily Briefings` thread that the coach
page **filtered out and never rendered** — so a push notification saying "tap to read" opened a page
that didn't contain it. Merging them into the day fixed that as a side effect.

## The rules

1. **`Conversation::forDay()` is the only way to create a conversation.** Web, native app and every
   job funnel through it. It is race-safe: concurrent creates collide on the unique index and the
   loser re-reads the winner.
2. **Every send lands in TODAY's chat**, regardless of which conversation id the client posted to.
   A tab left open on yesterday, or a user reading an old day, cannot append to the past. The UI
   also disables the composer on a past day, but the server is the thing that enforces it.
3. **A past day is read-only.** `?c={id}` or `?d=YYYY-MM-DD` opens one; `readOnly` drives the UI.
4. **An empty day is never created by merely viewing the page** — only by the first message. The
   chat list also hides any day with zero messages.
5. **The coach's memory rolls ACROSS days.** See below — this is the subtle one.

## Cross-day memory (`CoachService::history()`)

Splitting the UI by day must not reset the coach at midnight. The replay window therefore spans the
current day plus `HISTORY_DAYS` (14) before it, capped at `HISTORY_CAP` (40) turns, with each day's
block introduced by a dated system divider:

```
=== Thursday, July 30, 2026 ===
user:      knee felt off on squats
assistant: drop to 60% next session
=== TODAY — Saturday, August 1, 2026 ===
user:      back to normal load?
```

That divider is what lets the coach answer "what did we decide Tuesday?" from its own context.
Proactive messages are re-read with an `[update you pushed to them, unprompted]` tag, so the coach
doesn't thank the user for a question they never asked.

Compaction still runs per-day; a day that has a `summary` contributes it in place of the turns it
covers.

## Traps

- **`whereDate`, never `=`, when matching `day`.** The `date` cast hands the driver a full datetime.
  MySQL truncates it into the DATE column; SQLite stores `"2026-08-01 00:00:00"` verbatim. A plain
  equality lookup for `"2026-08-01"` then misses the row it just wrote — so every call creates a
  duplicate — and a `whereBetween` upper bound silently excludes the current day from its own
  memory window. Both bugs happened during this build; both are pinned by tests.
- **Timestamps are LOCAL wall-clock, not UTC.** `APP_TIMEZONE` (America/Mexico_City) sets PHP's
  default zone, so Laravel writes and reads `created_at` as local time. Do **not** convert when
  deriving a day from a timestamp — a `->utc()` would push every evening message onto the next day.
- **Dedupe guards live on `profile->settings`** (`sleep_reacted`, `workout_reacted`, …), not on the
  conversation, so the daily thread switch does not let a reaction fire twice.
- The **native app** keeps a cached conversation id and self-heals: the server returns today's id on
  every send. No Swift change was needed, but a cold launch may briefly show yesterday's thread
  until the first message.

## Backfill

`php artisan coach:backfill-day-chats [--dry-run]` re-files pre-day-chats history. It is idempotent
and deliberately a command, not a migration — migrations auto-run at container boot and a data
reshuffle must not fire itself mid-deploy.

Rehearsed against a copy of production (552 messages / 21 threads → 147 day chats): every message
preserved, none orphaned, no duplicate days, zero day/timestamp mismatches, second run a no-op.
