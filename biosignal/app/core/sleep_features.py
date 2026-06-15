"""Per-epoch sleep-staging features — shared by training and inference so the two can
never drift. Mirrors the feature families ojwalch/sleep_classifiers found predictive on
the PhysioNet sleep-accel dataset: motion (+ local context), heart rate relative to the
night's floor, local HR variability, and a circadian/clock proxy.

Keeping this in one place means the trained model and the live stager extract IDENTICAL
features from a whole-night (accel_counts, hr_bpm) pair.
"""

from __future__ import annotations

import numpy as np

FEATURE_NAMES = [
    "motion",        # per-epoch activity, normalised to the night
    "motion_roll",   # rolling mean motion (movement context)
    "motion_max",    # rolling max motion (recent movement burst)
    "hr_above_floor",  # HR above the night's resting floor
    "hr_var",        # local HR standard deviation (REM = irregular)
    "time_norm",     # position in the night, 0..1 (circadian proxy)
]


def _roll(x: np.ndarray, win: int, fn) -> np.ndarray:
    out = np.zeros_like(x, dtype=float)
    half = win // 2
    for i in range(len(x)):
        lo, hi = max(0, i - half), min(len(x), i + half + 1)
        out[i] = fn(x[lo:hi]) if hi > lo else 0.0
    return out


def extract_features(accel_e: np.ndarray, hr_e: np.ndarray | None, has_hr: bool) -> np.ndarray:
    """(per-epoch accel, per-epoch HR) → feature matrix (n_epochs, len(FEATURE_NAMES))."""
    accel_e = np.asarray(accel_e, dtype=float).ravel()
    n = max(1, accel_e.size)
    if accel_e.size == 0:
        accel_e = np.zeros(1)

    # Robust per-night motion normalisation (relative — absolute scale is device-specific).
    m_norm = accel_e / (np.percentile(accel_e, 95) + 1e-9)
    motion_roll = _roll(m_norm, 5, np.mean)
    motion_max = _roll(m_norm, 5, np.max)

    if has_hr and hr_e is not None and np.size(hr_e):
        hr = np.asarray(hr_e, dtype=float).ravel()
        floor = np.nanpercentile(hr, 10)
        hr = np.nan_to_num(hr, nan=floor)
        hr_above = hr - floor
        hr_var = _roll(hr, 5, lambda s: np.std(s) if s.size > 1 else 0.0)
    else:
        hr_above = np.zeros(n)
        hr_var = np.zeros(n)

    time_norm = np.linspace(0.0, 1.0, n)

    X = np.column_stack([m_norm, motion_roll, motion_max, hr_above, hr_var, time_norm])
    return np.nan_to_num(X, nan=0.0, posinf=0.0, neginf=0.0)
