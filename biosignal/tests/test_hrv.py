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
    accel = [100.0] * n                    # mostly still at 1g (accel magnitude ~100 centi-g)…
    for j, i in enumerate(range(n // 2, n // 2 + max(2, n // 20))):
        accel[i] = 100.0 + (40.0 if j % 2 == 0 else -40.0)   # …a mid-night toss-and-turn: the magnitude SWINGS
    result = hrv_core.process_hrv(ibi_ms=ibi, accel=accel)

    assert result["epoch_hr"], "IBI path must produce per-epoch HR"
    assert result["epoch_motion"], "IBI path must produce per-epoch motion (from accel)"
    assert len(result["epoch_motion"]) == len(result["epoch_hr"])
    assert 100 <= len(result["epoch_motion"]) <= 140  # ~60 min / 30 s ≈ 120 epochs
    # Motion is the per-epoch accel STD (movement), so the swinging toss-and-turn shows up while the still 1g
    # baseline reads ~0 — and it's on the stager's ~0-50 scale, NOT the gravity-dominated sum (~tens of thousands).
    assert max(result["epoch_motion"]) > min(result["epoch_motion"])   # the movement burst shows up
    assert max(result["epoch_motion"]) < 100                            # movement-isolated, not a gravity sum


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


def test_epoch_motion_is_movement_isolated_not_gravity_sum():
    """Regression for the stage-less-nights bug: the wrist accel is magnitude in centi-g (~100/sample at 1g).
    The per-epoch motion proxy must be the accel STD (movement, ~0-50), not sum(|accel|) (gravity-dominated,
    ~tens of thousands) — the latter floored the stager's percentile threshold and staged every epoch as WAKE."""
    import numpy as np

    rng = np.random.default_rng(7)
    ibi = [1090.0] * 27  # ~55 bpm over 30 s
    n = int(30 * 12.5)

    still = hrv_core.process_hrv(ibi_ms=ibi, accel=list(100 + rng.normal(0, 1.5, n)))
    restless = hrv_core.process_hrv(ibi_ms=ibi, accel=list(100 + rng.normal(0, 25, n)))

    still_m = (still.get("epoch_motion") or [999])[0]
    restless_m = (restless.get("epoch_motion") or [0])[0]
    assert still_m < 10, f"a still 1g wrist must read low motion, got {still_m}"
    assert restless_m > 15, f"a restless wrist must read high motion, got {restless_m}"
    assert restless_m > still_m


def test_duty_cycled_night_stages_asleep_not_all_awake():
    """The end-to-end symptom: a sparse duty-cycled night with movement-isolated motion must stage mostly
    ASLEEP, not collapse to all-awake / stage-less."""
    import numpy as np
    from datetime import datetime, timezone
    from app.core import staging

    rng = np.random.default_rng(9)
    sample_epochs, accel, hr, rmssd = [], [], [], []
    for i, e in enumerate(range(0, 7 * 60 * 2, 6)):  # a 30 s burst every 3 min over 7 h
        sample_epochs.append(e)
        m = abs(float(rng.normal(0, 3)))                       # still-wrist movement std (cg)
        if rng.random() < 0.12:
            m += abs(float(rng.normal(25, 8)))                 # restless/wake epoch
        accel.append(m)
        hr.append(52 + 6 * np.sin(i / 20.0) + float(rng.normal(0, 2)))
        rmssd.append(35 + float(rng.normal(0, 8)))
    t0 = datetime(2026, 7, 10, 4, 0, 0, tzinfo=timezone.utc)
    m = staging.stage_night(accel_counts=accel, hr_bpm=hr, rmssd_ms=rmssd,
                            start=t0.isoformat(), end=t0.isoformat(), sample_epochs=sample_epochs)
    asleep = m["deep_min"] + m["rem_min"] + m["light_min"]
    assert asleep > m["awake_min"], f"night staged mostly awake ({asleep} asleep vs {m['awake_min']} awake)"


def test_epoch_rmssd_rejects_ppg_artifacts_instead_of_saturating():
    """Per-epoch RMSSD must not pin at the 250ms ceiling from ppg missed/doubled beats.

    Real wrist-ppg peak detection injects the occasional spurious interval; the old crude
    [300,2000]+clamp path let one huge successive difference saturate RMSSD, pinning whole
    nights at 250 and flattening the deep/REM HRV discriminator. The artifact-robust path
    rejects implausible beat-to-beat jumps before the RMS.
    """
    from app.core.hrv import _epoch_rmssd_ms
    import math
    import numpy as np
    rng = np.random.default_rng(7)
    clean = 1000.0 + rng.normal(0, 30, 30)
    noisy = clean.copy()
    for i in (5, 12, 20, 25):          # a few missed/doubled beats
        noisy[i] = 1900.0 if i % 2 else 500.0

    r_clean = _epoch_rmssd_ms(clean)
    r_noisy = _epoch_rmssd_ms(noisy)
    assert 15 < r_clean < 100, r_clean                 # real overnight RMSSD band
    assert r_noisy < 100, r_noisy                      # crucially NOT pinned at 250
    assert abs(r_noisy - r_clean) < 15, (r_clean, r_noisy)   # artifacts rejected → ~clean
    # An essentially all-artifact epoch is honestly unknown, not a fabricated 250.
    assert math.isnan(_epoch_rmssd_ms(np.tile([1000.0, 500.0], 15)))


def test_finite_int_indices_drops_nan_so_kubios_does_not_crash():
    """NeuroKit artifact index lists can carry NaN placeholders; int(NaN) 500'd the HRV request
    (blocking reprocess of real nights). The guard keeps finite indices and drops non-finite ones."""
    from app.core.hrv import _finite_int_indices
    import numpy as np
    assert _finite_int_indices([1.0, float("nan"), 3.0, float("inf"), 5.0]) == {1, 3, 5}
    assert _finite_int_indices(np.array([np.nan, np.nan])) == set()
    assert _finite_int_indices([]) == set()


def test_process_hrv_ppg_degenerate_window_returns_clean_response_not_500():
    """A degenerate ppg window (beats peak-detect into all-artifact epochs) must return a clean HRV
    result — epoch_rmssd None per epoch, valid False — never raise (the crash that blocked reprocess)."""
    from app.core import hrv
    import numpy as np
    fs = 25
    # Pulses at alternating 0.6s/1.2s intervals: successive IBIs alternate 600/1200 (all-artifact).
    t, cur, toggle = [], 0.0, True
    while cur < 90:
        cur += 0.6 if toggle else 1.2
        toggle = not toggle
        t.append(cur)
    n = int(90 * fs)
    ppg = np.zeros(n)
    for pt in t:
        i = int(pt * fs)
        for k in range(-3, 4):
            if 0 <= i + k < n:
                ppg[i + k] += np.exp(-(k * k) / 2.0) * 100
    r = hrv.process_hrv(ppg=ppg.tolist(), sample_rate_hz=fs, ibi_ms=None, accel=None, want_resp=False)
    # The point is it RETURNS (never raises int(NaN)); the all-artifact epochs are guarded to None.
    assert isinstance(r, dict)
    assert r["epoch_rmssd"] is not None and all(x is None for x in r["epoch_rmssd"])
