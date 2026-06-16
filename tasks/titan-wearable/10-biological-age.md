# 10 — Biological Age (the synthesis of everything we measure)

> **Goal:** turn Titan's three data streams — **uploaded bloodwork**, the **wearable** (resting HR,
> HRV, sleep, steps), and the **VO₂max** estimate — into one honest "how old is your body" number, plus
> the per-system breakdown that tells you what's driving it and what to change.
>
> **The honest headline:** with bloodwork we can run a *mortality-validated* clock (PhenoAge). Without
> it we fall back to fitness + wearable estimates that are weaker and explicitly labelled so. We never
> claim a clinical biological-age test or a mortality percentage — it's a wellness trend with a range.

Built: `app/Support/PhenoAge.php` (the blood clock), `app/Support/BiologicalAge.php` (the combiner),
`biosignal/scripts/validate_bioage.py` (the honest anchor validation). ~60 papers reviewed across two
research streams; key anchors and numbers below.

---

## 1. The landscape of biological-age clocks (what the literature actually validates)

| Clock | Inputs | Trained against | Strength | Fit for Titan? |
|---|---|---|---|---|
| **PhenoAge** (Levine/Liu 2018) | 9 routine CBC+CMP+CRP markers + age | **10-yr mortality** (NHANES III) | Cheap labs, mortality-validated, widely used | ✅ **our blood core** |
| **KDM** (Klemera-Doubal 2006; Levine 2013) | flexible biomarker panel | **chronological age** (regression) | Degrades gracefully to any marker subset | 🟡 fallback when CRP absent |
| **Homeostatic Dysregulation** (Cohen 2013; Li 2015) | biomarker panel + healthy reference | Mahalanobis distance from "young healthy" | No outcome data needed; discounts correlated markers | 🟡 future, needs reference cohort |
| DNAm clocks (Horvath, GrimAge, DunedinPACE) | DNA methylation array | age / mortality / pace | Gold standard but **needs a methylation assay** | ❌ out of scope (not routine bloodwork) |
| **Wearable clocks** (PpgAge 2025; Pyrkov 2021; XGBAge 2024) | PPG/accel + sleep + activity | age / mortality | MAE ~2.5 yr (PPG) → ~5 yr (accel); rival DNAm | 🟡 the daily-feedback layer |
| **Fitness Age** (Nes 2011) | VO₂max vs age norm | population VO₂max | CRF = steepest mortality gradient known | ✅ **our fitness anchor** |

We ship **PhenoAge (blood) + Fitness Age (VO₂max)** as the two anchors and the wearable signals as
**modifiable levers** — the combination the literature supports (below).

---

## 2. PhenoAge — the blood core (exact, implemented)

Liu Z et al., *PLoS Med* 2018;15(12):e1002718 (algorithm) · Levine ME et al., *Aging* 2018;10(4):573.
Nine markers + age → a Gompertz 10-year-mortality score → the age with that population risk.

```
xb = -19.9067
     - 0.0336·Albumin[g/L]   + 0.0095·Creatinine[µmol/L] + 0.1953·Glucose[mmol/L]
     + 0.0954·ln(CRP[mg/dL]) - 0.0120·Lymphocyte[%]      + 0.0268·MCV[fL]
     + 0.3306·RDW[%]         + 0.00188·ALP[U/L]           + 0.0554·WBC[10³/µL]
     + 0.0804·Age[yr]
M = 1 − exp( −exp(xb)·(exp(120·γ)−1)/γ ),   γ = 0.0076927
PhenoAge = 141.50225 + ln(−0.00553·ln(1−M)) / 0.090165
```

**Units are the #1 implementation bug** — the coefficients are SI; our catalog is US, so we convert:
albumin g/dL×10, creatinine mg/dL×88.42, glucose mg/dL×0.0555, CRP **mg/L÷10 then ln**. A golden-value
unit test pins the whole chain: a **healthy 50-year-old → PhenoAge 39.9** (−10 yr); an inflamed/
dysmetabolic 50-year-old → ~71 (+21). Two corrections from the literature we encode: the denominator is
**0.090165** (not the widely-copied 0.09165) and CRP must be mg/dL *before* the log.

**Required panel (no graceful degrade — all 9 needed):** albumin, creatinine, fasting glucose, hs-CRP,
lymphocyte %, MCV, RDW, alkaline phosphatase, WBC. We added the 6 missing ones to the biomarker catalog;
`BiologicalAge` lists exactly which are still absent so the user knows what to add. **Honest caveats:**
PhenoAge is a mortality risk score wearing an age label — *PhenoAgeAccel* (vs chronological) is the real
quantity; it's NHANES-trained (recalibration caveat); and CRP/glucose/WBC spike with acute illness or a
non-fasting draw, so we gate implausible values and treat one draw as a trend point, not a verdict.

**High-value markers to add next** (beyond the PhenoAge 9): cystatin-C eGFR, HbA1c, GGT, ApoB, and the
free **NLR** (neutrophil/lymphocyte) from the CBC differential — each independently mortality-linked.

---

## 3. Fitness Age — the functional anchor

