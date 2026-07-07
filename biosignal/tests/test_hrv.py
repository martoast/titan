"""Tests for /process/hrv with a realistic synthetic overnight IBI series."""

from __future__ import annotations

from fastapi.testclient import TestClient

from app.core import hrv as hrv_core
from app.main import app
from tests.fixtures import synthetic_overnight_ibi

client = TestClient(app)


def test_overnight_rmssd_is_sane():
    """A ~480-min synthetic night should yield a healthy-range RMSSD and validate."""
    ibi = synthetic_overnight_ibi(minutes=480)
    result = hrv_core.process_hrv(ibi_ms=ibi)

    assert result["valid"] is True
    # Healthy adult overnight RMSSD typically ~20-80 ms.
    assert 15.0 <= result["rmssd"] <= 90.0, result
    assert result["hrv_ms"] == result["rmssd"]  # hrv_ms == overnight RMSSD
    # Resting HR near the ~60 bpm mean RR=1000ms (min windowed median => a bit lower).
    assert 45.0 <= result["resting_hr"] <= 65.0, result
    assert result["sdnn"] > 0
    assert result["artifact_pct"] < 5.0
    assert result["n_beats_clean"] > 25000  # ~480 min at ~1s/beat


def test_endpoint_returns_metrics_block():
    ibi = synthetic_overnight_ibi(minutes=480)
    resp = client.post("/process/hrv", json={"ibi_ms": ibi, "start": "2026-06-14T05:00:00Z", "end": "2026-06-14T13:00:00Z"})
    assert resp.status_code == 200, resp.text
    body = resp.json()
    assert "algo_version" in body
    m = body["metrics"]
    for key in ("hrv_ms", "resting_hr", "rmssd", "sdnn", "pnn50", "lf_hf", "artifact_pct", "valid"):
        assert key in m
    assert m["valid"] is True


def test_ibi_path_builds_sleep_epochs_from_accel():
    """The band's overnight default is an IBI stream (+ accel), not raw PPG. Sleep staging needs a
    per-30s-epoch grid (HR + motion); it used to be built only on the PPG path, so a watch-ended
    night staged on nothing and sealed with a duration but 0% on every stage. The IBI path must now
    produce the epoch grid, with motion coming from the device's accelerometer. Regression guard.
    """
    ibi = synthetic_overnight_ibi(minutes=60)
    n = len(ibi)
    accel = [0.2] * n                      # mostly still…
    for i in range(n // 2, n // 2 + max(1, n // 20)):
        accel[i] = 9.0                     # …with a mid-night toss-and-turn
    result = hrv_core.process_hrv(ibi_ms=ibi, accel=accel)

    assert result["epoch_hr"], "IBI path must produce per-epoch HR"
    assert result["epoch_motion"], "IBI path must produce per-epoch motion (from accel)"
    assert len(result["epoch_motion"]) == len(result["epoch_hr"])
    assert 100 <= len(result["epoch_motion"]) <= 140  # ~60 min / 30 s ≈ 120 epochs
    assert max(result["epoch_motion"]) > min(result["epoch_motion"])  # the movement burst shows up


def test_poor_signal_is_gated_invalid():
    """Too few, mostly-garbage beats => valid=false (suppress poor signal)."""
    bad = [1000, 250, 2500, 240, 3000, 200, 1000]  # most rejected as implausible
    result = hrv_core.process_hrv(ibi_ms=bad)
    assert result["valid"] is False


def test_ppg_25hz_rmssd_matches_truth():
    """A 25 Hz PPG (Bangle.js rate) must recover RMSSD close to ground truth — this
    only holds because ppg_to_ibi cubic-spline upsamples before peak detection. Without
    that, beat timing is quantized to 40 ms and RMSSD blows up (regression guard)."""
    import numpy as np

    rng = np.random.default_rng(7)
    ibi_true = np.clip(1000 + np.cumsum(rng.normal(0, 12, 180)) + rng.normal(0, 28, 180), 600, 1400)
    beat_t = np.cumsum(ibi_true) / 1000.0
    gt_rmssd = float(np.sqrt(np.mean(np.diff(ibi_true) ** 2)))

    fs0, T = 500, beat_t[-1] + 1
    t = np.arange(0, T, 1 / fs0)
    ppg = sum(np.exp(-((t - bt - 0.05) ** 2) / (2 * 0.04 ** 2)) for bt in beat_t)
    ppg = ppg + 0.02 * rng.normal(size=ppg.size)
    idx = np.round(np.arange(0, T, 1 / 25) * fs0).astype(int)
    ppg25 = ppg[idx[idx < ppg.size]]

    ibi_ms, _, _ = hrv_core.ppg_to_ibi(ppg25, sample_rate_hz=25)
    got = float(np.sqrt(np.mean(np.diff(ibi_ms) ** 2)))
    # Upsampled path should land within ~10 ms of truth; the old native-25 Hz path
    # was off by hundreds of ms.
    assert abs(got - gt_rmssd) < 10.0, (got, gt_rmssd)


def test_missing_signal_is_422():
    resp = client.post("/process/hrv", json={"start": "x"})
    assert resp.status_code == 422


def test_health_no_auth():
    resp = client.get("/health")
    assert resp.status_code == 200
    assert resp.json()["status"] == "ok"
