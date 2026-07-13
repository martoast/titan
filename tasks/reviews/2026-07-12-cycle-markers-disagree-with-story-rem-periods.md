# Review — timeline cycle markers count more than the story's REM periods (same-night disagreement)

**Date:** 2026-07-12 · **Reviewer:** Henry (field-test on Alex profile 1, real nights)
**On:** 25aa783 (WS1 inc3a — hero timeline tap-to-select + restorative emphasis + cycle markers)
**Verdict:** ✅ Interaction is well-built (sticky tap-select coexists with transient scrub, restorative
halo, hero-only, reduced-motion honored). ❌ The new **cycle markers double-count vs the story's
REM-period count** — the graph and the narrative disagree about the same night.

## What I saw (real nights)

`SleepTimeline.cycleBoundaries` marks the end of **every** REM run (any length), skipping only the
tail. `SleepStory.rem_periods` (after review fefbd4a's fix) counts REM periods with a **min-run
filter (≥3 min) + a physiological cap (~one per 80 min asleep)**. They diverge:

```
2026-07-11: timeline markers = 6   |  story "REM periods" = 3   ← draws 2× the narrated count
2026-07-09: timeline markers = 5   |  story = 3
2026-07-12: timeline markers = 5   |  story = 4
2026-07-10: timeline markers = 1   |  story = 1   (ok)
```

So the sleep detail screen shows the story ("...cycled through REM 3 times") directly above a
hypnogram with **6** cycle boundaries drawn on it. A user who counts the markers gets a different
number than the sentence — the exact same-night contradiction we've been fixing on every other
surface (debt ledger, week score, the "10h need"). It also re-introduces the very over-count the
story fix removed: fragmented micro-REM being treated as full cycles.

## Fix (one source of truth — this is an altitude fix, not a reimplementation)

The server already computes the correct cycle/REM-period count in `SleepStory`. Rather than
re-deriving (and drifting) in Swift, **expose the cycle-boundary epoch indices from the same
computation** and have the timeline draw those:

- In `SleepStory::forNight`, alongside `rem_periods`, return `cycle_boundaries: [int]` (the epoch
  indices where each counted REM period ends — using the SAME min-run filter + cap). Attach it to
  the `nightstory`/`SleepDetail` payload.
- `SleepTimeline` draws markers from `cycle_boundaries` when present, instead of its own
  `cycleBoundaries` raw scan. Then the count on the graph == the count in the sentence, always.

If you'd rather keep it client-side for now, at minimum port the story's rules into
`cycleBoundaries`: ignore REM runs shorter than ~3 min (6 epochs) and cap the number of boundaries
at ~one per 80 min asleep — same constants as `SleepStory::remPeriods`. But the payload approach is
better: it guarantees they can't drift again.

## Repro
`SleepStory::forNight($n, 8.0)['rem_periods']` vs a raw REM-run-end count of `$n->hypnogram` for
profile 1's 07-09/07-11/07-12 nights → 3-of-4 disagree.
