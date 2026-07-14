# Review — Bio Age page enrichment VERIFIED (one nit) · build the iOS page

**Date:** 2026-07-14 · **Reviewer:** Henry (field-test on Alex, real data)
**On:** c96f2a5 (BIO_AGE_PAGE · server enrichment foundation)
**Verdict:** ✅ **Verified.** One rounding nit; then build the page UI.

## Verified on real data
`BioAgePage::forProfile(1)`:
- **Waterfall math exact:** chrono 30.2 + components(VO₂max −1.0, RHR −2.2, HRV −3.0, Sleep −1.5,
  Activity 0.0 = −7.7) = **22.5** = titan_age. ✓
- Components enriched with real value+unit+methodology: VO₂max 29.2 ml/kg/min, RHR 49 bpm, HRV 135 ms,
  Sleep 70 (SRI), Activity 7142/day — each with a plain "how it maps to years." ✓
- **Tips personalized + honest:** protect-HRV, protect-RHR, and a celebrate ("nothing is aging you
  faster than your years — rare") since no older_levers. Calibrated framing, no "reverse aging." ✓
- Methodology footer present. Confidence "medium" (partial — no bloodwork). ✓

## Nit — round the VO₂max value
The `value` for the fitness component renders `29.222222222222` (unrounded float); the others are clean
(49, 135, 70, 7142). Round VO₂max to 1 dp (29.2). One-line fix in `enrichComponent`.

## Proceed — the iOS Bio Age page (the actual page)
Build the page per BIO_AGE_PAGE.md: tap the Today Biological-Age tile → dedicated page with the
shareable hero (Titan Age vs chrono + delta + pace + confidence + Share), the **contribution
waterfall** (each row expandable to value + `how`), youth-drivers/older-levers, the honest
"add bloodwork to sharpen" CTA (partial), the coach **tips** section (with an "ask the coach"
hand-off), and the methodology footer. Highest craft — it's the screenshot surface. Henry re-verifies
the rendered waterfall + tips on Alex's real data.
