# Review: sleep-seal series through `be3b761` + workout fix `787f45c`

**Reviewer:** Henry (server agent) — multi-agent audit: 8 finder angles per commit, adversarial verification per finding.
**Date:** 2026-07-09 (fifth review pass in this series)
**Scope:** `90fb608` (6-item mechanical patch) + `be3b761` (cleanup) + `787f45c` (workout six-bug fix), all verified against HEAD.
**Protocol:** findings live here; push fixes to master and this reviewer re-audits automatically.

---

## Part 1 — Sleep seal: scorecard for the 9c4285d audit's 10 findings

| # | Prior finding | Status at HEAD |
|---|---------------|----------------|
| 1 | Seal timing defeats clustering (45min quiescence + past-date shortcut) | ✅ **FIXED** — quiescence = SESSION_GAP_S, past-date shortcut deleted |
| 2 | Evening fragment clobbers night row | ✅ **FIXED** (upsertSleep guard) — but see S-1 below |
| 3 | Cron livelock on deterministic failure | ✅ **FIXED** (attempt cap + per-session isolation) — but see S-2 below |
| 4 | Nap rows headline dashboards | ⚠️ **PARTIAL** — nights() scope on 4 readers, ~6 night-readers still unswitched (S-4) |
| 5 | Night/nap verdict divergence (4 predicates, not threaded) | ❌ **NOT ADDRESSED** — carried (see 9c4285d review) |
| 6 | Dead-band duration regression | ✅ **FIXED** (3h grace) — see S-5 for the overshoot trade-off |
| 7 | isNight classifier holes (workout ppg_raw → recovery; beats guard) | ❌ **NOT ADDRESSED** — carried |
| 8 | ProcessWindowJob provisional recovery date mismatch | ❌ **NOT ADDRESSED** — carried |
| 9 | clusterSessions / groupIntoSessions duplication | ❌ **NOT ADDRESSED** — and 787f45c deepened it (W-6) |
| 10 | Vacuous evening-cluster test | ✅ **FIXED** |

`be3b761` also pre-empted four fresh candidates before this review could report them (dead QUIET_MINUTES, --night queue-retry restored, source-scoped guard, real reader test). Appreciated — it shortened this report.

## Part 2 — Sleep seal: verified findings at HEAD

### S-1 · CONFIRMED · upsertSleep guard blocks legitimate corrections and mis-binds the confirmed path
`app/Jobs/SealNightJob.php:760`
The richer-row guard has no confirmed-flag or reseal-override precedence. An inflated staged row already in the DB (the 900-min-bug era, or a grace-inflated fold) is **unrepairable**: a `--night` reseal running the fixed algorithm computes the honest shorter night and is silently discarded (900 ≥ 450+30). Worse, when the guard fires on the confirmed path, `sealConfirmedSession` still seals the scoped windows bound to the WRONG `sleep_log_id` (stages consumed forever) and dispatches `ReactToSleepConfirmed($log->id)` — the coach narrates a 15-hour night the user never slept.
**Fix shape:** confirmed seals and `--night` reseals bypass (or invert) the guard; never dispatch the coach summary when `upsertSleep` returned an existing row unchanged; don't seal windows to a row this seal didn't write.

### S-2 · CONFIRMED · MAX_SEAL_ATTEMPTS destroys nights on sustained transient outages
`app/Jobs/SealNightJob.php:318`
Nothing distinguishes transient from deterministic failure: a configured-but-crashlooping biosignal (bad deploy overnight) burns one attempt per hourly cron; at 4 the windows are released `STATUS_SEALED` + `seal_error` with **no row**, and every reseal path filters `status != SEALED` — permanent loss for what used to be mere delay. The confirmed path dies in ~30s (`tries=2/backoff=15`) and hands the night to this same auto path.
**Fix shape:** don't count attempts on `ConnectionException`/HTTP ≥ 500 (an ops state, not a data verdict — mirror the `configured()` guard's own comment); at the cap, park in a re-openable quarantine status instead of terminal `SEALED`.

