"""Step-count distance estimate (no-GPS / indoor run fallback)."""

import numpy as np

from app.core import gait


def _running_accel(fs=25, dur_s=60, cadence_spm=170.0):
    """Synthetic wrist accel for a steady run: vertical foot-strike at the step frequency + harmonic,
    arm swing at half cadence, gravity on z. m/s²."""
    n = int(fs * dur_s)
    t = np.arange(n) / fs
    f = cadence_spm / 60.0
    az = 9.8 + 3.0 * np.sin(2 * np.pi * f * t) + 0.7 * np.sin(2 * np.pi * 2 * f * t)
    ax = 1.6 * np.sin(2 * np.pi * f * t + 0.4)
    ay = 1.1 * np.sin(2 * np.pi * (f / 2) * t + 1.0)
    return ax.tolist(), ay.tolist(), az.tolist()


def test_estimates_distance_for_a_steady_run():
    ax, ay, az = _running_accel(cadence_spm=170)
    est = gait.estimate_distance(ax, ay, az, fs=25, duration_s=60, height_cm=178, activity_type="run")
    assert est is not None
    assert 150 <= est["cadence_spm"] <= 190        # recovers the cadence
    assert est["steps"] >= 150                     # ~170 steps in a minute
    assert est["stride_m"] > 1.0                   # a running step for a 178 cm athlete
    assert est["distance_km"] > 0.15               # ~0.22 km covered in that minute


def test_taller_runner_covers_more_ground():
    ax, ay, az = _running_accel(cadence_spm=170)
    short = gait.estimate_distance(ax, ay, az, fs=25, duration_s=60, height_cm=160, activity_type="run")
    tall = gait.estimate_distance(ax, ay, az, fs=25, duration_s=60, height_cm=195, activity_type="run")
    assert tall["distance_km"] > short["distance_km"]


def test_no_clear_gait_returns_none():
    rng = np.random.default_rng(1)
    noise = (rng.standard_normal(1500) * 0.3).tolist()
    assert gait.estimate_distance(noise, noise, noise, fs=25, duration_s=60, height_cm=178) is None


def test_missing_height_returns_none():
    ax, ay, az = _running_accel()
    assert gait.estimate_distance(ax, ay, az, fs=25, duration_s=60, height_cm=0) is None
