# 09 — Validation Results (what we've actually proven, on real data)

> **Why this doc exists.** Every metric Titan ships was validated against a gold-standard reference on
> **real public data**, **leave-subjects-out** (or leave-workout-out), with **honest error reported** —
> including the things that *didn't* work. This is the single source of truth for "how good is it, really,
> and how do we know." If a number isn't here with a dataset behind it, we don't claim it.
>
> Synthetic data lies. Every row below is real human physiology vs a reference instrument.

Each pillar has a reproducible script under `biosignal/scripts/validate_*.py` and (where it ships a model)
a `biosignal/app/models/*.joblib`. Re-run any of them to reproduce the number.

---

## The scoreboard

| Pillar | What we measure | Dataset (reference) | Protocol | **Honest result** | Grade |
|---|---|---|---|---|---|
| **Resting HRV** | overnight RMSSD | PPG-DaLiA — wrist PPG vs chest **ECG**, 15 subj | leave-subj, 25 Hz, 559 low-motion windows | raw **195 ms** MAE → gated **55 ms** MAE | 🟡 trend-grade |
| **Respiratory rate** | breaths/min (sleep) | BIDMC — fingertip PPG vs **manual breath annotations**, 24 rec | 25 Hz, Smart-Fusion windows | **MAE 2.89 br/min**, median 0.67, ±2=67% | 🟡 trend-grade |
| **Sleep staging** | sleep/wake + 4-class | PhysioNet Walch — wrist accel+HR vs **PSG**, 28 subj | leave-subj, 30-s epochs | sleep/wake **κ 0.496**, 4-class κ 0.323, 59.4% acc | 🟡 at lit. benchmark |
| **Activity / workout type** | rest/walk/run/cycle/stairs | PAMAP2 — hand accel, 9 subj | leave-subj, 25 Hz, accel-only | grouped **87.3% κ 0.83**; 12-class 76.9% κ 0.74 | ✅ strong |
| **Gym — exercise ID** | 10 lifts/bodyweight | MM-Fit — smartwatch wrist accel | leave-**workout**, 25 Hz | **95.4% κ 0.95**; lift-vs-cardio 97.7% κ 0.94 | ✅ strong |
| **Gym — rep counting** | reps per set | MM-Fit (per-set rep labels) | leave-workout, 25 Hz | **MAE 0.14 reps**, 99% within ±1 | ✅ strong |
| **VO₂max / fitness** | cardiorespiratory fitness | PhysioNet treadmill — measured **VO₂**, 981 tests / 846 ppl | leave-subj | **MAE 5.4–6.0 ml/kg/min**, r 0.52–0.59 | 🟡 cross-sectional |
| **In-motion HR** | bpm during exercise | PhysioNet wrist-PPG — vs chest **ECG**, 8 subj | walk/run/bike, 8 s windows | naive peaks **18 bpm MAE** → ❌ not good enough | ❌ deferred to on-device |

Grade key: ✅ ship with confidence · 🟡 ship as a **trend**, not an absolute, with honest caveats · ❌ not
trustworthy yet — do not build on it.

---

## Pillar detail

### 1. Resting HRV (overnight RMSSD) — `validate_hrv_quality.py`
**The north-star recovery metric.** Validated 2026-06-15 on PPG-DaLiA (15 subjects, wrist Empatica-E4 BVP
recorded alongside a chest ECG with hand-corrected R-peaks), decimated to the Bangle's **25 Hz**.

- Raw / ungated RMSSD: **195 ms MAE** vs ECG — wrist PPG is junk without quality control.
- Our existing pipeline gate (template-SQI + physiologic IBI reject + **Kubios fixpeaks** + artifact gate)
  retains ~18 % of windows at **55 ms MAE**.
- **What this proves:** the research's "biggest quality win" (SQI gating before HRV, §8) is *in substance
  already in the pipeline, and it works.* A further skewness+perfusion+template SQI gate did **not** beat
  the baseline (279 ms) and naive parabolic peak refinement without artifact correction was worse (108 ms)
  — Kubios is doing the real timing repair. So we **did not ship** the add-on (see *Negative results*).
- **Honest ceiling:** 55 ms ≈ the magnitude of resting RMSSD itself → **trend-grade**: trust the
  whole-night number, flag pNN50 as low-confidence at 25 Hz. Real-world should be *better* than this lab
  number because our Bangle streams beat-to-beat at rest (this E4 reference is motion-heavy daytime).

