"""Tests for gym exercise recognition + rep counting (/process/gym).

Rep counting is signal processing (testable on a clean synthetic cadence); exercise-classification
ACCURACY is validated on real data (scripts/validate_gym.py), so here we assert the CONTRACT."""

from __future__ import annotations

import numpy as np
from fastapi.testclient import TestClient

from app.core import gym
from app.main import app

client = TestClient(app)


def _rep_signal(reps, fs=25, sec_per_rep=2.0, seed=0):
    """A clean rhythmic 3-axis set: one motion cycle per rep on a gravity-offset axis."""
    rng = np.random.default_rng(seed)
    n = int(reps * sec_per_rep * fs)
    t = np.arange(n) / fs
    f = 1.0 / sec_per_rep
    x = 2.0 * np.sin(2 * np.pi * f * t) + rng.normal(0, 0.1, n)
    y = rng.normal(0, 0.1, n)
    z = 9.8 + 0.5 * np.sin(2 * np.pi * f * t) + rng.normal(0, 0.1, n)
    return x, y, z


def test_rep_counter_counts_clean_cadence():
    for reps in (8, 10, 12):
        x, y, z = _rep_signal(reps)
        assert abs(gym.count_reps(x, y, z, fs=25, unit="ms2") - reps) <= 1


def test_classify_returns_a_known_exercise():
    x, y, z = _rep_signal(10)
    out = gym.classify_exercise(x, y, z, fs=25, unit="ms2")
    assert out is not None
    assert out["exercise"] in gym._load()["classes"]
    assert 0.0 <= out["confidence"] <= 1.0
    assert isinstance(out["is_lift"], bool)


def test_rep_counting_is_unit_invariant():
    x, y, z = _rep_signal(10)
    xa, ya, za = np.array(x), np.array(y), np.array(z)
    in_g = gym.count_reps(xa / 9.80665, ya / 9.80665, za / 9.80665, fs=25, unit="g")
    in_mg = gym.count_reps(xa / 9.80665 * 1000, ya / 9.80665 * 1000, za / 9.80665 * 1000, fs=25, unit="mg")
    assert in_g == in_mg


def test_analyze_workout_segments_sets():
    """Two rep-sets separated by rest → two detected sets, summed reps in the summary."""
    rest = np.tile([0, 0, 9.8], (int(8 * 25), 1)).astype(float)
    s1 = np.column_stack(_rep_signal(10, seed=1))
    s2 = np.column_stack(_rep_signal(10, seed=2))
    sess = np.vstack([s1, rest, s2, rest])
    out = gym.analyze_workout(sess[:, 0], sess[:, 1], sess[:, 2], fs=25, unit="ms2")
    assert out["summary"]["n_sets"] == 2
    assert out["summary"]["total_reps"] >= 16


def test_gym_endpoint_contract():
    rest = np.tile([0, 0, 9.8], (int(8 * 25), 1)).astype(float)
    sess = np.vstack([np.column_stack(_rep_signal(10, seed=3)), rest, np.column_stack(_rep_signal(10, seed=4))])
    resp = client.post("/process/gym", json={
        "accel_xyz": {"x": sess[:, 0].tolist(), "y": sess[:, 1].tolist(), "z": sess[:, 2].tolist()},
        "accel_fs": 25, "accel_unit": "ms2",
    })
    assert resp.status_code == 200, resp.text
    body = resp.json()
    assert body["summary"]["n_sets"] >= 1
    assert all("reps" in s and "exercise" in s for s in body["sets"])