### S-3 · CONFIRMED · Offline-buffered halves split the night (wall-clock quiescence)
`app/Jobs/SealNightJob.php:300`
`sessionIsComplete` compares `window_end` to wall clock, but Path B store-and-forward is a designed mode: BLE drops at 03:00, band buffers 04:00–07:00 on-device, syncs at 08:00. The 06:00 cron seals the first half as the whole night; the buffered half (gap 60min — it would have clustered in) arrives to sealed-and-excluded windows. Every ending is wrong: <240min late half → phantom 3h "nap"; ≥240min → guard-blocked (data sealed away) or replaces the night. The `created_at − window_end` skew that signals a backlog drain is available and unused.
**Fix shape:** treat a large ingest-time skew on recent arrivals as "backlog draining, don't seal yet"; and/or a merge path when late windows land inside a sealed night's span.

### S-4 · CONFIRMED (mechanical) · nights() scope adoption incomplete
`app/Support/SleepDetail.php:113` and siblings
Grep-verified unswitched night-readers that still surface naps as "last night" / pollute night baselines: `SleepDetail::consistency` (same file the commit edited — nap bed/wake times crater the consistency score), `CoachBriefingService::latestSleep`, `CoachTools` (2 sites), `DuoService` (2 sites), `Readiness::compute` (readiness craters to ~5 when a nap seals). The coach will tell the user they slept 25 minutes while the fixed dashboard shows the real night.
**Fix shape:** sweep every `SleepLog` night-reader onto `->nights()` (grep list above); consider making the raw `where('is_nap', false)` sites in SleepController/SleepCoach use the scope too.

### S-5 · CONFIRMED (arithmetic) · POST_SAMPLE_SLEEP_GRACE_S overshoots for band-off-at-wake habit
`app/Jobs/SealNightJob.php:577`
The 3h grace can't distinguish "band died while asleep" (its target) from "band removed at wake, marker tapped later". A user who wakes 06:00, removes the band, and marks awake at 09:00 gets +3h of light sleep **every day**. Awake can never be detected inside the unsampled window.
**Fix shape:** scale the grace by staged sleep-propensity at the last sample (asleep at last sample → grace; awake at last sample → none), or cap grace at e.g. 60min when the marker is >2× grace past the last sample.

### S-6 · CONFIRMED (schedule facts) · Unconfirmed nights now seal after the morning briefing
`app/Jobs/SealNightJob.php:298`
Quiescence = 150min + hourly cron ⇒ auto nights seal 2.5–3.5h after the last window (was 0.75–1.75h). The 07:00 `coach:morning-briefing` now reads a stale night for any unconfirmed wake after ~03:30 (was ~05:15). Confirmed markers are unaffected.
**Fix shape:** accept, or add a wake-hours fast-path (e.g. quiescence can shrink once the session already spans ≥4h and ends in the morning).

Carried, still open from the 9c4285d review: verdict divergence (recovery-night/sleep-nap), ProcessWindowJob provisional date mismatch, clusterSessions/groupIntoSessions unification, efficiency items (per-window Carbon parses, blob hydration for skipped sessions, upsertSleep double-select, per-window seal_attempts writes).

## Part 3 — Workout commit `787f45c`: verified findings

### W-1 · CONFIRMED (mechanical) · training() filter missing where strain is actually computed
`app/Support/Strain.php:40`
`Strain::assess` still sums TRIMP over ALL sessions — the commit's own rationale ("a passively-imported 2-minute walk can't add strain") is not achieved at the headline. StrainDetail's list is filtered, so the strain ring shows a number the per-session breakdown can't explain; TrendsOverview's per-day strain disagrees with the same day's ring.

### W-2 · CONFIRMED (mechanical) · training() scope drops real workouts: 'other' overload + NULL/short durations
`app/Models/ActivitySession.php:126`
`activity_type != 'other'` excludes the DEFAULT for real workouts (watch-confirmed with no kind via `confirmedActivityType()`, HealthIngest imports without type, mixed-motion classifier output), and `duration_min >= 5` silently drops NULL durations (SQL three-valued logic) plus deliberately force-sealed 1–4min workouts. Real training vanishes from streaks — while `AchievementEngine` badge streaks still count everything, so the two streak notions contradict each other on screen.

### W-3 · CONFIRMED (mechanical) · trimp/calories null→0 semantics change
`app/Jobs/SealActivityJob.php:492`
`detect_sessions` ALWAYS returns totals (`sum([]) = 0.0`), so the `?? ($sess['trimp'] ?? null)` fallbacks are dead and zero-session workouts now write `trimp=0, calories_kcal=0` where the old code left NULL. "We couldn't estimate" became "you burned nothing", and a re-seal can stamp 0 over previously-computed real values.

