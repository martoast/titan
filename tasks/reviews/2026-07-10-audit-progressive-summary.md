# Audit — Progressive Summary (Phase 1 + 2)

**Method:** 3 parallel adversarial reviewers over the P1+P2 diff (`0348e08..90239b8`) — server seal core,
readers/notification/cross-surface, and the iOS client — each verifying every claim against the code.
**Verdict:** design is sound; **no critical / data-loss bug**. The computing row cannot block a real seal
(its `biosignal:computing` updated_via dodges the guards), never downgrades a `final` row, and every path
(defer-cap, offline, biosignal-down) still reaches a `final` write. The real issues were two **cross-surface
gaps** (other readers rendering a computing night's null stages) and the **iOS bulk-sync path** being weaker
than the live path. All confirmed findings below are FIXED in the follow-up commit unless marked DEFERRED.

## Fixed

**Cross-surface (a computing row has real duration/bed/wake but NULL stages/quality/coverage):**
- **F1 (HIGH)** `MobileDashboardController` sleep tile shipped the computing night's null stages with no
  `stage_status` — the native Dashboard/Recovery tile showed a stageless "last night." → `->final()` (shows
  the last complete night; the summary sheet owns the loading affordance).
- **F2 (HIGH)** `SleepDetail::forProfile` fabricated 0%-everything stages for a computing row and exposed no
  compute flag. → added `stage_status`/`finalized_at` to its output (kept newest-night so the iOS loading-card
  pop, which reads `detail.epoch_sec`, still works); web already guards via `hasStages()`.
- **F5 (MED)** coach "last night" narrators (`CoachTools::dailyCheckin`/`sleepDetail`, `CoachBriefingService::
  latestSleep`) could narrate an unfinished night. → `->final()`.
- **F8 (LOW)** `TrendsOverview` fed the computing row into today's recovery point. → `->final()`.
- New `SleepLog::scopeFinal()` = `where('stage_status','final')` centralizes this.

**Notification (`ReactToDeviceSync`):**
- **F3 (MED)** the still-staging guard matched a computing NAP row, suppressing the morning greeting even
  though the nap finalize (night-only) never re-fires it. → `->where('is_nap', false)`.
- **F4 (MED)** non-atomic `device_greeted` check-and-set → two workers (sync + finalize re-dispatch) could
  double-greet. → atomic `Cache::add` claim before notifying.
- **F7 (LOW)** recency check used app-tz `now()` vs UTC-stored `updated_at`. → `Carbon::now('UTC')`.

**Seal core (`SealNightJob`):**
- **A-F1 (MED)** `finalized_at => now()` on every finalize made `sleepRowWritten` (wasChanged) always true,
  defeating the no-op-reseal guard (mitigated only by the downstream per-day dedup). → `sleepRowWritten` now
  tests only narrative content columns, ignoring `finalized_at`/`stage_status`/`coverage`.
- **A-F2 (LOW-MED)** `coverage` wasn't in `STAGE_COLUMNS`, so a force duration-only correction left stale
  coverage over a nulled hypnogram (a chimera). → added `coverage` to `STAGE_COLUMNS`.
- **A-F5 (LOW)** `coverage` decimal(4,3) could error on an out-of-range stager value. → `clampCoverage` to [0,1].

**iOS (`AppModel`, `SleepSummaryView`):**
- **F1 (HIGH)** `checkForSyncedSleep` polled only ~45s — far short of the confirmed seal's ~120s defer +
  staging on the exact bulk-sync path — with no timeout fallback and no way to resume, stranding the spinner
  forever. → extended to ~200s, added a "saved — see Daily" fallback on timeout.
- **F2 (HIGH)** a dismissed computing card re-popped (the epoch stamp had moved to the finalize-only branch).
  → stamp `lastSeenSleepEpoch` on the POP (with a `poppedEpoch` guard so the current loop still follows the
  same night to final).
- **F3 (MED)** `fetchSealedSleep` 135s could time out mid-compute. → widened to ~195s.
- **F4 (MED)** the loading headline showed the envelope span (time-in-bed) labeled "ASLEEP" and could fire a
  premature "full night" verdict that then shrank/flipped on finalize (violating "numbers never move"). →
  while loading, label "in bed", use the in-bed span, and show a neutral "calculating…" verdict.

## Deferred (tracked, low/edge)
- **A-F4** a hard finalize crash between the computing write and window-seal leaves a `computing` placeholder
  until the auto cron re-seals — bounded, no data loss (windows stay unsealed → re-sealed to `final`). A
  belt-and-suspenders age-out sweep of stale `computing` rows is a possible future add.
- **A-F6** no unique index on `(profile_id, slept_at, is_nap)` — the `updateOrCreate` upsert is SELECT-then-write;
  the computing write slightly widens a pre-existing duplicate-row race. Real fix = a unique index + true upsert.
- **F6 (notification)** a >15-min wedged finalize greets with fallback readiness and no-ops the later correction.
  Narrow (needs a stuck queue past the defer window).
- **F9 (web)** the Blade Sleep page shows the provisional night as a normal (stageless) card — cosmetic only;
  `hasStages()` already prevents wrong numbers. A "calculating" state on web is a nice-to-have.
- **iOS F5** `Detail.stages` is non-optional — a stage-less computing payload would fail the decode; NOT active
  because `SleepDetail::forProfile` always emits a 4-element `stages` array. Left as a server guarantee.

## Clean (verified safe, no change)
Most aggregate readers lean on `duration_min`/`bedtime`/`wake_time` (all real on a computing row) or already
`whereNotNull('quality')`: SleepCoach, InsightFeed, DuoService, WeeklyReview, MetabolicHealth,
BehaviorCorrelations, RecoveryMetrics, SleepRegularity/consistency/CoachNudge, BiologicalAge, web
Dashboard/Recovery controllers, ReactToSleepLogged, AssistantTools. No Readiness caching anywhere. iOS
identity-reuse of `SleepSummaryState` (stable `id`) correctly drives `.sheet(item:)` in-place updates.
