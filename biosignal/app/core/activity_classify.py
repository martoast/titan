"""Workout classification from wrist 3-axis accelerometer — shared train/inference feature
extractor + model loader, so the two can never drift.

Validated on PAMAP2 (UCI) at the Bangle's ~25 Hz rate, leave-subjects-out: workout-grouped
(rest / walk / run / cycle / stairs / other) ~88% accuracy, κ ~0.85, ACCEL-ONLY (no HR — so
it doesn't depend on the in-motion HR we measured as noisy). 5.12 s windows.

Wired into the activity pipeline: /process/activity accepts an optional raw 3-axis accel
stream (`accel_xyz`, the wrist's live/T1 frames) and annotates each detected session with its
workout type. The firmware streams 3-axis accel at 25 Hz while connected (the live/workout
path) to match this model's validated rate; overnight it stays at 12.5 Hz (PPG + actigraphy
scalar) for power, so workout classification runs on the live path, not the overnight log.
"""

from __future__ import annotations

from pathlib import Path
from typing import Optional

import joblib
import numpy as np
from scipy.signal import welch

WINDOW_SEC = 5.12
GROUPS = ["rest", "walk", "run", "cycle", "stairs", "other"]
_MODEL_PATH = Path(__file__).resolve().parent.parent / "models" / "activity_classifier.joblib"
_MODEL: Optional[dict] = None
_TRIED = False

# The model trains on PAMAP2, whose accel is in m/s² (gravity ≈ 9.8). The amplitude
# features (std, IQR, jerk, band energy) are SCALE-DEPENDENT, so any incoming accel must
# be converted to m/s² first or predictions are garbage. The Bangle streams milli-g.
_G = 9.80665
_UNIT_TO_MS2 = {"ms2": 1.0, "g": _G, "mg": _G / 1000.0}


def _to_ms2(a, unit: str) -> np.ndarray:
    """Convert an accel axis to m/s² (the model's training units)."""
    try:
        k = _UNIT_TO_MS2[unit]
    except KeyError:
        # A genuine bad payload (the client sent an unknown unit) → deterministic 422, not a retry-forever 5xx.
        from .errors import DataFaultError

        raise DataFaultError(f"unknown accel unit {unit!r}; expected one of {list(_UNIT_TO_MS2)}")
    return np.asarray(a, dtype=float) * k


def _corr(a, b):
    if a.std() < 1e-9 or b.std() < 1e-9:
        return 0.0
    return float(np.corrcoef(a, b)[0, 1])


def extract_features(ax, ay, az, fs: int = 25) -> np.ndarray:
    """One 3-axis accel window → feature vector (accel-only, scale/rate-robust)."""
    ax, ay, az = (np.nan_to_num(np.asarray(v, dtype=float)) for v in (ax, ay, az))
    mag = np.sqrt(ax ** 2 + ay ** 2 + az ** 2)
    cols = []
    for ch in (ax, ay, az, mag):
        cols += [ch.mean(), ch.std(), ch.min(), ch.max(),
                 np.mean(np.abs(np.diff(ch))) if ch.size > 1 else 0.0,
                 np.percentile(ch, 75) - np.percentile(ch, 25)]
    cols += [_corr(ax, ay), _corr(ax, az), _corr(ay, az)]
    f, p = welch(mag - mag.mean(), fs=fs, nperseg=min(len(mag), 256))
    p = p + 1e-12
    cols += [f[np.argmax(p)], p[(f >= 0.5) & (f < 3)].sum(), p[(f >= 3) & (f < 8)].sum(),
             float(-np.sum((p / p.sum()) * np.log(p / p.sum())))]
    return np.nan_to_num(np.array(cols, dtype=float))


def _load():
    global _MODEL, _TRIED
    if not _TRIED:
        _TRIED = True
        try:
            if _MODEL_PATH.exists():
                _MODEL = joblib.load(_MODEL_PATH)
        except Exception:
            _MODEL = None
    return _MODEL


def classify_window(ax, ay, az, fs: int = 25, unit: str = "ms2") -> Optional[dict]:
    """One window → {activity, confidence} or None if no model / too short.

    `unit` is the unit of the incoming accel ('ms2', 'g', or 'mg'); it's converted to the
    model's m/s² training units before feature extraction. The Bangle sends 'mg' (milli-g).
    """
    bundle = _load()
    if bundle is None or np.size(ax) < int(WINDOW_SEC * fs * 0.5):
        return None
    ax, ay, az = (_to_ms2(v, unit) for v in (ax, ay, az))
    X = extract_features(ax, ay, az, fs).reshape(1, -1)
    clf = bundle["model"]
    proba = clf.predict_proba(X)[0]
    i = int(np.argmax(proba))
    return {"activity": str(clf.classes_[i]), "confidence": round(float(proba[i]), 2)}


def classify_stream(ax, ay, az, fs: int = 25, unit: str = "ms2", overlap: float = 0.5):
    """Slide the validated window over a raw 3-axis accel stream → a list of per-window
    {offset_s, activity, confidence}. This is the wrist's live (T1) accel → activity timeline.

    Returns [] if there's no model or the stream is shorter than one window.
    """
    bundle = _load()
    win = int(WINDOW_SEC * fs)
    if bundle is None or np.size(ax) < win:
        return []
    ax, ay, az = (_to_ms2(v, unit) for v in (ax, ay, az))
    clf = bundle["model"]
    step = max(1, int(win * (1 - overlap)))
    out = []
    for s in range(0, len(ax) - win + 1, step):
        X = extract_features(ax[s:s + win], ay[s:s + win], az[s:s + win], fs).reshape(1, -1)
        proba = clf.predict_proba(X)[0]
        i = int(np.argmax(proba))
        out.append({"offset_s": round(s / fs, 1),
                    "activity": str(clf.classes_[i]),
                    "confidence": round(float(proba[i]), 2)})
    return out


def dominant_activity(ax, ay, az, fs: int = 25, unit: str = "ms2") -> Optional[dict]:
    """Majority-vote the activity over a stream → {activity, confidence, fractions}.

    `confidence` is the mean confidence of the winning class's windows; `fractions` is the
    share of windows per activity (so a caller can see e.g. a run with walk warm-up). None
    if the stream is too short / no model.
    """
    wins = classify_stream(ax, ay, az, fs=fs, unit=unit)
    if not wins:
        return None
    n = len(wins)
    fractions: dict = {}
    confs: dict = {}
    for w in wins:
        a = w["activity"]
        fractions[a] = fractions.get(a, 0) + 1
        confs.setdefault(a, []).append(w["confidence"])
    top = max(fractions, key=lambda a: (fractions[a], sum(confs[a])))
    return {
        "activity": top,
        "confidence": round(float(np.mean(confs[top])), 2),
        "fractions": {a: round(c / n, 2) for a, c in sorted(fractions.items(), key=lambda kv: -kv[1])},
    }
