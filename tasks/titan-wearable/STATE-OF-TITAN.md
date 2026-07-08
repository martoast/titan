# State of Titan — the wearable/biosignal system, as built

> **One-glance inventory** of everything live in the wearable → biosignal → app → agent pipeline:
> what each feature is, where it lives, how good it is (real-data grade), and how it surfaces. The
> deep "how do we know" numbers are in [`09-validation-results.md`](09-validation-results.md); the
> forward backlog + feasibility calls are in [`08-sensor-research.md`](08-sensor-research.md). This is
> the "if you open the repo, here's what Titan actually does" map.

## What Titan is, now
A subscription-free, **local-first health/longevity OS**. A *dumb-sensor* wrist band (Bangle.js 2)
streams raw PPG + motion + barometer; an **open, validated biosignal service** (Python/FastAPI +
NeuroKit2) turns it into HRV, sleep, fitness, activity and longevity metrics; the **Laravel app**
stores + surfaces them; and an **MCP server** lets the user run the whole account from their own Claude
Code agent. Every shipped metric is validated on real public data with honest, leave-subjects-out
error — and the things that didn't hold up were *not* shipped (recorded as kept negatives).

## Architecture (where the work lives)
```
Bangle.js 2 firmware ──BLE/offline──▶ phone bridge ──HTTP──▶ Laravel app ──▶ biosignal service
 raw PPG+accel+baro    (T1/T4/T5/      (decode frames)   (ingest, seal,    (HRV, sleep, VO2max,
 GPS-gated, T2/T6/T7    T6/T7 frames)                      persist, surface)  activity, gym, floors,
 logs)                                                     │                  RR, gait, EE, function)
                                                           ▼
                                          web UI  +  MCP server (30 tools, the user's agent)
```
- **Firmware** — `firmware/banglejs/titan.app.js` (frames T1 live, T2 overnight PPG, T4 GPS, T5 HR,
  T6 offline workout accel, T7 ambient altitude; GPS battery-gated to workouts; continuous baro).
- **Bridge** — `resources/js/bridge-decode.js` (decode frames → windows; `npm test`).
- **Biosignal DSP** — `biosignal/app/core/*.py` + `routers/*.py`, validated by `scripts/validate_*.py`.
- **App composites** — `app/Support/*.php` (the longevity/health scores over stored data).
- **Seal/ingest** — `app/Jobs/SealNightJob`, `SealActivityJob`, `app/Services/Wearables/*`.
- **Agent** — `mcp/` server + `app/Services/Assistant/AssistantTools.php` (token-auth API).

---

## Feature inventory

Grade: ✅ ship with confidence · 🟡 ship as a **trend**, not an absolute · ⚪ honest heuristic (no
outcome data) · ❌/⏸️ tested-and-declined or deferred. Full numbers in doc 09.

### Recovery & autonomic
| Feature | Lives in | Real-data grade | Surfaced |
|---|---|---|---|
| Overnight HRV (RMSSD) | `core/hrv.py` (signal-quality gate) | 🟡 55 ms MAE vs ECG (raw 195) | Recovery page · MCP |
| Resting HR | `core/hrv.py` | ✅ min-of-windowed-medians | Recovery · MCP |
| Respiratory rate (sleep) | `core/respiration.py` | 🟡 2.89 br/min vs annotations | Recovery · MCP |
| Readiness score | `Support/Readiness.php` | composite (HRV+RHR+sleep) | Recovery · MCP |

### Sleep & circadian
| Feature | Lives in | Grade | Surfaced |
|---|---|---|---|
| Sleep staging (sleep/wake + 4-class) | `core/` Walch model | 🟡 κ 0.50 vs PSG | Sleep page |
| Sleep Regularity Index (SRI) | `Support/SleepRegularity.php` | ✅ (Phillips 2017) | Recovery/longevity · MCP |
| Circadian rest-activity rhythm (IS/IV/M10/L5/RA) | `Support/CircadianRhythm.php` | ✅ (Van Someren) | longevity · MCP |

### Activity & ambient
| Feature | Lives in | Grade | Surfaced |
|---|---|---|---|
| Activity/workout classification | `core/activity_classify.py` | ✅ 87% κ 0.83 (PAMAP2) | sessions · MCP |
| Personalized steps/MVPA goal | `Support/StepGoal.php` | ✅ (Paluch/Saint-Maurice) | Fitness · MCP |
| Movement breaks ("don't sit too long") | `Support/MovementBreaks.php` | ✅ (Dunstan) | Fitness · MCP |
| Floors / elevation | `core/elevation.py` (T7 baro) | 🟡 MAE 0.83 floors, 0 phantom | Fitness · MCP |
| Grade-aware energy expenditure | `core/energy.py` (Minetti) | ✅ 10% MAPE vs measured VO₂ | activity sessions |

### Fitness & function
| Feature | Lives in | Grade | Surfaced |
|---|---|---|---|
| VO₂max / fitness age | `core/fitness.py` (+`vo2max_run.joblib`) | 🟡 MAE 5.2 ml/kg/min, r 0.65 | Fitness page · MCP |
| Heart-rate recovery (HRR) | `core/fitness.py` | trend (cooldown not standardised) | Fitness · MCP |
| Training load + ACWR guardrail | `Support/TrainingLoad.php` | ⚪ EWMA heuristic, honest | Fitness · MCP |
| Gait cadence | `core/gait.py` | ✅ <1 spm; real median 116 | `/process/function` · MCP |
| Sit-to-stand (30CST) frailty screen | `core/gait.py` + `Support/ChairStand.php` | ✅ guided test, squat-validated counter | Fitness card · MCP |

