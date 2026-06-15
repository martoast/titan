"""Workout classification from wrist 3-axis accelerometer — shared train/inference feature
extractor + model loader, so the two can never drift.

Validated on PAMAP2 (UCI) at the Bangle's ~25 Hz rate, leave-subjects-out: workout-grouped
(rest / walk / run / cycle / stairs / other) ~88% accuracy, κ ~0.85, ACCEL-ONLY (no HR — so
it doesn't depend on the in-motion HR we measured as noisy). 5.12 s windows.

Production needs the firmware to stream 3-axis accel during workouts (today it streams raw
PPG + a scalar activity); the model + this extractor are ready for that signal.
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


def classify_window(ax, ay, az, fs: int = 25) -> Optional[dict]:
    """One window → {activity, confidence} or None if no model / too short."""
    bundle = _load()
    if bundle is None or np.size(ax) < int(WINDOW_SEC * fs * 0.5):
        return None
    X = extract_features(ax, ay, az, fs).reshape(1, -1)
    clf = bundle["model"]
    proba = clf.predict_proba(X)[0]
    i = int(np.argmax(proba))
    return {"activity": str(clf.classes_[i]), "confidence": round(float(proba[i]), 2)}
