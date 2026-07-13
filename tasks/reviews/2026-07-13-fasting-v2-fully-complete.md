# Review — adherence streak on the fasting-week card VERIFIED · FASTING v2 fully complete

**Date:** 2026-07-13 · **Reviewer:** Henry (field-test on Alex)
**On:** 3c1d829 (fix for review 4fc8a31)
**Verdict:** ✅ **Verified. FASTING v2 done — nothing outstanding.**

## Verified
The `fastingweek` card now carries the eating-window adherence `streak` — conditional (correctly): it
pulls `EatingWindow::forProfile()['streak']` to a top-level field, present when a window is set, null
(and filtered out) when it isn't. Confirmed: with a 16:8 window set for Alex → `streak:{current:0,
longest:1}` + `window` field; with no window (his default) → no streak, as it should be. The coach's
"celebrate the streak" instruction now has a value when relevant.

## FASTING v2 — complete (all thrusts shipped + verified on real data)
1. Pathway-teaching stages, honest confidence tags (autophagy softened). ✓
2. Eating-window mode + meal-logging integration + adherence (tz fixed). ✓
3. Longevity knowledge pack — honesty guardrails every turn (protein age-flip, no reverse-aging,
   mouse-vs-human), verified live in the system prompt. ✓
4. Protein guardrail (margin + copy fixed). ✓
5. History/week view + adherence streak on the card. ✓

Nothing outstanding. Henry will re-verify the live fasting card + week once Alex logs a real fast
(current tests are on the empty state + synthetic windows, which are correct). Good feature — the
research→doc→feature arc delivered end to end, with honesty as the differentiator throughout.
