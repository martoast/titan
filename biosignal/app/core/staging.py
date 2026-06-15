"""Walch-style actigraphy + HR sleep staging (baseline v1).

This is a TRANSPARENT HEURISTIC, not the trained Walch model. See 03-algorithms.md §3:
honest expectations are sleep/wake ~80-90%, wake specificity ~50-70%, 4-stage κ ~0.4-0.6,
deep(N3) weakest without EEG. We lead with sleep/wake + duration (solid) and present
stages with low confidence.

Features used (Walch 2019, `ojwalch/sleep_classifiers`):
  - motion  : activity counts per 30 s epoch (the dominant sleep/wake signal)
  - HR level: heart rate relative to the night's resting floor
  - HR var  : local HR standard deviation (REM = irregular/sympathetic; NREM = stable/vagal)

>>> TO PLUG IN THE REAL MODEL <<<
Replace `_classify_epoch` / `stage_night` internals with a call to a joblib-loaded
`ojwalch/sleep_classifiers` logistic/MLP model trained on the PhysioNet sleep-accel
dataset (features: motion, local HR SD, circadian clock proxy). Keep this function's
signature and the returned hypnogram contract so the router/Laravel side is unchanged.
"""

from __future__ import annotations

from datetime import datetime, timedelta, timezone
from typing import Optional

import numpy as np

EPOCH_SEC = 30

# Stage codes for the 30-s hypnogram.
WAKE, LIGHT, DEEP, REM = "wake", "light", "deep", "rem"


def _to_epochs(values: np.ndarray, n_epochs: int) -> np.ndarray:
    """Resample/clip an arbitrary-length series to exactly n_epochs (nearest-bin mean)."""
    values = np.asarray(values, dtype=float).ravel()
    if values.size == 0:
        return np.zeros(n_epochs)
    if values.size == n_epochs:
        return values
    idx = np.linspace(0, values.size - 1, n_epochs)
    return np.interp(idx, np.arange(values.size), values)


def _rolling_std(x: np.ndarray, win: int = 5) -> np.ndarray:
    out = np.zeros_like(x, dtype=float)
    half = win // 2
    for i in range(len(x)):
        lo, hi = max(0, i - half), min(len(x), i + half + 1)
        out[i] = np.std(x[lo:hi]) if hi - lo > 1 else 0.0
    return out


