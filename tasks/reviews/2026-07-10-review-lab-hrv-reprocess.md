# SLEEP LAB acceptance — ninth pass: RMSSD fix confirmed, blocked on stale source data

**Reviewer:** Henry (server) · **Against:** 558b470 (deployed + biosignal rebuilt, prod)
**Prior:** 437b406 (dead motion channel) fully fixed — duration Δ141→Δ5, deep now in tolerance.

## TL;DR

Your RMSSD saturation fix (558b470) **works** — but the acceptance is byte-identical
(`REJECT rem 15% vs 27%; light 79% vs 61%`) because the calibration learns from profile 1's
**already-stored** windows, which were computed *before* the fix and still hold the pinned
`rmssd ≈ 248`. The fix is live; the source data the extractor reads is stale. **This needs a
reprocess command + a reprocess of profile 1's 4 nights, then re-calibrate.**

## Evidence

**1. The fix is real — fresh data now gets honest HRV.** The newly-rendered LAB night (profile 4)
computes real per-epoch RMSSD after the fix:

```
fresh LAB night epoch_rmssd: n=159  p50=109.7  min=72.7  max=142.5   (was pinned flat 250)
```

**2. The calibration is still flat — it reads stale stored windows.** Extracted per-stage config
from profile 1 is unchanged, every stage pinned near the old ceiling:

```
deep  rmssd=248.9      light rmssd=248.7
rem   rmssd=248.0      wake  rmssd=247.6
```

**3. Why that keeps REM collapsing into light.** With RMSSD flat, REM and light are statistically
identical on every axis the stager uses:

```
             hr_mean   hr_sd    motion   rmssd
  rem        59.1      10.35    3.13     248.0
  light      60.4      11.12    3.08     248.7
```

The calibration targets identical HRV for both stages → the render makes them look the same →
the live stager can't separate them → REM epochs seal as light (rem 15 vs 27, light 79 vs 61).
Motion now separates deep (that's why deep passed); nothing separates REM from light, because the
one channel that should — HRV — is flat in the *source* the calibration was built from.

## What to build

1. **A reprocess/replay command.** There is none today. It should re-run `ProcessWindowJob` over a
   given profile's stored `ppg_raw` windows against the live biosignal and overwrite `result_refs`.
   The raw PPG is retained on the raw disk — verified: `object_key = raw/1/2026-07-10/….ppg.gz` —
   so the corrected RMSSD can be recomputed from the original waveform.

2. **Then reprocess profile 1's 4 nights (07-07…07-10) and re-`--calibrate`.** Once the extractor
   reads real per-stage RMSSD, REM should get its distinct HRV signature and the render will target
   distinct rmssd per stage. Expectation: rem/light move toward tolerance and acceptance goes green
   (or reveals the next real miss).

## Caveats / heads-up

- **Reprocessing re-seals real user nights.** It recomputes epoch features on Alex's actual sleep
  history — his stored stages/quality/recovery will shift. It should *improve* them (his current
  HRV/recovery is computed off the saturated channel, so those numbers are currently wrong), but
  it's a mutation on real data. Alex has been asked whether to reprocess his history; **gate the
  profile-1 reprocess on his go-ahead.** The command itself is safe to build now.
- **Keep the reprocess idempotent + backward-safe** — same seal invariants as always (SEAL_ARCHITECTURE.md);
  don't let a reprocess of overlapping windows double-count or shift the night's span.
- Sanity target after fix: rendered/real RMSSD should sit in the ~40–150ms range with clear
  per-stage separation (REM typically lower HRV than deep), not clustered at one value.

— Henry
