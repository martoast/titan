"""Tests for /process/sleep and /process/activity."""

from __future__ import annotations

from fastapi.testclient import TestClient

from app.main import app
from tests.fixtures import (
    synthetic_overnight_accel,
    synthetic_overnight_hr,
    synthetic_workout_accel,
)

client = TestClient(app)


def test_sleep_staging_summary():
    accel = synthetic_overnight_accel(n_epochs=960)
    hr = synthetic_overnight_hr(n_epochs=960)
    resp = client.post(
        "/process/sleep",
        json={
            "accel_counts": accel,
            "hr_bpm": hr,
            "start": "2026-06-14T05:00:00Z",
            "end": "2026-06-14T13:00:00Z",
        },
    )
    assert resp.status_code == 200, resp.text
    m = resp.json()["metrics"]
    # 960 epochs * 30s = 480 min in bed.
    total = m["deep_min"] + m["rem_min"] + m["light_min"] + m["awake_min"]
    assert 470 <= total <= 485, m
    assert m["duration_min"] > 300        # mostly asleep
    assert 0 <= m["quality"] <= 100
    assert len(m["hypnogram_30s"]) == 960
    assert set(m["hypnogram_30s"]) <= {"wake", "light", "deep", "rem"}


def test_sleep_model_is_opt_in():
    """The trained model is gated OFF by default (robust heuristic stays the default);
    enabling the flag loads + uses it. Both must return a valid hypnogram contract."""
    import importlib
    import os

    from app.core import staging

    rng = __import__("numpy").random.default_rng(5)
    accel = __import__("numpy").abs(rng.normal(3, 2, 480)).tolist()
    hr = (50 + rng.normal(0, 3, 480)).tolist()

    # Default: model not loaded.
    os.environ.pop("SLEEP_MODEL_ENABLED", None)
    importlib.reload(staging)
    assert staging._load_model() is None
    r_def = staging.stage_night(accel, hr, "2026-06-09T00:00:00Z", "2026-06-09T04:00:00Z")
    assert set(r_def["hypnogram_30s"]) <= {"wake", "light", "deep", "rem"}

    # Enabled: model loads (artifact ships in app/models/) and predicts.
    os.environ["SLEEP_MODEL_ENABLED"] = "1"
    importlib.reload(staging)
    assert staging._load_model() is not None
    r_on = staging.stage_night(accel, hr, "2026-06-09T00:00:00Z", "2026-06-09T04:00:00Z")
    assert set(r_on["hypnogram_30s"]) <= {"wake", "light", "deep", "rem"}

    os.environ.pop("SLEEP_MODEL_ENABLED", None)
    importlib.reload(staging)


def test_activity_detects_run():
    accel = synthetic_workout_accel()
    resp = client.post(
        "/process/activity",
        json={"accel_counts": accel, "start": "2026-06-14T17:00:00Z", "hr_max": 190, "hr_rest": 52, "weight_kg": 78},
    )
    assert resp.status_code == 200, resp.text
    m = resp.json()["metrics"]
    assert m["session_count"] == 1, m
    s = m["sessions"][0]
    assert 30 <= s["duration_min"] <= 40
    assert s["trimp"] > 0
    assert s["calories_kcal"] > 0
    assert m["total_trimp"] > 0


def test_activity_no_session_when_quiet():
    resp = client.post("/process/activity", json={"accel_counts": [0, 1, 0, 0, 1, 0]})
    assert resp.status_code == 200
    assert resp.json()["metrics"]["session_count"] == 0
