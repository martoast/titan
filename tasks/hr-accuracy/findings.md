# HR/HRV Accuracy — Deep Research Findings & Improvement Plan

**Date:** 2026-06-27
**Trigger:** "deep research into the way the community has done HR monitoring for this wearable…
make it bulletproof and top class, the way Whoop has it."
**Method:** 4 parallel web-research streams (Whoop methodology / academic motion-artifact algorithms /
PPG-HRV-vs-ECG frontier / Bangle.js 2 VC31B firmware), each grounded in our actual code, cross-checked
against the repo.

---

## TL;DR — where we actually stand

Our pipeline is **far more mature than a typical DIY wearable** and, at rest/sleep, is in Whoop's
ballpark. Already built & validated:

- **In-motion workout HR** (`biosignal/app/core/inmotion_hr.py`, wired `/process/inmotion-hr` →
  `ActivitySession.hr_source='ppg_inmotion'`): accel cadence-notch (dominant peak + 2 harmonics on the
  raw Welch spectrum) + soft continuity prior + cadence-collision detection + hold-last-good. Validated
  on PhysioNet (Jarchi & Casson).
- **Overnight HRV** (`biosignal/app/core/hrv.py`): NeuroKit2 → IBI sanity reject → Kubios
  (Lipponen–Tarvainen) correction → quality gate. **Already cubic-spline upsamples 25 Hz → 250 Hz
  before peak detection** (`PPG_PROC_HZ=250`). Validated on PPG-DaLiA (~195 ms RMSSD MAE raw → ~55 ms
  gated). Lipponen–Tarvainen remains the literature reference standard — keep it.
