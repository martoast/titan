"""The workout digital-twin end-to-end: a simulated workout (real replayed accel + synth HR/
GPS/baro) must flow through /process/activity + /process/fitness and come out classified, with
a VO2max and HRR. This is the pre-hardware integration test for Pillars 2 + 3 together."""

from __future__ import annotations

import pytest
from fastapi.testclient import TestClient

from app.core.activity_classify import GROUPS
from app.main import app
from app.sim.workout import simulate_workout

client = TestClient(app)


def _drive(activity, minutes=12.0, fitness=0.5):
    w = simulate_workout(activity=activity, minutes=minutes, fitness=fitness)
    p = w["profile"]
    act = client.post("/process/activity", json={
        "accel_counts": w["accel_counts"], "hr_bpm": w["hr_epoch_bpm"], "start": w["start"],
        "hr_max": p["hr_max"], "hr_rest": p["resting_hr"], "weight_kg": p["weight_kg"],
        "accel_xyz": w["accel_xyz"], "accel_fs": w["fs"], "accel_unit": w["accel_unit"],
        "accel_start": w["start"],
    }).json()["metrics"]
    fit = client.post("/process/fitness", json={
        "age": p["age"], "sex": p["sex"], "weight_kg": p["weight_kg"], "height_cm": p["height_cm"],
        "resting_hr": p["resting_hr"], "hr_max": p["hr_max"], "run": w["run"],
        "workout_hr_bpm": w["workout_hr_bpm"], "hr_fs": 1.0,
    }).json()
    return w, act, fit


@pytest.mark.parametrize("activity", ["run", "walk", "cycle", "stairs"])
def test_simulated_workout_classifies_correctly(activity):
    """Real replayed motion → the classifier recovers the intended activity end-to-end."""
    w, act, fit = _drive(activity)
    assert act["session_count"] >= 1, act
    s = act["sessions"][0]
    assert s["activity_type"] == activity, (activity, s["activity_type"], s.get("activity_mix"))
    assert s["trimp"] > 0 and s["calories_kcal"] > 0


def test_simulated_run_produces_fitness_metrics():
    w, act, fit = _drive("run", fitness=0.7)
    assert "run_calibrated" in fit["methods"]              # GPS pace drove the run-calibrated VO2max
    assert 30 <= fit["vo2max"] <= 70
    assert fit["fitness_level"] in {"low", "fair", "good", "high", "excellent"}
    assert fit["hrr"] is not None and fit["hrr"]["hrr_bpm"] > 0   # cooldown gave a real HRR


def test_fitter_runner_lower_hr_higher_vo2max():
    """The twin is internally consistent: a fitter athlete (lower HR at the same pace) reads a
    higher VO2max — the property the run-calibrated model exists to capture."""
    _, _, lo = _drive("run", fitness=0.2)
    _, _, hi = _drive("run", fitness=0.9)
    assert hi["vo2max"] > lo["vo2max"]
