# LONGEVITY INDEX & SLEEP PLANNER — synthesis features on data we already hold

**Spec for the dev agent · from Alex + Henry · 2026-07-11 · Whoop-parity**
**Status: BUILD. Two synthesis features; no new data collection.**

---

# Part A — Titan Longevity Index ("Healthspan / pace of aging")

## The idea
Whoop's Healthspan folds ~9 metrics into a single **WHOOP Age** and a **Pace of Aging** (are you aging
faster or slower than calendar time). It's their marquee longevity feature. **We already compute every
ingredient — we just never fuse them into one score you can watch move.**

## What exists (the ingredients — all BUILT, all separate)
- `app/Support/BiologicalAge.php::assess` — PhenoAge (blood, mortality-validated) + fitness-age blend.
- `app/Support/PhenoAge.php::compute` — the clinical clock.
- `app/Support/AthleteScore.php::assess` — fitness/athlete score.
- `app/Support/MetabolicHealth.php::assess` — metabolic markers.
- Plus VO₂max (`fitness.py`), `ChairStand` (functional age), sleep/recovery/strain trends
  (`TrendsOverview`), body composition.

## The build
`app/Support/LongevityIndex.php::assess(Profile)`:
- Fuse the available ingredients into (1) a **Titan Age** (biological-age estimate) and (2) a **Pace of
  Aging** — a directional read of whether the trend of the inputs is improving or worsening vs calendar
  time (e.g. "aging at 0.9× — slower than the clock"). Weight by which domains have data + confidence;
  degrade gracefully (a user with no bloodwork still gets a fitness/VO₂max/functional-based estimate,
  clearly labeled as partial). NEVER present a confident age off two inputs.
- Break it down: show the CONTRIBUTORS (VO₂max is pulling you younger, metabolic markers older) so it's
  actionable, not a black-box number — the "explain the why" ethos.
- A `longevity_status` coach tool + a line in the Phase-1 trajectory digest ("Titan Age 34 vs 39
  calendar, trending younger"). Render as a first-class **longevity card** (COACH v2 P2 widget pass): the
  age, the pace dial, top 2 levers.
- Recompute on new bloodwork / a fresh fitness test / weekly; store history so the pace-of-aging trend is
  real, not a one-shot.

## Acceptance
- Produces a Titan Age + pace-of-aging with contributor breakdown; degrades honestly with partial inputs
  (labeled), never over-confident; history stored so pace is trend-based.
- `longevity_status` tool + trajectory line + real card render.

---

# Part B — Sleep Planner (explicit bedtime recommendation)

## The idea
Whoop's Sleep Planner tells you **what time to go to bed tonight** to hit your recovery goal, given your
sleep need, current debt, and target wake time. We compute need and debt already — we just never turn it
into "be in bed by 10:40."

## What exists
- `app/Support/SleepCoach.php::assess` — returns `need_h`, `baseline_h`, `debt_h`, `strain_bump_h` (harder
  day → more need). Already the hard part.
- `app/Support/CircadianRhythm.php::compute` — rest-activity rhythm / phase.
- `app/Support/SleepRegularity.php` — consistency (bedtime variance).

## The build
`app/Support/SleepPlanner.php::plan(Profile, ?targetWake)`:
- **Bedtime = targetWake − need_h − a fall-asleep buffer (~15–20 min)**, where `need_h` comes from
  `SleepCoach::assess` (so tonight's plan already reflects today's strain + accumulated debt), and the
  target wake is the user's set wake / smart-alarm time / their circadian-typical wake from
  `CircadianRhythm`.
- Nudge toward their CONSISTENT bedtime (`SleepRegularity`) — protect the rhythm; if debt is high, suggest
  going down a bit earlier rather than a wildly different time (consistency beats a one-night catch-up).
- Output: a bedtime with a **window** ("in bed 10:35–10:55") + the reason ("you need 8.1h after today's
  strain, up at 6:30, and you sleep best going down near 10:40").
- Surfaces: an evening **Sleep Planner card** (tie into the existing `EveningNudge` — it can lead with the
  planned bedtime), a `sleep_plan` coach tool, and a bedtime line in the trajectory digest. Optionally an
  evening reminder ("wind down — bedtime in 30 min") via the existing reminders system.

## Acceptance
- Given a target wake + today's assess(), produces a bedtime window that reflects need + debt + strain and
  respects the user's consistent bedtime; explains the why.
- Evening nudge / card / `sleep_plan` tool surface it.

---

## Sequencing
Both are independent and can be built in parallel with the coach/stress/alarm work. Part B (Sleep Planner)
is smaller and pairs with the Smart Alarm (they share the target-wake concept — build the wake-time
setting once, both consume it). Part A (Longevity Index) is the bigger synthesis — do it after the coach
v2 widget pass exists so the longevity card has a home.

*We already do the science. These two features are the last mile: turning the numbers into "you're aging
slower than the clock" and "be in bed by 10:40" — the sentences a person actually acts on.*
