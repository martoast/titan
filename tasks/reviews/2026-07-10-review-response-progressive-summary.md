# Response to the reviewer's pass (range e9a7416..90239b8)

The reviewer's 10 findings, with disposition. NOTE: the review range **predates** the audit-hardening commit
`b8bee36`, so several findings were already fixed there — marked ✅(b8bee36). The rest are fixed in this commit
or explicitly deferred.

## Critical

**1. reconstructSpan timezone bug — FIXED.** `timeOnly()` formatted the stager's Zulu ISO with no tz
conversion (UTC clock) while the duration-only fallback stored profile-local, and `reconstructSpan` assumes
local — so for a non-UTC user the same-night/doze overlap inverted. `timeOnly(?string, string $tz)` now
`->setTimezone($tz)` so every path stores ONE convention (profile-local). Tests: `timeOnly` conversion +
a non-UTC (America/Mexico_City) overlap test that no longer pins UTC.

**2. Replayed wake marker destroys staged nights — FIXED.** Store-and-forward re-sends the marker after the
night's windows are SEALED → empty `$scoped` → duration-only attrs → force → STAGE_COLUMNS nulling erased the
staged hypnogram. Now: if `$scoped` is empty AND a staged `final` row already exists, the confirmed seal
bails (a windowless replay is a no-op); `$forceWrite` also requires `$scoped->isNotEmpty()`; and `upsertSleep`
never lets a STAGELESS write replace a staged night (regardless of the ±30-min margin). Test:
`test_a_replayed_wake_marker_does_not_erase_a_staged_night`.

**3. Capped finalize strands the computing placeholder — FIXED.** `handleSessionFailure`'s cap branch now
calls `settleComputingPlaceholder()` — flips a matching `computing` night to a duration-only `final` (its
envelope duration/bed/wake are known), so the iOS loading card resolves and readiness keeps the night instead
of an eternal spinner + a dropped night. Test: `test_a_capped_seal_settles_a_stranded_computing_placeholder`.

**4. Blanket 422 inverts deploy-bug semantics + QueryException — FIXED.** The `(ValueError, KeyError,
IndexError, TypeError) → 422` catch made a code regression (bad deploy) read as DETERMINISTIC → burn the cap
→ destroy nights in ~4h. Now a typed `DataFaultError` (biosignal/app/core/errors.py) is raised ONLY at the one
genuine in-body validation site (`activity_classify._to_ms2` unknown unit), and the four seal routers catch
ONLY `DataFaultError → 422`; every other exception → 500 (transient, retry-until-rollback). Pydantic/HTTPException
validators still 422 independently (they're genuine data faults). Laravel `isTransientFailure` now classifies
`QueryException` by SQLSTATE (22xxx/23xxx data/constraint = deterministic; 08xxx/40001/timeout = transient),
so DB-side poison (e.g. hypnogram over the column limit = 22001) caps instead of livelocking.
*Deferred:* the re-openable quarantine status at the cap — with the DataFaultError narrowing, only genuinely
unstageable data reaches the cap now (bugs are transient), so terminal-sealing there is correct; quarantine
remains a nice-to-have for the pure-auto no-envelope case (finding 3 already covers the confirmed case).

## High

**6. finalized_at breaks the no-op gate — ✅ (b8bee36).** `sleepRowWritten` already tests narrative content
columns only, ignoring `finalized_at`/`stage_status`/`coverage`.

**7. Refused-doze data loss + margin — PARTIALLY FIXED.** The ≥30-min-margin half is fixed: `upsertSleep`
never lets a stageless doze replace a STAGED night (finding 2's guard). *Deferred:* writing the refused doze
as its OWN second row — the data model allows one night row per `slept_at`, so a distinct ≥4h same-date sleep
can't be represented without a multi-night-per-date model (keyed on session_start). Its windows are still
consumed (no livelock); the doze summary is the only loss, on a rare edge. Tracked as a data-model follow-up.

**8. iOS pollers — MOSTLY ✅ (b8bee36); efficiency DEFERRED.** Budget (~200s), failed fallback, and
stamp-on-pop (dismissed stays dismissed) all fixed in b8bee36. *Deferred to a follow-up:* the ~60 full
`/api/me/sleep` polls (hydrating 14 hypnogram blobs each) → a light status endpoint + backoff + a column list.
Perf, not correctness.

**9. Recovery cascade holes — FIXED.** Nap-suppresses-greeting fixed ✅ (b8bee36, `is_nap=false`). The 15-min
hold is widened to 30 min (≫ the ~2-min stage defer, so it never truncates a legitimately slow finalize), and
finding 3's `settleComputingPlaceholder` guarantees a stuck placeholder resolves — together closing the
"stale greeting + permanent no-op correction" path in all but a >30-min-wedged-queue edge.

**10. stage_status only on Readiness — FIXED (class sweep).** Added `SleepLog::STATUS_COMPUTING/STATUS_FINAL`
constants and `scopeFinal()`. `->final()` now on: MobileDashboardController, CoachTools (dailyCheckin +
sleepDetail), CoachBriefingService::latestSleep, TrendsOverview (all b8bee36), plus **SleepCoach::assess**
(debt/need) and **all 7 AssistantTools night reads** (this commit). `SleepDetail::forProfile` stays newest
(the iOS loading-card pop reads its `epoch_sec`) but now exposes `stage_status`/`finalized_at` so consumers
gate; web already guards via `hasStages()`.

## Cleanup — done vs deferred
- **Done:** PROGRESSIVE_SUMMARY.md status header + `computing`/`final` state names (was "provisional/finalizing")
  + notes the payload exposes `stage_status`/`finalized_at`.
- **Deferred (non-blocking):** centralize the `final`+`finalized_at` copy at 4 sites; poison test →
  `RequestException(422)`; extract the UTC-pin `setUp` to a trait; the twin defer/placeholder tests; the 4×
  identical row SELECTs per confirmed seal; the phantom indentation in sealSleepFromPpg; **SealActivityJob
  consuming the transient classification** (activity 422s are now emitted but the workout seal still
  terminal-seals on outages — its own failure-model refactor); and the prior-pass also-rans (S-3 late-window
  MERGE, verdict threading, ProcessWindowJob date, clusterSessions unification).
