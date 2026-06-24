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

## Build order & status
- [ ] A1 backend — journal (BehaviorLog + catalog + coach tool)
- [ ] A1 backend — correlation engine (Stats + DailyOutcomes + BehaviorImpact + nightly job + tool + Discovery push)
- [ ] A1 iOS — Journal + Impacts
- [ ] A2 — weight EWMA trend + Goals + forecast (backend + coach + iOS)
- [ ] A3 — insight feed (backend + coach + iOS)
- [ ] B1 hydration · B2 fasting · B3 adaptive TDEE · B4 monthly report

Each item ships fully (backend + tests + iOS where relevant) and is committed green so `master`
stays deployable throughout.

## Research source notes
Whoop journal/correlation: ≥5-yes & ≥5-no in trailing 90d gate; Tier-1 mean(yes)−mean(no); Tier-2
per-user multivariable regression, coefficient = isolated impact. Oura "Discoveries": Pearson r,
labeled weak/moderate/strong. Behavior-science: Mann–Whitney (robust to skew/outliers) + Cliff's δ;
BH-FDR for multiple comparisons; min 5/5 to show, ≥20/group for "confident". Weight: Hacker's Diet
EWMA (α≈0.1) then least-squares slope → projected date; deficit ≈ (lb/wk ÷ 7) × 3500 kcal. Habits:
Lally 66-day automaticity, a single miss doesn't break it → grace/freeze (already in Streak model).
