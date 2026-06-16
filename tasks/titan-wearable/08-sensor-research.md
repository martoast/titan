# 08 — Sensor Science & Algorithm Roadmap (Academic Literature Review)

> **What this is.** A deep review of the peer-reviewed literature on *everything else we can honestly
> extract* from the sensors the Bangle.js 2 already has — for **longevity, cardiovascular & metabolic
> health, and workouts** — plus the data-collection and signal-processing upgrades that make those
> metrics trustworthy. Compiled from 7 parallel research sweeps (~150 searches, ~60 primary papers).
> Every recommendation carries an honest feasibility verdict and citations.
>
> **Why it matters.** Obesity and cardiovascular disease are the deadliest *preventable* killers in the
> US and worldwide. CRF (fitness) is a bigger mortality hazard than smoking. Most of these biomarkers
> need no new hardware — just better algorithms on signals we already stream. If we open-source this
> well, the leverage is enormous.

---

## 0. The one framing that governs everything

Our hardware (green-LED PPG @25 Hz, KX022 accel, GPS, BMP280 baro/temp) is **timing-rich, morphology-poor,
single-wavelength, and battery-bound**. That single fact predicts what's feasible:

| Signal class | Examples | Verdict |
|---|---|---|
| **Interval / rhythm** (beat *timing*, movement *cadence/regularity*) | HRV, AFib, sleep RR, rest-activity rhythm, sleep regularity, steps | ✅ **Our strength** |
| **Morphology** (pulse *waveform shape*) | vascular age / arterial stiffness | 🟡 **Trend-only**, beat-ensembled, sleep windows |
| **Multi-wavelength** | SpO₂ | ❌ **Physically impossible** (one green LED) |
| **Calibrated absolute** | cuffless BP (mmHg), body temperature, daily kcal | ❌ **Don't ship a number** |

And the deepest honesty rule, which every research stream independently reached:
**track intra-individual *trends* against each user's own baseline — not absolute values vs population norms.**
RHR "normal" spans 40–110 bpm across people but is tight within a person; the same is true for HRV, VO₂max,
temperature. *Personal deviation* is the honest, actionable, scientifically-defensible UX.

---

## 1. The prioritized build roadmap (read this first)

Ranked by **(evidence strength) × (feasibility on our hardware) × (mission fit: obesity/CVD/longevity)**.
"Already have" items mean we ship the *plumbing*; these are the *algorithm/feature* layer on top.

### Tier 1 — Build now: high evidence, fully feasible, on-mission

| # | Feature | Why (evidence) | Feasibility |
|---|---|---|---|
| 1 | **Steps/day + MVPA volume → personalized activity target** | +1,000 steps/day ≈ −15% mortality; benefit plateau ~7,000–8,700 (Paluch 2022; Saint-Maurice 2020: 8k vs 4k steps HR 0.49). The "10,000" target is marketing. | ✅ |
| 2 | **Sleep Regularity Index (SRI)** | Predicts mortality *more strongly than sleep duration*: 5th-pctile HR 1.53; top quintiles 20–48% lower mortality (Windred 2024, UK Biobank n≈60k). | ✅ (we already stage sleep) |
| 3 | **Circadian rest-activity rhythm (RA, IS, IV, M10/L5)** | Low relative-amplitude → all-cause HR 1.54, CVD 1.73 (Feng 2023, Lancet Healthy Longevity, n=92k). *Nearly free* from data we already stream 24/7. | ✅ |
| 4 | **Metabolic-risk forecast** (composite of RHR + HRV + VO₂max + sleep) | Each input forecasts incident T2D: RHR RR 1.20/+10bpm; VO₂max −28%/SD; HRV ↓; short sleep HR 1.45. Prevention *before* disease. | ✅ (all derivable) |
| 5 | **Post-meal / sitting-break movement nudges** | 2-min walk every 20–30 min cuts postprandial glucose & insulin ~24–30% (Dunstan 2012). The single highest-leverage *acute* anti-obesity lever; timing is everything and we know timing. | ✅ |
| 6 | **AFib / pulse-irregularity screening** (overnight, SQI-gated) | Best clinical evidence of any PPG metric; Fitbit PPV 98%, Apple 84%; same LED/accel/wrist config as every big study. Stroke prevention. | ✅ (frame as screening, "confirm with ECG") |
| 7 | ~~**Respiratory rate during sleep**~~ — ✅ **BUILT & validated** | Smart Fusion (3 PPG modulations) on BIDMC real PPG + manual breath annotations at 25 Hz: **MAE 2.89 br/min, median 0.67, ±2=67%**. Surfaced as an overnight trend (claim rail: not apnea detection). See doc 09 §1b. | ✅ done |
| 8 | **Resting-HR & HRV personal-baseline trends + illness early-warning** | RHR +10bpm → all-cause RR 1.09–1.17 (Zhang/Aune meta-analyses, >1M people); RHR *leads* temperature for infection (Mishra 2020; Radin 2020). | ✅ |
| 9 | **Pipeline: SQI gating + parabolic peak interpolation** (see §8) | The biggest *quality* multiplier — every metric above gets more trustworthy. `vital_sqi` (MIT); skewness/perfusion/DTW gates. | ✅ |

