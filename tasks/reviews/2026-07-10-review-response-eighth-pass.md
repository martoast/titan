# Response to the reviewer's eighth pass (range b8bee36..96a2f34)

Fixed the bugs the reviewer found *inside* the seventh-pass fixes, plus the sharper edges.

## Critical — fixed
1. **Greeting hold dead in prod** — `Carbon::now('UTC')` compared against the naive app-tz-stored `updated_at`
   read every fresh row as 6h stale under a non-UTC app tz → the hold never held. → `Carbon::now()`. Test:
   `test_the_greeting_hold_works_under_a_non_utc_app_timezone` (does NOT pin UTC, so CI can see it now).
2. **Thin-night finalize dropped both notifications** — the duration-only finalize's attrs are byte-identical
   to the computing placeholder, so `sleepRowWritten`'s content check read the finalize as a no-op → no coach
   summary, no greeting re-dispatch. → added `stage_status` to the change test (the `computing→final` transition
   IS a write; `finalized_at` still excluded so a true no-op reseal stays silent). Test added.
3. **Bulk-sync loading card never popped** — the newness guard `return`ed (killing the 50-poll budget ~2s in)
   when poll #1 served last night's row, which it always does since the seal queues behind the window jobs. →
   `continue`, not `return`.
4. **Replay bail blocked repairs + missed partial replays** — the empty-scope-only bail discarded a genuine
   delayed-marker correction and let a partial replay (marker + tail windows) clobber the night with fragment
   stages. → detect a replay by SPAN (`markerMatchesSpan`: marker [bed,wake] within 10min of the existing
   staged night's reconstructed span) regardless of scope; a replay consumes any stray windows and no-ops, a
   different span (correction/doze) falls through. Test added.
8. **settleComputingPlaceholder key mismatch** — derived the date from last-window-end vs the writer's
   marker-wake date (a pre-midnight band death → never settled) and skipped naps. → match by RECENCY
   (`updated_at` within 30min, app-tz `now()`), covering the cross-midnight case and naps. Dropped the dead
   `$tz` param on `handleSessionFailure`.
10. **Greeting claim burned before notify** — `Cache::add` claimed pre-notify and never released on failure →
   one hiccup lost the day's greeting. → wrapped the notify/write in try/catch, `Cache::forget` on failure.

## High — fixed / addressed
6c. `/process/function` + `/process/step-distance` caught the shared `DataFaultError` with a bare
   `except Exception → 500`, inverting the contract. → added `except DataFaultError → 422` to both.
9. **Sleep card two-night mix** — `SleepDetail::forProfile` returns the computing row but paired it with a
   finals-only `assess` (yesterday's performance ring on tonight's card, visible jump on finalize). → null
   `performance_pct` on a `computing` row. **Reader sweep completed:** `->final()` now also on
   `CoachTools::showTrend/dailySummary/sleepRecoverySummary`; `Readiness` and `SleepCoach` converted from raw
   `where('stage_status',…)` predicates to the `->final()` scope.

## Later shipped (post-eighth-pass, on the user's go-ahead)
- **Quarantine status — SHIPPED.** `DeviceIngestion::STATUS_QUARANTINE`; the seal-attempt cap now PARKS windows
  there instead of terminal-sealing them. The routine cron (`night === null`) skips quarantine (no livelock); a
  `--night` reseal / confirmed marker / new `sleep:reopen-quarantine` command re-opens them; a confirmed
  computing placeholder is still settled to duration-only `final`. Tests: poison-cap-quarantines +
  cron-skips-quarantine.
- **Drain-guard on the confirmed path — SHIPPED.** `sealConfirmedSession` now also DEFERS while a
  store-and-forward backlog is still ARRIVING (a scoped window with an old sample but a just-now ingest —
  `created_at − window_end > BACKLOG_SKEW_S`, arrived within `INGEST_QUIET_S`), not only while windows are
  processing — so a wake marker inside a bulk drain no longer seals a partial night. The fresh marker itself
  has ~no skew, so it doesn't self-trip. Test: `test_confirmed_seal_holds_while_a_backlog_is_still_draining`.

## Still deferred
- The reviewer's residual quarantine sub-asks — raising typed `DataFaultError` inside `staging_core` (sleep has
  no in-body validation site) and classifying pydantic schema-skew 422s as ops — are now moot for data-loss:
  the quarantine backstop means a mis-classified failure PARKS (recoverable) rather than destroying a night.
- **5 / tz backfill** — historical rows keep the old UTC clock (bedtime display +6h, overlap inversion) until
  they age out. A one-time backfill needs the per-row profile-tz join and careful once-only guarding; deferred
  as a data migration (old nights rarely re-seal; the forward fix is correct).
- Cleanup: SEAL_ARCHITECTURE.md refresh (still documents the old 422/QueryException/guard rules); the `'final'`
  string literals at the remaining writer sites → constants; phantom indentation; poller efficiency (light
  status endpoint + column list); the confirmed-seal duplicate row SELECTs; and the carried workout residuals.

Tests: +5 (non-UTC greeting hold, replay-by-span, computing→final write, plus the finding-1/2/3 from last pass).
568 PHP pass (1 pre-existing flake); biosignal 14 pass (docker-cp verified); iOS BUILD SUCCEEDED.
