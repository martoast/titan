# SLEEP WEEK — the week view for sleep (iOS + Web)

**Status:** BUILD NOW
**Requested by:** Alex, 2026-07-12
**One line:** Give sleep the same "week at a glance" surface training already has — every night's
score for the last 7 days, a cumulative weekly score, and a consistency streak. Big UI/UX lift.

---

## The ask (verbatim intent)

> "I want to be able to go back and see the scores of each night in the week on top of their
> cumulative score. Same way that we have the view for training for the week and the streak — we
> should look to have something similar for sleep. This is a big UI/UX improvement on iOS and web."

Sleep today shows **last night** beautifully (stages, performance, timeline) and a flat 14-night
list under it. What's missing is the **week as a unit**: the shape of the last 7 nights side by
side, one number for the week, and a streak that rewards showing up. Training already nails this
(`WorkoutStreak` → week ring + consecutive-day streak + 5-week heat strip). This spec ports that
mental model to sleep — and does NOT reinvent it. Mirror the training week screen's visual language
so it feels like the same product.

---

## What already exists (don't rebuild it)

- `GET /me/sleep` (`MobileSleepController::show`) already returns **`nights`** — up to 14, newest
  first, each with `date, duration_min, quality, deep/rem/light/awake_min, stage_status, coverage,
  low_confidence, bedtime, wake_time`. The per-night data is DONE.
- `SleepCoach::assess($profile)` returns `need_h, debt_h, last_h, performance_pct, band, label`.
  **`performance_pct` is the night score** — hours slept vs need, the Whoop "Sleep Performance"
  read. Reuse it; do not invent a new score.
- `SleepDetail::forProfile` / `sessionsForDay` — the single-night detail + timeline the week view
  taps THROUGH to. Reuse as-is.
- `WorkoutStreak::forProfile($profile, $tz)` — the exact structure to copy for the server side.

So the server work is one small support class + one block on the existing endpoint. The bulk of
this spec is **UI/UX** (iOS + web), which is where Alex wants the lift.

---

## Server — `App\Support\SleepWeek` (mirror `WorkoutStreak`)

New class `SleepWeek::forProfile(Profile $profile, string $tz = 'UTC'): array`. Bucket every night
on the user's **local** calendar day. Copy WorkoutStreak's timezone discipline exactly — read the
RAW `slept_at` and parse as UTC before `setTimezone($tz)`, or an evening/early-morning night lands
on the wrong day and "slept today" reads false. (WorkoutStreak documents this pitfall — cite it.)

Return shape:

```
{
  "week_score": 82,          // cumulative: mean performance_pct over the last 7 LOCAL nights that
                             //   have a confident score. null if 0 confident nights this week.
  "week_label": "Strong week",   // band on week_score (see bands below)
  "trend": "up",             // vs the prior 7-night window: up | flat | down | null
  "nights_logged": 6,        // confident nights in the last 7 (the ring's denominator is 7)
  "streak": {
    "current": 4,            // consecutive nights (walking back from today) that HIT sleep need
    "longest": 11,           // best ever
    "slept_well_last_night": true
  },
  "days": [                  // exactly 7 entries, OLDEST→NEWEST, one per local calendar day
    {
      "date": "2026-07-06",
      "weekday": "Mon",
      "score": 88,           // performance_pct for that night, null if no night logged
      "duration_min": 447,
      "hit_need": true,      // score >= NEED_THRESHOLD (counts for the streak)
      "low_confidence": false,  // low-signal night: show as estimate, EXCLUDE from week_score + streak
      "logged": true         // false = no sleep recorded that day (missed night → hollow slot)
    }
    // ... 7 total, missing days included as {logged:false, score:null}
  ],
  "strip": [ "2026-06-08", ... ]   // 5-week (35-day) heat strip of nights that HIT need, like workouts
}
```

### Rules that keep it honest (this is a trust surface)

