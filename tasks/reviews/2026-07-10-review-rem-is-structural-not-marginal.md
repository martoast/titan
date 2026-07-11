# Verdict: the REM miss is ARCHITECTURAL, not a signal bug — stop tuning marginals

**Reviewer:** Henry (server) · **Against:** 600d61d (both NaN guards) — full clean run complete.

## The chain is done. Here's what all six nights, reprocessed and resealed, tell us.

Everything upstream is fixed and confirmed:
- **Both int(NaN) crashes gone.** All 6 nights reprocess + reseal cleanly (07-06/07 included).
- **RMSSD saturation fixed.** epoch_rmssd real/varied on every night (was pinned 250). Your recovery data
  is corrected. **Keep this** — it was a real production bug — but it is NOT the REM lever (below).
- **Acceptance: duration Δ4, deep in tolerance.** Only REM/light still rejects: `rem 8% vs 27%`.

## The RMSSD fix did NOT fix REM — because REM was never an HRV problem

Clean 4-night calibration, REM vs light on every axis the renderer models:

```
axis      rem     light    Δ
hr_mean   59.1    60.4     1.3
hr_sd     10.35   11.12    0.77
motion    3.13    3.08     0.05
rmssd     121.9   120.9    1.0     ← real, varied, and STILL identical to light
```

REM and light are marginal twins. Deep separates (low motion, low rmssd); REM does not.

## Why — and this is the load-bearing finding

REM vs light **HR is inconsistent night to night** (per-night REM-minus-light HR):

```
07-07  +7.9   (REM hotter)
07-08  +7.4   (REM hotter)
07-09  −4.6   (REM COOLER)
07-10  +0.5   (flat)
```

There is **no stable per-epoch marginal** — HR, HRV, or motion — that separates REM from light. On some
nights REM runs hot, on others cool. So pooling epoch values across nights cancels to ~zero separation,
and even *within* a night the marginal is unreliable.

**REM is defined by temporal structure, not epoch marginals:** it occurs in bouts, in later sleep cycles,
following light, with a characteristic across-epoch HR pattern. The real trained stager identifies REM
from that sequence context — which is why your REAL nights DO get REM (07-08 sealed 113 min of it). The
stager works. What fails is the LAB *render*: NightScript → per-stage marginal draw → the rendered REM
epochs carry light-like marginals, so the stager — correctly — calls them light. **A marginal-statistics
renderer cannot synthesize a night the stager will label REM, because REM's signal is in the structure
the marginals discard.** No amount of per-stage tuning fixes this; it's the model shape.

## So this is a LAB-fidelity ceiling, not a product bug

Important reframe: **your real REM detection is fine.** The stager separates REM on real data via temporal
context. Only the LAB's synthetic night can't fool it on REM/light. Don't read the `rem 8%` as "the app
can't stage REM" — it stages your REM correctly.

## Options (agent + Alex to choose)

1. **Render from real temporal structure** — instead of drawing each REM epoch from a marginal, reproduce
   REM as *bouts* with the across-epoch HR trajectory (e.g. replay a real REM bout's HR shape, or model
   REM as a within-bout HR walk). This is the real fix but the most work — VirtualBand becomes
   sequence-aware, not marginal-aware.
2. **Score the acceptance on what the renderer CAN control** — deep %, efficiency, duration, and a combined
   "light+REM = non-deep sleep" bucket (which passes: 8+86 = 94% vs 61+27 = 88%, Δ6, in tol). Document
   REM/light split as a known marginal-renderer limitation. Ship the LAB as validated on everything else.
3. **Widen the REM/light tolerance** to acknowledge the marginal ceiling (least principled).

My recommendation: **(2) now, (1) later.** The LAB has already earned its keep — it surfaced and drove
fixes for the dead motion channel AND the RMSSD saturation, both real production bugs. Gate it green on
the dimensions a marginal renderer faithfully controls, log REM/light as the documented ceiling, and put
sequence-aware REM rendering (option 1) on the roadmap rather than blocking the LAB on it.

— Henry
