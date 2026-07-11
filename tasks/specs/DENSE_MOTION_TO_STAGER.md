# Feed the dense T10 motion to the STAGER (not just the timeline)

**Spec for the dev agent · from Alex + Henry · 2026-07-11**
**Status: BUILD. Now UNBLOCKED — dense motion is finally flowing (proven on Alex's nap: 137 samples / 80% coverage).**

## Why now
The T10 dense-motion channel works end to end for the first time (Alex's reflashed watch → `motion_samples`,
80% epoch coverage vs the old ~15-30% sparse burst). But the **stager doesn't use it.** `SealNightJob::
stageSparse` (`app/Jobs/SealNightJob.php:863-905`) builds the `accel_counts` it sends to the biosignal
stager from the per-window `epoch_motion` (the SPARSE burst proxy). The dense `motion_samples` currently
feed ONLY the timeline `motion_series` (viz), via `buildContinuousMotionSeries` (`:955`).

The staging root-cause work this session showed the trained model is starved of continuous actigraphy
(deep needs the sustained-stillness signal the sparse hold-filled motion degrades). **Dense motion is
exactly what it wants.** Feeding it to the stager is the natural next lever, and it stacks with the retrain.

## The build
- In `stageSparse`, when a dense continuous-motion series exists for the night (the same
  `buildContinuousMotionSeries` already computed for the viz — reuse it, don't recompute), **prefer it as
  the `accel_counts` / `sample_epochs` fed to `biosignal->processSleep`**, falling back to the sparse
  per-window proxy when dense is absent (older nights, fully-connected nights). Mirror the "prefer denser
  channel" logic ccc91a5 already uses for the viz (`count(continuous) > count(proxy)`).
- **Unit caveat (critical):** the proxy motion and the T10 continuous motion are in DIFFERENT units
  (proxy = np.std(accel) / (1-ppg_quality); T10 = milli-g EMA, ~14-199 on Alex's nap vs proxy ~1-30). The
  stager's motion features are used RELATIVELY (percentile/rolling), but confirm the model isn't tripped by
  the absolute scale — normalize the dense series into the same range the stager expects, or verify the
  feature extraction is scale-invariant. Do NOT just swap raw values without checking the scale.
- Keep `sample_epochs` alignment on the one t0 grid (the dense series is already aligned there).

## Validation (real-night, like the staging fixes)
- Re-seal Alex's nap #55 and recent nights WITH dense motion fed to the stager; report before/after stage
  distributions. Expectation: sharper deep/light separation (dense stillness → more confident deep), and
  it should help the 07-10-class outliers that starved on sparse motion.
- Add the dense-motion path to the sleep seal test with a dense fixture.
- This ALSO gives the retrain (RETRAIN_SLEEP_STAGER.md) a continuous-actigraphy feature dimension — note the
  interplay: once the stager consumes dense motion, the retrain should train on dense-motion inputs too.

## Caveat
Dense motion only exists for nights the band captured T10 (reflashed firmware + an on-watch Sleep session).
Older/sparse nights keep the proxy path — never regress them. The prefer-denser guard handles this.

*We proved the channel works. Now point it where it matters: the stager, not just the picture.*