### W-4 · CONFIRMED · runs/lift set matcher misattaches and double-attaches
`app/Http/Controllers/Api/MobileRunsController.php:110`
No exclusivity, no persisted linkage, and `performed_at` is the FIRST-SET time — `CoachTools::logSet` reuses one Workout for 6h, so two morning lifts (legal 20–40min apart) put ALL sets in one Workout matched to session A (session B shows `strength: null`); a null `ended_at` opens a 4h20m catchment; the stale docblock still describes the deleted exact-key convention. Net improvement over the (never-matching) exact key, but the right fix is persisting `activity_session_id` at seal/log time (`ReactToWorkoutSealed` knows both sides and currently persists nothing).

### W-5 · CONFIRMED · zone catch-all scores HR artifacts at maximum effort weight
`biosignal/app/core/route.py:337`
The removed `1.01×HRmax` bound was a load-bearing artifact filter. Now `stableHrMax`'s own 215-bpm cap *guarantees* a sustained cadence-lock (e.g. 220bpm, acknowledged as an artifact in SealActivityJob's comments) stays above `hr_max`, lands in the `float('inf')` catch-all, and scores weight 6.0 — 10 minutes of artifact = +60 Relative Effort, an extra hard-run's worth. Nothing upstream clips the series (`robustMax` only shapes `maxHr`). The fix's motivation was legitimate (real max efforts scored 0); the correct form is the catch-all PLUS a plausibility clamp (~1.15×HRmax, mirroring the 215 cap).

### W-6 · CONFIRMED · avg_hr includes zero-filled dropout windows
`app/Jobs/SealActivityJob.php:483`
The reorder itself was justified (`sessions[0].mean_hr` was first-leg-only), but the promoted raw mean `array_sum($hr1)/count($hr1)` lacks the positivity filter `zonesFromHr` has (`$v <= 0 → skip`): the per-second builders emit ALL-ZERO windows when HR loses lock (firmware publishes only at conf ≥ 90), so a chest-strap/on-chip lift with one dropped ~10-min window reports avg_hr ~25% low (148 → 111). One-line fix: filter non-positive samples before the mean. The idle-second dilution is a documented semantic choice but contradicts the seal's own inter-set-rest comment — worth an explicit call.

### W-7 · PLAUSIBLE (verified, low severity) · sessions[0] reclassification
`app/Jobs/SealActivityJob.php:372`
`detect_sessions` is chronological, so MIN_SESSION_MIN 10→5 lets a 5–9min warm-up walk become `sessions[0]` and title a hint-less workout "Walk" with the walk's confidence. Verified narrow: watch hints fully override, route/splits/TRIMP stay correct, and the same mislabel pre-existed for 10–19min warm-ups (when it also corrupted totals — which this commit fixed). Cosmetic-to-moderate; fix by picking the LONGEST qualifying bout for type, not the first.

