"""Respiratory-rate-from-PPG sanity tests: a synthetic PPG with a KNOWN breathing rate, modulated
the three ways breathing actually shows up in a pulse wave, must recover that rate at 25 Hz.

This is a sanity floor — the real accuracy number comes from scripts/validate_respiration.py on
BIDMC (real PPG + manual breath annotations).
"""

from __future__ import annotations

import numpy as np

from app.core import respiration as resp


def _synthetic_ppg(fs=25, seconds=120, hr_bpm=60.0, rr_br_min=15.0):
    """A pulse train at hr_bpm, modulated by breathing at rr_br_min via amplitude (RIAV), baseline
    wander (RIIV), and heart-rate sinus arrhythmia (RIFV) — the three real modulations."""
    f_resp = rr_br_min / 60.0
    f_hr = hr_bpm / 60.0
    n = int(fs * seconds)
    t = np.arange(n) / fs
    resp_wave = np.sin(2 * np.pi * f_resp * t)
    # RIFV: instantaneous heart phase with sinus-arrhythmia frequency modulation.
    inst_f = f_hr * (1 + 0.06 * resp_wave)
    phase = 2 * np.pi * np.cumsum(inst_f) / fs
    pulse = -np.cos(phase)                       # sharp-ish systolic upstroke proxy
    amp = 1.0 + 0.20 * resp_wave                 # RIAV
    baseline = 0.30 * resp_wave                  # RIIV
    return amp * pulse + baseline


def test_window_recovers_known_rate():
    ppg = _synthetic_ppg(rr_br_min=15.0)
    out = resp.estimate_rr_window(ppg, 25)
    assert out["valid"], out
    assert abs(out["resp_rate"] - 15.0) <= 2.0, out


def test_tracks_a_different_rate():
    ppg = _synthetic_ppg(rr_br_min=22.0, hr_bpm=66.0)
    out = resp.estimate_rr_window(ppg, 25)
    assert out["valid"], out
    assert abs(out["resp_rate"] - 22.0) <= 2.5, out


def test_smart_fusion_rejects_pure_noise():
    rng = np.random.default_rng(0)
    noise = rng.standard_normal(25 * 90)
    out = resp.estimate_rr_window(noise, 25)
    # Pure noise has no coherent breathing band → the 3 modulations should disagree (or no beats).
    assert out["valid"] is False, out


def test_overnight_aggregate_is_a_trustworthy_median():
    ppg = _synthetic_ppg(seconds=300, rr_br_min=14.0)
    out = resp.estimate_respiratory_rate(ppg, 25)
    assert out["valid"], out
    assert abs(out["resp_rate"] - 14.0) <= 2.0, out
    assert out["coverage"] > 0.5, out
