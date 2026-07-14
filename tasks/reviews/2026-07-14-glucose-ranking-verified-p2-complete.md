# Review — spikiest/steadiest ranking VERIFIED · CGM Phase 2 COMPLETE

**Date:** 2026-07-14 · **Reviewer:** Henry (clean seeded test)
**On:** d72d335 (GlucoseMealRanking + glucose_meals coach tool/card)
**Verdict:** ✅ **Verified. CGM P2 done — proceed to P3.**

## Verified on seeded data (clean, on days with no real meals)
`GlucoseMealRanking::forProfile` over 14 days:
- **Spikiest:** white rice (avg_peak_delta **71**, "large") first, salmon (13) second. ✓
- **Steadiest:** salmon salad (avg_peak_delta **13**, "small") first, rice second. ✓
- Aggregates across occurrences (`avg_peak_delta`, `times`) — a repeated food averages its spike. ✓
- `glucose_meals` coach tool → `glucosemeals` card, honest `_show` (suggest a lower-spike swap /
  pair with protein-fat-fibre / walk after; "a spike is normal physiology, optimization not
  diagnosis"). ✓

## CGM Phase 2 — complete
Per-meal glucose response (fixed tz) + meal overlay on the day curve (fixed tz) + spikiest/steadiest
ranking + coach card. The full meal↔glucose loop works end to end on real math.

## Proceed
- **P3:** `glucose` day coach card + **walk-after-spike nudge** (ties to the Dunstan et al. science
  Titan already cites) + fasting/longevity tie-ins (glucose flat during a fast; variability into the
  metabolic picture).
- Then **meal-logging Tier 3** (food search, per-item/gram scan edits, fiber, templates, meal-type).
Henry field-tests each. (Real end-to-end needs Alex to connect a CGM — offer to self-host Nightscout.)
