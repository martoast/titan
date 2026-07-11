"""Unit tests for the sleep-staging robustness fixes (Henry's night-#54 diagnosis).

These guard the MECHANISMS that fix the "band HR jitter reads as REM" pathology:
  - `_denoise_hr` removes epoch-to-epoch PPG-HR spikes before feature extraction.
  - `_viterbi_path` decodes with the bundle's shipped transition matrix (temporal smoothing) instead
    of raw per-epoch argmax, so impossible rapid stage flips / marathon bouts can't survive.

The end-to-end REM-fraction improvement was PROVEN by Henry on a real reflashed night (#54, 48%->20%
REM); it can't be reproduced from synthetic HR here (synthetic jitter isn't real duty-cycled PPG), so
that verification lives with re-sealing the real night. Here we pin the deterministic building blocks.
"""

import numpy as np

from app.core import staging


def test_denoise_hr_removes_epoch_jitter_but_keeps_structure():
    rng = np.random.default_rng(0)
    n = 600
    # A slow HR floor with a real REM-like surge, plus heavy epoch-to-epoch PPG jitter on top.
    base = np.full(n, 52.0)
    base[200:260] = 62.0
    hr = base + rng.normal(0, 9, n)

    before = float(np.median(np.abs(np.diff(hr))))
    after = staging._denoise_hr(hr, k=5)
    after_jitter = float(np.median(np.abs(np.diff(after))))

    assert before > 5.0                          # the raw signal is genuinely jittery
    assert after_jitter < before / 3             # the median filter kills the epoch spikes
    # The slow structure survives: the surge region still reads clearly higher than the floor.
    assert np.median(after[200:260]) > np.median(after[:150]) + 5


def test_denoise_hr_preserves_holes_and_is_safe_on_edges():
    hr = np.array([60.0, np.nan, 61.0, 200.0, 59.0, np.nan])
    out = staging._denoise_hr(hr, k=5)
    assert np.isnan(out[1]) and np.isnan(out[5])         # unsampled epochs stay holes
    assert out[3] < 100                                  # the 200 bpm spike is medianed away
    # Degenerate inputs never raise.
    assert staging._denoise_hr(np.array([]), 5).size == 0
    assert np.allclose(staging._denoise_hr(np.array([60.0, 61.0]), 1), [60.0, 61.0])


def test_viterbi_path_smooths_a_flip_flopping_emission():
    # Two states; the per-epoch argmax alternates every epoch, but transitions are strongly penalized,
    # so the most-likely PATH is a single constant state (no oscillation) — the temporal-smoothing win.
    n = 40
    log_emit = np.zeros((n, 2))
    for i in range(n):
        log_emit[i] = [0.0, -0.2] if i % 2 == 0 else [-0.2, 0.0]   # near-tie, alternating winner
    log_trans = np.log(np.array([[0.99, 0.01], [0.01, 0.99]]))     # staying is 99×more likely than flipping
    path = staging._viterbi_path(log_emit, log_trans)

    flips = int(np.sum(np.abs(np.diff(path))))
    assert flips == 0                                    # argmax would flip ~39 times; Viterbi holds one state
    argmax_flips = int(np.sum(np.abs(np.diff(np.argmax(log_emit, axis=1)))))
    assert argmax_flips > 30                             # sanity: the raw signal really is flip-flopping


def test_viterbi_path_follows_strong_evidence_and_handles_empty():
    # A clear two-block signal must still be decoded as two blocks (smoothing ≠ collapsing everything).
    log_emit = np.vstack([np.tile([0.0, -8.0], (20, 1)), np.tile([-8.0, 0.0], (20, 1))])
    log_trans = np.log(np.array([[0.95, 0.05], [0.05, 0.95]]))
    path = staging._viterbi_path(log_emit, log_trans)
    assert list(path[:20]) == [0] * 20 and list(path[20:]) == [1] * 20
    assert staging._viterbi_path(np.zeros((0, 2)), log_trans).size == 0
