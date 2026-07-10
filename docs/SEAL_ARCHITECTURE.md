# The Seal: how raw sensor bursts become a saved night or workout

> **Read this before touching `SealNightJob`, `SealActivityJob`, `ProcessWindowJob`, the biosignal
> `staging.py`/`activity.py`, or any "last night" / streak / strain reader.**
>
> Nearly every serious data bug in Titan's history has been a *seal* bug — not a math bug. The DSP, the
> sleep-staging model, and the run analytics are sound. What breaks is the **assembly**: how sparse,
> battery-saving sensor bursts get stitched into one saved `SleepLog` or `ActivitySession`. This document
> is the mental model we wish we'd had. If you internalize the [Invariants](#the-invariants) and run the
> [pre-change checklist](#pre-change-checklist), you will not re-introduce the bugs listed at the bottom.

---

## 1. The one fact everything follows from: the band duty-cycles

The wearable (a Bangle.js watch) **cannot stream continuously** — the battery wouldn't last a day. So it
samples in **short bursts** and sleeps the radio/sensors between them:

| Mode | What actually arrives |
|---|---|
| **Sleep (overnight)** | a **~30-second burst every few minutes**. An 8-hour night is *dozens of tiny windows*, not one continuous recording. |
| **Workout** | a stream of short windows while the session is active, then silence. |
| **24/7 HR** | duty-cycled samples, sparser than a workout. |

Three consequences that the seal MUST respect:

1. **The data is SPARSE in time.** 34 thirty-second bursts spread across 8 hours cover ~17 minutes of
   *wall-clock samples* but represent an **8-hour span**. The gaps between bursts are not "missing" — they
   are time the user was (probably) still asleep / still working out.
2. **The data can arrive LATE and OUT OF ORDER.** Bluetooth drops; the band buffers on-device and replays
   in bulk when it reconnects ("store-and-forward"). A window's *ingest time* (`created_at`) can be hours
   after its *sample time* (`window_end`).
3. **The data can be INCOMPLETE.** A night can end with the band dead, off-wrist, or out of range. The seal
   still has to produce an honest row.

> **The cardinal sin** (and the origin of the "17-minute night" outage): **concatenating the bursts** as if
> they were contiguous. Never do this. Bursts are *sparse samples placed at their real epochs*, and the
> unsampled gaps are reconstructed — never summed into a duration.

---

## 2. The pipeline

```
 Band ──BLE──▶ phone/bridge ──HTTPS──▶ POST /api/devices/ingest
                                          │  (HMAC-signed batches; DeviceIngestionController)
                                          ▼
                                 device_ingestions rows        ← one row per raw window ("window")
                                 status: received → queued
                                          │
                                          ▼
                                   ProcessWindowJob             ← per-window: DSP → epoch features,
                                 status: → processed              stored on the row (result_refs / blob)
                                          │
                          ┌───────────────┴────────────────┐
                          ▼                                 ▼
                    SealNightJob                      SealActivityJob
             (assembles a night/nap)            (assembles a run/lift)
             status of consumed windows → sealed
                          │                                 │
                          ▼                                 ▼
                      SleepLog                        ActivitySession
                          │                                 │
                          ▼                                 ▼
              ReactToSleepConfirmed              ReactToWorkoutSealed
              (coach summary, only when         (coach reaction)
               user-confirmed & row written)
                          │                                 │
                          ▼                                 ▼
          Readers: dashboards, coach tools, readiness, strain, streaks, trends, duo, insights
```

- **A "window"** = one `DeviceIngestion` row = one raw burst. Kinds include `ppg_raw`, `ibi`, `sleep`,
  accel/workout windows. It carries `window_start` / `window_end` (the *sample* clock, UTC), `created_at`
  (the *ingest* clock), a `status`, and `result_refs` (per-window results + bookkeeping like
  `seal_attempts`, `sleep_log_id`).
- **`status` lifecycle:** `received → queued → processing → processed → sealed`. A window is only
  `sealed` once a seal has consumed it. **Reseal queries filter `status != SEALED`** — so once sealed, a
  window is invisible to future seals. This is why "just reseal it" does **not** repair a bad row whose
  windows are already sealed (see [Re-seal & repair](#6-idempotency-re-seal-and-repair)).
- **Seals run from the queue and the scheduler** (hourly cron for auto-seals; on-demand when the user ends
  a session on the watch). They are meant to be **idempotent** — re-running must not double-count or corrupt.

---

## 3. The Invariants

These are the rules that, when violated, produced real user-facing bugs. Treat them as tests-in-prose.

### I1 — Bursts are sparse samples; place them, never concatenate
Stage/aggregate a session across its **true span** (`bed → wake`, `start → end`), putting each burst at its
real epoch. Unsampled epochs are **holes** to be reconstructed (folded into `light` sleep for a confirmed
night; interpolated/masked for HR), **not** deleted and **not** summed into the duration.
- Sleep: `staging.py::stage_night(sample_epochs=…)` → `_reconstruct` places bursts on a `bed..wake` grid;
  `_fill_holes` bridges gaps up to `FILL_GAP_MAX_EPOCHS`; larger gaps become `NODATA` and are counted via
  `coverage`. `EPOCH_SEC = 30`.
- Violating this = the "17-minute night."

### I2 — Session boundaries are GAP-based, not calendar-based
A session is a run of windows with no gap larger than the threshold. **Never** group by "date" (`nightOf`,
`whereDate`) — a night crosses midnight, and two workouts on one day are distinct.
- Sleep: `SealNightJob::clusterSessions` with `SESSION_GAP_S = 150 min`.
- Workout: `SealActivityJob::groupIntoSessions` with `SESSION_GAP_MINUTES = 20 min`.

### I3 — A session is "complete" only after a full gap of quiet
Don't seal while more windows for the same session could still arrive. Completeness = "no window has ended
within the last *full gap*." A shorter quiescence than the clustering gap will **split one session in two**
(a BLE drop mid-workout seals the first half as the whole thing).
- `SealNightJob::sessionIsComplete` (uses `SESSION_GAP_S`); `SealActivityJob::sessionIsComplete` (uses
  `SESSION_GAP_MINUTES` — *not* a smaller "quiet" constant; that mistake was the `QUIET_MINUTES` bug).

### I4 — Two entry paths, one authority rule
- **Confirmed / marker path**: the user ended the session on the watch, so we get an authoritative
  `[bed, wake]` (sleep) or `[start, end]` (workout) **envelope**. This path is **guaranteed to write a row**
  even if the windows are thin/late/never-arrived (offline case), and it is what fires the coach summary.
- **Auto path**: the cron infers the session purely from window quiescence. Never fires the coach summary.
- **Only the confirmed path is "authoritative"** for overwriting an existing row — and even then only for a
  *same-session correction* (see I6). `--night` reseals are **not** authoritative (they run fleet-wide).

### I5 — Failure classification decides data-loss (see §5)
A **transient** failure (service down, 5xx, DB/storage blip) must be **retried**, never counted toward a cap
or sealed away. A **deterministic** failure (a genuinely bad payload → HTTP **422**) must **count toward the
cap** so it can't re-aggregate forever. Getting this backwards either destroys good nights on an outage or
livelocks on poison data. **The biosignal service must emit 422 for data faults and 5xx only for infra** —
if a router wraps every error as 500, the classifier inverts.

### I6 — Re-seal must not clobber a richer row or leave a chimera (see §6)
The richer-of-the-two wins for auto re-seals. An authoritative correction may overwrite, but **only** when
its envelope overlaps the existing row's span (same session), and it **must null the stage columns it
doesn't set** so a duration-only correction can't inherit stale stages.

### I7 — Naps are not nights
A short session (`< NAP_MAX_MIN = 240` for sleep) is a **nap** and must never headline "last night" or
pollute night baselines (readiness, consistency, regularity, trends). **Every** night-baseline `SleepLog`
reader must use the `->nights()` scope (`where('is_nap', false)`). Sweep by class, not by memory.

### I8 — Only completed sessions count as "training"
Streaks/strain/trends use `ActivitySession::scopeTraining()`. It filters by **length** (`MIN_TRAINING_MIN = 5`,
NULL excluded) — a NULL-duration row is an *unfinished placeholder* (the coach's `startActivity` stub), not a
workout. Do **not** filter on `activity_type` (a real watch/HealthKit workout legitimately carries `'other'`).
The strain **ring** and its per-session **breakdown** must apply the *same* filter or they'll disagree on screen.

---

## 4. Sleep seal specifics (`SealNightJob` + `staging.py`)

- **Entry:** confirmed (`sealConfirmedSession` → `stageScoped` → `stageSparse`) or auto
  (`sealNight` → `sealSleepFromPpg` / `sealSleep` → `stageSparse`).
- **Sparse reconstruction:** the stager receives the bursts as `sample_epochs` and rebuilds the night across
  `bed..wake`. Holes inside a **confirmed** span are presumed asleep and folded into `light_min` (the user
  told us when they slept). The **auto** path is more conservative.
- **Coverage gate:** `MIN_COVERAGE = 0.30`. Below that we don't fabricate stages — we write an **honest
  duration-only row** (no hypnogram, never "100% awake"). Better a plain "you slept ~45 min" than garbage.
- **The grace window:** `POST_SAMPLE_SLEEP_GRACE_S = 3h`. If the band dies mid-night, we presume sleep up to
  3h past the last sample so a dead band still reads as a real night — but never the many hours a
  forgot-to-mark marker would claim (the "900-minute night"). *(Known limitation S-5: this is a blind time
  cap, not awake-state-aware — see [Open issues](#open-issues).)*
- **Absurd-span clamp:** a session is clamped to `MAX_SESSION_MIN = 16h`.
- **Nap vs night:** `NAP_MAX_MIN = 240`. Naps key on `session_start`; nights key on `slept_at` (one night row
  per date). A ≥4h "evening doze" collides with the night key — the confirmed-force overlap check (I6)
  protects the real night from it.
- **Coach summary fires only** when the seal is user-confirmed **and** actually wrote the row
  (`sleepRowWritten`) — never re-narrating a no-op reseal.

## 5. Workout seal specifics (`SealActivityJob` + `activity.py` / `route.py`)

- **Totals are whole-workout, not first-leg.** A run with a mid-run pause splits into sub-sessions;
  read the **summed** `total_trimp` / `total_calories_kcal` and the full elapsed `duration_min` — never
  `sessions[0]`. A `0` total means "couldn't estimate" → write NULL, not a hard `0`.
- **Type from the longest bout, confidence to match.** With `MIN_SESSION_MIN = 5`, a short warm-up walk can
  be `sessions[0]`; label the workout from the *longest* bout, and take `activity_confidence` from the same bout.
- **Watch choice wins.** A `lift`/`run` hint from the watch overrides the accel classifier.
- **HR zeros are masked.** The per-second HR series contains all-zero windows when HR loses lock (published
  only at confidence ≥ 90). Zeros are **excluded** from `avg_hr` and from TRIMP/`mean_hr` (`activity.py`
  masks `hr > 0`, length-preserving so epoch alignment with accel/speed holds); an all-zero epoch falls back
  to the accel proxy.
- **Relative Effort artifact clamp:** a PPG cadence-lock (~220 bpm) must not score max effort. Drop samples
  above `min(1.15 × hr_max, 215 bpm)` (`route.py::relative_effort`). The fractional clamp alone is porous
  above hr_max ≈ 191, hence the absolute 215 ceiling.
- **Phantom-run guard:** GPS drift while standing still (span `< MIN_RUN_SPAN_M = 150 m` yet path
  `> DRIFT_PATH_RATIO × span`) is jitter — seal the windows, write no run.
- **We do NOT invent lifts.** The accel gym-classifier is gone; a strength session seals its real stats
  (HR/load/calories), and exercises+weights are recorded **only** when the user tells the coach.

## 5b. Failure handling (the transient/deterministic contract)

```
biosignal call fails
      │
      ├─ transient?  (ConnectionException, Flysystem/AWS, RequestException 5xx / 401 / 403 / 408 / 425 / 429,
      │               and QueryException by SQLSTATE — connection/deadlock/timeout, but NOT 22xxx/23xxx)
      │        └─▶ do NOT burn an attempt, do NOT seal. Leave windows open; next cron retries.
      │            A genuine outage — or a rolled-back-soon code deploy — recovers with no data loss.
      │
      └─ deterministic? (HTTP 422 — a data fault the payload will always trigger; a 22xxx/23xxx DB fault)
               └─▶ count toward MAX_SEAL_ATTEMPTS (4). At the cap, PARK the windows in STATUS_QUARANTINE
                   (not terminal sealed) so poison can't re-aggregate hourly, but the night stays recoverable.
```
`SealNightJob::isTransientFailure` implements the split. **A code bug must be TRANSIENT, not deterministic** —
a bad deploy raising `KeyError`/`TypeError`/a bare `ValueError` from numpy is a 5xx (retry until rollback),
NOT a 422 (which would burn the cap and destroy nights fleet-wide in ~4h). So the biosignal routers map ONLY a
typed **`DataFaultError`** (raised at explicit validation sites, e.g. `activity_classify` unknown unit) → 422;
everything else → 500. `QueryException` is classified by SQLSTATE (22xxx/23xxx data/constraint = deterministic;
else transient). **Quarantine is the backstop:** at the cap the windows are PARKED, not destroyed — the routine
cron skips `STATUS_QUARANTINE`, but a `--night` reseal, a confirmed marker, or `sleep:reopen-quarantine`
re-opens them (and `sleep:recover-stages` re-processes the raw blobs when the epoch FEATURES themselves are the
fault). A confirmed placeholder is still settled to a duration-only `final` so the loading card resolves.

## 6. Idempotency, re-seal, and repair

- **`upsertSleep` richer-row guard:** an auto re-seal will not let a thinner row clobber a richer
  biosignal-sealed night (staged + ≥30 min longer wins).
- **Authoritative force** (confirmed same-night correction only): bypasses the guard **and** nulls the
  `STAGE_COLUMNS` it doesn't set, so a duration-only correction can't leave a chimera (a 430-min duration
  stapled onto a stale 15h hypnogram). Scoped by `confirmedOverwriteAllowed` (envelope overlaps existing span).
- **`--night` is not a repair tool.** It runs fleet-wide and its windows are already sealed (invisible to
  reseal). Repairing a bad historical row needs an **explicit unseal** step (open item — see below).

## 7. Readers

- **Sleep:** use `->nights()` for anything that means "last night" or a night baseline. Writers and
  deliberate nap views are the only exceptions.
- **Workout:** use `->training()` for streaks/strain/trends. Apply it consistently across a metric and its
  breakdown.

---

## Pre-change checklist

Before you merge a change to a seal or a reader, confirm:

- [ ] **No concatenation.** Bursts stay sparse; duration comes from the *span*, not the sum of sample lengths. (I1)
- [ ] **Boundaries are gap-based**, and completeness waits a *full* gap. (I2, I3)
- [ ] **Confirmed vs auto** behavior is correct; only confirmed fires the coach and only confirmed (same-session) may force-overwrite. (I4, I6)
- [ ] **Failures classified right:** transient retries, deterministic (422) caps; any new biosignal router emits 422 for data faults. (I5)
- [ ] **Re-seal is safe:** richer row protected, force nulls stale stages, no double-count. (I6)
- [ ] **Naps excluded** from every night reader (`->nights()`); **placeholders/short bouts excluded** from training (`->training()`). (I7, I8)
- [ ] **A metric and its on-screen breakdown use the same filter.**
- [ ] **Tests:** add a case that would have caught the specific failure you're preventing. Pin behavior with
      the real seal path where feasible (the reflection-based unit tests in
      `tests/Feature/SleepDutyCycleSealTest.php` are the pattern for the private helpers).

---

## Constants glossary

| Constant | Where | Meaning |
|---|---|---|
| `EPOCH_SEC = 30` | staging.py, SealNightJob | one sleep epoch = 30 s |
| `FILL_GAP_MAX_EPOCHS = 16` | staging.py | bridge holes up to 16 epochs (8 min); larger = NODATA |
| `MIN_COVERAGE = 0.30` | SealNightJob | below this, write honest duration-only, no fabricated stages |
| `SESSION_GAP_S = 150 min` | SealNightJob | gap that separates sleep sessions / defines completeness |
| `NAP_MAX_MIN = 240` | SealNightJob | shorter ⇒ nap (keyed on `session_start`), else night (`slept_at`) |
| `MAX_SESSION_MIN = 16h` | SealNightJob | absurd-span clamp |
| `POST_SAMPLE_SLEEP_GRACE_S = 3h` | SealNightJob | presume sleep past a dead band's last sample |
| `MIN_SLEEP_MIN = 20` | SealNightJob | floor for a real sleep |
| `MAX_SEAL_ATTEMPTS = 4` | SealNightJob | deterministic-failure cap (poison guard) |
| `STAGE_COLUMNS` | SealNightJob | nulled on a force write to avoid chimera rows |
| `SESSION_GAP_MINUTES = 20` | SealActivityJob | gap that separates workouts / defines completeness |
| `MIN_SESSION_MIN = 5` | SealActivityJob | shortest bout worth sealing |
| `MIN_TRAINING_MIN = 5` | ActivitySession | streak/strain length floor (NULL excluded) |
| `MIN_RUN_SPAN_M = 150`, `DRIFT_PATH_RATIO = 3.0` | SealActivityJob | phantom-GPS-drift guard |
| `1.15 × hr_max`, `215 bpm` | route.py | Relative Effort artifact clamp (fractional + absolute) |

---

## Open issues (known, deliberately deferred)

Tracked in `tasks/reviews/2026-07-09-review-sleep-seal-and-workout.md`:

- **S-3 — offline-buffer split (open data-loss).** Store-and-forward replays a night's second half in bulk
  after the first half was already sealed. The fix needs a `created_at` vs `window_end` **ingest-skew
  signal** ("backlog draining — don't seal yet") plus a **merge path** for late windows that land inside an
  already-sealed span. This is the highest-priority remaining bug.
- **Quarantine status + explicit unseal tool.** A re-openable park state (instead of terminal `sealed`) so a
  permanently-missing blob and historical-row repairs have a home.
- **W-4 — persisted `activity_session_id` FK** for coach-logged sets (currently a fuzzy `performed_at` join).
- **Persisted `is_training` flag** so streaks don't depend on the length heuristic (which excludes force-sealed
  1–4 min workouts and admits ≥5 min stray-motion blobs).
- **S-5 grace is time-based, not awake-state-aware; S-6 auto nights seal after the morning briefing.**

---

*This document was distilled from a multi-pass hardening effort (the "17-minute night" outage through the
sixth adversarial review). If you find yourself surprised by seal behavior, the surprise probably belongs
here — add it.*
