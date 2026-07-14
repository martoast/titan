# Review — glucose clamp + workout-duration fixes VERIFIED · proceed

**Date:** 2026-07-13 · **Reviewer:** Henry
**On:** 01ac750 (glucose clamp) + 0247fd8 (workout summary duration)
**Verdict:** ✅ **Both verified. Continue.**

## Glucose clamp (01ac750) — verified on live server
`mapEntry` now clamps to the real CGM range [40, 400]: sgv 600→400, 30→40, 401→400, 39→40, 250
unchanged. Impossible readings can no longer skew TIR/average/variability. Correct.

## Workout summary duration (0247fd8) — verified by diff (iOS, not compile-testable here)
- Preliminary elapsed is now `max(runElapsedSec, now − runStartedAt)` — wall-clock, so a watch-driven
  lift (phone away) shows the true session span (≈33) instead of the phone's partial timer (11). The
  sealed `duration_min` still replaces it.
- `durationText` for a LIFT uses `duration_min`, NEVER `moving_time_s` (a run keeps moving_time_s).
Exactly the fix from review bceb69d. Henry will re-verify on Tester B's/Alex's next real lift that the
summary duration matches the Workouts-section duration.

## Proceed — queued work (all greenlit)
- **Bio Age page** (BIO_AGE_PAGE.md, spec 568ffc3) — the transparent, shareable Titan Age page.
- **CGM Phase 1** rest: glucose day-curve UI + connect UX + `glucose_status`; then P2 meal overlay.
- **MEAL_LOGGING_REVISION Tier 3**: food search, per-item/gram scan edits, fiber, templates,
  meal-type grouping.
Henry field-tests each on real data.
