# Review — no guardrail that a logged meal's macros reconcile with its calories

**Date:** 2026-07-12 · **Reviewer:** Henry (field-test on Alex profile 1, real meals)
**On:** 8fdd830 (meal card B4) — *the card is correct*; it surfaced a data/logging gap.
**Verdict:** ✅ `MealCard` faithfully renders stored macros + Edit/Delete actions. ❌ The card made
visible that a meal can be logged with calories that don't reconcile with its P/C/F — and nothing
catches it.

## What I saw (real, 9 meals)

The meal card for Alex's latest log reads **"protein shake with milk and peanut butter — 470 kcal ·
37P · 0C · 0F."** Peanut butter is ~50% fat and milk has carbs, so 0C/0F is clearly wrong, and
4·37 = 148 kcal can't account for 470. Checked all 9 meals — **only this one is off**:

```
meal                                     kcal   P   C   F   4P+4C+9F   gap
protein shake w/ milk + peanut butter    470   37   0   0     148     +322  ← inconsistent
3 pork tacos                             720   36  54  36     684      +36
4 slices of pizza                       1120   44 128  48    1120       +0
...the other 7 all reconcile within ±40 (normal rounding)
```

So the fast-logger is generally healthy (8/9 consistent). This was a one-off turn that captured
kcal + protein but left carbs/fat at 0. The problem isn't this single meal — it's that **there's no
reconciliation check**, so a macro-inconsistent meal saves silently and then (a) shows broken 0C/0F
chips on the new card and (b) undercounts the day's carbs/fat in the macro totals.

## Fix (lightweight guardrail on log_meal / update_meal)

When a meal is logged/updated with `calories` set, sanity-check against `4·P + 4·C + 9·F`:
- If the gap is large (say > ~120 kcal or > 25%) **and** carbs or fat is 0/missing, the macros are
  incomplete — have the coach **estimate the missing macros** to fit the calories (it already
  estimates for fast-logging; just don't let a macro sit at 0 when calories say otherwise), or
  back-solve the obvious missing one.
- Keep it soft: this is an estimate tracker (the whole point of the fast-log feature), so don't
  block the log — just don't store a meal whose macros contradict its own calorie number.

This keeps the daily macro totals honest, which is the reason the food log exists — and stops the
meal card from displaying a self-contradicting meal.

## Alex's one bad meal
Meal #47 ("protein shake…") is the only affected row. It's his data, so I didn't edit it — he can
tap **Edit** on the card (or tell the coach "fix that shake's macros") and the new update_meal path
will correct it. Roughly it should be ~37P / ~15C / ~28F for 470 kcal.

## Repro
`profile 1`'s meals: `calories` vs `4·protein_g + 4·carbs_g + 9·fat_g` → meal #47 gaps by +322 kcal.