1. **Low-confidence nights never inflate the week.** A night flagged `low_confidence` (mostly-NODATA
   coverage or implausible split — see the trust fix, review 12d2a7e/08b09b8) is shown in the strip
   as an **estimate**, but is **excluded** from `week_score` and does **not** advance the streak.
   Silent inclusion would be exactly the dishonesty we just fixed in sealing.
2. **Missing nights are visible, not hidden.** A day with no sleep log is a hollow slot in the 7-day
   row (`logged:false`), not skipped — the gap is information (Alex noticed Tester B's gap immediately).
3. **`hit_need` / streak threshold:** a night counts if `performance_pct >= 85` (hit ~85% of need).
   Put the constant in one place (`SleepWeek::NEED_THRESHOLD`) so it's tunable.
4. **Week bands** for `week_label`: `>=90 Excellent · 80–89 Strong · 65–79 Building · <65 Run down`.
   Reuse SleepCoach band vocabulary if it already has one — don't fork tone.
5. Wrap the whole thing resilient like the dashboard does (`safe(fn () => …)`) — a week-agg failure
   must never blank the Sleep screen.

### Endpoint

Add a `"week": SleepWeek::forProfile($profile, $tz)` block to `MobileSleepController::show`
(read `$tz` from the request the way the dashboard controller already does). Web reads the same
payload — no separate route needed unless the web dashboard fetches sleep independently, in which
case expose the same array on the web sleep domain controller.

---

## iOS — the Sleep Week screen

Port the **Workouts week screen's** structure so it reads as the same family. Top-to-bottom:

1. **Cumulative week ring (the hero).** One big ring/number = `week_score`, with `week_label`
   under it and the `trend` arrow. This is the "how was my week" glance. Same ring component the
   training week uses — restyled to the sleep palette (deep indigo/violet, not the training color).

2. **The 7-night row (the heart of the ask).** Seven slots Mon→Sun, each a small vertical
   bar or mini-ring sized to that night's `score`, the weekday under it, hours inside/below.
   - Tonight/most-recent night emphasized.
   - `hit_need` nights filled in the accent; below-need nights muted; `low_confidence` nights get
     the estimate treatment (dashed/′~′ badge); `logged:false` days are a hollow outline.
   - **Tap a night → push the existing single-night detail** (`SleepDetail` timeline + stages).
     This is the "go back and see the scores of each night" behavior — every bar is a doorway.

3. **Streak card.** `current` streak with a flame/moon motif, `longest` as the ghost target,
   mirroring the training streak card. "4 nights hitting your need."

4. **5-week heat strip.** The `strip` array as a calendar heat-strip identical to the Workouts
   one — nights that hit need are lit. Consistency is the Whoop metric that matters most; make it
   the thing you can't miss.

5. **This week's tip (the "so what").** One card under the strip: a single, specific,
   data-driven insight about THIS week's pattern + one action. Not generic sleep hygiene — it must
   name what the week actually shows. This is where the week view stops being a chart and starts
   coaching. See the tip engine below.

Keep last-night's full detail where it already lives; the week view sits ABOVE it (or as a
segmented toggle "Last night · Week") — your call, but don't duplicate the stage breakdown.

## The weekly tip engine — `SleepWeek` computes it, coach voices it

The week view earns its place by telling Alex ONE true thing about his week and what to do about
it. Compute a `tip` on the `week` payload:

```
"tip": {
  "headline": "Your bedtime swung 2h10m this week",
  "insight": "Your sleep landed anywhere from 10:40pm to 12:50am. That variance is why deep sleep
              was down — the body banks its deepest sleep when it can predict lights-out.",
  "action": "Aim for lights-out within a 30-min window. Pick 11:00pm and hold it.",
  "domain": "consistency"   // consistency | duration | debt | timing | quality | restorative | win
}
```

### How to pick the tip (priority order — fire the first that triggers, one per week)

