# RETRAIN the sleep stager on band-realistic HR — the durable fix for staging

**Spec for the dev agent · from Alex + Henry · 2026-07-11**
**Status: BUILD (training-data work — needs no live user data). The durable fix behind the HR-denoise patch.**

## Why (proven this session on Alex's real nights)

The stager (`sleep_stager.joblib`, HistGradientBoosting, `train_real.py`) was trained on PhysioNet Walch
2019 PSG — where HR is clean, averaged Apple-Watch HR. **Its entire REM signal is HR-variability**
(`train_real.py:68-72`: rolling HR std at 5/11/21, gradient, acceleration, detrended surges). But our
Bangle.js band streams **duty-cycled PPG HR that jitters** epoch-to-epoch (measured on Alex's nights:
median |ΔHR| ~5 bpm, p90 ~16, spikes 58→80→86→49→88). The model reads that device jitter as REM →
**48% REM / 5% deep on real nights.**

We patched it downstream (Hampel + window-3 median HR denoise, 894ace7 — got 3/5 nights into healthy
ranges) but a global HR filter can't fix per-night outliers: **07-10 collapses to REM 1% / deep 32%
despite NORMAL jitter (median ΔHR 4.8, ≈ a good night's 4.6)** — the model simply wasn't trained on our
device's input distribution. The real fix is to **train on inputs that look like our band's.**

## The build — `train_real.py`, guarded by leave-subjects-out + real-night regression

### 1 · HR-jitter augmentation (the core change)
Augment each PSG subject's clean HR with **band-realistic noise** before feature extraction, so train and
inference distributions match:
- Add per-epoch jitter matched to our band's measured profile (median |ΔHR| ~5, p90 ~16 bpm) + occasional
  isolated spikes (the missed/doubled-beat readings). Calibrate the noise model from real device HR
  (pull the per-epoch |ΔHR| distribution from `device_ingestions.epoch_hr` — I measured it; reuse that).
- Train on BOTH clean AND jittered copies (augmentation), or a jittered-only set — so the model learns
  REM's *sustained* multi-epoch HR structure and stops firing on single-epoch device spikes.
- Apply the SAME `_denoise_hr` (staging.py) to the jittered training HR that inference applies — so the
  model is trained on exactly what it sees at inference (denoise-then-features), closing the last gap.

### 2 · Sparse / duty-cycle simulation
The band gives SPARSE samples (~15-30% epoch coverage) that get scattered + hold-filled into a STEP grid
(`staging.py` `_reconstruct`/`_fill_holes`). The PSG training data is continuous. **Decimate + reconstruct
the training inputs the same way** (drop to a realistic duty cycle, scatter, hold-fill) so the model isn't
expecting continuous actigraphy — this also addresses the chronic deep under-call (deep needs the
sustained-stillness signal that hold-filling degrades).

### 3 · Fix the REM-inflating prior
`class_weight="balanced"` (`train_real.py:151`) trains as if every stage is 25% likely, stripping REM's
~20% base rate → systematic REM over-prediction (proven: undoing it dropped REM ~15pt). Replace with
realistic stage priors (or `class_weight=None` + a base-rate prior applied to `predict_proba` before the
Viterbi). Keep the shipped `logT` Viterbi (it's correct and already applied at inference).

### 4 · Keep the denoise + Viterbi as complementary
Don't remove the HR-denoise (894ace7) or the Viterbi (439ca2f/8597c08) — a retrained model reduces
RELIANCE on them but they stay as belt-and-suspenders. Re-tune the denoise strength only if the retrained
model wants a lighter touch.

## Validation — the load-bearing part (this is why the last fix slipped)
- **Leave-subjects-out (GroupKFold)** κ/accuracy as today — must not regress the literature ceiling
  (sleep/wake κ≈0.5, 4-class ≈65-70%).
- **REAL-NIGHT REGRESSION (new, required):** commit Alex's 5 real nights as fixtures (the sparse
  accel/hr/sample_epochs I exported — reuse them) and assert the retrained model stages them into
  PLAUSIBLE ranges: REM 12-30%, deep 10-25%, no night at REM<5% or a single stage >70%. **07-10 is the
  acceptance bar** — if the retrain stages 07-10 plausibly, it worked; the downstream flag becomes a
  rare safety net, not the primary defense. This end-to-end test on real device data is what the isolated
  model tests missed.
- Report the before/after stage distribution on all 5 nights in the commit (like I've been doing).

## Data / ops
- Retrain needs the PhysioNet Walch dataset (`train_real.py` reads `/tmp/sleep-accel`, loaded via
  `validate_on_physionet.py`). Add/confirm a download step so the retrain is reproducible in CI.
- Ship the new `sleep_stager.joblib` (baked into the biosignal image at build). Bump a model-version tag
  in the bundle so we can tell which model staged a night.

## Sequence / relationship to the rest
- Independent of the T10 firmware work — do it now, it needs no live data.
- Once T10 dense motion flows (after Alex's reflash), a FOLLOW-UP retrain can add continuous actigraphy as
  a feature dimension (the model currently only has sparse motion) — but don't block this retrain on it.
- Also open (d85f377 follow-on): persist `stages_low_confidence` on SleepLog + have the coach/UI caveat a
  flagged night. That's the safety net; THIS retrain is the real fix.

*We spent the session proving the stager mistakes our band's HR noise for dream sleep. Denoising helped;
the durable answer is a model that has SEEN our band's noise in training and learned to look through it.
Validate it on Alex's real nights, not just held-out PSG — that's the test that catches the gap.*