### Tier 2 — Build as explicit trends / refinements (good value, honest caveats)

| # | Feature | Why | Feasibility |
|---|---|---|---|
| 10 | ~~**Sharpen VO₂max**~~ — ✅ **DONE** (Firstbeat %HR-reserve feats) | CRF is the *strongest* mortality hazard — low vs elite HR **5.04** (Mandsager 2018). Sharpened MAE **5.6→5.2**, r **0.59→0.65** via speed-at-fixed-%HRR + monotonic constraints, leave-subjects-out. The hoped-for ~3.5 needs true submax VO₂ (ceiling 4.5); ACSM speed→VO₂ fails on ramp data, so we ship the honest 5.2. See doc 09. | ✅ done |
| 11 | ~~**Grade-adjusted pace + grade-aware energy expenditure**~~ — ✅ **DONE** | Minetti 2002 cost-of-transport engine (grade-aware, walk+run). Validated on real measured VO₂: flat CoT **3.67 vs 3.60** Minetti (2%), EE **10% MAPE**. Replaces the MET proxy for GPS-paced runs/walks; cycling keeps the proxy. See doc 09 §6b. | ✅ done |
| 12 | ~~**Training load: TRIMP + ACWR (injury guardrail)**~~ — ✅ **DONE** | EWMA acute:chronic workload ratio (Williams 2017) over per-session Banister TRIMP. Bands: sweet-spot 0.8–1.3, spike >1.5 → ease off. Surfaced on Fitness + MCP. Framed as progressive-overload GUIDANCE, not injury prediction (ACWR's individual validity is debated — Impellizzeri 2020; we say so). See doc 09. | ✅ done |
| 13 | ~~**Floors / elevation climbed**~~ — ✅ **DONE** | Drift/noise-robust baro floor counter (peak-valley + per-climb rate gate). Validated on physics-realistic synthetic traces: MAE **0.83 floors** (1–20), **0 phantom** on a flat day. Firmware streams continuous ambient altitude (T7); server counts floors. On-HW accuracy is a validation-day item. See doc 09 §7b. | ✅ done |
| 14 | ~~**"Fitness Age" composite**~~ — ✅ **DONE & EXPANDED → Biological Age** | Went bigger: with bloodwork upload we ship the mortality-validated **PhenoAge** clock (golden-value tested) + VO₂max **Fitness Age** + wearable **levers**, combined transparently. Honest scope (composed, not outcome-calibrated). See the dedicated **doc 10**. | 🟡 estimate, never mortality % |
| 15 | ~~**Vascular-age / arterial-stiffness trend**~~ — ❌ **TESTED, doesn't hold → NOT shipped** | The APG aging index should rise with age; on real wrist PPG (PPG-DaLiA, 15 subj) it doesn't — r ≈ **−0.3 @ 25 Hz, −0.04 @ 64 Hz** (weak AND wrong sign). The firewall's "morphology-poor wrist PPG" call was right. Honest negative kept (doc 09); revisit only with multi-wavelength/higher-SNR hardware. | ❌ not on this hardware |
| 16 | ~~**Gait cadence + guided sit-to-stand frailty test**~~ — ✅ **DONE** | Cadence recovers a known rate (<1 spm after parabolic interp) + lands physiological on real PPG-DaLiA walking (median 116 spm). Guided 30-sec chair-stand (Rikli & Jones) reuses the squat-validated rep counter (MAE 0.14); scored vs age/sex norms. Did NOT ship passive wrist gait-speed (per firewall). See doc 09. | ✅ done |
| 17 | ~~**Life-space mobility** (GPS)~~ — ⏸️ **DEFERRED (battery vs value)** | Real signal in the frail/old (2.4× mortality, Mackey) but soft for a fit user, and it needs all-day GPS — the worst power cost for the weakest evidence on the list. Decision: not worth it on this hardware. If ever revisited, do it **phone-side** (coarse ambient location from the companion app — costs the *phone's* battery, never the watch's), never watch GPS. | ⏸️ deferred |

### Tier 3 — Hardware revision (cheap, high-unlock)

| # | Add | Unlocks | Evidence |
|---|---|---|---|
| 18 | **Skin-contact NTC thermistor** (~cents, 0.05 °C) | Illness detection **+5pp AUC** (0.77→0.82, Mason 2022 TemPredict); ovulation/cycle (82% LH agreement, Zhu 2021); partial circadian phase. | strong |
| 19 | **Ambient-light (lux) sensor** | Turns our GPS "time-outdoors" proxy into real light-exposure measurement (mood/sleep/myopia — Burns 2021). | moderate |

### ❌ Do NOT build / do not surface (honesty firewall)
- **SpO₂** — one green LED cannot compute it. Tell users plainly.
- **Cuffless BP in mmHg** — fails ISO 81060-2; apparent accuracy is a calibration artifact (Mukkamala 2022, Aurora n=1,125). Redirect demand into #15 stiffness trend.
- **Body/skin temperature, fever, ovulation from the BMP280** — it's a ±1 °C *board* sensor that self-heats; it measures the PCB, not skin. Log raw as *ambient/context* only, never as body temp.
- **Daily "calories burned" as actionable truth** — ±14–30% per-person error can't see the ~100–350 kcal/day imbalances that drive obesity (Brage 2015; White 2019). Show *trends/ranges*. The IDEA trial (Jakicic 2016, JAMA) found a passive tracker made weight loss **worse by 2.4 kg** — never outsource the user's self-regulation to a calorie number.
- **Glucose proxy** — not feasible from green PPG + accel (van Doorn 2021). Instead *act on* the behaviors that lower glucose (#5).
- **Daytime/in-motion versions** of RR, vascular age, absolute "stress score" — confine to clean rest/sleep windows.
- **Parkinson's screening** — tremor reaches 12 Hz; at 25 Hz we alias it, and false positives cause real harm. Out of scope as a claim.

---

## 2. PPG (Vcare VC31B) — beyond HR & resting HRV

> **Physics:** green light penetrates shallowly; wrist is low-perfusion/high-motion; 25 Hz = 40 ms timing.
> Great for *rhythm*, poor for *morphology*, impossible for *multi-wavelength*.

| Metric | Method | Evidence | Feasibility |
|---|---|---|---|
| **AFib / arrhythmia** | Pulse-interval irregularity (RMSSD, sample entropy, Poincaré SD1/SD2) over 30–60 s still windows; SVM→1D-CNN; accel-gated | Kwon 2019 CNN sens 99.3%/spec 95.9% AUC 0.998; Apple Heart PPV 0.84 (NEJM 2019, n=419k); Fitbit PPV 98.2% (Circulation 2022, n=455k) | ✅ screening |
| **Respiratory rate (sleep)** | RIIV+RIAV+RIFV "Smart Fusion", discard windows where the 3 disagree >4 bpm | Karlen 2013; Dehkordi 2018 RMSE 1.8 bpm (CapnoBase) | ✅ rest/sleep |
| **Vascular age / stiffness** | 2nd-derivative SDPPG a–e waves: b/a, d/a, SI=height/Δt; beat-ensemble + upsample | Takazawa (r≈0.8 vs age); Otsuka 2017 d/a → CV-mortality HR 2.3–2.6 (n=4,373) | 🟡 trend only |
| **Autonomic/"stress" trend** | Short-window HRV (RMSSD/HF drop); no EDA available | HRV tracks arousal but can't isolate psychological stress; no vendor score validated | 🟡 trend only |
| **SpO₂** | ratio-of-ratios red+IR | needs 2 wavelengths; green alone can't | ❌ |
| **Cuffless BP** | PTT/morphology→mmHg | Aurora: no better than calibration baseline; fails ISO | ❌ |

**Build order:** SQI/motion pipeline (§8) → AFib → sleep RR → vascular-age *trend* + autonomic trend.
**AI-PPG "vascular age" clock** (Nie 2025, n=212k, age-gap → mortality HR 1.02/yr) is the strongest single
PPG biomarker but needs clean *waveform morphology* our green wrist PPG can't give — defer to red/IR hardware.

---

## 3. Accelerometer (Kionix KX022) — the longevity workhorse

> Most epidemiology used Axivity/ActiGraph at 50–100 Hz, auto-calibrated, non-dominant wrist. Our KX022 @25 Hz
> can derive the same *behavioral* metrics, but the published hazard ratios transfer only after **our own
> calibration + validation** — quote them as literature context, not "your personal risk," until then.

| Metric | Evidence (numbers) | Feasibility |
|---|---|---|
| **Steps/day & MVPA → mortality** | Saint-Maurice 2020 (NHANES): 8k vs 4k steps HR 0.49, 12k HR 0.35; Paluch 2022 (15 cohorts, n=47k): per +1k steps −15%; plateau ~7–8.7k | ✅ |
| **Sleep Regularity Index** | Windred 2024: 5th-pctile HR 1.53; **> duration as predictor** | ✅ |
| **Circadian RAR** (RA/IS/IV/M10/L5) | Feng 2023 (n=92k): low RA → all-cause HR 1.54, CVD 1.73, cancer 1.32 | ✅ |
| **ENMO/MAD raw metrics** | the GGIR/UK-Biobank lingua franca; bridges us to all cohort thresholds | 🟡 (needs autocalibration; 25 Hz under-reads vigorous vs 100 Hz) |
| **Energy expenditure / METs** | ANN on same-wrist accel r=0.84, RMSE ~1.25 MET (Montoye); ±1 MET ceiling | 🟡 trends only |
| **Gait speed** ("6th vital sign") | Studenski 2011 (n=34k): HR 0.88 per +0.1 m/s | 🟡 (cadence ✅, wrist speed hard) |
| **Sedentary time & breaks** | independent of MVPA; breaking sitting → metabolic benefit (Healy; Diaz 2017) | 🟡 (wrist can't split sit vs stand-still) |
| **Frailty / falls** (iTUG, sit-to-stand) | sit-to-stand classified fallers 87% vs 63% stopwatch; Greene npj Dig Med 2019 | 🟡 guided test only |
| **HDCZA sleep** (van Hees, no diary) | PSG-validated, runs on z-angle at low rate | ✅ |
| **Tremor / PD** | UK Biobank AUC 0.85 — but cohort-level | ❌ (Nyquist 12.5 Hz aliases tremor; harm risk) |

**Open pipelines/datasets:** GGIR (Apache, R — *port the logic*), **SKDH** (Pfizer, MIT, Python — adopt),
**Capture-24** (open, CC-BY, best labeled wrist set), NHANES (open), UK Biobank (restricted).
⚠️ **biobankAccelerometerAnalysis is academic/non-commercial — do not use**; reimplement instead.

---

## 4. GPS (AT6558) + Barometer (BMP280)

| Metric | Evidence | Feasibility |
|---|---|---|
| **VO₂max / CRF from pace+HR** | Mandsager 2018: low vs elite HR **5.04** (>smoking/diabetes/CAD); Firstbeat method MAPE ~5% / <3.5 mL·kg·min err | ✅ already (sharpen to ~3.5) |
| **Grade-adjusted pace + EE** | Minetti 2002 cost curve; de Müllenheim 2016: altitude correction RMSE 1.00→0.79 MET | ✅ |
| **TRIMP / ACWR** | Banister; Gabbett sweet-spot 0.8–1.3, danger ≥1.5; Dijkhuis 2020 | ✅ |
| **Floors / elevation** | Harvard Alumni HR 0.84 (≥35 floors/wk); BMP280 0.25 m relative noise ≪ 1 floor | ✅ |
| **Life-space mobility** | Mackey 2014/16: 2.4× mortality; Kennedy: −72%/10-pt 6-mo decline; predicts cognition | 🟡 (GPS duty-cycle) |
| **Critical speed** | Jones 2017 (trust CS, not D′/W′) | 🟡 (needs maximal efforts) |
| **Active-commute detection** | Celis-Morales 2017 (BMJ, UK Biobank n=263k): cycle-commute −41% mortality | 🟡 (needs continuous GPS) |
| **Baro respiratory rate** | wrist-RR papers use piezo/contact sensors, not an *atmospheric* altimeter | ❌ |

---

## 5. Temperature & circadian

**BMP280 temperature is a ±1 °C self-heating *board* sensor, not skin temperature.** All flagship temp features
(circadian phase via distal-proximal gradient, ovulation's 0.3–0.7 °C rise, fever) need a calibrated ~0.05 °C
skin-contact thermistor → **hardware-revision feature, not current-hardware.** Log BMP280 temp as *ambient context only.*

But most circadian value needs **no temp sensor** and is ✅ today:
- **Rest-activity rhythm / relative amplitude** (accel) — strongest, see §3.
- **Chronotype + social jetlag** (accel+PPG) — SJL >1h → ~2× metabolic-syndrome risk (Wong/Roenneberg); obesity-linked, behaviorally actionable.
- **Nocturnal HR dipping + HRV circadian amplitude** (PPG) — non-dipping → ↑CVD/mortality.
- **Illness early-warning** from RHR+HRV deviation (HR leads temperature anyway).
- **Time-outdoors / daylight proxy** (GPS + solar position) — Burns 2021: +1 h outdoors → less depression; honest as a *proxy*, duty-cycle GPS.

**Recommendation:** add a cents-cost skin thermistor in v2 (unlocks illness +5pp AUC, ovulation, partial circadian phase); optionally an ALS lux sensor.

---

## 6. Obesity & metabolic — the mission, soberly

The literature is humbling: trackers reliably raise activity (~+1,800 steps/day) but the *weight-loss* signal is
weak-to-null, and the famous **IDEA RCT (Jakicic 2016) found a passive tracker made weight loss worse by 2.4 kg.**
The lesson isn't "wearables fail" — it's "a passive number bolted onto self-regulation backfires (moral licensing)."

Our two evidence-backed, population-scale levers:
1. **Forecast metabolic risk** from signals we already produce — RHR (RR 1.20/+10bpm → T2D), HRV↓, VO₂max (−28%/SD), sleep duration (U-shaped, optimum 7–8 h), sleep-timing regularity/social jetlag. This is *prevention*, our strongest footing.
2. **Well-timed behavioral nudges** inside a retention-engineered JITAI loop — **post-meal walks & sitting-breaks** (Dunstan: ~25% lower postprandial glucose), **NEAT activation** (Levine: lean−obese gap ~350 kcal/day, behavioral so must be *prompted*), adaptive goals (~7–8.7k not 10k), social/community as the free coaching substitute (coaching is the #1 multiplier; abandonment ~⅓ in 6 mo is the real enemy).

**Design principles:** honesty over precision theater · forecast > measure · act on validated behaviors even when we can't measure the outcome · *when* you move beats *how much* · engagement is the product · adaptive evidence-based goals · reinforce internal self-regulation, never outsource it to a calorie number.

---

## 7. Proposed "Fitness Age / Longevity" composite (honest, transparent)

Each input is independently mortality-linked in large cohorts **and** cleanly derivable from our stack. The *fused*
score is a labeled **wellness estimate, never a survival probability** — and our transparency (we publish the formula)
is the genuine differentiator vs Whoop/Oura black boxes (Doherty 2025: none disclose theirs).

| Component | Signal | Evidence anchor | Weight | Rationale (effect × our fidelity) |
|---|---|---|---|---|
| Cardiorespiratory fitness | VO₂max est. | Mandsager: HR 5.04 low vs elite | **30%** | biggest effect, but our estimate is noisy → capped |
| Resting HR | nocturnal RHR | Aune: +10bpm RR 1.17 | **20%** | most robust + most accurately measured |
| Resting HRV | nocturnal RMSSD | Jarczok 2022 meta | **15%** | strong but age/sex/breathing-confounded → personal-baseline only |
| Activity volume+intensity | steps, peak cadence, vigorous min | Harper 2025 (only *wrist-validated* mortality model, ΔC-index +0.008–0.015) | **20%** | only directly wrist-validated component |
| Sleep regularity | SRI | Windred: HR 1.53 | **15%** | strong, modifiable, clean |

Two outputs: a **"Fitness Age"** (map CRF+RHR+activity onto age norms — framing only) and, as the *primary* UX, a
**personal trajectory** (each component vs the user's own 60–90-day baseline). Show a data-coverage confidence band.
Never imply CVD/diabetes screening.

---

## 8. Data collection & signal-processing upgrades (the quality multiplier)

**Sampling rates** (evidence: Khan 2016 plateau 15–20 Hz; Tsanas 2022 & Yamane 2025 → 10 Hz sufficient for activity/sleep):

| Goal | Min usable | Recommend |
|---|---|---|
| ENMO/MVPA, steps, sleep, workout HAR | 10–15 Hz | **25 Hz default** |
| RMSSD/HRV | ~20 Hz *with interpolation* | 25 Hz + sub-sample peak refine |
| Tremor (only reason to burst) | 24–30 Hz | 100 Hz burst on-demand |

> **VALIDATED (2026-06-15), and the result was a surprise.** Tested on real data — PPG-DaLiA, 15
> subjects, wrist PPG decimated to the Bangle's 25 Hz vs hand-corrected chest-ECG R-peaks, 559
> low-motion 2-min windows (`biosignal/scripts/validate_hrv_quality.py`). Raw/ungated RMSSD MAE was
> **~195 ms** (wrist PPG is junk without QC). Our **existing** pipeline gate (template-SQI + physiologic
> reject + Kubios fixpeaks + artifact gate) brings the retained ~18% of windows to **~55 ms MAE** — i.e.
> the "biggest quality win" below is, in substance, **already in our pipeline, and it works.** Adding a
> *further* skewness+perfusion+template SQI gate on top did **not** beat that baseline on this data, so we
> did **not** ship it (would add surface without buying accuracy). Net: this is now a *validation* win, not
> a new feature. And note the honest ceiling — 55 ms abs error ≈ the magnitude of resting RMSSD itself, so
> 25 Hz wrist RMSSD stays **trend-grade** (the 🟡 verdict in §1), trustworthy whole-night, not beat-to-beat.

**Concrete pipeline upgrades (priority order):**
1. ~~**SQI gating before HRV**~~ — ✅ **done & validated** (callout above): template-SQI + Kubios in the
   existing pipeline already deliver 55 ms MAE on real wrist-PPG-vs-ECG; an extra `vital_sqi`-style
   skewness/perfusion/DTW gate did not improve on it, so it was evaluated and dropped, not shipped.
2. **Parabolic (3-point) peak interpolation** around each systolic peak — Hejjel shows it beats cubic-spline upsampling for IBI timing. *Tested in isolation: naive sub-sample refinement without artifact correction was worse (108 ms) — Kubios fixpeaks is doing the real timing repair at 25 Hz, so parabolic refine is not a drop-in win.* Keep our 25→250 Hz upsample; **flag pNN50 as low-confidence at 25 Hz** (most timing-fragile).
3. **van Hees 2014 autocalibration** (server-side) — non-movement windows → unit-sphere fit; cuts cross-device error ~17–77 mg → 3–8 mg. Essential for unit-to-unit KX022 comparability. Log KX022 die temperature for offset compensation.
4. **Stream raw triaxial, never proprietary counts** (ActiGraph counts correlate r²=0.19 with ENMO in sleep). Compute **ENMO + MAD** (MAD is most rate-robust). On-device: only cheap van Hees **non-wear** flagging (SD<3 mg & range<50 mg over 2/3 axes) to gate BLE/flash and save battery.
5. **For "HR during a workout"** (not HRV): an accel-referenced **NLMS** stage (`padasip`, MIT) or port JOSS (best BPM accuracy 1.28). These are spectral HR trackers — they do **not** preserve IBI timing, so never route HRV through them.

**Adopt (MIT/BSD, Python, maintained):** **SKDH** (accel: gait/activity/sleep/calibration), **NeuroKit2** (PPG/HRV — already use), **vital_sqi** (QC), HeartPy (optional).
**Port the algorithm (good but R/copyleft):** GGIR (Apache, R), **pyActigraphy is GPL — reimplement its circadian metrics, don't import**, Walch sleep recipe (MIT).
**License-blocked:** biobankAccelerometerAnalysis (academic-only).

**Validation datasets (which proves what):** Capture-24 (open — activity), PPG-DaLiA (open — PPG-HR/motion; decimate its 64 Hz to test our 25 Hz upsample against ECG), Walch `sleep-accel` (open — we already use), NHANES (open — steps), MMASH (HRV), DREAMT/MESA (sleep vs PSG, credentialed), IEEE SPC'15 (motion-HR).

---

## 9. The one architectural decision that unlocks the most

**GPS duty-cycling.** Today GPS is battery-gated to detected workouts. Three high-value longevity signals are blocked
only by that: **life-space mobility** (#17), **non-workout walking EE** (#11), **active-commute detection**. A
low-duty-cycle opportunistic fix (a few times/day) or phone-side location when the companion app is present would
unlock all three. Scope them together as one "ambient location" investment. (Life-space needs daily max-distance, not
continuous tracks — cheap.)

---

## 10. Sources

Representative anchors (each agent stream has full lists; ~60 papers total):

- **Mortality / fitness:** Mandsager 2018 JAMA Netw Open (CRF) · Saint-Maurice 2020 JAMA & Paluch 2022 Lancet Public Health (steps) · Zhang 2016 CMAJ & Aune 2017 (RHR) · Jarczok 2022 Neurosci Biobehav Rev (HRV) · Studenski 2011 JAMA (gait) · Harper 2025 medRxiv (wrist mortality model)
- **Circadian / sleep:** Windred 2024 Sleep (SRI) · Feng 2023 Lancet Healthy Longevity (RAR) · Roenneberg 2012 Curr Biol (social jetlag) · van Hees 2018 Sci Rep (HDCZA) · Walch 2019 Sleep
- **PPG:** Perez 2019 NEJM (Apple Heart) · Lubitz 2022 Circulation (Fitbit AFib) · Kwon 2019 JMIR · Dehkordi 2018 Front Physiol (RR) · Otsuka 2017 Hypertens Res (SDPPG) · Mukkamala 2022 Hypertension (BP critique) · Nie 2025 Commun Med (AI-PPG age)
- **Metabolic / obesity:** Jakicic 2016 JAMA (IDEA) · Dunstan 2012 Diabetes Care (sitting breaks) · Levine 2005 Science (NEAT) · Ferguson 2022 Lancet Digital Health (umbrella) · Scheer 2009 PNAS (misalignment)
- **GPS/baro:** Firstbeat 2017 white paper (VO₂max) · Minetti 2002 & de Müllenheim 2016 (EE) · Mackey 2014/16 JAGS (life-space) · Celis-Morales 2017 BMJ (commute)
- **Temperature:** Mason 2022 Sci Rep (TemPredict) · Zhu 2021 JMIR (ovulation) · Kräuchi 2000 (DPG) · BMP280 datasheet (Bosch)
- **Pipeline:** Khan 2016 Pattern Recognit Lett & Tsanas 2022 Sensors (sampling) · van Hees 2014 J Appl Physiol (calibration) · Migueles 2019 Sci Rep (ENMO/MAD/counts) · Elgendi 2016 Bioengineering & Dao 2022 Front Physiol (SQI) · Béres/Hejjel 2021 (HRV sampling) · Reiss 2019 Sensors (PPG-DaLiA) · Adamowicz 2022 JMIR (SKDH) · Makowski 2021 (NeuroKit2)

*(Full per-stream source lists with URLs are preserved in the session research transcript.)*

---

### How to use this document
Tier 1 is the next sprint's backlog. Each item is a small, validatable feature on signals we already stream — the same
real-data, leave-subjects-out rigor we used for sleep/HRV/workout/VO₂max. The highest-leverage *non-feature* work is the
**§8 SQI + interpolation + autocalibration** pipeline upgrade, because it makes everything else trustworthy. The biggest
*strategic* unlock is the **§9 GPS duty-cycle** decision. And the firewall in §1 (the ❌ list) is what keeps us honest —
which, for an open-source platform asking people to trust it with their health, is the whole game.
