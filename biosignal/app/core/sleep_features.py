"""Per-epoch sleep-staging features — shared by training and inference so they can never
drift. Walch-style families validated on the PhysioNet sleep-accel PSG dataset: motion
(+ context), HR level/variability/dynamics, and a circadian clock.

The SAME extract_features() is used to train app/models/sleep_stager.joblib and to run the
live stager, so the model sees identical inputs in the lab and on the wrist.
"""

from __future__ import annotations

import numpy as np

# Names are illustrative; what matters is that train + inference call extract_features().
FEATURE_NAMES = ["activity+context", "hr_level/var/dynamics", "stillness", "circadian"]


def _roll(x, w, fn):
    out = np.zeros_like(x, dtype=float)
    h = w // 2
    for i in range(len(x)):
        out[i] = fn(x[max(0, i - h):min(len(x), i + h + 1)])
    return out


def _pctrank(x):
    return np.argsort(np.argsort(x)) / max(1, len(x) - 1)


def _runlen(b):
    out = np.zeros(len(b)); c = 0.0
    for i, v in enumerate(b):
        c = c + 1 if v > 0 else 0.0
        out[i] = c
    return out


def extract_features(activity, hr, has_hr: bool = True) -> np.ndarray:
    """(per-epoch activity, per-epoch HR) → feature matrix (n_epochs, n_features).

    has_hr is accepted for signature compatibility; the model requires HR, so callers
    without HR should use the heuristic/HMM fallback instead.
    """
    activity = np.asarray(activity, dtype=float).ravel()
    n = max(1, activity.size)
    if activity.size == 0:
        activity = np.zeros(1)
    hr = np.asarray(hr, dtype=float).ravel() if has_hr and hr is not None else np.full(n, 60.0)
    if hr.size != n:
        hr = np.interp(np.linspace(0, max(hr.size - 1, 0), n), np.arange(hr.size), hr) if hr.size else np.full(n, 60.0)

    a = np.log1p(np.maximum(activity, 0))
    cols = [a]
    for w in (3, 5, 9, 15, 31):
        cols.append(_roll(a, w, np.mean))
    cols.append(_roll(a, 9, np.max))
    cols.append((a <= np.percentile(a, 35)).astype(float))   # "still" indicator

    floor = np.percentile(hr, 5)
    cols.append(hr - floor)
    for w in (3, 9, 15, 31):
        cols.append(_roll(hr, w, np.mean) - floor)
    for w in (5, 11, 21):                                     # multi-scale HR variability (REM ↑)
        cols.append(_roll(hr, w, np.std))
    cols.append(np.gradient(hr))                              # HR derivative
    cols.append(np.gradient(np.gradient(hr)))                # HR acceleration
    cols.append(hr - _roll(hr, 31, np.mean))                 # detrended HR — REM surges
    cols.append(_pctrank(hr))                                # HR rank within the night
    cols.append(_roll(hr, 21, np.min) - floor)               # rolling-min HR (deep ≈ floor)

    still = (a <= np.percentile(a, 40)).astype(float)
    cols.append(_runlen(still))                              # consecutive still epochs (depth)
    cols.append(np.cumsum(still) / (np.arange(n) + 1))       # cumulative sleep pressure

    t = np.linspace(0.0, 1.0, n)
    cols += [t, np.cos(2 * np.pi * t), np.cos(np.pi * t)]     # circadian (deep early, REM late)
    return np.nan_to_num(np.column_stack(cols))