### Minor, self-verified
- Quiescence port to SealActivityJob is missing SealNightJob's max-frontier fix; `QUIET_MINUTES=10` is dead but still declared/referenced (SealActivityJob:38/73) — same dead-constant hazard just cleaned out of the sleep job.
- `TIMESTAMPDIFF` in MobileRunsController:111 is MySQL-only — fails on sqlite dev/CI environments (works on this server's MySQL). Portable form: order in PHP after fetch, or `ABS(strftime/EXTRACT...)` per driver, or just persist the FK (W-4) and delete the heuristic.

## Priority order for the next patch

1. **S-1** (corrections blocked + coach notified of stale nights) and **S-2** (outage destroys nights) — both are data-loss/data-wrong at the seal core.
2. **W-1/W-2** — strain and streaks visibly wrong for normal usage patterns (unlabeled workouts, imported sessions).
3. **S-3** (offline-buffer split) — designed usage mode, needs the backlog-skew signal.
4. **S-4** sweep + **W-3** null→0 — mechanical, low risk.
5. S-5/S-6 trade-off tuning + the carried altitude items (verdict threading, ProcessWindowJob date, clustering unification — now needed in BOTH seal jobs).

---

## Part 4 — Sixth-pass audit of `ac206d8` (response to this review)

**Scorecard:** S-4 ✅ (listed readers) · S-5/S-6 accepted · W-3 ✅ · W-6 ✅ (avg_hr) · W-7 ⚠️ half · W-4 ⚠️ minor sub-item only (deferred in commit msg — note deferrals HERE next time) · S-1 ⚠️ fixed one direction, broke the other · S-2 ⚠️ inverted · W-1/W-2 ⚠️ partial · S-3 ❌ silently dropped.

### The big four (all CONFIRMED, quoted code)

**A-1 · The transient classifier inverts the poison cap.** The biosignal routers wrap EVERY code fault in `HTTPException(500)` (sleep.py:61) — so real poison payloads are classified "transient", never burn attempts, and the livelock the cap fixed is back for exactly its target class. Only pydantic 422s cap. The poison test uses `RuntimeException`, so CI is green. → Make the routers emit 422 for data faults (or bound 5xx retries at ~24); test with `RequestException(500)`.

**A-2 · Confirmed evening doze clobbers the night again.** `upsertSleep($key, $attrs, true)` is unconditional in sealConfirmedSession: a confirmed ≥240-min evening doze (same wake-date key) now bypasses the guard built to stop precisely this, replaces the real night, and the coach narrates it. → Force only when the marker's [bed,wake] overlaps the existing row's span; otherwise second row.

**A-3 · Force-repair writes chimera rows.** The duration-only fallback attrs omit stage fields; `updateOrCreate` partial-updates → a repaired 900-min row keeps 900 min of stale stages + the 15h hypnogram under the new 430-min duration. (And this is the MAIN repair path, since the bad row's windows are SEALED → nothing scopes → duration-only.) → Null stage columns on unstaged force-writes.

**A-4 · `--night` is a fleet-wide force-clobber.** `isAuthoritative()` is job-scoped: `--night` (all profiles when `--profile` omitted) forces EVERY same-date session — straggler fragments overwrite rich sealed nights, and a repair run can undo itself via a later evening cluster. Meanwhile the repair intent is inert (sealed windows invisible to the reseal). → Session-scoped force + an explicit unseal tool for repairs.

### Also confirmed
- **A-5** isTransientFailure misses MinIO/Flysystem, QueryException, 429, and 401 (token rotation) → terminal destruction persists for those ops states; quarantine status still absent (half the fix shape, no deferral note).
- **A-6** W-5 clamp ineffective: 220/1.15 ≈ 191 → the artifact in the clamp's own comment passes for hr_max > 191. Use an absolute ceiling shared with the 215/222 bounds.
- **A-7** scopeTraining: NULL-duration now counts → `startActivity` chat placeholders and durationless imports light streaks; force-sealed 1–4min real workouts STILL excluded; ≥5min 'other' stray-motion blobs re-admitted. The real fix is a persisted seal-time flag.
- **A-8** hrEpoch (line 327) still consumes unfiltered $hr1 → the zero-window dilution fixed for avg_hr persists inside TRIMP/calories.
- **A-9** Sweep leftovers: CoachTools:1921 showTrend, BiologicalAge:91 (live SRI divergence vs getLongevity), RecoveryController:39, MetabolicHealth:58, InsightFeed:129, BehaviorCorrelations:58, FitnessController:45 weekTrimp. Sweep by class, not by list.
- **A-10** W-7 half: activity_confidence (and duration fallback) still from sessions[0] while type is from the longest bout.

### Cleanup (non-blocking)
isAuthoritative() unused at its own twins (handle():228, the literal `true` at 663); phantom indentation in sealSleepFromPpg survives its 6th commit (861-888); orphaned docblocks (sealConfirmedSession's in SealActivityJob, scopeVisibleTo's); avg_hr comments contradict the $sess fallbacks; 5 reflection tests pinning privates; efficiency: dead pre-select in upsertSleep on force, unconditional hr filter, strengthDetail hydrating all candidates, DuoService full-history fetches, the long-standing double-sorts.

### Process note
Deferrals (W-4 FK, S-3) were stated only in the commit message. Put them in this file next time so they're tracked — S-3 (offline-buffer split) is now dropped twice without a note and remains a CONFIRMED open data-loss bug.
