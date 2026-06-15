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
