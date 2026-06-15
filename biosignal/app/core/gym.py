"""Gym exercise recognition + rep counting from a wrist accelerometer.

The lifting/treadmill counterpart to activity_classify: that model (PAMAP2) covers locomotion
(walk/run/cycle/…) but never saw the gym, so a lifter needs this. Validated on MM-Fit (real
smartwatch-wrist accel, 10 exercises with rep labels), leave-WORKOUT-out at the Bangle's 25 Hz:
exercise classification 95% (κ 0.95), lift-vs-cardio 98%, rep counting MAE 0.14 / 99% within ±1
(see scripts/validate_gym.py). Reuses the shared accel feature extractor so train==infer.

Rep counting note: a rep is ONE movement cycle, so reps are counted off a SIGNED principal-axis
projection (|accel| double-counts — it peaks on both the up and down). For alternating-arm lifts
(e.g. curls done one arm at a time) the wrist correctly counts ITS arm's reps.
"""

from __future__ import annotations

from pathlib import Path
from typing import Optional

import numpy as np
from scipy.signal import butter, filtfilt

from . import activity_classify as ac  # shared extract_features + unit handling

_MODEL_PATH = Path(__file__).resolve().parent.parent / "models" / "gym_classifier.joblib"
_MODEL: Optional[dict] = None
_TRIED = False


def _load():
    global _MODEL, _TRIED
    if not _TRIED:
        _TRIED = True
        try:
            if _MODEL_PATH.exists():
                import joblib
                _MODEL = joblib.load(_MODEL_PATH)
        except Exception:
            _MODEL = None
    return _MODEL


def classify_exercise(ax, ay, az, fs: int = 25, unit: str = "ms2") -> Optional[dict]:
    """One set's 3-axis accel → {exercise, confidence, is_lift}. None if no model / too short.
    `unit` ('ms2'|'g'|'mg') is converted to the model's m/s² training units (Bangle sends 'mg')."""
    bundle = _load()
    if bundle is None or np.size(ax) < int(ac.WINDOW_SEC * fs):
        return None
    ax, ay, az = (ac._to_ms2(v, unit) for v in (ax, ay, az))
    X = ac.extract_features(ax, ay, az, fs).reshape(1, -1)
    clf = bundle["model"]
    proba = clf.predict_proba(X)[0]
    i = int(np.argmax(proba))
    ex = str(clf.classes_[i])
    return {"exercise": ex, "confidence": round(float(proba[i]), 2),
            "is_lift": ex in set(bundle.get("lift", []))}


def count_reps(ax, ay, az, fs: int = 25, unit: str = "ms2") -> int:
    """Count reps in a set from wrist accel. Projects onto the most-periodic axis (per-axis or
    principal) — a signed 1-D signal with one peak per rep — and reads the dominant rep period."""
    seg = np.column_stack([ac._to_ms2(v, unit) for v in (ax, ay, az)]).astype(float)
    n = len(seg)
    if n < int(2 * fs):
        return 0
    c = seg - seg.mean(axis=0)
    _, evecs = np.linalg.eigh(np.cov(c.T))
    candidates = [c[:, 0], c[:, 1], c[:, 2], c @ evecs[:, -1]]
    b, a = butter(2, [0.2 / (fs / 2), 1.4 / (fs / 2)], btype="band")
    lo, hi = int(fs / 1.4), int(fs / 0.2)
    best_period, best_strength = None, -1.0
    for sig in candidates:
        x = filtfilt(b, a, np.nan_to_num(sig))
        acf = np.correlate(x, x, "full")[len(x) - 1:]
        acf = acf / (acf[0] + 1e-9)
        band = acf[lo:min(hi, len(x) - 1)]
        if band.size < 3:
            continue
        k = int(np.argmax(band))
        if band[k] > best_strength:
            best_period, best_strength = lo + k, float(band[k])
    if best_period is None:
        return max(1, round(n / fs / 2))
    return int(round(n / best_period))


# --- Set segmentation + whole-workout analysis ------------------------------------------------
MIN_SET_SEC = 8.0     # a set is at least this long
MIN_REST_SEC = 4.0    # a rest gap this long splits two sets


def segment_sets(mag_motion, fs: int):
    """Find active SETS in a strength workout from the motion envelope: contiguous high-motion
    runs (the sets) separated by low-motion rest. Returns [(start_idx, end_idx), …]."""
    env = mag_motion
    if len(env) < int(MIN_SET_SEC * fs):
        return []
    # Threshold BETWEEN the rest level and the active level, so it works whether the session is
    # mostly rest (few long sets) or mostly active (many sets) — a median-relative cut breaks when
    # active dominates.
    lo, hi = np.percentile(env, 20), np.percentile(env, 85)
    thr = lo + 0.35 * (hi - lo)
    active = env > thr
    sets, i, n = [], 0, len(active)
    min_set, min_rest = int(MIN_SET_SEC * fs), int(MIN_REST_SEC * fs)
    while i < n:
        if not active[i]:
            i += 1
            continue
        j = i
        gap = 0
        while j < n and (active[j] or gap < min_rest):
            gap = 0 if active[j] else gap + 1
            j += 1
        end = j
        while end > i and not active[end - 1]:
            end -= 1
        if end - i >= min_set:
            sets.append((i, end))
        i = j
    return sets


def analyze_workout(ax, ay, az, fs: int = 25, unit: str = "ms2") -> dict:
    """A whole gym workout's 3-axis accel → detected sets (exercise + reps each) + a summary.
    This is what turns a logged lifting session into 'Squats 3×10, Shoulder Press 3×10, …'."""
    ax, ay, az = (ac._to_ms2(v, unit) for v in (ax, ay, az))
    mag = np.sqrt(ax ** 2 + ay ** 2 + az ** 2)
    w = max(1, int(0.5 * fs))
    env = np.abs(np.convolve(np.abs(np.diff(mag, prepend=mag[0])), np.ones(w) / w, mode="same"))
    out = []
    for s, e in segment_sets(env, fs):
        sub = slice(s, e)
        c = classify_exercise(ax[sub], ay[sub], az[sub], fs=fs, unit="ms2")
        if not c:
            continue
        c.update(reps=count_reps(ax[sub], ay[sub], az[sub], fs=fs, unit="ms2"),
                 start_s=round(s / fs, 1), duration_s=round((e - s) / fs, 1))
        out.append(c)
    summary: dict = {"n_sets": len(out), "total_reps": int(sum(x["reps"] for x in out)), "exercises": {}}
    for x in out:
        ex = x["exercise"]
        agg = summary["exercises"].setdefault(ex, {"sets": 0, "reps": 0, "is_lift": x["is_lift"]})
        agg["sets"] += 1
        agg["reps"] += x["reps"]
    return {"sets": out, "summary": summary}
