"""Synthetic biosignal fixtures for tests and sample payloads.

The overnight IBI generator produces a physiologically plausible ~480-min night:
  - mean RR ~1000 ms (HR ~60 bpm), slow circadian drift,
  - respiratory sinus arrhythmia (RSA) — the beat-to-beat oscillation RMSSD measures,
  - a few injected artifacts (ectopic-like short/long beats) to exercise the Kubios fix.

Target: a sane overnight RMSSD in a healthy adult range (~25-70 ms).
"""

from __future__ import annotations

import numpy as np


def synthetic_overnight_ibi(
    minutes: int = 480,
    mean_rr_ms: float = 1000.0,
    rsa_amplitude_ms: float = 35.0,
    seed: int = 42,
    artifact_rate: float = 0.005,
) -> list[float]:
    """Generate a realistic overnight RR/IBI series (ms).

    RMSSD is driven primarily by the RSA oscillation + small white noise. With these
    defaults the series lands around RMSSD ~30-45 ms (typical healthy overnight).
    """
    rng = np.random.default_rng(seed)

    # Estimate beat count from mean HR over the duration.
    approx_beats = int(minutes * 60 * 1000 / mean_rr_ms)
    t = np.arange(approx_beats)

    # Circadian / ultradian slow drift in mean RR (+/- 60 ms over the night).
    drift = 60.0 * np.sin(2 * np.pi * t / approx_beats) + 25.0 * np.sin(
        2 * np.pi * t / (approx_beats / 5)
    )

    # Respiratory sinus arrhythmia: ~0.25 Hz breathing => period ~ (0.25*RR) beats.
    resp_period_beats = max(int((1.0 / 0.25) / (mean_rr_ms / 1000.0)), 2)
    rsa = rsa_amplitude_ms * np.sin(2 * np.pi * t / resp_period_beats)

    # Small beat-to-beat white noise (vagal + measurement).
    noise = rng.normal(0, 8.0, size=approx_beats)

    rr = mean_rr_ms + drift + rsa + noise

    # Inject sparse artifacts: ectopic-like (short then compensatory long) + dropouts.
    n_artifacts = int(approx_beats * artifact_rate)
    art_idx = rng.choice(np.arange(2, approx_beats - 2), size=n_artifacts, replace=False)
    for i in art_idx:
        if rng.random() < 0.5:
            rr[i] *= 0.55          # premature beat (short)
            rr[i + 1] *= 1.45      # compensatory pause (long)
        else:
            rr[i] *= 2.1           # missed beat (very long; >2000ms => rejected)

    return [round(float(x), 1) for x in rr]


def synthetic_overnight_accel(n_epochs: int = 960, seed: int = 7) -> list[float]:
    """~8 h of 30-s epochs (960) of mostly-quiet sleep accel counts with brief arousals."""
    rng = np.random.default_rng(seed)
    base = rng.poisson(0.4, size=n_epochs).astype(float)  # mostly still
    # A few brief awakenings / position changes.
    for _ in range(6):
        i = rng.integers(0, n_epochs - 3)
        base[i : i + 3] += rng.integers(8, 20)
    return [float(x) for x in base]


def synthetic_overnight_hr(n_epochs: int = 960, seed: int = 11) -> list[float]:
    """Per-epoch HR (bpm) loosely anti-correlated with accel; REM-like bursts of variance."""
    rng = np.random.default_rng(seed)
    t = np.arange(n_epochs)
    base = 56 + 4 * np.sin(2 * np.pi * t / n_epochs)  # slow drift ~52-60
    # REM-ish high-variability windows.
    for _ in range(4):
        i = rng.integers(0, n_epochs - 40)
        base[i : i + 40] += rng.normal(0, 6, size=40)
    base += rng.normal(0, 1.0, size=n_epochs)
    return [round(float(x), 1) for x in base]


def synthetic_workout_accel(seed: int = 3) -> list[float]:
    """A day with a ~35-min run session embedded in otherwise low activity (per-30s epoch)."""
    rng = np.random.default_rng(seed)
    quiet1 = rng.poisson(1.0, size=40).astype(float)
    run = rng.normal(45, 8, size=70).clip(min=20)  # 70 epochs * 30s = 35 min
    quiet2 = rng.poisson(1.0, size=40).astype(float)
    return [float(x) for x in np.concatenate([quiet1, run, quiet2])]


def synthetic_workout_xyz(minutes: float = 16.0, fs: int = 25, cadence_hz: float = 2.7, seed: int = 7):
    """A raw 3-axis accel stream (m/s²) for a rhythmic, gravity-offset workout. NOT meant to
    match a real activity label (synthetic motion can't) — it exists to exercise the classify
    WIRING: unit conversion, windowing, session annotation. Real-label accuracy is validated
    separately on PAMAP2 (scripts/classify_pamap2.py)."""
    rng = np.random.default_rng(seed)
    n = int(minutes * 60 * fs)
    t = np.arange(n) / fs
    osc = lambda ph, amp: amp * np.sin(2 * np.pi * cadence_hz * t + ph) + rng.normal(0, 0.4, n)
    x = osc(0.0, 2.5)
    y = osc(1.0, 1.8)
    z = 9.8 + osc(2.0, 3.0)  # gravity offset on the vertical axis
    return {"x": x.tolist(), "y": y.tolist(), "z": z.tolist()}
