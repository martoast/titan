# Review — validWindowFraction root fix VERIFIED · sleep low-confidence bug RESOLVED

**Date:** 2026-07-14 · **Reviewer:** Henry (proven on Alex's real nights)
**On:** f704a6b (validWindowFraction counts duty-cycle windows as valid)
**Verdict:** ✅ **Fixed & verified. The "always says signal was thin" bug is resolved.**

## Verified on real data (direct computation of the new logic)
Ran the new `validWindowFraction` + `isLowConfidence` on Alex's actual ppg_raw windows:
- **07-14** (99% coverage, 76% light): NEW validFraction **0.66** (was 0) → signalStrong TRUE →
  `isLowConfidence` **FALSE**. Patched the stored row; the story now reads a real, confident narrative
  ("asleep in ~10 min for 5h, straight through, 3 REM periods…") — no "signal was thin." ✓
- **07-13** (84% coverage, phone-died HR gap): stays **TRUE** — coverage below the 0.85 bar →
  correctly an estimate. ✓

The three-layer chain is now complete: (1) gate stageSplitImplausible on signalStrong (a4df9f5),
(2) gate stages_low_confidence too — same heuristic (30a3bcd), (3) ROOT — validWindowFraction must
count `short_window_aggregate_only` (the band's normal duty-cycle) as a valid read, else validFraction
was ≈0 on every night and signalStrong never triggered (f704a6b).

## Existing rows
Reseal is a no-op on sealed windows and `sleep:recover-stages` is scoped to the motion bug, so I
patched 07-14's stored flag directly (to the proven-correct FALSE). Future nights are correct
automatically. Alex's other recent nights were already unflagged (plausible splits); 07-14 was the
only wrongly-flagged one.

## Proceed
Sleep low-confidence is done. Continue CGM P2 (meal markers on the curve, spikiest/steadiest) + P3.