- **Firmware**: 25 Hz rest / 50 Hz workout raw PPG (in the VC31B's tuned 20–40 ms poll band),
  sport-mode tagging, duty-cycled overnight HRV bursts.

**The honest physics ceiling:** wrist PPG under intense irregular motion is fundamentally hard — *even
Whoop* degrades to ~17% MAPE on burpees; their real fix is telling users to move the sensor to the
bicep (17%→12%). Two hardware gaps we cannot fully close on a Bangle.js 2: single green photodiode
(Whoop 4.0/5.0 = 4 PDs + 3 green LEDs → spatial source separation), and no SpO₂/red-IR (locked behind
a vendor blob Espruino doesn't ship).

---

## UPDATE 2026-06-27 (PM) — measured on real ECG data; in-motion approach reversed

Downloaded the PhysioNet harness dataset and measured against ECG. **Big finding: our spectral
accel-notch in-motion method was WORSE than plain peak-detection for walk/run** (median ~75–84 vs
~12/24 bpm MAE), because the wrist-swing cadence harmonics blanket the HR band and the notch deletes
the pulse. It only ever won for cycling. The Viterbi change (validated only on synthetic data) slightly
worsened the real aggregate via confident lock-in.

**Action taken:** time-domain **peak-tracking is now the DEFAULT** in-motion estimator
(`inmotion_hr.peaktrack_series`, wired in `routers/inmotion_hr.py`); the accel-notch + Viterbi path
(`estimate_series`) is reserved for an explicit `activity=cycle/bike` caller. Measured peaktrack: walk
**12.3**, run **23.7**, worst-record **30.5** (vs notch's 134) — robust, no catastrophic failures.

**BeliefPPG: prototyped, verdict NO.** Installed + ran the pretrained model on the same data. Typical
(median) running ~19 (better than 24) and single digits on good-signal records, BUT bimodal —
catastrophic blow-ups (78–113 bpm) on bad-signal subjects, *worse* than peaktrack on walking, and it's
the wrong sensor domain (would need a fine-tuning data campaign on our band) + drags TensorFlow into the
image. Modest, unreliable gain for large cost. Tool: `scripts/proto_inmotion_compare.py`.

**The real fix for running + lifting is a BLE chest strap** — see `tasks/chest-strap/scope.md` (~2 days,
no firmware, no new backend deps). Wrist PPG stays the rest/sleep/HRV signal it's good at.

## Implementation status (2026-06-27)

**Shipped this session (Tier 0 + Tier 1 server-side):**
- ✅ **Tier 0** — firmware `PPG_FIELDS` now `["raw",…]` (de-glitched) + corrected field-doc comment. *(needs reflash)*
- ✅ **Tier 1 #2** — `inmotion_hr.estimate_series` rewritten as a **global Viterbi tracker** (peakiness²-weighted
  observation + Gaussian transition prior), replacing greedy hold-last-good; chosen peak parabolically
  interpolated for sub-bin bpm. All 4 in-motion tests pass.
- ✅ **Tier 1 #4** — HRV beat timing moved to the **max-upslope fiducial** (`hrv._upslope_fiducials`) with
  systolic-peak fallback; epoch RMSSD re-aligned. All HRV tests pass.
- ✅ **Tier 1 #5** — firmware overnight bursts are now **accel-gated** (skip+retry when moving). *(needs reflash)*
- ✅ **Tier 1 #3 — was ALREADY built:** `app/Support/Readiness.php` already does lnRMSSD 7-day-vs-60-day
  z-score (logistic) AND HRV-CV (`cv()`, recent-vs-older). No work needed.

Verification: biosignal suite **68 passed**. Empirical MAE deltas still TODO — run the validation harnesses
where the PhysioNet / PPG-DaLiA datasets live (not checked into the repo). Firmware items need a manual reflash.

**Not yet done (Tier 2+, deferred for review):** BeliefPPG refiner, NLMS/RLS adaptive pre-filter,
harmonic-sum on collision, upstream morphological SQI gate, chip quality/normalization channel.

---

## Prioritized plan (do-now → step-change → hardware ceiling)

### TIER 0 — confirmed bugs / wrong-field (cheap, do first)

1. **Stream `raw`, not `vcPPG`.** `firmware/banglejs/titan.app.js` `PPG_FIELDS: ["vcPPG","raw",…]`
   picks `vcPPG` first, and the header comment wrongly calls it "the documented raw-PPG field." Per
   Espruino source: `vcPPG` is the bare ADC that **glitches on every auto-exposure/gain step**;
   `raw = (vcPPG + vcPPGoffs) * 2` is the **de-glitched** composite the community uses. `filt` clips at
   16-bit — avoid. **Fix:** reorder to `["raw","vcPPG","filt","adc"]`. Server math is relative
   (bandpass) so the 2× scale is harmless. *Effort: trivial. Impact: removes gain-step artifacts from
   every downstream HR/HRV computation.* (Requires a reflash.)
   Sources: espruino jswrap_bangle.c; majorinput.co.uk raw-vs-filt; discussion #6309, #7232.

### TIER 1 — best ROI software (server-side, no reflash, generous compute)

2. **Global Viterbi/HMM tracking to replace greedy hold-last-good in `inmotion_hr.py`.** We already
   have the observation model (per-window PSD) and the transition cost (our Gaussian continuity prior).
   Replace per-window argmax + hold-last-good with a backward DP over the whole workout's window
   sequence. Removes catastrophic harmonic-lock/cadence-lock (sees the future, bridges bad windows from
   both sides) and hold-last-good drift. *Effort: low (1–2 days). Impact: −0.3 to −0.6 bpm average,
   large worst-case/tail reduction.* Evidence: WFPV 1.97→1.37 bpm with Viterbi; BeliefPPG +11%.

3. **lnRMSSD rolling-baseline z-score + HRV-CV reporting (product win).** Stop leading with absolute
   ms. Compute a personal 30–60-day baseline of nightly lnRMSSD; report today's z-score / % deviation +
   HRV-CV. Cancels systematic PRV/measurement bias, matches Whoop/Elite-HRV, makes a noisy absolute
   value actionable. *Effort: low (post-processing on data we already store).* Sources: Whoop HRV-CV;
   Plews/Bellenger lnRMSSD-CV.

4. **Switch HRV beat-timing fiducial off the systolic peak.** NeuroKit's default `ppg_findpeaks`
   (Elgendi) returns the systolic peak — the *flattest, most jitter-prone* point. Use the max-slope
   (first-derivative/VPG) or tangent-intersection point, or at least parabolic-refine the chosen
   fiducial. Steeper dV/dt → less timing jitter; stacks with the upsampling we already do. *Effort:
   low–med.* Sources: fiducial-point review PMC9280335; greedy-IBI arXiv 2301.02906.

5. **Accel-gate the overnight HRV bursts ("sample only when still").** Today `SLEEP_DUTY_ON_MS=30000 /
   PERIOD=180000` is a **fixed timer** (~17% duty). We already maintain an always-on accel signal (the
   GPS/auto-detect gate) — our own code comment says "the gating is free." Trigger a burst on low accel
   variance; abort/skip during movement. Both **saves battery** (no wasted bursts during tossing) and
   **improves quality** (PPG only good when still). *Effort: medium. Impact: better signal + energy.*
   Source: discussion #5820 (Gordon: disable auto-exposure under motion — same principle).

### TIER 2 — step change for real-world (mixed gym/run) HR

6. **Adopt BeliefPPG (or equivalent NN-distribution + offline belief-propagation) as a post-hoc
   refiner.** This is the single biggest accuracy lever for our actual use case (messy real-world
   motion, not a treadmill). On PPG-DaLiA, classical methods collapse (SpaMA ~15.6 bpm) while
   BeliefPPG hits ~3.18 bpm — **2–5× error reduction.** It's purpose-built for offline refinement: NN
   emits a per-window HR-bin *distribution*, then a Viterbi pass smooths the whole recording. Open
   source (PyPI `beliefppg`, ETH SIPLAB), pretrained on public benchmarks → can start as a drop-in on
   our already-collected raw PPG+accel windows; fine-tuning on our band's data is the larger task.
   Compute is a non-issue server-side. *Effort: medium–high. Impact: step change on real activity.*
   Source: Bieri et al., UAI 2023; github.com/eth-siplab/BeliefPPG.

7. **Accel-referenced adaptive filter (NLMS/RLS, per-axis, convex-combined) BEFORE the FFT.** Feed the
   motion-cancelled signal into the existing spectral stage. Spectral notching assumes stationary
   motion within the window; ANC adapts sample-by-sample, tracking *within-window* envelope changes
   (jogging, intense activity) — exactly where our fixed-depth notch is weakest. *Effort: medium.
   Impact: −0.5 to −1.0 bpm on intense/time-varying motion.* Sources: CPC (NLMS+RLS) 0.92 bpm SPC-12,
   PMC5830890; CASINOR.

### TIER 3 — targeted / polish

8. **Harmonic-sum joint model on detected cadence collision.** When HR ≈ stride/rep frequency, notch-
   ing deletes the HR. Fit PPG as TWO harmonic series (HR + motion), motion fundamental fixed from
   accel; different harmonic structure separates them even when fundamentals coincide. ~0.74 bpm
   in-class on SPC-12, purpose-built for our hardest regime. *Effort: med–high.* Source: Nathan &
   Jafari arXiv 1610.05112.

9. **Upstream PPG morphological SQI gate** (template-correlation / skewness-SQI, e.g. E2E-PPG) before
   HRV peak detection — catches plausible-but-corrupt *waveforms* our post-hoc 5%-corrected gate
   misses. Keep Lipponen–Tarvainen for the IBI series. *Effort: medium.* Source: HealthSciTech E2E-PPG.

10. **Stream a quality/normalization channel from the chip:** LED current readback `Bangle.hrmRd(0x17)`
    + VC31B INT flags (`REG1`: `INT_PS` proximity/wear, `INT_OV` overload) + `vcEnv` ambient. Gives the
    server real amplitude normalization (HRM-raw has no gain field) and a hardware contact/overload
    signal for a multi-signal contact gate (raw-DC band + low/stable vcEnv + motion + confidence).
    Also: **freeze `hrmGreenAdjust` during high motion** (hold last-good current) to kill mid-lift
    gain-step artifacts. *Effort: medium.* Sources: hrm_vc31.c; discussion #5820.

11. **Right-size / honesty on reported metrics:** lead with RMSSD/SDNN/pNN50; flag HF as low-confidence;
    **demote/drop LF/HF** from overnight wrist PPG (least reliable index). Optional bonus metric:
    nightly respiratory rate via RSA/RIIV. *Effort: low.* Sources: Oura JMIR e27487; Kubios resp-rate.

### Targeting refinement (minor)
- Whoop computes RMSSD on the **final slow-wave-sleep episode**, not the whole night. We report whole-
  night (per Kinnunen, defensible). Minor refinement: weight toward the deepest, most autonomically
  stable window. *Effort: low–med (needs sleep-stage alignment we already compute).*

---

## Do NOT chase (dead ends / not worth it)
- **Higher sampling rate** — 25/50 Hz ≈ Whoop's operative ~26 Hz; VC31B ceiling is ~60–80 Hz (sags),
  not 100–150 Hz. Not an accuracy gap.
- **Red/IR / SpO₂ / multi-wavelength** — hardware-capable but locked behind a vendor binary blob
  Espruino doesn't ship. Green only.
- **`hrmSportMode` tuning for our pipeline** — only tweaks the vendor algo's on-watch bpm/confidence;
  the raw ADC stream is unchanged. (Set 0/-1 for any on-watch lifting fallback, but irrelevant to the
  server path.)
- **Deep-learning peak correction for overnight HRV** — doesn't beat Lipponen–Tarvainen in the low-
  motion sleep regime.
- **Chasing the PRV-vs-HRV floor** — irreducible ~2–4 ms RMSSD at rest; far below current error.
  Absorb it via the z-score framing (#3).
- **EEMD/CEEMDAN/VMD decomposition front-ends** — small, inconsistent gains at highest compute;
  benefit overlaps with ANC + tracking. (SSA is the only decomposition with a track record — it's
  inside TROIKA — consider only if we want a non-ML denoise stage.)
- **Multi-photodiode source separation** — physically impossible on a 1-PD VC31B. Mitigate with accel
  fusion + surfacing bicep-placement guidance to users (placement beats any DSP).

---

## Key validation next step
Re-run the existing harnesses to get OUR numbers on OUR data after each change:
`biosignal/scripts/validate_inmotion_hr.py` (PhysioNet, bpm MAE) and
`biosignal/scripts/validate_hrv_quality.py` (PPG-DaLiA, RMSSD MAE). The definitive deltas are what
these report, not the literature's.

## Source index
Whoop optics/PDs: smarthome724 teardown, the5krunner. Whoop patents: US9730591B2 (raw-PPG motion
filter), US2016/0324432 (dual-path + GSR on-body), US11986323B2 (ML confidence), US9750415B2 (sleep
HRV). Whoop validation: Bellenger 2021 PMC8160717, PMC12367097, placement PMC12788198.
Motion algos: TROIKA arXiv 1409.5181, JOSS 1503.00688, SpaMA MDPI 16/1/10, WFPV (Temko, github
andtem2000/PPG), BeliefPPG arXiv 2306.07730 (github eth-siplab/BeliefPPG), Deep PPG / PPG-DaLiA
PMC6679242, CPC PMC5830890, harmonic-sum 1610.05112. HRV/sampling: Choi & Shin 2017
(10.1088/1361-6579/aa5efa), Béres & Hejjel 2021 (S1746809421001865), Reali 2022 PMC8877143,
Lipponen–Tarvainen 2019 (PMID 31314618), E2E-PPG (HealthSciTech), HRV-CV (whoop.com). Bangle/VC31B:
espruino discussions #7232 #7335 #7738 #5820 #6309 #7301, hrm_vc31.c, jswrap_bangle.c,
majorinput.co.uk, kendell.dev/blog/vcareexplanation, validation PMC12074211.
