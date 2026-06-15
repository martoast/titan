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


def test_sleep_model_default_and_disable():
    """The real-PSG-trained model is the DEFAULT (loads + used); SLEEP_MODEL_ENABLED=0
    disables it and falls back. Both must return a valid hypnogram contract."""
    import importlib
    import os

    from app.core import staging

    rng = __import__("numpy").random.default_rng(5)
    accel = __import__("numpy").abs(rng.normal(3, 2, 480)).tolist()
    hr = (50 + rng.normal(0, 3, 480)).tolist()

    # Default: real model loads + is used.
    os.environ.pop("SLEEP_MODEL_ENABLED", None)
    importlib.reload(staging)
    assert staging._load_model() is not None
    r_def = staging.stage_night(accel, hr, start="2026-06-09T00:00:00Z", end="2026-06-09T04:00:00Z")
    assert set(r_def["hypnogram_30s"]) <= {"wake", "light", "deep", "rem"}

    # Disabled: model not loaded → physiology fallback, still a valid hypnogram.
    os.environ["SLEEP_MODEL_ENABLED"] = "0"
    importlib.reload(staging)
    assert staging._load_model() is None
    r_off = staging.stage_night(accel, hr, start="2026-06-09T00:00:00Z", end="2026-06-09T04:00:00Z")
    assert set(r_off["hypnogram_30s"]) <= {"wake", "light", "deep", "rem"}

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
    # No 3-axis stream supplied → classification is a no-op, sessions still detected.
    assert s["activity_type"] is None


def test_activity_no_session_when_quiet():
    resp = client.post("/process/activity", json={"accel_counts": [0, 1, 0, 0, 1, 0]})
    assert resp.status_code == 200
    assert resp.json()["metrics"]["session_count"] == 0


def test_activity_classifies_session_from_xyz():
    """When a raw 3-axis accel stream rides along, each session is annotated with a workout
    type. Asserts the WIRING contract (the model's real-label accuracy is validated on PAMAP2,
    not here): a label from the model's groups, a sane confidence, and a mix that sums to ~1."""
    from app.core.activity_classify import GROUPS
    from tests.fixtures import synthetic_workout_xyz

    xyz = synthetic_workout_xyz(minutes=16.0, fs=25)
    n_epochs = int(16 * 60 / 30)  # 30-s epochs spanning the stream
    resp = client.post(
        "/process/activity",
        json={
            "accel_counts": [40.0] * n_epochs,
            "start": "2026-06-15T12:00:00Z",
            "accel_xyz": xyz,
            "accel_fs": 25,
            "accel_unit": "ms2",
            "accel_start": "2026-06-15T12:00:00Z",
        },
    )
    assert resp.status_code == 200, resp.text
    s = resp.json()["metrics"]["sessions"][0]
    assert s["activity_type"] in GROUPS, s
    assert 0.0 <= s["activity_confidence"] <= 1.0
    assert abs(sum(s["activity_mix"].values()) - 1.0) < 0.05


def test_activity_classification_is_unit_invariant():
    """The Bangle streams milli-g, the model trains in m/s². Identical motion in 'g' vs 'mg'
    (a 1000× scale) must classify identically — proves the unit normalization works."""
    import numpy as np

    from app.core import activity_classify as ac
    from tests.fixtures import synthetic_workout_xyz

    xyz = synthetic_workout_xyz(minutes=8.0, fs=25)
    ms2 = {k: np.asarray(v) for k, v in xyz.items()}
    g = {k: v / 9.80665 for k, v in ms2.items()}        # m/s² → g
    mg = {k: v * 1000.0 for k, v in g.items()}           # g → milli-g

    d_ms2 = ac.dominant_activity(ms2["x"], ms2["y"], ms2["z"], fs=25, unit="ms2")
    d_g = ac.dominant_activity(g["x"], g["y"], g["z"], fs=25, unit="g")
    d_mg = ac.dominant_activity(mg["x"], mg["y"], mg["z"], fs=25, unit="mg")
    assert d_ms2["activity"] == d_g["activity"] == d_mg["activity"]
