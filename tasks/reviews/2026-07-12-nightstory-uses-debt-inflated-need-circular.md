# Review — nightstory takeaway says "your ~10h need" (debt-inflated + circular)

**Date:** 2026-07-12 · **Reviewer:** Henry (field-test on Alex profile 1 + Tester B profile 6, real data)
**Verdict on 1de800a (nightstory card):** ✅ Card, hypnogram, action, and — nicely — the honesty
path all work (Tester B's low_confidence night shows the EstimateChip + a fit-check takeaway, not a fake
story). ❌ One user-facing number is off: the sufficiency line uses the **debt-inflated, capped**
need instead of the **baseline** need.

## What I saw (Alex, real)

```
nightstory takeaway: "Good structure, but only 5.6h asleep — well short of your ~10h need;
                      that's what's building your sleep debt."
```

`sleepDetail()` passes `$coach['need_h']` into `SleepStory::forNight($last, $coach['need_h'])`.
`SleepCoach` returns **two** needs: `baseline_h` (~8h, the stable "how much you need") and `need_h`
(baseline + a debt-payback bump + strain, **capped at NEED_CAP_H = 10**). Alex's debt is 9.5h
("heavy"), so `need_h` is pinned near the 10h cap — and that's the number the story shows.

## Why it's wrong

1. **It tells Alex he "needs ~10h."** Nobody's standing nightly need is 10h. That reads as absurd /
   discouraging and undercuts trust in the number — the same trust we've been protecting on the debt
   ledger and week view.
2. **It's circular.** "You're short of your 10h need, and that's what's building your debt" — but the
   need is 10h *only because* of the debt. Debt is built by falling short of **baseline (~8h)**, not
   short of a target that's high *because* you're already in debt.

## Fix

For the sufficiency comparison + the "what's building your debt" line, use **`baseline_h` (~8h)** —
that's the honest reference for "was this night enough." Reserve the debt-inflated `need_h` (~10h)
for **forward-looking advice only** ("to chip at your debt, aim for ~10h tonight"), never for the
"your need" framing. Concretely: pass `baseline_h` (or a dedicated stable-need) into
`SleepStory::forNight` for `short_by`, and if you want to mention tonight's elevated target, label it
as a target, not a need. Then the line reads: "only 5.6h — short of your ~8h need, which is why your
debt keeps climbing," which matches the debt ledger's own logic.

## Note
This is the same "one number, everywhere, honest" rule from the debt-ledger fixes — baseline-need vs
tonight's-target are two different things and the app should never conflate them in user copy.

## Repro
`CoachTools::sleepDetail()` on profile 1 → takeaway cites `~10h need`; `SleepCoach::assess(p)` shows
`baseline_h=8`, `need_h=10` (debt-inflated, capped).