Detect the week's dominant pattern from data already on hand (the 7 nights + `SleepCoach` need/debt
+ stage splits). Rank so the biggest lever wins:

1. **timing/consistency** — bedtime spread across the week > ~90 min (stddev of bed times). The
   single highest-leverage sleep behavior; lead with it when present.
2. **debt** — cumulative sleep debt over the week rising / `debt_h` high. "You're carrying 4.2h of
   debt — here's the one night to bank it back."
3. **duration** — mean duration well under need (multiple sub-need nights).
4. **restorative** — low deep+REM fraction despite adequate hours (fragmented sleep).
5. **catch-up pattern** — big weekend oversleep compensating short weekdays (social jetlag).
6. **win** — nothing's wrong: name what went RIGHT so the good week is reinforced, not silent.
   ("5 nights hit your need and your bedtime held — this is the week to repeat.")

### Rules

- **Always teach the mechanism, never just the rule.** This plugs into the Coach v3 educational
  stance (COACH_V3_SITUATIONAL_INTELLIGENCE.md THRUST 2): the `insight` line explains the WHY (why
  variance kills deep sleep, why debt compounds), not just "sleep more." A tip the user understands
  is a tip they keep.
- **One tip, weekly, specific.** Never a list, never generic hygiene ("avoid caffeine"). If it
  could be printed in any sleep app for any user, it's wrong — it must reference this week's numbers.
- **Confident data only.** Low-confidence and missing nights are excluded from pattern detection
  (a jittery estimate must not trigger a "your deep sleep is low" tip).
- **Reuse, don't fork the brain.** If `SleepCoach` or the `KnowledgeEnricher`/coach digest already
  phrases these mechanisms, pull from there so the tip's voice matches the coach. The tip is the
  same intelligence, surfaced on the week card instead of in chat. Consider exposing it to the coach
  too (a `sleep_week` digest) so "how was my sleep this week?" in chat gives the same answer.
- **Graceful when thin:** < 3 confident nights this week → `domain:"win"`-style encouragement to
  keep logging, no fabricated pattern.

---

## Web — same, on the sleep domain

The web dashboard already iterates a `sleep` domain (`routes/web.php`). Build the same week view
there from the same `week` payload: cumulative score, 7-night row (click-through to the night),
streak, heat strip. Match the web design system; don't hand-roll a one-off.

---

## Design principles (Jobs / Chesky bar)

- **One glance answers "how did I sleep this week?"** Big number, seven nights beneath it. If it
  takes a second read, it's too busy.
- **Every night is tappable.** The week is an index into nights, not a dead chart.
- **Reward consistency, not perfection.** The streak celebrates showing up 7 nights, which is the
  behavior that actually moves recovery — same reason the training streak works.
- **Honesty is visible.** Estimate nights look like estimates; missing nights look missing. We just
  earned this trust in the sealing pipeline — don't spend it in the chart.
- **It's the same product as training.** Someone who knows the Workouts week screen should need zero
  learning to read the Sleep week screen. Shared components, sleep palette.

## Acceptance

- [ ] `SleepWeek::forProfile` returns the shape above; low-confidence + missing nights handled per
      the honesty rules; tz bucketing matches WorkoutStreak (evening/early-AM night lands on the
      right day). Unit test with a non-UTC tz + one low_confidence + one missing night.
- [ ] `/me/sleep` includes the `week` block; failure-resilient.
- [ ] iOS Sleep screen shows the cumulative ring + 7-night row + streak + heat strip + this-week's
      tip; tapping a night opens its existing detail.
- [ ] The tip references THIS week's real numbers (headline + insight + action), teaches the
      mechanism, and excludes low-confidence/missing nights from detection.
- [ ] Web sleep domain shows the same.
- [ ] Field check on Alex's real account: last 7 nights render with correct scores, the week number
      equals the mean of the confident nights, and a low_confidence night shows as an estimate and
      is excluded from the number. (Henry will verify on real data before sign-off.)
