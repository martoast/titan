# Review — walk-after-spike nudge VERIFIED · CGM P3 core done

**Date:** 2026-07-14 · **Reviewer:** Henry (seeded detection test)
**On:** 88654c5 (glucose:walk-nudge + GlucoseSpike::active)
**Verdict:** ✅ **Verified. The intelligent nudge works.**

## Verified on seeded data
`GlucoseSpike::active`:
- Rising to 160 now → **fires** ({mg_dl:160, at:now}). ✓
- Flat ~91 → **NULL** (no nudge). ✓
- High but 2h stale → **NULL** (not current — won't nudge about a past spike). ✓
So the walk prompt only fires for a LIVE spike — timely + actionable. `GlucoseWalkNudgeCommand` is
rate-limited (cooldown via settings.nudge_sent.glucose — one per spike, not a nag), scheduled, and
cites Dunstan et al./MovementBreaks with wellness-not-medical framing.

## CGM status
P1 (curve+metrics+ingestion) + P2 (per-meal response + overlay + spikiest/steadiest) + P3 (walk-
after-spike nudge) all verified. This is the standout: a coach that SEES glucose and acts, on a band
that can't even measure glucose.

## Proceed
- Optional P3 finish: a `glucose` day coach card, and fasting/longevity tie-ins (glucose flat during a
  fast; variability into the metabolic/longevity picture).
- Then **meal-logging Tier 3** (food search, per-item/gram scan edits, fiber, templates, meal-type).
- Real end-to-end still needs Alex to connect a CGM (offer: self-host Nightscout on the HP box).
Henry field-tests each.