### Strength (gym)
| Feature | Lives in | Grade | Surfaced |
|---|---|---|---|
| ~~Exercise recognition (10-class) / rep counting from wrist accel~~ | **REMOVED** (was `core/gym.py` + `gym_classifier.joblib`) | validated 95.4% κ 0.95 in the lab, but in practice INVENTED lifts (jumping jacks/sit-ups) for any low-motion session, so auto-detection was cut (9b9f4d6 seal, then the whole classifier). Git history keeps it. | — |
| Set segmentation + weight entry + "needs weights" nudge | `Jobs/SealActivityJob` + Workouts UI (sets written only by explicit coach logging) | end-to-end | Workouts |
| Voice-logged sets / live session / hands-free finish | phone bridge + Workouts | — | Workouts |

### Longevity & metabolic
| Feature | Lives in | Grade | Surfaced |
|---|---|---|---|
| Metabolic-health forecast | `Support/MetabolicHealth.php` | composite (T2D-linked signals) | Recovery · MCP |
| **Biological age** (blood + fitness + wearable) | `Support/BiologicalAge.php` | 🟡 PhenoAge mortality-validated; blend composed | Recovery · MCP |
| PhenoAge blood clock (9 markers) | `Support/PhenoAge.php` | ✅ golden-value tested (Levine/Liu) | Biomarkers panel |
| Bloodwork upload → markers + clock | `Health/BiomarkerController.php` | LLM lab-PDF extraction | Biomarkers page |

### Women's health — menstrual cycle
| Feature | Lives in | Grade | Surfaced |
|---|---|---|---|
| Cycle engine (phase, predictions, fertile window, conception likelihood, regularity) | `Support/Cycle.php` | physiology-based (luteal-anchored ovulation; learns avg from history) | Cycle page · dashboard · coach · MCP |
| Phase × recovery tie-in | `Support/Cycle.php` `recoveryByPhase()` | derived from logged RHR/HRV per phase | Cycle page · coach |
| Period + daily logging (flow/symptoms/mood/BBT) | `menstrual_cycles` + `cycle_logs` | manual / coach / MCP | Cycle page · coach · MCP |
| Cycle ring UI + dashboard tile + generative chat card | `cycle/index.blade.php` · dashboard · `app.js` | — | app |
| Coach + MCP cycle tools | `CoachTools` (cycle_status/log_period/log_cycle) · `AssistantTools` (get_cycle/…) | gated to women/enabled | coach · MCP |

**Rail:** awareness + coaching only — never contraception, never diagnosis; the `Cycle::DISCLAIMER`
travels with every fertility/conception value, and hormonal birth control suppresses the fertile-window framing.

### Platform
API tokens + 30-tool assistant API (`AssistantTools.php`) · MCP server (`mcp/`) · device-agnostic
ingestion + night/activity seal jobs · the firmware frame protocol + bridge decoder + workout simulator.

---

## The honest "no"s (the discipline working)
Recorded so we never re-litigate them — see doc 09 "negative results" + doc 08:
- **In-motion HR** ❌ — naive wrist-PPG peaks are 18 bpm MAE during exercise; HR path **split** (resting
  PPG→IBI for HRV; the watch's on-device accel-corrected bpm for workouts, to validate on hardware).
- **Vascular age / arterial stiffness** ❌ — APG aging index doesn't track age on wrist PPG (r −0.3 @
  25 Hz, wrong sign); wrist green-LED is morphology-poor. Not shipped; reproducible negative kept.
- **Life-space mobility** ⏸️ — all-day GPS is the worst power cost for the softest signal; deferred. If
  ever revisited, phone-side ambient location, never watch GPS.
- **Methodology negatives kept**: extra SQI gate (didn't beat the validated 55 ms gate), naive parabolic
  refine, ACSM submax extrapolation (VO₂max), `|accel|` rep counting, data-driven age clock on a narrow
  cohort — each tried on real data, rejected, documented.

## The two rails that never change
1. **Claim discipline** — wellness vocabulary only. **Never ship** ECG · AFib · sleep-apnea · cuffless
   BP · SpO₂ · any diagnose/monitor/treat claim. The hardware is timing-rich, morphology-poor,
   single-wavelength → rhythm metrics ✅, morphology trend-only 🟡, medical-grade ❌. This keeps us out
   of FDA/MDR device regulation.
2. **Stay on the information side** — publish designs, don't sell finished units, don't charge for data,
   local-first. Low liability, honest mission (the OpenAPS model).

## Reproduce any number
`cd biosignal && .venv/bin/python scripts/validate_<x>.py <data>` — `hrv_quality`, `respiration`,
`on_physionet` (sleep), `vo2max`, `energy`, `gym`, `inmotion_hr`, `elevation`, `gait`, `bioage`,
`vascular` (the negative). Tests: `python -m pytest` (biosignal), `php artisan test` (app), `npm test`
(bridge).
