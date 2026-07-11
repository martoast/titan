# Reprocess pass 2: RMSSD fix works, but a SECOND int(NaN) (neurokit) still blocks 07-07

**Reviewer:** Henry (server) · **Against:** 1ee0587 (int(NaN) Kubios guard + reprocess fault-tolerance)

## Good news — two things landed

1. **Fault-tolerance works.** The reprocess no longer aborts on one bad night — it skipped the
   failures and resealed the rest: **07-05, 07-08, 07-09, 07-10 ✓**; 07-06, 07-07 skipped.
2. **The RMSSD fix works on the nights that resealed.** 07-08 `epoch_rmssd` went from pinned-250 to
   **real and varied: min 44, p50 118, max 185**. The calibration is no longer flat — per-stage rmssd
   now separates: `deep 123.9 · rem 140.1 · wake 155.8 · light 175.3` (was all ≈248).
   Your sleep *stages* are unchanged (stager keys on HR+motion, not rmssd) — history intact, HRV corrected.

## Still blocked — a SECOND, different int(NaN), inside neurokit

07-06 and 07-07 still 500 with `cannot convert float NaN to integer` — but this is **not** the bug you
fixed in 1ee0587. I pinned it by running all 175 of 07-07's windows through `process_hrv`: exactly
**one window (id 1433, 2026-07-07 04:48)** crashes, and it's deep inside neurokit2's quality step, not
our RMSSD code:

```
File "/srv/app/core/hrv.py", line 206, in ppg_to_ibi
    signals, info = nk.ppg_process(ppg, sampling_rate=proc_rate)
  ...
File ".../neurokit2/epochs/epochs_create.py", line 167, in epochs_create
    epoch_max_duration = int(max(i * sampling_rate for i in parameters["duration"]))
ValueError: cannot convert float NaN to integer
```

A pathological ppg window (near-flat / too few detectable cycles at 15 Hz) makes neurokit's
template-match quality assessment produce a NaN cycle duration, and its own `int()` blows up. One bad
window out of 175 aborts the whole night's reseal.

### Fix ask

Wrap `nk.ppg_process` (hrv.py:206) in try/except — on failure, treat that window as low-quality
(quality_mean=0, no epochs / skip) and continue, rather than letting the exception 500 the request.
This is a **library-level** failure on degenerate input; guard at the call site. (Reproduce with
window 1433: `hrv.process_hrv(ppg=<1433 ppg>, sample_rate_hz=15, ...)` → the traceback above.)

## Can't judge REM tuning yet — calibration is contaminated

The acceptance actually regressed (`rem 9% vs 27%`, was 15%), **but don't chase that number yet** — the
calibration is built from a MIX: 07-08/09/10 real rmssd + 07-07 still pinned-250 (it never resealed).
The per-stage rmssd is averaging fixed and unfixed nights, so REM's signature is half-corrupted.

**Correct sequence before we tune REM:**
1. Fix the neurokit crash above → 07-07 (and ideally 07-06) can reseal.
2. Re-run `sleep:reprocess --profile=1 --apply` → all 4 calibration nights on consistent, corrected rmssd.
3. `sleep:lab --calibrate` → *then* read the REM/light split honestly.

## One flag for later (not blocking)

The corrected per-epoch rmssd (124–185ms) is still ~2× physiological (real overnight RMSSD ~20–100ms).
Likely the 15 Hz ppg sample rate: at 15 Hz one sample ≈ 66 ms, so IBI quantization alone inflates the
successive differences. The whole-night *recovery* rmssd is Kubios-corrected and fine; only this
per-epoch/staging path carries the coarse-rate noise. Relative separation may still be enough for
staging — we'll see after step 3 — but if REM won't separate cleanly, the 15 Hz quantization is the
next suspect.

— Henry
