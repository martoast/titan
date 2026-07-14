# Review — workout summary shows wrong duration for a watch-driven lift (11 vs 33)

**Date:** 2026-07-13 · **Reporter:** Henry (Alex flagged on Tester B's real lift; verified in data)
**Severity:** user-facing wrong number on the post-workout summary (the "wow" sheet).

## What happens
Tester B started AND ended a lift on the WATCH (phone away), lifted ~33 min. The **post-workout summary
sheet read 11 min**; the **Workouts/lift-detail section read 33 min** (correct). Verified: her sealed
`activity_session #31` = `duration_min 33` (started 04:58 → ended 05:37, moving_time_s NULL). The
section reads that 33. The summary shows a different, wrong 11.

## Root cause
`AppModel` seeds the summary immediately from LIVE phone stats:
```
workoutSummary = WorkoutSummaryState(… elapsedSec: runElapsedSec …)   // ~line 1370
```
`runElapsedSec` is the PHONE's live timer. For a **watch-driven** workout (started/ended on the band,
phone backgrounded/away), that timer only accrues while the phone is foreground/connected — here ~11
min of a 33-min session. `WorkoutSummaryView.durationText` (~line 351):
```
let s = detail?.moving_time_s ?? detail?.duration_min.map { $0*60 } ?? summary.elapsedSec
```
Before the sealed `detail` loads, it falls back to `summary.elapsedSec` = the wrong 11. It's meant to
self-correct when `fetchSealedSummary` attaches `detail` (duration_min 33) — but if the seal/fetch is
slow, fails, or the user looks first, the summary shows/sticks at 11.

## Fix
1. **Don't seed the preliminary duration from the phone's live timer for watch-driven workouts.** Use
   **wall-clock** (`now − runStartedAt`, where runStartedAt is the session start) as the preliminary
   elapsed — that's accurate regardless of whether the phone was tracking. If the true start isn't
   known until the seal, show a **"computing…" duration** (mirror the sleep summary's computing state)
   instead of a wrong number, until the sealed `duration_min` lands.
2. **Prefer the sealed `duration_min` and treat `elapsedSec` as a last-resort placeholder**, not the
   headline once anything better exists.
3. **Guard `durationText` for strength:** never use `moving_time_s` as a lift's duration (it's a
   running metric — time-moving, not session length). For strength, duration = `duration_min`. (Not
   the active trigger here since moving_time_s is NULL for lifts, but it's a latent wrong-number bug
   if a lift ever gets a moving_time_s.)

## Verify (Henry)
After the fix: a watch-driven lift's summary should show the session duration (≈ wall-clock span /
sealed duration_min), never the phone's partial foreground time; and it should never briefly flash a
too-short number. Re-check on Tester B's next lift (and Alex's) — summary duration must match the
Workouts-section duration.

## Repro
`activity_session #31` (profile 6): duration_min 33, span 38 min; summary showed 11 (= phone
runElapsedSec). Section (reads duration_min) shows 33.
