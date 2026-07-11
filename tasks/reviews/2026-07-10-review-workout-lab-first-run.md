# WORKOUT LAB — first field run: 1 real production bug + 1 harness blocker

**Reviewer:** Henry (server) · **Against:** 8552c1b · ran `workout:lab --calibrate` + both scenarios
(explicit `--ingest-url=http://localhost:8080/...` — see note 3).

## Results

- **calibrate:** ACCEPT (built-in steady-run passes). But `type source: {"run":"default(0)","strength":"real(8)"}`
  — all 8 of Alex's real sessions are strength; the **run envelope is uncalibrated defaults**, so the run
  scenario is validated against synthetic targets, not real running data. (Not a bug — a coverage gap. If we
  want the run path grounded, we need at least one real run session, or say so in the scorecard.)
- **steady-run scenario:** P1–P4 PASS, **P5 FAIL** — real bug, below.
- **gym-lift scenario:** CRASHES before scoring — harness blocker, below.

## Finding 1 (PRODUCTION BUG) — WorkoutStreak buckets evening workouts on the wrong day

P5 "surfaces agree" fails: `/api/runs` lists the sealed run correctly, but the SAME payload's
`streak.worked_out_today` is false and `active_days` omits the workout's day. Root cause is a tz-convention
miss in `app/Support/WorkoutStreak.php:42-43`:

```php
->pluck('started_at')
->map(fn ($ts) => CarbonImmutable::parse($ts)->setTimezone($tz)->toDateString())
```

`activity_sessions.started_at` is stored **UTC wall-clock** (the seal convention — MobileRunsController:118-123
documents this and handles it with `parse($raw, 'UTC')->setTimezone($tz)`). WorkoutStreak parses it with **no
source tz**, so it reads the UTC wall-clock as app-local and never converts. Empirically proven on the LAB
session:

```
started_at RAW               = 2026-07-11 03:52:38   (a 21:52 America/Mexico_City workout on the 10th)
WorkoutStreak (parse no-tz)  = 2026-07-11            ← WRONG
correct (parse as UTC)       = 2026-07-10
today (Mexico)               = 2026-07-10
```

Any workout after ~18:00 local (past 00:00 UTC) lands on the next day. **This hits Alex directly — he trains
in the evening** (real sessions 19:00–22:00), so his streak/heat-strip mark the wrong day and "worked out
today" reads false right after he finishes. Fix: mirror the controller —
`CarbonImmutable::parse($ts, 'UTC')->setTimezone($tz)` at line 43. Check the other buckets in the same file
(`worked_out_today`, `this_week`, `this_month`, streak cursor) all compare against these same dates, so the
one-line source-tz fix corrects them together — but please add a non-UTC test (evening workout, app tz) so
this can't regress. This is the SAME convention that bit the sleep side; it belongs in a shared helper if
there's an obvious home.

## Finding 2 (HARNESS BLOCKER) — gym-lift can't run: exercises.category NOT NULL

```
SQLSTATE[HY000]: 1364 Field 'category' doesn't have a default value
insert into exercises (slug, name, muscle_group, updated_at, created_at) ...
```

`WorkoutLab.php:238-240` creates exercises without `category`, but the column is NOT NULL with no default
(`2026_06_15_050000_create_exercises_table.php:21` — `compound | isolation | cardio`). `category` is fillable;
the gym-lift `setEvents` (`WorkoutScript.php:239-243`) carry `muscle_group` but no `category`. Fix: add a
`category` to each setEvent and pass it (or default it, e.g. `$ev['category'] ?? 'compound'`) at line 240.
Blocks the entire gym-lift scenario — the whole strength path (P4 "sets belong", chunk-fold, session merge)
is currently untestable through the LAB until this lands.

## Note 3 (LAB ergonomics) — default ingest URL is the port-80 swallow trap again

`WorkoutLab.php:165` defaults `--ingest-url` to `http://localhost/api/devices/ingest` (port 80). In-container,
:80 is dead (`curl → 000`); the app serves on :8080 (`→ 401`). Same silent-swallow bug SLEEP LAB had (fixed in
7d6de95). Without `--ingest-url=http://localhost:8080/...` every batch is dropped and the seal sees nothing.
Point the default at the reachable app port (or `config('app.url')`).

— Henry
