"""Tests for in-motion HR (app/core/inmotion_hr.py + /process/inmotion-hr).

The keystone assertion: when a strong motion artifact sits at a DIFFERENT frequency than the true
heart rate, the estimator recovers the heart rate — it does NOT cadence-lock onto the motion the
way the on-chip bpm register does. Plus a clean-signal sanity check, hold-last-good under noise,
and the HTTP contract.
"""

from __future__ import annotations

import numpy as np
from fastapi.testclient import TestClient

from app.core import inmotion_hr as core
from app.main import app

client = TestClient(app)


def _synth(fs, dur, hr_hz, motion_hz, hr_amp, motion_amp, seed=0):
    """A PPG corrupted by a motion artifact at `motion_hz`, plus a matching accel that bounces at
    `motion_hz` (vertical bounce on z over gravity → a clean motion-magnitude peak)."""
    rng = np.random.default_rng(seed)
    t = np.arange(int(dur * fs)) / fs
    ppg = (hr_amp * np.sin(2 * np.pi * hr_hz * t)
           + 0.3 * hr_amp * np.sin(2 * np.pi * 2 * hr_hz * t)        # cardiac 2nd harmonic
           + motion_amp * np.sin(2 * np.pi * motion_hz * t)          # motion contamination
           + 0.05 * rng.standard_normal(t.size))
    az = 1.0 + 0.6 * np.sin(2 * np.pi * motion_hz * t)               # gravity + bounce
    ax = 0.03 * rng.standard_normal(t.size)
    ay = 0.03 * rng.standard_normal(t.size)
    return ppg, ax, ay, az, t


def test_recovers_hr_not_cadence():
    """True HR 150 bpm (2.5 Hz); a STRONGER motion artifact at 90 bpm (1.5 Hz). The naive spectral
    peak is the cadence (90) — the estimator must suppress it and recover ~150."""
    fs = 25.0
    ppg, ax, ay, az, t = _synth(fs, dur=40, hr_hz=2.5, motion_hz=1.5, hr_amp=0.4, motion_amp=1.0)

    # Sanity: the raw PPG spectrum really is dominated by the cadence (the trap the on-chip algo falls in).
    fp, pp = core._band_spectrum(core._bandpass(ppg, fs), fs)
    naive_bpm = fp[int(np.argmax(pp))] * 60.0
    assert abs(naive_bpm - 90) < 10, f"test setup: naive should lock to cadence ~90, got {naive_bpm:.0f}"

    out = core.estimate_series(ppg, fs, ax, ay, az, fs, min_confidence=20)
    assert out["summary"]["hr_mean"] is not None
    assert abs(out["summary"]["hr_mean"] - 150) < 12, out["summary"]
    # And crucially NOT the cadence.
    assert abs(out["summary"]["hr_mean"] - 90) > 25, out["summary"]


def test_clean_signal_is_reliable():
    """Clean PPG at 120 bpm with negligible motion → recovered accurately and flagged reliable."""
    fs = 25.0
    ppg, ax, ay, az, t = _synth(fs, dur=30, hr_hz=2.0, motion_hz=1.5, hr_amp=1.0, motion_amp=0.0)
    az = np.full_like(az, 1.0)  # no bounce → no motion peak
    out = core.estimate_series(ppg, fs, ax, ay, az, fs)
    assert abs(out["summary"]["hr_mean"] - 120) < 8, out["summary"]
    assert out["summary"]["coverage"] > 0.5            # most windows clear the bar on their own
    assert any(out["reliable"])


def test_holds_last_good_under_noise():
    """A good stretch then pure noise: the noisy windows hold the last good HR and flag unreliable
    (never emit a fabricated jump)."""
    fs = 25.0
    rng = np.random.default_rng(1)
    good, ax, ay, az, t = _synth(fs, dur=20, hr_hz=2.0, motion_hz=1.5, hr_amp=1.0, motion_amp=0.0)
    az = np.full_like(az, 1.0)
    noise = 0.5 * rng.standard_normal(int(20 * fs))
    ppg = np.concatenate([good, noise])
    axx = np.concatenate([ax, 0.03 * rng.standard_normal(noise.size)])
    ayy = np.concatenate([ay, 0.03 * rng.standard_normal(noise.size)])
    azz = np.concatenate([az, np.full(noise.size, 1.0)])
    out = core.estimate_series(ppg, fs, axx, ayy, azz, fs)

    # Last few windows are in the noise region: not reliable, and bpm held near the good value (~120).
    assert out["reliable"][0] or any(out["reliable"][:5])      # the good stretch is trusted
    tail_reliable = out["reliable"][-3:]
    tail_bpm = [b for b in out["bpm"][-3:] if b is not None]
    assert not all(tail_reliable)                              # noise is flagged
    assert tail_bpm and all(abs(b - 120) < 25 for b in tail_bpm)  # held, not wild


def test_http_contract():
    fs = 25.0
    ppg, ax, ay, az, t = _synth(fs, dur=20, hr_hz=2.2, motion_hz=1.4, hr_amp=0.5, motion_amp=0.8)
    r = client.post("/process/inmotion-hr", json={
        "ppg": ppg.tolist(), "fs_ppg": fs,
        "accel_x": ax.tolist(), "accel_y": ay.tolist(), "accel_z": az.tolist(), "fs_acc": fs,
        "min_confidence": 20,
    })
    assert r.status_code == 200, r.text
    body = r.json()
    assert "algo_version" in body
    assert len(body["bpm"]) == len(body["t"]) == len(body["confidence"]) == len(body["reliable"])
    assert "coverage" in body["summary"]
