"""Hardening: malformed inputs must be rejected as 422, never crash a worker with an uncaught 500
(or, for sleep staging, allocate unbounded memory from a caller-controlled time span)."""

from __future__ import annotations

from fastapi.testclient import TestClient

from app.core import staging
from app.main import app

client = TestClient(app)


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


def test_a_core_code_bug_surfaces_as_500_not_422(monkeypatch):
    """Finding 4: a raw KeyError/ValueError from a CORE regression (a bad deploy) must surface as 500 —
    TRANSIENT, so the Laravel caller retries until the deploy is rolled back — and NEVER 422, which it treats
    as a deterministic data verdict and would use to cap and destroy nights fleet-wide. Only an explicit
    DataFaultError (deliberately-rejected bad payload) is 422."""
    from app.core import activity as activity_core

    def boom(**_):
        raise KeyError("simulated core regression")

    monkeypatch.setattr(activity_core, "detect_sessions", boom)
    c = TestClient(app, raise_server_exceptions=False)
    r = c.post("/process/activity", json={"accel_counts": [10.0, 12.0, 8.0, 15.0]})
    assert r.status_code == 500, r.text
