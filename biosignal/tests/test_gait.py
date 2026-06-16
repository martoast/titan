"""Gait cadence + guided sit-to-stand. Sanity + the endpoint; real-data accuracy is in
scripts/validate_gait.py (cadence recovers a known rate + lands physiological on PPG-DaLiA walking;
STS reuses the squat-validated rep counter, MAE 0.14)."""

from __future__ import annotations

import numpy as np
from fastapi.testclient import TestClient

from app.core import gait
from app.main import app

client = TestClient(app)
RNG = np.random.default_rng(5)


def _walk(cadence_spm, fs=25, seconds=20):
    f = cadence_spm / 60.0
    t = np.arange(fs * seconds) / fs
    bounce = np.sin(2 * np.pi * f * t)
    swing = 0.6 * np.sin(2 * np.pi * (f / 2) * t)
    return (swing + 0.2 * RNG.standard_normal(t.size),
            0.5 * bounce + 0.2 * RNG.standard_normal(t.size),
            9.81 + bounce + 0.2 * RNG.standard_normal(t.size))


def _sts(reps, fs=25, seconds=30):
    t = np.arange(fs * seconds) / fs
    bump = -np.cos(2 * np.pi * (reps / seconds) * t)
    return (0.4 * bump + 0.25 * RNG.standard_normal(t.size),
            0.3 * bump + 0.25 * RNG.standard_normal(t.size),
            9.81 + 1.5 * bump + 0.25 * RNG.standard_normal(t.size))


def test_cadence_recovers_known_rate():
    for true in (100, 110, 120):
        r = gait.cadence_spm(*_walk(true))
        assert r is not None
        assert abs(r["cadence_spm"] - true) <= 4, (true, r)


def test_cadence_rejects_non_gait():
    # Still (gravity + noise only) → no periodic gait → None.
    n = 25 * 20
    still = (RNG.standard_normal(n) * 0.05, RNG.standard_normal(n) * 0.05, 9.81 + RNG.standard_normal(n) * 0.05)
    assert gait.cadence_spm(*still) is None


def test_sit_to_stand_counts_reps():
    for true in (8, 12, 16, 22):
        r = gait.sit_to_stand(*_sts(true), duration_s=30)
        assert r is not None
        assert abs(r["reps"] - true) <= 1, (true, r)


def test_chair_stand_score_bands_by_age():
    # An older adult: 9 reps is below the ~60yo male norm; a younger adult clears it easily.
    old = gait.chair_stand_score(9, age=70, female=False)
    assert old["band"] == "below"
    strong = gait.chair_stand_score(20, age=70, female=False)
    assert strong["band"] in ("good", "average")


def test_function_endpoint_sit_to_stand_scored():
    ax, ay, az = _sts(10)
    resp = client.post("/process/function", json={
        "ax": ax.tolist(), "ay": ay.tolist(), "az": az.tolist(), "fs": 25,
        "test": "sit_to_stand", "duration_s": 30, "age": 68, "sex": "M",
    })
    assert resp.status_code == 200, resp.text
    sts = resp.json()["sit_to_stand"]
    assert sts is not None and abs(sts["reps"] - 10) <= 1
    assert "score" in sts and sts["score"]["band"] in ("below", "average", "good")
