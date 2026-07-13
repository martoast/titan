# Review — eating-window adherence mis-flags in-window meals (timezone mismatch)

**Date:** 2026-07-13 · **Reviewer:** Henry (field-test on Alex profile 1, real meals)
**On:** 98b8d19 (FASTING_EVIDENCE Thrust 2 — eating-window mode + adherence)
**Verdict:** ✅ The window model, the meal-logging hook, the honest framing, and the adherence-streak
shape are all good. ❌ One real bug: adherence uses a different timezone than the one `eaten_at` is
stored in, so meals near the window edge are mis-classified as "outside."

## What I saw (real data)

Set a 16:8 window (12:00–20:00) for Alex and checked his 9 real meals. His **eggs breakfast at
12:07** was flagged **OUTSIDE** a window that opens at 12:00 — it's clearly *inside* by 7 minutes.

## Root cause (confirmed)

```
profile.settings.timezone = America/Tijuana   (UTC-7)
config('app.timezone')    = America/Mexico_City (UTC-6)
Meal.eaten_at cast        = 'datetime'  -> anchored to app.timezone (Mexico_City)
EatingWindow resolves tz  = profile.settings.timezone (Tijuana)   [forProfile():~117, mealOutside():~195]
```

`EatingWindow::forProfile` / `mealOutside` do `$meal->eaten_at->copy()->setTimezone($tz)` with
`$tz = profile tz (Tijuana)`, but `eaten_at` is a wall-clock stored in **app tz (Mexico_City)**. The
`setTimezone` **shifts the wall-clock by 1 h** (12:07 → 11:07), so a noon meal falls before a noon
window. Any meal within ~1 h of either edge flips. This makes the "you ate outside your window" note
and the adherence streak wrong — and it would gently scold the user for meals that were actually
in-window, which is the opposite of the honest, non-shaming intent.

## The fix

The meal time and the window boundaries **must be compared in the same frame that `eaten_at` is
stored in.** `eaten_at` is anchored to `app.timezone` (that's what every meal-list/display uses), so
the eating-window check should use **that same tz for the meal wall-clock**, not re-convert to
`profile.settings.timezone`:

- In `forProfile`/`mealOutside`, derive each meal's minute-of-day from `eaten_at` **without**
  `setTimezone` to a different zone — read it in the tz `eaten_at` is already cast in (app tz), the
  same wall-clock the app shows. Then the window start (also a user-entered wall-clock) and the meal
  wall-clocks line up.
- Keep using the user's real tz only for **"is the window open right NOW"** (`Carbon::now($tz)`),
  since that's a real-moment question — but the *historical meal* comparison must match the stored
  frame.

## Deeper issue worth flagging (not this PR's job to fully fix)

The real latent fault is that **`eaten_at` is anchored to a fixed `app.timezone` (Mexico_City) that
doesn't match the user's actual tz (Tijuana)** — so *any* feature that converts meal times to the
user's real tz will be off by the offset. Long-term this wants a proper tz-consistency pass (store
UTC, convert to the user's tz everywhere, or stamp each meal with the tz it was logged in). For now,
making EatingWindow consistent with the existing `eaten_at` frame fixes the user-facing bug without a
migration. Flag it in `docs/SEAL_ARCHITECTURE.md`-style notes so the next tz-touching feature doesn't
re-trip it.

## Repro
Set a 12:00–20:00 window for profile 1; `EatingWindow::mealOutside($p, <eggs meal eaten_at 12:07>)`
returns `true` (outside) because 12:07 Mexico_City → 11:07 Tijuana. Should be `false` (inside).

## Otherwise: T2 is good
Model, `set_eating_window` tool, the gentle out-of-window note, honest framing, and the streak shape
are all correct. Just fix the tz frame, then proceed to Thrust 3 (education layer + coach) and Thrust
4 (protein guardrail). Henry will re-verify adherence on Alex's real meals after the fix.
