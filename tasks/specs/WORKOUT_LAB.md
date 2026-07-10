# WORKOUT LAB — the virtual athlete that makes broken workouts impossible

**Spec for the dev agent · from Alex + Henry · 2026-07-10 · sibling of SLEEP_LAB.md**
**Status: BUILD AFTER SLEEP LAB PHASE 1 (shared harness bones — build them once).**

---

## 1 · Why

A workout is a promise with a shorter fuse than a night: the user just *did the thing* — they're
standing there sweating, looking at their wrist, waiting for us to tell them what it was worth. Every
seal bug on the workout side breaks that moment: sets attached to the wrong lift, a run split in two,
a workout terminal-sealed to nothing during a deploy, a 4-minute max-effort finisher that counts nowhere
while a stray-motion blob lights the streak.

Same law as SLEEP LAB, adapted:

> **THE FIVE PROMISES (workout edition)**
> 1. **Never lost.** A worn workout ALWAYS produces a session. Failure parks, never destroys.
> 2. **Never fabricated.** No artifact HR scoring max effort; no stray-motion blob counting as training.
> 3. **Never split or merged.** One workout is one session — through BLE drops, backlog flushes, back-to-back gym blocks.
> 4. **Sets belong to their session.** Logged sets attach to the workout they happened in — deterministically, not by proximity guessing.
> 5. **Every surface agrees.** Session detail, strain ring, streaks, badges, trends — one workout, one truth.

## 2 · Architecture — reuse SLEEP LAB's, add the athlete

```
WorkoutScript (ground truth)  →  VirtualAthlete (renders effort into wire reality)  →  REAL ingest API
                                                                                            ↓
Assertions ← sessions · route/splits/RE · TRIMP/zones · sets attachment · streaks/strain/readers
```

- **WorkoutScript** — declarative: activity type, effort blocks (`warmup 8m z1 → tempo 20m z4 →
  intervals 6×(2m z5 / 90s z2) → cooldown`), GPS track (or indoor), set-logging events with timestamps,
  device story (live stream / offline buffer / BLE drop mid-run / watch kind hint present or absent /
  explicit End tap vs auto-detect / clock drift).
- **VirtualAthlete** — extends `BiosignalSimulator` + the existing `SimulateRun`/`SimulateWorkout`
  (which already hit the real HMAC ingest API — extend, never duplicate). Renders effort blocks into
  physiologically coherent signals: HR that rises/decays with effort and drifts with fatigue, accel
  matching the modality (stride cadence for runs, burst-rest for lifts), GPS with realistic jitter,
  workout-kind windows + concurrent `ppg_raw`, and the artifact vocabulary (cadence-lock spikes,
  strap dropouts to zero, off-wrist noise) — because the artifacts are what our scars are made of.
- **Harness** — same `sleep:lab` runner, new namespace: `workout:lab [--scenario= --tz= --chaos]`.
  Same lab-profile isolation, same scorecard, same post-deploy gate.

## 3 · Calibration
`workout:lab --calibrate` extracts from real sessions (profile 1 has clean strength data: 61–74min,
TRIMP 120–294, avg HR 100–111, zones): per-type HR envelopes, TRIMP-per-minute ranges, active-epoch
densities, artifact rates. Synthetic lifts/runs must land within tolerance of real ones through the
whole pipeline — generator and classifier speaking the same language.

## 4 · Scenario library — the six open scars go here FIRST
These are confirmed review findings that kept falling off the deferral ledger. Each becomes a permanent
scenario; fixing the underlying bug is IN SCOPE for the scenario to go green:

- `sets-belong` (P4) — two lifts 30–40min apart, sets logged in both → **requires the W-4 fix: persist
  `workouts.activity_session_id` at seal/log time (migration + backfill best-effort), delete the ±20min
  proximity matcher.** Assert: each detail shows exactly its own sets, never null, never doubled.
- `strap-dropout` (P2) — 10min of zero-HR mid-lift → TRIMP/calories computed from POSITIVE samples only
  (**fix hrEpoch zero-dilution** — the avg_hr filter applied at the source, once, for all consumers).
- `backlog-flush` (P3) — offline run, out-of-order window replay → ONE session (**port the max-frontier
  fix from clusterSessions into groupIntoSessions — or better, extract the shared gap-clusterer the
  reviews asked for and delete both copies**).
- `deploy-mid-seal` (P1, chaos) — biosignal restarts during an activity seal → windows survive for
  retry (**SealActivityJob adopts the shared transient/deterministic classifier + quarantine, same as
  the sleep job**).
- `cadence-lock` (P2) — 10min of 220bpm artifact on HRmax 200 → relative effort unmoved (**fix the
  1.15×hr_max clamp: absolute ceiling shared with the 215/222 bounds; assert RE delta ≈ 0 vs the same
  run without the artifact**).
- `honest-finisher` (P2/P5) — explicit End on a 4min max-effort → counts in streak + strain
  (**training() consults the force-sealed/user-ended signal — persist it at seal time**); inverse:
  a 6min unconfirmed stray-motion 'other' blob → counts NOWHERE.

**Golden paths:** `steady-run` (route, splits ±2s/km of script, GAP, elevation, RE in envelope),
`interval-run` (work/rest structure visible in zones), `gym-lift` (kind hint, zones, TRIMP), `hike`
(long low-intensity — is a night for nobody, a workout for strain), `auto-detected` (no hint — longest
bout classifies, confidence from the SAME bout).
**Cross-domain:** `evening-workout-then-sleep` — the full day: workout seals as a workout, night seals
as a night, recovery row comes from the night only, strain from the workout only. The two pipelines'
oldest shared scar, finally rehearsed together.
All scenarios: tz matrix, both hint/no-hint variants where meaningful.

## 5 · Tolerances
Statistical: TRIMP ±15%, calories ±20%, RE ±15%, avg/max HR ±5bpm, splits ±2s/km vs script. Binary:
session count, type, set attachment, streak/strain/badge agreement, quarantine reachability, zone-clamp
artifact immunity. Green or it doesn't ship.

## 6 · Build plan
1. WorkoutScript + VirtualAthlete effort rendering + `steady-run`/`gym-lift` green end-to-end (+ calibration).
2. The six scars (with their underlying fixes) + golden paths + tz matrix.
3. Chaos mode + `evening-workout-then-sleep` + CLAUDE.md gate alongside `sleep:lab`.

*A workout is the user at their most alive. Measure it like it matters.*
