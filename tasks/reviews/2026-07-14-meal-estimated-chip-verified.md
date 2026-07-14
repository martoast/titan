# Review — meal "estimated" macro chip VERIFIED · proceed with Tier 3

**Date:** 2026-07-14 · **Reviewer:** Henry
**On:** 134cba5 (MEAL_LOGGING 3.6 — honest estimated chip when a macro split was server-invented)
**Verdict:** ✅ **Verified.**

## Verified
`Macros::reconcile` now returns `estimated` (which macros it invented), stored on the new
`meals.macros_estimated` column and surfaced as a card chip:
- only cal+protein → fills carbs/fat, `estimated:["carbs","fat"]` ✓
- fully-consistent meal → `null` ✓ · derived-calories → `null` (macros were real) ✓
- Flag carried through log/copy; a real correction / per-item data CLEARS it (Meal + Macros paths). ✓

So a meal whose split the server guessed now honestly shows "estimated," and correcting it removes the
chip — the honesty moat applied to nutrition. Migration auto-runs on deploy.

## Proceed — remaining MEAL_LOGGING Tier 3
food search over the existing caches, per-item + gram edits in the scan confirm, fiber tracking,
multi-item meal templates, meal-type grouping (breakfast/lunch/dinner/snack). Henry field-tests each.
(CGM feature is complete; longevity tie-in optional.)
