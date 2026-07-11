# LAB PIPELINE FIDELITY — make the simulator produce what the watch actually produces

**Spec for the dev agent · from Alex + Henry · 2026-07-11**
**Status: BUILD. The meta-fix: every "passed in sim, failed on the watch" bug this session traces here.**

## Why (Alex's insight, proven)

The LAB simulates PHYSIOLOGY (stages, sessions) but not the WIRE/FRAME/INGESTION pipeline with the real
watch's characteristics. So fixes validated in the LAB kept breaking on real data (the RMSSD saturation,
the REM over-staging from HR jitter, the dense-motion channel, empty timelines). Now that the reflashed
watch has PROVEN what it produces, we can make the sim faithful. **Ground truth:
`docs/DATA_PIPELINE_REFERENCE.md`** (frame formats + the measured real fingerprint). Build to match it.

## Part A — `lab:pipeline-reference` command (the reusable "grasp the structure" tool)
An artisan command that introspects a REAL profile's ingested data and emits the canonical reference +
fixtures, so the ground truth is reproducible and stays current as data grows:
- For a `--profile`, dump: every `device_ingestions.kind` seen, the `result_refs` key schema per kind,
  window length distribution, epochs-per-window, and the statistical fingerprint from
  `DATA_PIPELINE_REFERENCE.md §4` (HR jitter median/p90 |ΔHR|, epoch_hr/rmssd/motion ranges, T10
  motion_samples range+coverage, bursts/night). Plus `hr_samples`/`motion_samples`/`activity_sessions`
  shapes.
- Output: a JSON reference file (`biosignal/tests/fixtures/pipeline_reference.json`) AND a few real-window
  fixtures per kind (already have `real_nights_profile1.json`; add real ppg_raw windows, a real T10 motion
  series, a real workout). These are the LAB's acceptance targets.
- Re-runnable so the reference tracks reality (e.g. after more nights, workouts, a real GPS run).

## Part B — make VirtualBand / VirtualAthlete faithful
Fix the divergences in `app/Services/Lab/` (`VirtualBand.php`, `VirtualAthlete.php`,
`NightScript.php`, `WorkoutScript.php`) per `DATA_PIPELINE_REFERENCE.md §5`:
1. **Stream `ppg_raw`, not `ibi`.** Overnight default → the real `T2→ppg_raw` shape (server peak-detects);
   drop the synthetic `ibi`+`confidence` window the real band never emits. Keep a connected variant that
   carries `accel_mag_cg` (the T1 path) so the live-sleep motion proxy is exercised too.
2. **Emit the missing summary channels** so the canonical tables populate:
   - `hr_trend` (T5) → `hr_samples` (1 pt/min, median bpm + conf).
   - `motion_trend` (T10) → `motion_samples` (1 pt/epoch, milli-g EMA scale ~14-199). THIS is what makes a
     LAB night have a real dense movement strip + lets it exercise the dense-motion stager path.
   - `activity` (T8 steps) → `daily_activity`. Optional: T4 GPS-only, T7 floors.
3. **Inject the REAL HR jitter.** Overlay the measured median |ΔHR|~7 / p90~18 noise (+ occasional spikes)
   on the physiological HR so the sim reproduces the staging behavior of a real night. A clean-HR sim can
   never catch an HR-noise bug. Make jitter a knob (0 = idealized, "real" = measured profile).
4. **Model both motion scales** — the epoch_motion proxy (~1-3 np.std) AND the T10 milli-g EMA (~14-199),
   at the real coverages (proxy ~15-30%, T10 ~80%).
5. **Provenance parity:** workout `src:"banglejs2"` (not "simulator"); optionally content-derived batch uid.
6. **Duty-cycle realism:** ~29 s bursts, 1 epoch each, ~100-190 bursts/night — not a dense continuous stream.

## Part C — acceptance: the sim must be statistically indistinguishable from the watch
Extend SLEEP LAB / WORKOUT LAB acceptance with a **fidelity gate**: render a LAB night, then assert its
ingested data matches `pipeline_reference.json` within tolerance —
- same `device_ingestions.kind`s produced; `result_refs` schema matches per kind;
- HR-jitter distribution (median/p90 |ΔHR|) within tolerance of real;
- motion channels present at the right scale + coverage (proxy AND T10);
- `hr_samples`/`motion_samples`/`sleep_logs.hr_series`/`motion_series` all populate (non-empty strips);
- a LAB night sealed end-to-end reproduces a plausible stage split (ties to the staging fixes/retrain).
This gate is the thing that would have caught every sim-vs-real gap this session.

## Sequence
Part A first (the reference is the target). Then B (make the sim match). Then C (lock it with the gate).
Pairs with RETRAIN_SLEEP_STAGER (the retrain wants real-fingerprint inputs) and DENSE_MOTION_TO_STAGER
(the LAB must emit T10 to test it).

*The LAB's whole value is catching bugs before they reach Alex's wrist. It can only do that if it lies as
little as possible about what the wrist actually sends. Make the sim produce the real fingerprint, and gate
on it.*