### 1b. Respiratory rate — `validate_respiration.py` (core: `respiration.py`)
Overnight breaths/min from the **PPG-only** T2 waveform (no accel in that stream). Breathing modulates
the pulse wave three ways — baseline (RIIV), amplitude (RIAV), and heart-rate sinus arrhythmia (RIFV) —
so we read each one's breathing-band spectral peak and apply **Smart Fusion** (Karlen 2013): report the
mean only when the three agree (≤ 2 br/min spread), else discard the window. Validated on **BIDMC** (24
recordings, fingertip PPG vs two experts' manual breath annotations) at 25 Hz: **MAE 2.89 br/min, median
|error| 0.67, within ±2 = 67 %, coverage 38 %**.
- **Honest limitation:** a systematic **~2 br/min under-estimate** that persists at every gate threshold
  (methodological, not the disagreement tail). BIDMC is tachypneic ICU patients (RR up to 23); our target
  regime is resting/sleep RR (~12–18 br/min), lower and cleaner, where the sub-1-br/min median holds. We
  surface it as a **trend** (like HRV), not an absolute, and report the bias rather than fabricate a
  correction (which would overfit BIDMC). Competitive with the literature (~3 br/min) — at decimated 25 Hz.
- **Claim rail:** resting/sleep RR trend ✅ (what Oura/Whoop report); **not** apnea/respiratory-event
  detection (❌, stays behind the firewall).

### 2. Sleep staging — `validate_on_physionet.py` (model: `sleep_stager.joblib`)
PhysioNet Walch "Motion + heart rate" (28 subjects with usable **PSG** labels), the exact signals our
pipeline produces. Leave-subjects-out, 30-s epochs: **sleep/wake κ 0.496, 4-class κ 0.323, 59.4 % acc** —
at the published wrist-actigraphy+HR benchmark. The score **converged** (25-subj run κ 0.51 ± 0.02 →
28-subj identical), confirming the ceiling is the **signal** (averaged HR, no EEG), not the model or data.
Deep sleep is the bottleneck (65 % of true deep → predicted light) because this dataset lacks beat-to-beat
HR — **the exact signal our Bangle's per-epoch RMSSD provides**, so real-world deep/REM should beat this.

### 3. Activity / workout type — `validate_*` (model: `activity_classifier.joblib`)
PAMAP2 (9 subjects), using **only the hand accelerometer** — what a wrist sees. Leave-subjects-out at
25 Hz: workout-grouped **87.3 % κ 0.83** (88.8 % with HR), 12-class 76.9 % κ 0.74. HR adds only ~1.5 %, so
we **ship accel-only** — robust against the noisy in-motion HR measured in Pillar 7. At 100 Hz it's ~90 %
κ 0.86, so it holds at our rate (~2 pt drop). Main confusion: walk ↔ stairs (similar wrist swing).

### 4–5. Gym — exercise recognition + rep counting — `validate_gym.py` (model: `gym_classifier.joblib`)
The user's primary training. MM-Fit (real smartwatch **wrist** accel, 10 exercises × ~3 sets × ~10 reps,
per-set rep labels), leave-**workout**-out at 25 Hz: 10-class **95.4 % κ 0.95**, lift-vs-cardio 97.7 %
κ 0.94, rep counting **MAE 0.14 reps / 99 % within ±1**. Two findings we keep:
- Rep counting **must** use a *signed* principal/per-axis projection, not `|accel|` — magnitude peaks on
  both the up and down of each rep and double-counts. The counter self-selects the most periodic axis.
- `bicep_curls` read exactly *true/2*: MM-Fit does curls **alternating arms**, so a single-wrist watch
  correctly counts *its* arm. Not a bug — reported as such, excluded from the honest aggregate.

### 6. VO₂max / cardiorespiratory fitness — `validate_vo2max.py` (model: `vo2max_run.joblib`)
PhysioNet treadmill maximal tests (981 tests / 846 people, breath-by-breath **measured VO₂**, ground truth
47.3 ± 8.9 ml/kg/min). Leave-subjects-out:

| Method | MAE (ml/kg/min) | r |
|---|---|---|
| Demographic-only (age/sex/BMI) — the no-exercise floor | 6.0 | 0.52 |
| + HR-at-standard-pace (ascending phase only) | 5.7 | 0.54 |
| + HRR + HRmax (full wrist-from-a-run) | **5.4** | 0.59 |
| Run-calibrated learned (profile + grade-adjusted-pace HR + HRR) | 5.6 | 0.59 |

