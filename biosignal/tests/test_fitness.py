"""Tests for /process/fitness — VO2max estimate + heart-rate recovery."""

from __future__ import annotations

import numpy as np
from fastapi.testclient import TestClient

from app.core import fitness as fc
from app.main import app

client = TestClient(app)


def test_vo2max_demographic_is_physiological():
    """A fit young man and an older higher-BMI person bracket sensibly, in range."""
    young = fc.estimate_vo2max(age=28, sex="M", weight_kg=72, height_cm=178)
    older = fc.estimate_vo2max(age=58, sex="M", weight_kg=95, height_cm=178)
    assert 35 <= young["vo2max"] <= 60, young
    assert older["vo2max"] < young["vo2max"]            # age + BMI both lower it
    assert young["plusminus"] == fc._MODEL_MAE          # honest band surfaced
    assert young["methods"] == ["demographic"]          # no resting HR → demographic only


def test_sex_difference():
    m = fc.estimate_vo2max(age=30, sex="M", weight_kg=70, height_cm=175)["vo2max"]
    f = fc.estimate_vo2max(age=30, sex="F", weight_kg=70, height_cm=175)["vo2max"]
    assert m > f                                         # same body, male norm higher


def test_resting_hr_blends_uth():
    """A low resting HR (fit) should pull the estimate up via the Uth-Sørensen blend."""
    base = fc.estimate_vo2max(age=30, sex="M", weight_kg=75, height_cm=180)
    fit = fc.estimate_vo2max(age=30, sex="M", weight_kg=75, height_cm=180, resting_hr=45, hr_max=190)
    assert "uth_resting_hr" in fit["methods"]
    assert fit["vo2max"] > base["vo2max"]


def test_heart_rate_recovery():
    """Synthetic cooldown: HR peaks then decays ~30 bpm over 60 s → HRR ≈ 30."""
    fs = 1.0
    ramp = np.linspace(120, 180, 120)                   # 2 min ramp to peak
    recover = 180 - 30 * (1 - np.exp(-np.arange(90) / 40.0))  # decay after peak
    hr = np.concatenate([ramp, recover])
    out = fc.heart_rate_recovery(hr, fs=fs, window_s=60)
    assert out is not None
    assert out["peak_hr"] == 180
    assert 18 <= out["hrr_bpm"] <= 32                   # most of the 30 bpm drop captured


def test_run_calibrated_uses_pace_and_grade():
    """A logged GPS run engages the run-calibrated model (subsumes demographics), and a low-HR-
    at-pace run reads fitter than a high-HR-at-pace one for the same profile."""
    n = 600
    speed = np.clip(6 + np.arange(n) * 0.01, 6, 12).tolist()   # steady ramp 6→12 km/h
    # HR stays below HRmax (190) at all paces — in-distribution; lower HR at pace = fitter.
    fit_hr = (50 + 9 * np.array(speed)).tolist()               # ~122 @8, ~158 @12
    unfit_hr = (75 + 9 * np.array(speed)).tolist()             # ~147 @8, ~183 @12
    prof = dict(age=35, sex="M", weight_kg=78, height_cm=180)

    fit = fc.estimate_vo2max(**prof, hr_max=190, run={"hr": fit_hr, "speed_kmh": speed})
    unfit = fc.estimate_vo2max(**prof, hr_max=190, run={"hr": unfit_hr, "speed_kmh": speed})
    assert "run_calibrated" in fit["methods"]
    assert "demographic" not in fit["methods"]                 # run subsumes demographics
    assert fit["vo2max"] > unfit["vo2max"]                     # lower HR at pace ⇒ fitter

    # Grade (baro): the same HR/pace uphill ⇒ harder effort ⇒ reads at least as fit.
    flat = fc.estimate_vo2max(**prof, hr_max=190, run={"hr": fit_hr, "speed_kmh": speed})["vo2max"]
    up = fc.estimate_vo2max(**prof, hr_max=190,
                            run={"hr": fit_hr, "speed_kmh": speed, "grade": [0.05] * n})["vo2max"]
    assert up >= flat
    assert fc.grade_adjusted_speed(10.0, 0.05) > 10.0          # uphill pace → higher flat-equivalent


def test_fitness_endpoint_contract():
    resp = client.post(
        "/process/fitness",
        json={
            "age": 34, "sex": "M", "weight_kg": 80, "height_cm": 181,
            "resting_hr": 52, "hr_max": 188,
            "workout_hr_bpm": list(np.concatenate([
                np.linspace(110, 175, 100), 175 - 25 * (1 - np.exp(-np.arange(80) / 35.0))]).round(0)),
            "hr_fs": 1.0,
        },
    )
    assert resp.status_code == 200, resp.text
    body = resp.json()
    assert 30 <= body["vo2max"] <= 65
    assert body["fitness_level"] in {"low", "fair", "good", "high", "excellent"}
    assert "uth_resting_hr" in body["methods"]
    assert body["hrr"] is not None and body["hrr"]["hrr_bpm"] > 0