def stage_night(
    accel_counts: list,
    hr_bpm: Optional[list] = None,
    start: Optional[str] = None,
    end: Optional[str] = None,
) -> dict:
    """Produce a 30-s hypnogram + summary from per-epoch accel counts (+ optional HR).

    accel_counts / hr_bpm are resampled to a common epoch grid spanning [start, end]
    (or, if no timestamps, one epoch per accel sample).
    """
    accel = np.asarray(accel_counts, dtype=float).ravel()
    if accel.size == 0:
        accel = np.zeros(1)

    # Determine epoch count from the time span when available, else from accel length.
    t0 = _parse_ts(start)
    t1 = _parse_ts(end)
    if t0 and t1 and t1 > t0:
        n_epochs = max(int((t1 - t0).total_seconds() // EPOCH_SEC), 1)
    else:
        n_epochs = int(accel.size)
        t0 = _parse_ts(start) or datetime.now(timezone.utc)

    accel_e = _to_epochs(accel, n_epochs)

    if hr_bpm:
        hr_e = _to_epochs(np.asarray(hr_bpm, dtype=float), n_epochs)
    else:
        hr_e = np.full(n_epochs, np.nan)

    has_hr = np.isfinite(hr_e).any()
    hr_floor = np.nanpercentile(hr_e, 10) if has_hr else np.nan
    hr_var = _rolling_std(np.nan_to_num(hr_e, nan=hr_floor if has_hr else 0.0)) if has_hr else np.zeros(n_epochs)

    # Motion threshold (Cole-Kripke-ish): scale to the night's activity distribution.
    motion_thr = max(np.percentile(accel_e, 60), 1.0)

    hypnogram: list[str] = []
    for i in range(n_epochs):
        hypnogram.append(
            _classify_epoch(
                motion=accel_e[i],
                motion_thr=motion_thr,
                hr=hr_e[i] if has_hr else np.nan,
                hr_floor=hr_floor,
                hr_var=hr_var[i],
                hr_var_thr=np.percentile(hr_var, 70) if has_hr else 0.0,
            )
        )

    hypnogram = _smooth_hypnogram(hypnogram)
    return _summarize(hypnogram, t0)


def _classify_epoch(motion, motion_thr, hr, hr_floor, hr_var, hr_var_thr) -> str:
    """Single-epoch heuristic. Motion dominates wake/sleep; HR splits the sleep stages."""
    # High motion => wake (Walch: motion is the strongest sleep/wake predictor).
    if motion >= motion_thr * 1.5:
        return WAKE

    has_hr = np.isfinite(hr) and np.isfinite(hr_floor)
    if not has_hr:
        # Accel-only: low motion => light, very still => deep (coarse).
        return DEEP if motion < motion_thr * 0.25 else LIGHT

    hr_above_floor = hr - hr_floor

    # REM: irregular HR (high local variance) near baseline, low motion.
    if hr_var >= hr_var_thr and hr_above_floor < 8:
        return REM
    # Deep: lowest, most stable HR + minimal motion.
    if hr_above_floor < 3 and motion < motion_thr * 0.4:
        return DEEP
    # Otherwise light sleep.
    return LIGHT


def _smooth_hypnogram(hyp: list[str], min_run: int = 4) -> list[str]:
    """Remove implausibly short stage runs (<2 min) by absorbing into neighbours."""
    if not hyp:
        return hyp
    out = hyp[:]
    i = 0
    while i < len(out):
        j = i
        while j < len(out) and out[j] == out[i]:
            j += 1
        run = j - i
        if run < min_run and i > 0:
            out[i:j] = [out[i - 1]] * run
        i = j
    return out


def _summarize(hyp: list[str], t0: datetime) -> dict:
    n = len(hyp)
    counts = {WAKE: 0, LIGHT: 0, DEEP: 0, REM: 0}
    for s in hyp:
        counts[s] = counts.get(s, 0) + 1

    epoch_min = EPOCH_SEC / 60.0
    deep_min = round(counts[DEEP] * epoch_min, 1)
    rem_min = round(counts[REM] * epoch_min, 1)
    light_min = round(counts[LIGHT] * epoch_min, 1)
    awake_min = round(counts[WAKE] * epoch_min, 1)
    asleep_min = deep_min + rem_min + light_min
    duration_min = round(asleep_min, 1)

    # bedtime / wake_time from first and last non-wake epoch.
    sleep_idx = [i for i, s in enumerate(hyp) if s != WAKE]
    if sleep_idx:
        bedtime = t0 + timedelta(seconds=sleep_idx[0] * EPOCH_SEC)
        wake_time = t0 + timedelta(seconds=(sleep_idx[-1] + 1) * EPOCH_SEC)
    else:
        bedtime = t0
        wake_time = t0 + timedelta(seconds=n * EPOCH_SEC)

    time_in_bed = max(asleep_min + awake_min, 1.0)
    efficiency = asleep_min / time_in_bed
    # Simple 0-100 quality: efficiency + healthy deep/REM proportions.
    deep_ratio = deep_min / max(asleep_min, 1.0)
    rem_ratio = rem_min / max(asleep_min, 1.0)
    quality = int(
        np.clip(
            100 * (0.6 * efficiency + 0.2 * min(deep_ratio / 0.18, 1.0) + 0.2 * min(rem_ratio / 0.22, 1.0)),
            0,
            100,
        )
    )

    return {
        "duration_min": duration_min,
        "deep_min": deep_min,
        "rem_min": rem_min,
        "light_min": light_min,
        "awake_min": awake_min,
        "bedtime": bedtime.isoformat(),
        "wake_time": wake_time.isoformat(),
        "quality": quality,
        "hypnogram_30s": hyp,
    }


def _parse_ts(ts: Optional[str]) -> Optional[datetime]:
    if not ts:
        return None
    try:
        return datetime.fromisoformat(ts.replace("Z", "+00:00"))
    except Exception:
        return None
