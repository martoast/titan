# 03 — Algorithms & Validation Plan

*Device streams raw PPG + tri-axial accel → server-side Python service → Titan. Goal: reproduce Whoop/Oura/
Polar core metrics with open, validated algorithms. **North-star: overnight/resting HRV-based recovery** — the
regime where PPG is most accurate because motion is minimal.*

**Core stack:** **NeuroKit2** (MIT) for PPG→RR→HRV · **HeartPy** (MIT) lightweight alt · **pyHRV** (BSD-3) for
Lomb-Scargle on uneven RR · **[ojwalch/sleep_classifiers](https://github.com/ojwalch/sleep_classifiers)** (MIT)
+ its PhysioNet dataset for sleep · simple TRIMP/VO2max formulas. All permissive except **pyActigraphy
(GPL-3.0 — isolate it)**.

## 1. PPG → inter-beat (RR/IBI) intervals
- **NeuroKit2** `ppg_process()` → band-pass 0.5-8 Hz → systolic peak detection (Elgendi 2013) → `signal_fixpeaks
  (method="Kubios")` → `hrv()`. (Makowski 2021, *Behavior Research Methods* 53:1689.)
- **HeartPy** adaptive-threshold alt (~98.5% peak acceptance on PPG).
- **Signal-quality gate before HRV:** template-matching SQI (Orphanidou 2015; NeuroKit2 `ppg_quality()` default,
  accept ≥0.86; PPG sens 91%/spec 95%); skewness SQI (Elgendi 2016). Package: **vital_sqi** (MIT, 74 SQIs).
- **Motion-artifact handling** (accel as noise reference; only matters in-motion):
  | Method | HR MAE (running) |
  |---|---|
  | TROIKA (Zhang 2015) | ~2.0-2.4 BPM |
  | JOSS (Zhang 2015) | 1.28 BPM |
  | WFPV (Temko 2017, [andtem2000/PPG](https://github.com/andtem2000/PPG)) | 1.97 (1.37 +Viterbi) |
  | Deep PPG CNN (Reiss 2019) | 7.65 BPM (real-life PPG-DaLiA) |
  Classic LMS/NLMS/RLS: ~1-3 BPM at rest, **11-20 BPM** in varied real-life motion (why CNNs took over).
- **At rest/overnight (our case): trivial** — simple peak detection ≤2-5 BPM. Overnight RMSSD from PPG vs ECG:
  **r²≈0.98, bias ≈ −1.2 ms** (Kinnunen 2020, Oura). 5-min windows weaker (r²≈0.77) → **aggregate ≥30 min /
  whole night.** **In motion: accuracy collapses; defer (irrelevant to recovery).**
- **Artifact correction is critical:** Lipponen & Tarvainen 2019 (Kubios; NeuroKit2 `signal_fixpeaks`). **Just
  0.1% undetected beat errors significantly distort RMSSD/HF.** Require **≥97% beat accuracy** before trusting
  HRV; but don't over-correct (interpolating >15% biases SDNN).

## 2. HRV metrics
- Time: **RMSSD** (primary vagal), SDNN, SDSD, pNN50/20. Freq: LF/HF/total (Welch or Lomb-Scargle for uneven RR).
  Nonlinear: Poincaré SD1/SD2, SampEn, DFA α1. (NeuroKit2 `hrv_*` or pyHRV.)
- **Standard:** 1996 Task Force, *Circulation* 93:1043. Windows: 5-min short-term, 24h/whole-night long-term.
- **Best recovery correlate = overnight RMSSD; report ln(RMSSD), 7-day rolling mean** (Plews 2013; Buchheit
  2014 — single daily values too noisy). **Avoid LF/HF for "stress/recovery"** (Billman 2013 — doesn't index
  sympathovagal balance). RMSSD is least respiration-confounded → ideal for uncontrolled overnight.
- Nocturnal because sleep = long, still, supine windows (night-to-night reliability r≈0.92). Caveat: RMSSD is
  sleep-stage-dependent (lower in slow-wave) → average whole-night or be stage-aware.

## 3. Sleep staging from wrist HR + accel (no EEG)
- **Build on Walch 2019** (*SLEEP* 42:zsz180; `ojwalch/sleep_classifiers`, MIT) + PhysioNet sleep-accel dataset
  (31 subj, ODC-By). Features: motion + local HR SD + circadian "clock proxy."
  | Task | Accuracy | κ |
  |---|---|---|
  | Wake/Sleep | 80.1% | 0.32 (0.53 on MESA) |
  | Wake/NREM/REM | 72.3% | 0.28 (0.40 on MESA) |
- Classic actigraphy baselines: Cole-Kripke (1992), Sadeh (1994) via **pyActigraphy** (GPL-3.0). High sleep
  sensitivity, **poor wake specificity** (over-call sleep).
- EEG reference tools (validation only, NOT the wrist device): **YASA** (BSD-3; 86.6%, κ 0.80 — near human),
  **U-Sleep** (MIT; F1 ~0.77-0.81) — use to auto-score any PSG you collect.
- **Honest expectations vs PSG:** sleep/wake ~80-90%; **wake specificity ~50-70% (the hard part)**; 4-stage
  ~60-79%, κ ~0.4-0.6; deep(N3) weakest without EEG. HR/HRV enables REM/NREM split (NREM = vagal/high HRV;
  REM = sympathetic/irregular HR) → invest in clean IBI.

## 4. Resting HR + respiratory rate
- **RHR** (most trustworthy wearable metric): min of windowed (5-min) medians over sleep, or overnight average.
  `HR = 60000/IBI(ms)`. Accuracy: bias −0.63 bpm (Oura), Whoop −0.3 bpm ICC 0.99.
- **Respiratory rate** from RSA/PPG modulations (RIIV/RIAV/RIFV), 0.1-0.4 Hz band. **Karlen Smart Fusion** (RMSE
  ~1.8 brpm on CapnoBase); NeuroKit2 `rsp_rate()`/`ppg_rate()`. **Sleep-only** (motion destroys it).

## 5. Recovery / readiness score (heuristic)
All commercial scores converge on **overnight RMSSD vs personal baseline (dominant) + RHR (inverted) + sleep**;
none publish weights. Whoop = RMSSD during slow-wave sleep + RHR + RR + sleep (patent US 9,750,415); Oura = 9
weighted contributors; Polar Nightly Recharge = ANS Charge (HR>HRV>breathing, first ~4 h vs 28-day baseline).

**Concrete implementable formula** (Plews/Buchheit-grounded):
```python
ln_rmssd_today = ln(rmssd_ms)                         # always log-transform
ln_rmssd_7d    = mean(ln_rmssd[t-6..t])               # 7-day smoothing
base_mean, base_sd = mean/std(ln_rmssd[t-60..t-1])    # 60-day baseline
z_hrv = (ln_rmssd_7d - base_mean) / base_sd
z_rhr = -(rhr_today - base_mean_rhr) / base_sd_rhr    # inverted
z_to_score(z) = 100 / (1 + exp(-1.1*z))               # logistic: z=0 → 50
recovery = 0.50*z_to_score(z_hrv) + 0.25*z_to_score(z_rhr) + 0.25*sleep_score
# Fatigue overlay: if ln_rmssd_7d < base_mean - 0.5*base_sd AND CV(ln_rmssd) rising → recovery *= 0.85
```
Require ≥14 nights before scoring (cold start). **Honesty caveat (product copy):** the *sensors* are validated;
the *composite scores* are largely **unvalidated as performance predictors** (Lundstrom 2024: raw HRV
out-predicted Whoop's score). Treat the score as an informed nudge, not a verdict.

## 6. Strain / cardiovascular load
- **Edwards eTRIMP** (recommended — needs only HRmax + time-in-zone): `Σ(min_in_zone_n × weight_n)`, zones
  50-60..90-100% HRmax, weights 1-5.
- **Banister TRIMP:** `duration × HRr × 0.64 × e^(1.92·HRr)` (M), HRr = (HRex−HRrest)/(HRmax−HRrest).
- **Whoop Strain 0-21** = proprietary logarithmic scale over cumulative time-in-zone load. Approximate:
  `Strain = 21 × ln(1+a·L) / ln(1+a·L_max)`, L = daily TRIMP, L_max = rolling 95th-pct.
- **Fitness/Fatigue/Form:** CTL = 42-day EWMA, ATL = 7-day EWMA, **TSB = CTL − ATL** (Banister impulse-response).

## 7. VO2max (optional, trend-grade)
- Passive: **Uth ratio** `VO2max = 15.3 × HRmax/HRrest` (needs only HRrest + observed HRmax).
- With pace/GPS: Firstbeat-style extrapolation (~5% MAPE). **Biggest error = HRmax** (±15 bpm → ~7-9% error) —
  auto-detect or field-test, don't use 220−age.

## 8. Validation methodology
- **Ground truth (tiered):** Bittium Faros/Movesense (raw ECG) > **Polar H10** (RR field gold standard, 99.6%
  vs Holter, resting HRV agreement r/ICC 0.95) > Oura/Whoop (comparison only, never reference).
- **Public datasets:** Walch sleep-accel (ODC-By) · PPG-DaLiA (CC-BY, PPG+accel+ECG, 8 activities) · WESAD
  (CC-BY, stress) · Sleep-EDF · MESA (NSRR, application) · CapnoBase (RR) · BIDMC · MIMIC-III.
- **Stats:** **Bland-Altman** (bias + 95% LoA; log-transform RMSSD), **repeated-measures BA** for multi-night,
  **Lin's CCC** + ICC(A,1) + MAE/MAPE; for sleep: **Cohen's κ + confusion matrix + per-class sens/spec/F1.**
- **At-home protocol (n=2):** device + Polar H10 every night; NTP sync + 3-wrist-tap accel alignment; identical
  overnight window; per-person repeated-measures BA + CCC + ICC; check **delta agreement** (Spearman of nightly
  deltas — does it flag the same low-recovery mornings?). Pre-register thresholds (RHR bias ±2 bpm, LoA ±5 bpm,
  ICC>0.90; ln-RMSSD CCC>0.80, delta-Spearman>0.7). **n=2 is a pilot, not generalizable** — real validation needs
  ~25-30 subjects across ages/skin tones, ideally a PSG subset.

## 9. Where it will be inaccurate (communicate honestly)
- **Wrist HRV in motion/daytime:** PRV ≠ HRV moving — **only trust HRV still/asleep.**
- **Sleep staging without EEG:** wake spec ~50-70%, 4-stage κ ~0.4-0.6 — present stages with low confidence,
  lead with sleep/wake + duration (solid).
- **Body composition:** not derivable from PPG+accel — don't claim it.
- **VO2max:** trend-grade. **Recovery score:** heuristic, not a validated predictor.
- **Uncertainty UX:** confidence ranges not false-precision; trends vs personal baseline not absolutes; gate
  metrics on SQI (suppress poor signal); **no medical claims** — wellness/training guidance only.

## 10. Phased algorithm roadmap
| Phase | Deliverable | Methods | Diff |
|---|---|---|---|
| **P0** | Overnight RMSSD + RHR, validated vs H10 | NeuroKit2 → SQI gate → Kubios fix → whole-night RMSSD; RHR = min windowed median | **3** |
| **P1** | 0-100 recovery score | §5 formula; rolling baselines; cold-start | **4** |
| **P2** | Sleep/wake (P2a) → 3-4 stage (P2b) | Cole-Kripke/Sadeh → Walch retrained on our sensor | **6 / 8** |
| **P3** | Nocturnal respiratory rate | Karlen / NeuroKit2 | **5** |
| **P4** | Strain (eTRIMP, 0-21), CTL/ATL/TSB | time-in-zone, log-map, Banister | **5** |
| **P5** | In-motion HR (daytime strain) | accel-ref cancellation (WFPV/JOSS) or CNN | **9** |
| **P6** | VO2max | Uth + Firstbeat-style; robust HRmax | **6** |
| **P7** | Tuning / multi-subject validation | §8 methodology; per-user calibration | **7** |

**Sequencing:** P0 north-star first (overnight PPG is the clean regime — ship + validate hard vs H10). Sleep
(P2) unlocks slow-wave RMSSD windowing. In-motion HR (P5) is hardest and deliberately last (irrelevant to
recovery).
