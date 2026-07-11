# FOLLOW-UP: the HR-denoise staging fix (8597c08) does NOT reproduce its proven result in production

**Reviewer:** Henry · **Against:** 8597c08 deployed (biosignal rebuilt 16:05Z, `_denoise_hr` confirmed live)
**Field test: re-sealed profile 1's nights through the deployed fix. It barely moved — some nights WORSE.**

## Evidence (real re-seal, not isolated)

I confirmed `_denoise_hr(hr_e, k=5)` is in the running container's model path (`staging.py:292`,
unconditional), then re-sealed profile 1's 5 nights through it. Before → after:

```
night     REM  before→after      deep before→after
07-11     49% → 46%              5%  → 5%
07-10     29% → 26%              14% → 13%
07-09     40% → 44%   (WORSE)    17% → 11%   (WORSE)
07-08     33% → 38%   (WORSE)    15% → 14%
```

The commit's own claim (from my night-#54 diagnosis) was **48% → 20% REM, deep → ~17%**. Production
delivers ~46% and drifts both directions. **The isolated test and the real seal path diverge.**

## Most likely cause — the denoise is applied too late, to the wrong signal

`_denoise_hr` is a rolling **median** applied to `hr_e` — but `hr_e` is the ALREADY reconstructed +
hole-filled epoch grid (`staging.py` ~203-207: sparse duty-cycle HR scattered onto the grid, short gaps
HELD, long gaps interp). That grid is a **step function**: each real sample is held across ~5 epochs,
then jumps to the next. A k=5 rolling median over a held-step signal **cannot remove a sustained step** —
it just shifts the edge by ~2 epochs. The model's rolling-STD features (windows 5/11/21) still see every
step edge → REM still fires.

My original diagnosis median-filtered the **raw per-epoch HR** (the 93 real readings) BEFORE
reconstruction — removing the spike readings themselves — which is a different, stronger operation than
filtering the reconstructed step-grid. That's the gap.

## Fix asks
1. **Denoise EARLIER — filter the raw sparse HR before `_reconstruct`/`_fill_holes`**, not the
   post-reconstruction `hr_e`. Remove spike *readings* at the source, then reconstruct from the cleaned
   samples. (Or: reconstruct HR by smooth interpolation between real samples instead of hold-fill, so the
   grid isn't a step function — then the rolling-STD features stop firing on hold edges.)
2. **Consider a stronger/among-samples filter** — a Hampel on the real readings (reject a sample >N MADs
   from its neighbors) targets exactly the 58→80→86→49→88 spikes; a plain k=5 median on a step-grid
   doesn't.
3. **Add a REAL end-to-end regression test, not an isolated model call.** Take a stored sparse-HR night
   fixture, run it through the SAME path the seal uses (`stageSparse` → biosignal `stage_night`), and
   assert REM < ~30% / deep > ~10%. The isolated test passed while production failed — that's exactly the
   kind of gap an end-to-end test catches. This is the load-bearing ask: prove it on the seal path.
4. Keep the Viterbi/`logT` change (it's correct and independent), but note it didn't rescue this on its
   own — the input HR is still too jittery going in.

## Caveat worth stating
Alex reports last night (07-11) was genuinely poor, short (4.4h), and late (1:29am) — so SOME low-deep /
elevated-REM is real physiology (short late sleep is REM-enriched). The bug is the *magnitude and
inconsistency* (46-49% REM, 69-min REM blocks, night-to-night swings), not that every number is wrong.
The fix should make the split trustworthy; right now it isn't. Re-verify by re-sealing these same 5
nights and confirming REM lands ~20-30% and deep ~10-18% before calling it done.

— Henry
