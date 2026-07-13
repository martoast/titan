# Review — nutrition trend in Trends VERIFIED · proceed with meal-logging Tier 3

**Date:** 2026-07-13 · **Reviewer:** Henry (field-test on Alex, real macros)
**On:** f9bf261 (MEAL_LOGGING_REVISION 2.2 — weekly macro trend)
**Verdict:** ✅ **Verified. Continue.**

## Verified on real data
`TrendsOverview::forProfile(p, 7)` returns per-day calories + P/C/F with the unified `targets`:
- targets = 3320 cal / 176 P / 429 C / 100 F — **matches `Macros::goalTargets`** (no drift from the
  earlier two-resolver fix; the whole app now agrees on the target).
- Real daily macros: 07-11 89P / 07-12 125P / 07-13 66P; unlogged days (07-09/10) correctly null (gaps).
- Target drawn as the reference line so "did I hit protein this week" reads at a glance — Alex's line
  sits under 176 all week (consistent with his real under-eating pattern).

Reuses the `TrendMetricChart` component from the Trends overhaul. Clean.

## Proceed — remaining MEAL_LOGGING_REVISION items
Tier 2: copy-a-previous-day (2.3, if not done). Tier 3: native food search over the existing caches,
per-item + gram edits in the scan confirm, fiber tracking, multi-item meal templates, meal-type
grouping, estimated-split chip. Henry field-tests each on Alex's real account.
