# BLOCKER: sleep:reprocess crashes on real nights — int(NaN) in the ppg HRV path (regression from 558b470)

**Reviewer:** Henry (server) · **Against:** 3b97ee5 (`sleep:reprocess`) on top of 558b470 (RMSSD fix)
**Status:** reprocess→recalibrate→green is BLOCKED. Your data is intact (crash-safe, see below).

## What happened

Ran `sleep:reprocess --profile=1 --apply` (you approved reprocessing your real history). It resealed
07-05, then **500'd on 07-06**, and on a rescoped retry **500'd immediately on 07-07** — a real, good
calibration night, not just the degenerate one:

```
{"detail":"HRV processing failed: cannot convert float NaN to integer"}   (HTTP 500)
```

So this is not a junk-data issue — it hits normal nights. The whole reprocess is blocked until fixed.

## Narrowed down

- **It's the ppg path in `process_hrv`.** routers/hrv.py:76 wraps only `process_hrv`. I reproduced:
  the **IBI path never crashes** (`ibi_ms=[...]` → clean return, NaN epochs guarded); the **ppg path**
  is where real `ppg_raw` windows go, and that's what 500s.
- **It's a regression from 558b470.** That commit changed *only* `_epoch_rmssd_ms` (+ tests). Its new
  jump-rejection (`d = d[abs(d) <= thr]`) makes an epoch return `float("nan")` **far more often** than
  the old `size < 3` check did — any epoch whose surviving successive-diffs drop below 2 now yields NaN.
- **The output guard is insufficient.** hrv.py:449 guards the serialized `epoch_rmssd`
  (`None if np.isnan(x) else round(x,1)`), but *something else* on the ppg path takes a now-more-often-NaN
  float into an `int()` (or a pydantic `int` field) without a guard. The test that shipped with 558b470
  (`test_epoch_rmssd_rejects_ppg_artifacts…`) validated the RMSSD *value* but never ran a real all-artifact
  ppg window through the full `process_hrv` → response path, so this crash slipped through.

## Repro (agent: you have the failing input — profile 1's 07-07 ppg_raw windows)

```
docker exec <biosignal> python3 -c "from app.core import hrv; hrv.process_hrv(ppg=<one 07-07 window ppg>, sample_rate_hz=25, ibi_ms=None, accel=None, want_resp=False)"
```
Feed a real 07-07 `ppg_raw` window (or synthesize a ppg whose beats peak-detect into an all-artifact
epoch). The traceback will name the exact `int()`. My synthetic attempts hit a *different* neurokit
"too few peaks" ValueError first, so it needs the real waveform to isolate.

## Fix asks

1. **Guard the int(NaN) on the ppg path** — wherever a NaN-capable epoch/metric float reaches `int()` or
   a pydantic `int` field, coerce NaN→None/0. Prime suspects: whole-night aggregation over the now-NaN-heavy
   `epoch_rmssd`, or `staging.py:366 quality = int(np.clip(...))` if stage minutes go NaN when a night is
   mostly artifact. (I couldn't pin the exact line without the real window; you can, fast.)
2. **Add the regression test that was missing** — a full `process_hrv(ppg=…)` call on an all-artifact
   window asserting a clean response (not a 500). This is the test 558b470 should have had.
3. **Make `sleep:reprocess` fault-tolerant** — one bad night currently aborts the entire run (that's why
   07-06 killed the whole batch). Catch per-night, log + skip, continue; quarantine/skip the degenerate
   07-06 (dur=7m, awake=1280m, q=0 — junk seal on the cleanup list) rather than dying on it.

## Data integrity — no rollback needed

Confirmed your 4 calibration nights are UNCHANGED (07-07…07-10 still show pre-reprocess values and their
original 13:07–13:08 `updated_at`). The reseal 500s *before* committing, so nothing partial was written.
Before-snapshot is saved server-side regardless. Once the NaN guard lands I'll re-run the reprocess.

— Henry
