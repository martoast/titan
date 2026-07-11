# SLEEP AS SESSIONS — naps + overnight as individual, revisitable sessions with a daily total

**Spec for the dev agent · from Alex + Henry · 2026-07-11**
**Status: BUILD. This is why Alex "can't see his sleep" — the data exists, the UI throws most of it away.**

## The problem (Alex's words + proven in data)

> "We only show last night's sleep — if I take a nap in the day we don't see it. It should be like a
> workout session: save each sleep session, come back to see each one's data individually, on top of the
> numbers from them all added together for today's sleep."

Proven: on 2026-07-11 Alex has **two sealed sleep sessions** — overnight #54 (269m, fully staged) and nap
#55 (86m, fully staged, deep 32/light 43/rem 11). But:
- `/api/me/sleep` `nights[]` returns **only non-nap rows** (the nap is filtered out entirely), and the
  `is_nap` flag isn't even in the payload.
- `detail` is a SINGLE object — only the most-recent overnight. The nap's timeline/stages are unreachable.
- "Today's sleep" shows 269m; the real total (269 + 86 = **355m**) is never computed. The nap is captured,
  staged, and then **discarded from the UI.**

The data model already supports this (`sleep_logs` rows with `is_nap`, one per session). It's purely the
API shape + the app treating sleep as one "last night" object instead of a list of sessions.

## The design — mirror the WORKOUT sessions model

Sleep should behave like the activity/workout screen: a **list of sessions**, each openable to its own
detail, above a **daily aggregate**.

### 1 · API: expose sessions + a daily roll-up
- Return **all of a day's sleep sessions** (overnight + every nap) as a list, each carrying its OWN
  `is_nap`, span, stages, quality, coverage, and its full `hypnogram`/`epoch_sec` (the per-session detail
  the timeline needs) — not just the latest. Reuse `SleepDetail` per session.
- Add a **daily sleep aggregate**: total asleep = Σ session durations, combined stage minutes (Σ deep, Σ
  rem, Σ light), and a sensible day-level quality/performance. Decide the sleep-need/debt basis: the
  overnight is the anchor for debt/need; naps ADD to total sleep and reduce debt (a nap is real recovery).
  Make that explicit — don't double-count or let a nap inflate "sleep performance" as if it were a night.
- Keep it timezone-correct (Alex is America/Tijuana; sessions must group on the USER's local day — this is
  the same tz-convention trap that's bitten us; use the profile tz, not the app default).

### 2 · iOS: a sleep sessions list + per-session detail + today card
- **Today card** (top): total asleep across sessions ("5h 55m · 1 night + 1 nap"), combined stage bar,
  performance. Tapping a segment or "see sessions" expands the list.
- **Sessions list** (like the workouts list): one row per session — night or nap, time range, duration,
  quality, a mini stage ribbon. Naps clearly badged.
- **Session detail** (like a workout detail): the FULL v2 sleep timeline for THAT session — stage ribbon +
  the dense movement strip (T10 dense motion now flows — 80% coverage on the nap) + HR overlay + scrub.
  This is the "see the timeline and stages" Alex is missing, PER session.
- History: past days each show their sessions, revisitable — a nap from Tuesday is still there.

### 3 · The v2 timeline component (build it — it was speced, never built)
The server already serves the hypnogram + stages (`/api/me/sleep` detail: 552-epoch hypnogram confirmed).
Build the timeline UI per SLEEP_TIMELINE.md + SLEEP_TIMELINE_V2 (stage ribbon / movement strip / HR
overlay, honest NODATA holes) and render it in the session detail (hero) + the today card (mini). Diagnose
why the CURRENT build shows no timeline at all despite the data being served — likely it was never wired
into the shipped view, or the summary only renders totals. This is the piece Alex literally cannot see.

## Acceptance
- After a night + a nap, the app shows BOTH as separate sessions, each openable to its own timeline; the
  today total = sum of both (355m for 07-11, not 269m); naps badged, not hidden.
- `/api/me/sleep` returns the session list with per-session hypnogram + a daily aggregate; naps included;
  grouped on the user's local day (Tijuana).
- Each session detail renders the v2 timeline (ribbon + dense movement strip + HR); a nap and an overnight
  both render correctly (shorter axis for the nap).
- Debt/need semantics documented: nap adds to total + cuts debt, doesn't masquerade as a scored night.

## Relationship to other work
- The dense movement strip needs DENSE_MOTION (now flowing) — pair this with feeding dense motion to the
  stager (DENSE_MOTION_TO_STAGER.md), so the sessions both STAGE better and DISPLAY the dense movement.
- Also fixes the mental model for the coach: "today's sleep" should reason over the daily aggregate
  (night + naps), not just last night — feed the aggregate into the trajectory digest.

*Sleep isn't one thing that happens at night — it's every session you actually slept. Save them like
workouts, show each one's story, and total them for the day. Right now we capture the nap and then hide it.*
