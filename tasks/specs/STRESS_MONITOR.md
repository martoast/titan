# STRESS MONITOR — real-time stress from the signal we already stream

**Spec for the dev agent · from Alex + Henry · 2026-07-11 (refreshed 2026-07-12) · Whoop-parity**
**Status: BUILD NOW — Alex's chosen next feature. Highest-value gap with NO new hardware.**

## ⚡ Current-state update (2026-07-12) — the ground this now stands on is PROVEN
Since this spec was first written, the exact infrastructure it depends on shipped and was verified on
Alex's real data — so v1 is now mostly wiring, not new plumbing:
- **`hr_samples`** (T5 HR trend) — continuous all-day on-chip HR, populating and verified. This is the
  stress monitor's primary daytime input; no new firmware needed for v1.
- **`motion_samples`** (T10 dense motion) — dense per-epoch movement, proven (~full-night coverage on a
  real night). Use it for the motion-gate (reject exercise as stress) AND as the template for the new
  `stress_samples` table (same `insertOrIgnore` + **app-tz `recorded_at`** convention — that tz detail is
  load-bearing; the T10 seal already paid for that lesson).
- **Coach v2 widgets are LIVE** (P2 shipped) — the `stress_now` card must be a first-class native widget in
  that existing registry (not the generic key/value fallback), same as the sleep/strain cards.
- **Coach trajectory digest is LIVE** (P1) — add the stress line to it (`CoachTrajectory`), and mirror the
  `strain_status` tool for a `stress_status` tool.
- **LAB fidelity gate exists** — add a stress-monitor acceptance the same way: a scripted high-HR-while-
  STILL window must read as stress; a high-HR-while-MOVING window must NOT (the one correctness invariant).

Build v1 from `hr_samples` + `motion_samples` + the existing baseline machinery; the firmware daytime-HRV
micro-burst (§1 v2) is a later sharpening, not a blocker. Everything below still holds.

## The idea

Whoop's Stress Monitor reads HR + HRV in the moment against your 14-day baseline → a 0–3 stress score,
and offers a guided **physiological sigh** (calm down) or **cyclic breathing** (wake up). It's their most
loved daily-touch feature. **We can build the whole thing on data we already produce** — HR is streamed
continuously (T5), HRV comes from PPG bursts, and we already have baseline + confidence machinery. No
sensor gap. This is analytics + a beautiful breathing UX.

## What exists to build on

- **Live HRV path** — `spot_reading` (`CoachTools.php:162`, `ReactToSpotReading.php`) already commands a
  ~60s band capture → server HRV → a `spot` card. The band supports on-demand bursts.
- **Continuous HR** — the band streams on-chip HR (T5) all day; overnight it duty-cycles clean HRV bursts.
- **Baselines + confidence** — `RecoveryMetrics`, `RecoveryConfidence`, `Readiness` already compute
  personal HRV/RHR baselines with data-sufficiency gating. Reuse them; don't reinvent.
- **RecoveryLog** currently stores only a SUBJECTIVE 1–10 stress (`RecoveryLog.php:20`) — the objective
  monitor is what's missing.

## The build

### 1 · A stress score (0–3, Whoop-style), computed server-side
`app/Support/StressMonitor.php` → `assess(Profile, window)`:
- Inputs: current HR vs resting baseline, current HRV vs 14-day baseline, motion (to reject
  exercise-driven HR as "stress"). Stress rises when HR is elevated AND HRV is suppressed AND the user
  is NOT active (high HR + low motion + low HRV = physiological stress, not a workout).
- Output: continuous 0–3 (calm / low / medium / high) + confidence (reuse `RecoveryConfidence` — never a
  hard number on a thin baseline) + the drivers ("HR 78 vs 61 rest, HRV 41 vs 62").
- **Day-strip**: persist periodic samples so the app can render a stress-over-day curve (like the sleep
  movement strip). Store in a lightweight `stress_samples` table (mirror `motion_samples` from the T10
  work — same insertOrIgnore + app-tz `recorded_at` convention; DON'T repeat the tz trap the T10 seal
  hit). Sampled from the T5 HR trend + opportunistic HRV bursts; no new firmware REQUIRED for v1
  (derive from HR-vs-baseline + motion), but v2 can add a light daytime HRV micro-burst cadence for
  sharper reads (firmware follow-up, battery-checked like the sleep duty).

### 2 · Guided breathing interventions (the delightful half)
A native iOS breathing screen the stress card deep-links into:
- **Physiological sigh** (down-regulate): double-inhale through nose, long exhale — an animated expanding/
  contracting orb paces it, ~1–3 min. Offer when stress is medium/high.
- **Cyclic / box breathing** (up-regulate alertness) — offered when they want energy.
- Optional: fire a band **buzz** pattern to pace the breath on-wrist (reuse the existing `buzz_band`
  command path) so they can do it screen-free.
- Log the session; re-read HRV after (a `spot_reading`) to show "your HRV came up 8ms" — the loop that
  makes it feel real. Whoop does exactly this.

### 3 · Surfaces
- **Live stress card** (`titan-card type:"stress_now"`) — the 0–3 gauge + drivers + a "Take a minute to
  breathe" CTA. Build it as a first-class widget in the COACH v2 P2 widget pass (don't let it degrade to
  the generic key/value fallback).
- **Day view**: a stress-over-day strip on the dashboard/recovery screen.
- **Coach integration**: a `stress_status` tool (mirror `strain_status`) so the coach can read and lead
  with it ("your stress has been running high since 2pm — want to do a physiological sigh?"), and the
  Phase-1 trajectory digest gains a stress line.
- **Proactive nudge** (rate-limited): if stress holds high for a sustained window during waking hours,
  offer the breathing intervention — the real-time interruption is the point.

## Acceptance
- StressMonitor produces a confidence-gated 0–3 that RISES on high-HR + low-HRV + low-motion and does NOT
  flag a workout as stress; verify against a synthetic elevated-HR-while-moving case (not stress) vs
  elevated-HR-while-still (stress).
- The day strip renders from `stress_samples`; a breathing session logs and a follow-up HRV read attaches.
- Stress card renders as a real widget; `stress_status` tool + trajectory line present; nudge rate-limited.

## Notes
- Keep the honesty ethos: no stress number without baseline confidence; say "still learning your calm
  baseline" early on.
- Do NOT double-count exercise. Motion-gating is the load-bearing correctness detail — a run must never
  read as a panic attack.

*We already measure the two signals stress is made of. This is turning them into a number you can act on,
and a 90-second way to change it.*
