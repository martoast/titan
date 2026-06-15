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


def test_poor_signal_is_gated_invalid():
    """Too few, mostly-garbage beats => valid=false (suppress poor signal)."""
    bad = [1000, 250, 2500, 240, 3000, 200, 1000]  # most rejected as implausible
    result = hrv_core.process_hrv(ibi_ms=bad)
    assert result["valid"] is False


def test_missing_signal_is_422():
    resp = client.post("/process/hrv", json={"start": "x"})
    assert resp.status_code == 422


def test_health_no_auth():
    resp = client.get("/health")
    assert resp.status_code == 200
    assert resp.json()["status"] == "ok"