We **ship a transparent demographic equation** (Ridge matches the GBM, MAE 5.6) **blended with
Uth-Sørensen** (15.3·HRmax/HRrest) when resting HR exists — because the run-calibrated model, unlike
demographics, *responds to training* (lower HR at the same GPS pace = fitter), so it tracks your trend even
though cross-sectional accuracy is the same. Returns an honest ± band, never false precision.

### 7. In-motion HR — `validate_inmotion_hr.py` (**the honest "no" that shaped the architecture**)
PhysioNet "Wrist PPG During Exercise" (8 subjects, wrist PPG + accel vs chest **ECG**). Naive PPG peak
detection (our HRV pipeline's approach): walk 13.5 / run 24.1 / bike 17.9 → **18 bpm MAE** — too inaccurate
for trustworthy workout HR / TRIMP / HR-zones / VO₂. A quick accel-aware spectral+tracking fix was *worse*
(~37, locks onto motion harmonics). **Design implication: split the HR path** — raw PPG→IBI for *resting*
HRV (validated), and the watch's **on-device accel-corrected bpm** (VC31 `e.bpm`, what Garmin/Apple use)
for *workout* HR, to be validated on hardware. Workout-HR rigor is **gated on hardware**, not claimed now.

---

## Negative results we keep (so we never re-litigate them)

Honest science means recording what failed. Each of these was tried on real data and rejected:

| Tried | Why it failed | What we do instead |
|---|---|---|
| Extra skewness/perfusion/template **SQI gate** on top of the pipeline | Didn't beat the existing gate (279 ms vs 55 ms) on PPG-DaLiA | Existing template+Kubios+artifact gate (validated 55 ms) |
| **Parabolic peak refinement** (naive, no artifact correction) | Worse (108 ms) — Kubios does the real timing repair at 25 Hz | Keep 25→250 Hz upsample + Kubios fixpeaks |
| **ACSM submaximal HR→pace extrapolation** for VO₂max | Predicts metabolic *demand*, overshoots actual VO₂ past the aerobic ceiling (MAE 10.5–27, r 0.12–0.26) | Run-calibrated learned model on the ascending phase |
| **HRR-60s** as a single-shot fitness number | Only r 0.17 vs VO₂max (cooldown not standardised) | Report as a personal *recovery trend*, not a fitness value |
| **Accel-spectral in-motion HR** | Locks onto motion harmonics (~37 bpm MAE) | Defer to the sensor's on-device accel-corrected bpm |
| `|accel|` for **rep counting** | Magnitude peaks on both up+down → double-counts | Signed per-axis projection, self-selecting the rep axis |
| **HR feature** in activity/sleep models | Adds only ~1.5 % (activity); barely registers (sleep, no beat-to-beat HR) | Ship accel-only models — robust to noisy in-motion HR |

---

## Methodology (the rigor rules)

1. **Real data only.** Public datasets with a gold-standard reference instrument (ECG, PSG, breath-by-breath
   VO₂, lab rep/exercise labels). No synthetic signals in any reported number.
2. **Leave-subjects-out** (or leave-workout-out) — the score reflects a *new* person/session the model never
   saw, via `GroupKFold`. No subject leakage between train and test.
3. **At our hardware rate.** Everything is decimated to the Bangle's **25 Hz** so the numbers reflect the
   device we actually ship, not the dataset's native (often 64–256 Hz) rate.
4. **Honest metrics.** Cohen's κ + confusion matrix for classification; MAE + correlation (+ Bland–Altman on
   hardware day) for regression. Report the failure modes, not just the headline.
5. **Transparent over black-box** when accuracy ties (we ship the VO₂max *equation*, not the GBM).

---

## The honesty firewall (what we will NOT validate or ship)

Per the two rails in [`README.md`](README.md), these stay **❌ out of scope** regardless of technical
feasibility — they cross from wellness into regulated medical device territory (FDA/MDR):

**ECG · AFib / arrhythmia screening · sleep-apnea detection · cuffless blood pressure · SpO₂ · any
diagnose/monitor/treat claim.** The hardware is *timing-rich, morphology-poor, single-wavelength* → rhythm
metrics ✅, morphology trend-only 🟡, and the medical-grade claims ❌. We use **wellness vocabulary only**
(recovery / sleep / fitness / strain). This is what keeps an open-source platform asking people to trust it
with their health *honest* — which is the whole game.

---

*See [`08-sensor-research.md`](08-sensor-research.md) for the forward-looking backlog (what to build next)
and [`03-algorithms.md`](03-algorithms.md) for the algorithm designs behind these numbers.*
