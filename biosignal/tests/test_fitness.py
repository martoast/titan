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


def test_run_calibrated_engages_and_extractor_is_correct():
    """A logged GPS run engages the run-calibrated model (subsumes demographics+uth) and returns a
    plausible VO2max. The end-to-end accuracy lives in scripts/validate_vo2max.py (real data, MAE
    5.3); here we assert the plumbing + the DETERMINISTIC feature extractor, which is what a unit
    test can verify (a tree model isn't monotonic on hand-crafted out-of-distribution inputs)."""
    n = 900
    speed = np.clip(6 + np.arange(n) * 0.01, 6, 15).tolist()   # ramp 6→15 km/h
    fit_hr = (72 + 7 * np.array(speed)).tolist()               # lower HR at every pace (fitter)
    unfit_hr = (92 + 7 * np.array(speed)).tolist()             # higher HR at every pace
    prof = dict(age=35, sex="M", weight_kg=78, height_cm=180)

    fit = fc.estimate_vo2max(**prof, resting_hr=50, hr_max=190, run={"hr": fit_hr, "speed_kmh": speed})
    assert "run_calibrated" in fit["methods"]
    assert "demographic" not in fit["methods"]                 # run subsumes demographics + uth
    assert 25 <= fit["vo2max"] <= 75                           # plausible, in range
    assert fit["plusminus"] == fc._RUN_MODEL_MAE               # run-model band surfaced

    # Feature extractor (deterministic): a fitter runner reaches a given %HR-reserve at a FASTER
    # pace → higher speed_at_70hrr; and holds a LOWER %HRR at a fixed pace → lower pcthrr_at_12.
    ff = fc.run_feature_vector(35, False, 24.1, 190, 50, fit_hr, speed)
    uf = fc.run_feature_vector(35, False, 24.1, 190, 50, unfit_hr, speed)
    F = fc.RUN_FEATURES
    assert ff[F.index("speed_at_70hrr")] > uf[F.index("speed_at_70hrr")]
    assert ff[F.index("pcthrr_at_12")] < uf[F.index("pcthrr_at_12")]

    # Grade (baro): the extractor folds grade into an equivalent FLAT speed → higher pace-at-cost.
    flat_fv = fc.run_feature_vector(35, False, 24.1, 190, 50, fit_hr, speed)
    up_fv = fc.run_feature_vector(35, False, 24.1, 190, 50, fit_hr, speed, grade=[0.05] * n)
    assert up_fv[F.index("speed_at_70hrr")] > flat_fv[F.index("speed_at_70hrr")]
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
