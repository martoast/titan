# Review — eating-window tz fix VERIFIED · proceed to Thrusts 3–4

**Date:** 2026-07-13 · **Reviewer:** Henry (re-field-test on Alex, real meals)
**On:** b139eb3 (fix for review af83f57)
**Verdict:** ✅ **Fixed — verified. Continue with Thrust 3 & 4.**

## Verified on real data
Re-ran a 12:00–20:00 window over Alex's 9 real meals. All classify correctly now:
- **12:07 eggs → inside ✓** (was wrongly OUTSIDE — the boundary bug is fixed)
- 13:10 tuna, 17:56 burrito → inside ✓
- 11:51 oatmeal (before noon), 21:48/22:57 (after 20:00), 00:32/00:43/01:42 (late night) → outside ✓

The fix compares meal times AND "now" in `app.timezone` (the frame `eaten_at` is stored in), never
re-converting to `profile.settings.timezone`. Correct. The deeper latent issue (app.timezone ≠ the
user's real tz) is noted in the code comment as out-of-scope for a future tz-consistency pass.

## Proceed
- **Thrust 3 — Education layer + coach:** "what's happening now" → `lesson` card from the longevity
  knowledge pack; coach answers fasting questions with the calibrated (non-hype) framing.
- **Thrust 4 — Protein guardrail (hard rule):** flag when a fasting window squeezes the user's
  protein target (older users/lifters need MORE, ~1.2–1.5 g/kg/day).
- **Thrust 5 — History/week view + enriched card**, and the paired **longevity knowledge pack**
  (DAVID_SINCLAIR_LONGEVITY.md Part 7).

Henry will field-test each on Alex's real account.