Nes 2011 (*MSSE*, HUNT n=4,637): map VO₂max to the age at which it is the population median. CRF is the
single steepest mortality gradient measured — low-vs-elite **aHR 5.04** (Mandsager 2018, n=122k), steeper
than smoking or diabetes — so fitness earns anchor status. Norm: peak ~50 (men)/42 (women) ml/kg/min at
25, declining ~0.45/yr. Our validated VO₂max (MAE 5.2, doc 09) feeds it. The anchor is **calibrated and
behaves** on 940 real treadmill subjects: average-fitness people land fitness age ≈ chronological
(mean gap +0.3 yr), and the gap tracks VO₂max (r −0.72, fitter ⇒ younger) — see `validate_bioage.py`.

> **Honest negative we keep:** we first tried a *data-driven* age clock (predict chronological age from
> fitness markers) on the treadmill set — MAE 8.1 yr from VO₂max alone, **no better than guessing the
> mean** (the cohort is young, athletic, narrow-aged), and fitting VO₂max-age norms from it gave a
> *positive* female slope. A real data-driven clock needs an NHANES-style population. So we use
> established literature norms, not norms re-fit from a biased cohort.

---

## 4. The wearable levers (bounded, modifiable — not independent age axes)

The research is explicit: wearable activity signals track aging and respond to behaviour, but their
*incremental* discrimination over fitness + blood is modest (UK Biobank step/mortality Δc-index ≈ 0.008),
and they're partly redundant with fitness. So we add each as a **capped age offset** on the anchor, with
the total wearable nudge capped (±8 yr) and steps down-weighted when fitness is present (activity feeds
VO₂max — no double counting):

| Lever | Effect size | Mapping | Cap |
|---|---|---|---|
| Resting HR | all-cause RR **1.17 / +10 bpm** (Aune 2017) | +2 yr per +10 bpm over 60 | ±5 |
| Sleep regularity (SRI) | 5th-pctile **HR 1.53** (Windred 2024) | −3 yr per +20 SRI over 60 | ±4 |
| Daily steps | 8k vs 4k **HR 0.49** (Saint-Maurice 2020) | −2 yr per +3k over 7k (½ wt if fitness present) | ±4 |
| HRV (RMSSD) | declines with age, **noisiest** signal | −2 yr per +15 ms over 40 — *lowest weight* | ±3 |

Each contribution is shown in the breakdown so the user sees the modifiable levers, not a black box.

---

## 5. How we combine them (and why)

`BiologicalAge::assess()`:
1. **Anchor** = evidence-weighted blend of the two strong, *independent* absolute ages: PhenoAge (blood,
   0.5) + Fitness Age (VO₂max, 0.5). Only one present → use it. Neither → chronological age (low conf).
2. **Levers** = the bounded wearable offsets above, summed and capped, applied to the anchor.
3. **Biological age** = anchor + levers, clipped to [18, 100]; **delta** vs chronological → band.
4. **Confidence** = high (blood + fitness), medium (one anchor), low (wearable only) — surfaced, with a
   range, never false precision.

We do **not** naively average sub-ages (that double-counts correlated signals and gives noise an equal
vote) — the anchors are blended, the levers are bounded modifiers. This composes individually-validated
signals transparently; the *blend itself* is an evidence-weighted heuristic, **not** mortality-calibrated
(we have no outcome data — graded ⚪ in doc 09 alongside the honest metrics).

---

## 6. The path to a *real* Titan clock

To earn a validated, mortality-calibrated biological age (not a composed estimate), the route is:
train a Gompertz/Cox model on an **NHANES-style cohort with mortality follow-up**, using the markers
Titan actually collects (blood + RHR + VO₂max-proxy + activity), the way PhenoAge itself was built —
then convert predicted risk to an age. Until we have that, the honest product is the transparent
composite here, with PhenoAge carrying the mortality-validated weight. The `BioAge` R toolkit
(Kwon & Belsky 2021, *GeroScience*) is the reference for KDM/PhenoAge/HD if we add the fallbacks.

---

## Sources (anchors)
- **PhenoAge:** Liu 2018 *PLoS Med*; Levine 2018 *Aging* — https://pmc.ncbi.nlm.nih.gov/articles/PMC5940111/
- **KDM / toolkit:** Klemera & Doubal 2006 *Mech Ageing Dev*; Levine 2013 *J Gerontol A*; Kwon & Belsky 2021 *GeroScience* (BioAge)
- **Homeostatic dysregulation:** Cohen 2013 / Li 2015 *Aging Cell*
- **Fitness Age / CRF:** Nes 2011 *MSSE* (HUNT); Mandsager 2018 *JAMA Netw Open*
- **Wearable clocks:** PpgAge 2025 *Nat Commun*; Pyrkov 2021 *Aging*; XGBAge 2024
- **Levers:** Aune 2017 *NMCD* (RHR); Windred 2024 *Sleep* (SRI); Saint-Maurice 2020 *JAMA* / Paluch 2022 *Lancet Public Health* (steps); Shaffer & Ginsberg 2017 (HRV norms)
