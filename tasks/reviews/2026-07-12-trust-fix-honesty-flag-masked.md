# FOLLOW-UP: the trust fix's low_confidence flag is MASKED by its own span-merge (Tester B's real night)

**Reviewer:** Henry · re-sealed Tester B's night #62 (profile 6) through 094407c · 2026-07-12

## Span-merge: ✅ works
Her night went from a truncated **bed 01:11/wake 02:58, 4h11m, cov 0.40** to her real
**bed 01:23/wake 09:01, 6.6h, cov 0.99** — `mergeOvernightSleepFragments` correctly rebuilt the whole
night instead of one fragment. Good.

## Honesty flag: ❌ does NOT fire on the exact night it was built for
After the reseal: `low_confidence = false`, `quality = 70`, stages **deep 38% / REM 2% / light 60%**.
But the underlying signal is garbage: **0 of 170 ppg_raw windows are fully valid** — all
`short_window_aggregate_only`/`invalid_signal` (poor PPG contact / band fit). So Tester B would see a confident
"6.6h, quality 70" night when her band barely read her pulse. That's the exact over-confidence the fix was
supposed to kill.

## Two root causes
1. **The flag keys on COVERAGE, which the span-merge INFLATES.** Bridging the NODATA gaps drives coverage
   0.40 → 0.99, so the `coverage < 0.5` gate never trips — the merge masks the poor signal. The flag must
   also (or instead) key on **SIGNAL QUALITY: the fraction of fully-valid ppg_raw windows** (here 0/170 =
   0%). A night with ~0% valid HRV windows is low-confidence regardless of bridged epoch coverage.
2. **The stage-plausibility check isn't catching REM 2%.** d85f377's rule was `rem_frac < 0.05 OR
   dominant > 0.70`; Tester B's REM is 0.02 and deep is 38% — it should fire, but low_confidence is false.
   Verify the d85f377 stage-plausibility path is actually wired into the new unified `low_confidence`
   (it looks like only the coverage branch made it in).

## Fix
- `low_confidence = TRUE` when ANY of: coverage < 0.5 (pre-merge / true measured), **valid-window fraction
  < ~0.5**, OR the stage split is implausible (REM<5% / a stage>70%). Compute the valid-window fraction
  BEFORE the span-merge bridging so the merge can't mask it.
- Re-seal Tester B #62 as the fixture: it MUST come out `low_confidence = true` with a fit-check nudge (0%
  valid windows + REM 2% is the textbook low-signal night).
- Keep the span-merge — showing 6.6h is right; just flag it honestly as an estimate, not quality-70 fact.

*The span fix made the DURATION honest; now make the CONFIDENCE honest. A night the band couldn't read
must say so — that's the whole point.*

— Henry
