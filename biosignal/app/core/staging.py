"""Walch-style actigraphy + HR sleep staging (baseline v1).

This is a TRANSPARENT HEURISTIC, not the trained Walch model. See 03-algorithms.md §3:
honest expectations are sleep/wake ~80-90%, wake specificity ~50-70%, 4-stage κ ~0.4-0.6,
deep(N3) weakest without EEG. We lead with sleep/wake + duration (solid) and present
stages with low confidence.

Features used (Walch 2019, `ojwalch/sleep_classifiers`):
  - motion  : activity counts per 30 s epoch (the dominant sleep/wake signal)
  - HR level: heart rate relative to the night's resting floor
  - HR var  : local HR standard deviation (REM = irregular/sympathetic; NREM = stable/vagal)

TRAINED MODEL (DEFAULT): a gradient-boosted classifier over `sleep_features.extract_features`,
trained + cross-validated on REAL polysomnography (PhysioNet Walch 2019 — wrist motion + HR +
PSG stages). Leave-subjects-out it reaches sleep/wake κ≈0.46 and 4-class accuracy≈59% — i.e.
literature-grade for wrist motion+HR with no EEG (Walch 2019), tested on people it never saw.
It is the default; SLEEP_MODEL_ENABLED=0 falls back to the physiology HMM / heuristic below
(also used automatically when HR is absent). Retrain: scripts/train_real.py <data> --save.
(An earlier synthetic-trained model proved brittle on real data and was replaced — synthetic
sleep doesn't generalise; real PSG does.)
"""

from __future__ import annotations

import os
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Optional

import joblib
import numpy as np

from . import sleep_features, sleep_hmm

EPOCH_SEC = 30

# Stage codes for the 30-s hypnogram.
WAKE, LIGHT, DEEP, REM = "wake", "light", "deep", "rem"

# Trained classifier (HistGradientBoosting on motion + HR features). OPT-IN via
# SLEEP_MODEL_ENABLED — the shipped baseline is trained on SYNTHETIC data and, while it
# scores ~98% in-distribution, it's brittle to distribution shift (collapses stages on
# out-of-distribution input). So the robust relative-threshold heuristic stays the DEFAULT
# until the model is retrained on real PSG data (PhysioNet — see scripts/train_sleep_model.py).
# Flip the flag on once that model is validated.
_MODEL_PATH = Path(__file__).resolve().parent.parent / "models" / "sleep_stager.joblib"
_MODEL: Optional[dict] = None
_MODEL_TRIED = False


def _model_enabled() -> bool:
    # The real-PSG-trained model (PhysioNet Walch 2019) is the validated DEFAULT.
    # Set SLEEP_MODEL_ENABLED=0 to force the physiology HMM / heuristic instead.
    return os.getenv("SLEEP_MODEL_ENABLED", "1").strip().lower() not in ("0", "false", "off", "no")


def _load_model() -> Optional[dict]:
    global _MODEL, _MODEL_TRIED
    if not _MODEL_TRIED:
        _MODEL_TRIED = True
        if not _model_enabled():
            return None
        try:
            if _MODEL_PATH.exists():
                _MODEL = joblib.load(_MODEL_PATH)
        except Exception:
            _MODEL = None
    return _MODEL


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
    rmssd_ms: Optional[list] = None,
    start: Optional[str] = None,
    end: Optional[str] = None,
) -> dict:
    """Produce a 30-s hypnogram + summary from per-epoch accel (+ HR + RMSSD).

    Default = the cardio-respiratory HMM stager (motion + HR + per-epoch HRV, Viterbi-
    decoded over physiological transitions) — robust and untrained. The opt-in trained
    model (SLEEP_MODEL_ENABLED) and the simple threshold heuristic remain as alternatives.
    All emit a per-epoch stage list over the SAME epoch grid; we smooth + summarise identically.
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
    hr_e = _to_epochs(np.asarray(hr_bpm, dtype=float), n_epochs) if hr_bpm else np.full(n_epochs, np.nan)
    has_hr = np.isfinite(hr_e).any()
    rmssd_e = _to_epochs(_clean_floats(rmssd_ms), n_epochs) if rmssd_ms else None

    hypnogram: Optional[list[str]] = None

    # 1) Default: model trained on REAL PSG (needs HR). Cross-validated leave-subjects-out
    #    at sleep/wake κ≈0.46, 4-class acc≈59% — literature-grade for wrist motion+HR.
    bundle = _load_model()
    if bundle is not None and has_hr:
        try:
            stages = bundle.get("stages", ["wake", "light", "deep", "rem"])
            X = sleep_features.extract_features(accel_e, hr_e, has_hr=True)
            preds = bundle["model"].predict(X)
            # Model classes may be ints (real-PSG model) or strings (older artifact).
            hypnogram = [stages[int(p)] if isinstance(p, (int, np.integer)) else str(p) for p in preds]
        except Exception:
            hypnogram = None

    # 2) Fallback: physiology HMM (e.g. no HR, or model unreadable).
    if hypnogram is None:
        try:
            hypnogram = sleep_hmm.stage_hmm(accel_e, hr_e if has_hr else None, rmssd_e, n_epochs)
        except Exception:
            hypnogram = None

    # 3) Last-resort transparent heuristic.
    if hypnogram is None:
        hypnogram = _heuristic_stage(accel_e, hr_e, has_hr, n_epochs)

    hypnogram = _smooth_hypnogram(hypnogram)
    return _summarize(hypnogram, t0)


def _clean_floats(seq) -> np.ndarray:
    """[float | None] → float array with None/NaN forward-filled, for resampling."""
    arr = np.array([np.nan if v is None else float(v) for v in seq], dtype=float)
    if np.isfinite(arr).any():
        med = np.nanmedian(arr)
        arr = np.where(np.isfinite(arr), arr, med)
    else:
        arr = np.zeros_like(arr)
    return arr


def _heuristic_stage(accel_e: np.ndarray, hr_e: np.ndarray, has_hr: bool, n_epochs: int) -> list[str]:
    """Transparent Walch-style fallback (used when no trained model is present)."""
    hr_floor = np.nanpercentile(hr_e, 10) if has_hr else np.nan
    hr_var = _rolling_std(np.nan_to_num(hr_e, nan=hr_floor if has_hr else 0.0)) if has_hr else np.zeros(n_epochs)
    motion_thr = max(np.percentile(accel_e, 60), 1.0)
    hr_var_thr = np.percentile(hr_var, 70) if has_hr else 0.0

    return [
        _classify_epoch(
            motion=accel_e[i],
            motion_thr=motion_thr,
            hr=hr_e[i] if has_hr else np.nan,
            hr_floor=hr_floor,
            hr_var=hr_var[i],
            hr_var_thr=hr_var_thr,
        )
        for i in range(n_epochs)
    ]


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
