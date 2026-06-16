"""Barometric floor counting: sanity + the two things that matter — count real climbs, reject
weather drift. Accuracy across 1–20 flights (MAE ~0.8 floors) lives in scripts/validate_elevation.py."""

from __future__ import annotations

import numpy as np
from fastapi.testclient import TestClient

from app.core import elevation as el
from app.main import app

client = TestClient(app)
FS = 1.0
RNG = np.random.default_rng(3)


def _bump(height_m, climb_s=12, dwell_s=90, fs=FS):
    """An up-dwell-down altitude bump (one or more storeys), with realistic BMP280 noise."""
    up = np.linspace(0, height_m, int(climb_s * fs))
    top = np.full(int(dwell_s * fs), height_m)
    down = np.linspace(height_m, 0, int(climb_s * fs))
    seg = np.concatenate([np.zeros(int(40 * fs)), up, top, down, np.zeros(int(40 * fs))])
    return seg + RNG.standard_normal(seg.size) * 0.3


def test_single_storey_is_one_floor():
    out = el.floors_from_altitude(_bump(3.0), sample_rate_hz=FS)
    assert out["floors"] == 1, out
    assert out["n_climbs"] == 1


def test_multi_storey_climb_counts_floors():
    # A 15 m climb in one go = 5 storeys.
    out = el.floors_from_altitude(_bump(15.0), sample_rate_hz=FS)
    assert 4 <= out["floors"] <= 6, out


def test_weather_drift_is_not_counted():
    """A flat day where pressure drifts metres over hours must read ~0 floors (the key robustness)."""
    n = int(3 * 3600 * FS)
    drift = np.cumsum(RNG.standard_normal(n)) * (5.0 / np.sqrt(n))   # ~±5 m slow wander
    noise = RNG.standard_normal(n) * 0.3
    out = el.floors_from_altitude(drift + noise, sample_rate_hz=FS)
    assert out["floors"] == 0, out


def test_descent_alone_is_no_floors():
    # Going only downhill climbs nothing.
    down = np.concatenate([np.zeros(40), np.linspace(0, -9, 12), np.full(120, -9.0)])
    out = el.floors_from_altitude(down + RNG.standard_normal(172) * 0.3, sample_rate_hz=FS)
    assert out["floors"] == 0, out


def test_endpoint_contract():
    alt = _bump(6.0).tolist()                              # ~2 storeys
    resp = client.post("/process/elevation", json={"altitude_m": alt, "sample_rate_hz": FS})
    assert resp.status_code == 200, resp.text
    body = resp.json()["metrics"]
    assert body["floors"] >= 1
    assert body["ascent_m"] > 3.0
