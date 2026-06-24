# Titan ⇒ Whoop/Oura parity — master plan

Generated 2026-06-24 from a 5-stream deep research (Whoop, Oura, metabolic/weight-loss apps,
behavior-change science) cross-referenced against a full inventory of Titan's existing features.
Mission: make elite, $200-hardware / $30-month health intelligence **free + open** for the millions
fighting obesity and metabolic illness.

## What Titan already has (do NOT rebuild)
Recovery/readiness, HRV/RHR/resp, strain + recovery-aware target, sleep stages/debt/regularity,
VO₂max, biological age (PhenoAge) + fitness age, athlete score, autoregulation, full nutrition
(meals, macros, photo scan, food library + per-profile corrections, targets, pantry), weight/body
metrics + raw trend chart, progress photos + dream-physique render, full menstrual cycle, periodized
program builder, coach memory + knowledge base, 60+ coach tools, wearable pipeline, Apple Health
import, **a Streak model**, StepGoal/DailyActivity, scheduled briefings/nudges/weekly-review/meal
reminders, proactive protein + sleep reactions.

## The gaps that matter (confirmed missing, ranked by impact-for-effort, software-only)

### PHASE A — the moat (differentiated, mission-critical)
- **A1. Behavior journal + correlation engine** ⭐ — #1 across every source. Log lifestyle factors
  (alcohol, caffeine-late, late meal, stress, steps, meditation…) → nightly per-behavior stats vs
  next-day HRV/RHR/recovery/sleep → "alcohol cuts your recovery ~14%". Tier 1 = Mann–Whitney U +
  Cliff's δ + %median-change, gate ≥5/≥5 in 90d, surface p<0.05 (BH-FDR). Tier 2 (later) = ridge
  regression for confounder-isolated effects. Surfaces in a coach tool, the insight feed, an iOS
  Journal screen, and "Discovery" pushes.
- **A2. True-weight EWMA trend + structured goals w/ forecast** — smooth the scale's daily noise
  (Hacker's Diet EWMA α≈0.1) → slope → projected goal date + calorie-deficit translation. Goal model
  (metric, target, date). The actual fat-loss engine; tiny code, huge payoff.
- **A3. Insight feed** — 3–5 ranked cards/day (correlation, milestone/win, goal forecast, anomaly,
  guidance). Fitbit got +30% DAU from *fewer* metrics — ranking + dedupe is the point.

### PHASE B — high-value, mission-aligned
- **B1. Hydration** tracking + daily target ring + nudge.
- **B2. Fasting / eating-window** tracker (derive from meal timestamps) + streak + coach.
- **B3. Adaptive TDEE / macro targets** — estimate maintenance from intake + weight-trend slope, auto-
  adjust calories (the MacroFactor magic). Folds in the nutrition research.
- **B4. Monthly report** — extend the weekly review to a monthly assessment with month-over-month
  deltas + the month's strongest behavior correlations.

### PHASE C — deferred (hardware/scope/regulatory) — documented, not built now
Smart haptic alarm (needs band-side wake logic), mindfulness/breathwork sessions, social/teams,
barcode food scanning (needs a UPC DB), cuffless BP / ECG (needs MG-class hardware + FDA exposure),
"Pace of Aging" 30d-vs-180d (nice enhancement on existing bio-age).

## Build order & status — autonomous session 2026-06-24
- [x] **A1 — behavior journal + correlation engine** (the moat). Stats (Mann–Whitney/Cliff's δ/BH),
  BehaviorCorrelations, nightly `insights:behavior` command + Discovery push, coach `log_behavior` /
  `my_impacts`, iOS journal toggles + impacts. Tested (planted-effect detection). ✅ backend + iOS.
- [x] **A2 — true-weight EWMA trend + Goals + forecast** (the fat-loss engine). WeightTrend (α=0.10,
  interpolation, slope→ETA, kcal deficit), Goal model, coach `set_goal_weight` / `weight_progress`,
  `GET/POST /me/weight`. Tested. ✅ backend + coach + API (iOS chart = follow-up).
- [x] **A3 — insight feed** (anomaly/goal/win/correlation, ranked, capped). Coach `insights` +
  `GET /me/insights`, iOS "FOR YOU" feed on Today. Tested. ✅ backend + coach + iOS.
- [x] **B1 — hydration** (bodyweight target, log_water/hydration_today, `/me/hydration`). ✅ backend
  + coach + API (iOS ring = follow-up).
- [x] **B2 — fasting** (editable timer + metabolic stage timeline; start_fast/end_fast/fasting_status,
  `/me/fasting*`). ✅ backend + coach + API (iOS timer = follow-up).

### Remaining (clear next steps)
- iOS surfaces for weight chart, hydration ring, fasting timer (backends + endpoints are live now).
- B3 adaptive TDEE (algorithm captured in §research: `TDEE = mean_intake − Δtrend·3500/days`, 30-day
  recency-weighted) — auto-adjust the macro target from the weight trend.
- B4 monthly report (extend the weekly review + month-over-month deltas + top correlations).
- Tier-2 correlation: ridge regression for confounder-isolated effects (WHOOP-parity upgrade).
- Phase C (hardware/scope): smart haptic alarm, mindfulness/breathwork, social/teams, barcode
  scanning (Open Food Facts), CGM Zone Score (Levels), Pace-of-Aging, cuffless BP/ECG.

Every shipped item is committed green; `master` stayed deployable throughout (full suite 426 passing).

## Research source notes
Whoop journal/correlation: ≥5-yes & ≥5-no in trailing 90d gate; Tier-1 mean(yes)−mean(no); Tier-2
per-user multivariable regression, coefficient = isolated impact. Oura "Discoveries": Pearson r,
labeled weak/moderate/strong. Behavior-science: Mann–Whitney (robust to skew/outliers) + Cliff's δ;
BH-FDR for multiple comparisons; min 5/5 to show, ≥20/group for "confident". Weight: Hacker's Diet
EWMA (α≈0.1) then least-squares slope → projected date; deficit ≈ (lb/wk ÷ 7) × 3500 kcal. Habits:
Lally 66-day automaticity, a single miss doesn't break it → grace/freeze (already in Streak model).
