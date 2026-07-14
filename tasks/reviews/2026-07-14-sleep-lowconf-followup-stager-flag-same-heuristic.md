# Review (follow-up) — 07-14 STILL flagged: stages_low_confidence is the SAME heuristic, gate it too

**Date:** 2026-07-14 · **Reviewer:** Henry (re-sealed 07-14 after a4df9f5 — still low_confidence)
**On:** a4df9f5 (which correctly implemented my earlier fix) — but the fix was incomplete. **My earlier
review was wrong on one point; this corrects it.**

## What I got wrong
My review said "keep trusting the stager's `stages_low_confidence` unconditionally — it's a model
assessment." It is NOT. `biosignal/app/core/staging.py:471`:
```python
stages_low_confidence = bool(rem_frac < 0.05 or dominant_frac > 0.70)
```
That is the **identical crude distribution heuristic** as the PHP `stageSplitImplausible` — not a
predict_proba/model-uncertainty signal. So gating only the PHP copy (a4df9f5) doesn't help: 07-14
(cov 0.991, signalStrong=true → stageSplitImplausible skipped) is **still low_confidence=true** because
`isLowConfidence` trusts `stages_low_confidence` unconditionally, and the stager set it (light 76% > 70%).

## Fix (PHP — no biosignal rebuild needed)
Fold `stages_low_confidence` into the SAME signal-gated branch as `stageSplitImplausible` (they're the
same heuristic, so they get the same treatment). Only `degenerateNight` (real sliver) stays
unconditional. In `isLowConfidence`:
```php
$stageDoubt = (bool) ($metrics['stages_low_confidence'] ?? false) || $this->stageSplitImplausible($metrics);
return $this->degenerateNight($metrics) || (! $signalStrong && $stageDoubt);
```
(Optionally ALSO gate it at the source — staging.py:471 has `coverage` in scope, so
`... and coverage < 0.85` — but that needs a biosignal image rebuild; the PHP fix is sufficient and
central.)

## Verify (Henry, re-seal after)
- 07-14 (cov 0.991, validFraction high → signalStrong) → **low_confidence FALSE**, story drops the
  "signal was thin" lead.
- 07-13 (cov 0.839 < 0.85, real HR gap) → **stays TRUE** (signalStrong false → stageDoubt applies; also
  its validFraction is low).

## Repro
Re-sealed 07-14 after a4df9f5 → low_confidence still true; signalStrong=true means it's not the PHP
heuristic → it's `stages_low_confidence` (staging.py:471), trusted unconditionally.
