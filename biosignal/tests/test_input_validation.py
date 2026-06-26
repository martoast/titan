"""Hardening: malformed inputs must be rejected as 422, never crash a worker with an uncaught 500
(or, for sleep staging, allocate unbounded memory from a caller-controlled time span)."""

from __future__ import annotations

from fastapi.testclient import TestClient

from app.core import staging
from app.main import app

client = TestClient(app)


def test_gym_rejects_nonpositive_sample_rate():
    # accel_fs=0 → butter(...) divides by fs/2 → ZeroDivisionError → 500. Must be a 422 now.
    body = {"accel_xyz": {"x": [0, 1, 0], "y": [0, 1, 0], "z": [1, 1, 1]}, "accel_fs": 0}
    assert client.post("/process/gym", json=body).status_code == 422


def test_function_rejects_nonpositive_sample_rate():
    body = {"ax": [0, 1, 0], "ay": [0, 1, 0], "az": [1, 1, 1], "fs": 0}
    assert client.post("/process/function", json=body).status_code == 422


def test_fitness_rejects_nan_inputs():
    # The real vector is a raw body with a literal NaN token — Python's json.loads (used by Starlette)
    # accepts it, so it must be rejected at the model, not crash later at response serialization.
    raw = '{"age": NaN, "sex": "M", "weight_kg": 70, "height_cm": 175}'
    r = client.post("/process/fitness", content=raw, headers={"content-type": "application/json"})
    assert r.status_code == 422


def test_fitness_rejects_mismatched_run_lengths():
    body = {
        "age": 30, "sex": "M", "weight_kg": 70, "height_cm": 175,
        "run": {"hr": [120, 130, 140], "speed_kmh": [10.0, 11.0]},   # different lengths
    }
    assert client.post("/process/fitness", json=body).status_code == 422


def test_sleep_staging_clamps_a_runaway_span():
    # A tiny body with a 6-year span must not allocate millions of epochs.
    out = staging.stage_night(
        accel_counts=[0], hr_bpm=None, rmssd_ms=None,
        start="2020-01-01T00:00:00Z", end="2026-01-01T00:00:00Z",
    )
    assert len(out["hypnogram_30s"]) <= staging.MAX_EPOCHS
