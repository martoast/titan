# Review — meal↔glucose response returns nothing (tz mismatch, same class as eating-window)

**Date:** 2026-07-14 · **Reviewer:** Henry (integration-tested with seeded readings)
**On:** 9226e80 (CGM_INTEGRATION P2 — per-meal glucose response)
**Verdict:** ✅ The math is right. ❌ The windowing queries the wrong timezone → **no glucose attaches
to meals.** Breaks the killer feature until fixed.

## What I found
`GlucoseResponse::compute` + the **Carbon-instant** windowing are correct — passing a readings
collection explicitly (`forMeal($p,$meal,$rows)`) returns the right response (baseline 91, time-to-peak
45 on a classic spike). But the **query** paths convert the meal time to **UTC** and query the
`taken_at` column, which is stored in **app-tz** (verified: a reading's raw DB value is
`2026-07-14 08:16:50` = America/Mexico_City wall-clock, same frame as `meals.eaten_at`). So the query
searches ~6 h off and finds nothing → `forMeal` returns **null**.

Two buggy spots, both `->utc()` before a `whereBetween('taken_at', …)` against app-tz storage:
- `GlucoseResponse::forMeal` line ~112: `$atUtc = $at->copy()->utc()` → the SQL fallback (line 118).
- `MobileNutritionController::dayGlucoseReadings` lines ~57–59: `->utc()->subMinutes(20)` /
  `->utc()->addHours(3)` → the pre-fetch that feeds every meal card. **This is the production path**, so
  in production the pre-fetched collection is empty → every meal gets `glucose: null`.

Note the inconsistency: `GlucoseDay::forProfile` (the day curve) queries in **app-tz** and works — that's
the correct pattern to match.

## Fix
Query glucose readings in the **same frame they're stored** (app-tz), not UTC — mirror `GlucoseDay`.
Drop the `->utc()` on the query bounds in both `forMeal` (line 112) and `dayGlucoseReadings`
(57–59); keep the Carbon `lte/gte/betweenIncluded` instant comparisons (those are tz-agnostic and
already correct). Then the SQL bounds match the app-tz-stored `taken_at`.

(Deeper latent issue — the same one flagged for the eating window (af83f57): the app stores datetimes
in app.timezone, and the Nightscout provider produces UTC `taken_at` Carbons; they land as app-tz
wall-clock on save, so ALL glucose queries must use the app-tz frame. A real long-term fix is UTC
storage everywhere, but out of scope here — just make glucose consistent with GlucoseDay for now.)

## Verify (Henry)
After the fix, `forMeal` on a meal with surrounding readings returns the response (non-null), and the
nutrition index attaches a real `glucose` block to meals covered by a CGM. I'll re-run the seeded
integration test (spike → +54 mg/dL, back-to-baseline timing) and confirm meals show their response.

## Repro
`GlucoseResponse::forMeal($p, $meal)` (SQL path) → null even with a clear spike in `glucose_readings`
around `eaten_at`; passing the readings collection explicitly returns the correct response.
