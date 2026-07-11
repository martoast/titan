"""Unit tests for the sleep-staging robustness fixes (Henry's night-#54 + reseal diagnoses).

The pathology: the band's duty-cycled PPG HR spikes between readings, and the trained stager reads that
HR *variability* (rolling std/gradients in sleep_features) as REM (~48% on real nights).

The load-bearing lesson from the reseal review (f42c3f8): filtering the POST-reconstruction epoch grid
does NOT help, because that grid is a step function (each reading HELD across ~5 epochs) and a rolling
median can't remove a sustained step. The denoise MUST run on the RAW readings before reconstruction.
So the key test below drives the fix through the SAME `_reconstruct` path the seal uses and asserts the
reconstructed grid's variability actually drops — the thing an isolated model call missed.

(The end-to-end REM fraction on Alex's real nights is validated by re-sealing them on prod — the real
duty-cycled HR pathology can't be faithfully reproduced from synthetic data here.)
"""

import numpy as np

from app.core import staging


def test_denoise_hr_rejects_spike_readings_but_keeps_slow_structure():
    # Henry's shape: a slow HR floor with a real REM-like surge, punctuated by spike readings.
    hr = np.array([52, 53, 86, 51, 52, 49, 53, 52, 63, 62, 88, 61, 62, 40, 63, 62], dtype=float)
    out = staging._denoise_hr(hr)
    # The isolated spikes (86, 88, 40) are pulled back toward their neighbours…
    assert out[2] < 70 and out[10] < 75 and out[13] > 50
    # …while the two regimes (floor ~52, surge ~62) remain clearly distinct.
    assert np.median(out[:8]) < np.median(out[8:]) - 5
    assert float(np.median(np.abs(np.diff(out)))) < float(np.median(np.abs(np.diff(hr))))


def test_denoise_before_reconstruction_cuts_the_grids_rem_variability():
    # THE load-bearing test: clean raw sparse readings, then reconstruct via the SAME path the seal uses,
    # and confirm the reconstructed grid's rolling-STD (the exact feature the model reads as REM) collapses.
    # Filtering the hold-filled grid (the old bug) could not do this.
    n_epochs = 300
    sample_epochs = list(range(0, n_epochs, 6))
    rng = np.random.default_rng(1)
    slow = 55 + 3 * np.sin(np.linspace(0, 6, len(sample_epochs)))
    spikes = rng.choice([0, 0, 0, 28, -22, 25], len(sample_epochs))
    raw = slow + spikes + rng.normal(0, 7, len(sample_epochs))

    def grid_from(samples):
        g, _ = staging._reconstruct(np.asarray(samples, float), sample_epochs, n_epochs)
        return staging._fill_holes(g)

    raw_var = float(np.mean(staging._rolling_std(grid_from(raw), 11)))
    clean_var = float(np.mean(staging._rolling_std(grid_from(staging._denoise_hr(raw)), 11)))

    assert clean_var < raw_var / 2.0, (raw_var, clean_var)   # ~3-4x in practice


def test_denoise_hr_preserves_holes_and_is_safe_on_edges():
    hr = np.array([60.0, np.nan, 61.0, 200.0, 59.0, 60.0, 61.0, np.nan])
    out = staging._denoise_hr(hr)
    assert np.isnan(out[1]) and np.isnan(out[7])          # unsampled epochs stay holes
    assert out[3] < 100                                   # the 200 bpm spike reading is rejected
    # Degenerate inputs never raise.
    assert staging._denoise_hr(np.array([])).size == 0
    assert staging._denoise_hr(np.array([60.0, 61.0])).size == 2


def test_viterbi_path_smooths_a_flip_flopping_emission():
    n = 40
    log_emit = np.zeros((n, 2))
    for i in range(n):
        log_emit[i] = [0.0, -0.2] if i % 2 == 0 else [-0.2, 0.0]
    log_trans = np.log(np.array([[0.99, 0.01], [0.01, 0.99]]))
    path = staging._viterbi_path(log_emit, log_trans)
    assert int(np.sum(np.abs(np.diff(path)))) == 0        # Viterbi holds one state; argmax would flip ~39×
    assert int(np.sum(np.abs(np.diff(np.argmax(log_emit, axis=1))))) > 30


def test_stages_low_confidence_flags_implausible_splits_only():
    t0 = staging._parse_ts("2026-06-14T05:00:00Z")
    # A healthy split (deep 18% / rem 24% / light 58%) is NOT flagged.
    healthy = ["deep"] * 40 + ["rem"] * 52 + ["light"] * 128
    assert staging._summarize(healthy, t0)["stages_low_confidence"] is False
    # REM collapsed (<5%) — the 07-10-style model outlier — IS flagged.
    rem_collapse = ["deep"] * 70 + ["rem"] * 3 + ["light"] * 147
    assert staging._summarize(rem_collapse, t0)["stages_low_confidence"] is True
    # One stage dominating (>70%) IS flagged.
    dominated = ["light"] * 190 + ["rem"] * 20 + ["deep"] * 10
    assert staging._summarize(dominated, t0)["stages_low_confidence"] is True
    # Too little sleep to judge → never flagged (can't call a split on a handful of epochs).
    tiny = ["rem"] * 2 + ["light"] * 4
    assert staging._summarize(tiny, t0)["stages_low_confidence"] is False


def test_viterbi_path_follows_strong_evidence_and_handles_empty():
    log_emit = np.vstack([np.tile([0.0, -8.0], (20, 1)), np.tile([-8.0, 0.0], (20, 1))])
    log_trans = np.log(np.array([[0.95, 0.05], [0.05, 0.95]]))
    path = staging._viterbi_path(log_emit, log_trans)
    assert list(path[:20]) == [0] * 20 and list(path[20:]) == [1] * 20
    assert staging._viterbi_path(np.zeros((0, 2)), log_trans).size == 0
