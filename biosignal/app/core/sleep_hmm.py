"""Cardio-respiratory HMM sleep stager — the robust DEFAULT.

Uses three per-30s-epoch signals, each SELF-NORMALISED to the night (so absolute scale /
device / person don't matter), scored against physiological stage templates, then decoded
with Viterbi over realistic stage transitions + a circadian prior. Parameters come from
sleep physiology, NOT from a fitted dataset — so it generalises without training data, and
the temporal model makes it robust to noisy single epochs.

Physiology (Trinder 2001; Stein & Pu 2012; de Zambotti 2018; Fonseca 2015):
  WAKE : high MOTION (dominant cue); high HR; low-ish HRV
  DEEP : minimal motion; lowest HR (at the night's floor); HIGHEST HRV (vagal); stable
  REM  : atonia (no motion); HR elevated / variable; LOW HRV (sympathetic)
  LIGHT: in between on every axis
  + architecture: deep dominates the first third of the night, REM the last; stages
    persist for minutes (you don't flip stage every 30 s).
"""

from __future__ import annotations

import numpy as np

STAGES = ["wake", "light", "deep", "rem"]

# Stage templates: motion in a robust log-ratio-to-night-baseline scale (~0 = the sleep
# floor, ~2.5+ = genuine wake — so ordinary sleep twitches stay near 0 and don't read as
# wake); HR and HRV self-normalised to [0,1]. Plus per-feature weights (how diagnostic).
_PROFILES = {
    #         motion  hr    hrv     w_motion w_hr  w_hrv
    "wake":  ((2.50, 0.75, 0.30), (1.5, 1.0, 0.5)),
    "light": ((0.30, 0.45, 0.50), (1.4, 1.0, 1.0)),
    "deep":  ((0.05, 0.12, 0.85), (1.4, 1.6, 2.2)),
    "rem":   ((0.05, 0.60, 0.20), (1.4, 1.6, 2.2)),
}

# Per-epoch transition probabilities (physiological architecture). Rows = from, cols = to.
_TRANS = np.array([
    # to:  wake  light  deep   rem
    [0.86, 0.12, 0.01, 0.01],   # from wake
    [0.04, 0.80, 0.10, 0.06],   # from light
    [0.01, 0.22, 0.76, 0.01],   # from deep
    [0.04, 0.22, 0.01, 0.73],   # from rem
])
_INIT = np.array([0.50, 0.40, 0.05, 0.05])  # nights start awake/light

_EMIT_GAIN = 4.0   # sharpness of the emission scoring
_TIME_BONUS = 0.6  # strength of the circadian prior (deep early, REM late)


def _norm01(x: np.ndarray) -> np.ndarray:
    """Scale to [0,1] by the night's 5th–95th percentile (robust, scale-free)."""
    x = np.asarray(x, dtype=float)
    lo, hi = np.nanpercentile(x, 5), np.nanpercentile(x, 95)
    if not np.isfinite(hi - lo) or hi - lo < 1e-9:
        return np.full(x.shape, 0.5)
    return np.clip((x - lo) / (hi - lo), 0.0, 1.0)


def stage_hmm(motion, hr, rmssd, n_epochs: int) -> list[str]:
    """motion (req) + HR + per-epoch RMSSD → most-likely 30-s stage sequence (Viterbi)."""
    # Motion as a log-ratio to the night's robust baseline (median ≈ the sleep floor):
    # ~0 for ordinary sleep (incl. twitches), large only for genuine sustained movement.
    mot = _fit(np.maximum(np.asarray(motion, float), 0.0), n_epochs)
    base = float(np.median(mot)) + 1.0
    m = np.clip((np.log1p(mot) - np.log1p(base)) / 2.0, 0.0, 3.0)

    has_hr = hr is not None and np.isfinite(np.asarray(hr, float)).any()
    h = _fit(_norm01(np.nan_to_num(np.asarray(hr, float), nan=np.nan)), n_epochs) if has_hr else np.full(n_epochs, 0.5)

    has_hrv = rmssd is not None and np.isfinite(np.asarray(rmssd, float)).any()
    if has_hrv:
        r = np.asarray(rmssd, float)
        r = _fit(_norm01(np.nan_to_num(r, nan=np.nanmedian(r))), n_epochs)
    else:
        r = None

    tfrac = np.linspace(0.0, 1.0, n_epochs)

    # Emission log-likelihoods (n_epochs, 4).
    emit = np.zeros((n_epochs, 4))
    for j, st in enumerate(STAGES):
        (pm, ph, pr), (wm, wh, wr) = _PROFILES[st]
        d = wm * (m - pm) ** 2 + wh * (h - ph) ** 2
        if r is not None:
            d = d + wr * (r - pr) ** 2
        emit[:, j] = -_EMIT_GAIN * d
    # Circadian prior: nudge deep early, REM late.
    emit[:, 2] += _TIME_BONUS * (0.5 - tfrac)
    emit[:, 3] += _TIME_BONUS * (tfrac - 0.5)

    return _viterbi(emit)


def _fit(x: np.ndarray, n: int) -> np.ndarray:
    x = np.asarray(x, float).ravel()
    if x.size == n:
        return x
    if x.size == 0:
        return np.full(n, 0.5)
    return np.interp(np.linspace(0, x.size - 1, n), np.arange(x.size), x)


def _viterbi(emit: np.ndarray) -> list[str]:
    n, k = emit.shape
    log_t = np.log(_TRANS + 1e-12)
    delta = np.log(_INIT + 1e-12) + emit[0]
    back = np.zeros((n, k), dtype=int)
    for i in range(1, n):
        scores = delta[:, None] + log_t  # (from, to)
        back[i] = np.argmax(scores, axis=0)
        delta = np.max(scores, axis=0) + emit[i]
    path = [int(np.argmax(delta))]
    for i in range(n - 1, 0, -1):
        path.append(int(back[i][path[-1]]))
    return [STAGES[s] for s in reversed(path)]
