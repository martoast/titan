# Review — a 7-minute "night" poisons the Sleep Debt ledger (honesty-flag gap upstream)

**Date:** 2026-07-12
**Reviewer:** Henry (field-test on Alex, profile 1, real data)
**Verdict on 7cc78b5 (Sleep Debt ledger):** ✅ Ledger is correct — accrues, pays down bounded,
decays, clamps, honors `low_confidence`. **The bug is upstream and it surfaced through the ledger.**

## What I saw

Ran `SleepDebt::forProfile` on Alex's real 14 nights. Balance pinned at the **9.5h cap / "heavy,"**
payback "13 nights." The ledger walk showed why — the oldest night dumped **+7.9h** in one step:

```
2026-07-06  bal=7.5  delta=+7.9   ← this night
2026-07-07  bal=8.7  delta=+1.7
... climbs to the 9.5 cap and stays pinned
```

The offending row (`sleep_logs.id=7`):

```
dur_min=7   awake_min=1280   deep=0  rem=0  light=7
coverage=NULL   stage_status=final   is_nap=false   low_confidence=FALSE
bedtime=01:02:28  wake=01:08:58
```

A **6-minute** wrist-on sliver sealed as a **final full night**: 7 min asleep, 1,280 min (21h)
awake, no deep/REM, no coverage. The ledger read it as a genuine near-sleepless night and (correctly,
per its own rules) added ~a full night of debt. Because `low_confidence=false`, the ledger's honesty
skip never fired.

## The actual bug — the honesty gate has a hole for this shape

This is the SAME class as the trust fix (08b09b8 / review 12d2a7e): a low-signal night must be flagged
so downstream metrics can exclude it. This night wasn't flagged, even though it's the most obvious
garbage imaginable. The gate misses it because:

- **`coverage` is NULL**, so a `coverage < 0.5` test may not trigger (NULL isn't < 0.5 in SQL; in PHP
  it coerces to 0 — behavior differs by where the check runs). A NULL/absent coverage is *itself* a
  low-confidence signal and must flag, not pass.
- **The stage-plausibility check didn't catch a 7-min/1280-awake split.** REM is 0% (< 5%) which
  should trip the implausible-split rule — but it clearly didn't for this row, so the gate likely
  only evaluates plausibility when coverage clears a floor, and NULL coverage skips it.
- **Arguably not a "night" at all.** 7 min asleep / 1,280 min awake is a degenerate seal envelope.
  Either it should be `low_confidence=true`, or the seal shouldn't finalize a sub-threshold sliver
  (e.g. < ~1h measured asleep with no coverage) as a `nights()` row.

## Fix (upstream — one fix cascades everywhere)

1. **Flag it.** In the seal's `low_confidence` computation, treat **NULL/absent coverage as
   low-confidence** (don't let NULL slip the `< 0.5` gate), and run stage-plausibility
   **independently of coverage** (REM 0% / a single stage dominating / minutes-asleep ≪ time-in-bed
   should flag regardless of coverage). The ledger, the Sleep Week view, and SleepCoach all already
   honor `low_confidence`, so flagging this row fixes all three at once — no per-surface patch.
2. **Consider a floor on "night" sealing:** a sub-~1h envelope with no coverage probably shouldn't
   finalize as an overnight `nights()` row at all (it's a fragment, not a night).

## Not required, but worth a thought

Even with the fragment excluded, Alex is genuinely carrying real debt — 07-07…07-12 are 6.3/5.9/4.5/
5.3/4.5/5.6h, all well under baseline. So "heavy" is directionally TRUE; the fragment just inflates
the number and stretches the payback to 13 nights. Once id=7 is flagged, re-seal/recompute should
drop the balance to an honest (still elevated) figure. **I'll re-verify on Alex's real ledger after
the flag fix lands.**

## Repro
`SleepDebt::forProfile(Profile::find(1))` → inspect `history`; `sleep_logs` id=7 is the +7.9h step.
