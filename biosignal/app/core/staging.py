"""Walch-style actigraphy + HR sleep staging (baseline v1).

This is a TRANSPARENT HEURISTIC, not the trained Walch model. See 03-algorithms.md §3:
honest expectations are sleep/wake ~80-90%, wake specificity ~50-70%, 4-stage κ ~0.4-0.6,
deep(N3) weakest without EEG. We lead with sleep/wake + duration (solid) and present
stages with low confidence.

Features used (Walch 2019, `ojwalch/sleep_classifiers`):
  - motion  : activity counts per 30 s epoch (the dominant sleep/wake signal)
  - HR level: heart rate relative to the night's resting floor
  - HR var  : local HR standard deviation (REM = irregular/sympathetic; NREM = stable/vagal)

TRAINED MODEL (DEFAULT): a gradient-boosted classifier over `sleep_features.extract_features`,
trained + cross-validated on REAL polysomnography (PhysioNet Walch 2019 — wrist motion + HR +
PSG stages). Leave-subjects-out it reaches sleep/wake κ≈0.46 and 4-class accuracy≈59% — i.e.
literature-grade for wrist motion+HR with no EEG (Walch 2019), tested on people it never saw.
It is the default; SLEEP_MODEL_ENABLED=0 falls back to the physiology HMM / heuristic below
(also used automatically when HR is absent). Retrain: scripts/train_real.py <data> --save.
(An earlier synthetic-trained model proved brittle on real data and was replaced — synthetic
sleep doesn't generalise; real PSG does.)
"""

from __future__ import annotations

import os
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Optional

import joblib
import numpy as np

from . import sleep_features, sleep_hmm

EPOCH_SEC = 30
# Hard ceiling on epochs per stage_night call (~48h). A tiny payload with a huge start/end span
# must never allocate millions of epochs and spin the Viterbi/rolling loops (memory + CPU DoS).
MAX_EPOCHS = 2 * 24 * 60 * 60 // EPOCH_SEC

# Stage codes for the 30-s hypnogram. NODATA marks an epoch the band never sampled and that is too
# far from any real sample to infer — a coverage HOLE. It is scored as neither asleep nor awake.
WAKE, LIGHT, DEEP, REM, NODATA = "wake", "light", "deep", "rem", "nodata"

# The band duty-cycles overnight (~30 s burst every few minutes) to save battery, so the night arrives
# as SPARSE samples with gaps. We bridge a short gap — a missed burst or two — by holding the neighbouring
# sample (a quiet gap between asleep samples stays asleep); a gap longer than this is a real coverage hole
# (band off, a mode change, or a genuinely unsampled stretch) and must NOT be smeared into sleep. 16 epochs
# = 8 min ≈ 2–3 duty periods.
FILL_GAP_MAX_EPOCHS = 16

# Trained classifier (HistGradientBoosting on motion + HR features). This is the DEFAULT
# stager, controlled by SLEEP_MODEL_ENABLED (set it to 0 to force the physiology HMM /
# heuristic fallback instead -- e.g. for debugging or HR-less streams). The shipped model is
# trained on REAL PSG data (PhysioNet / Walch 2019, 28 subjects; see scripts/train_sleep_model.py),
# cross-validated leave-subjects-out at sleep/wake kappa ~0.46, 4-class accuracy ~59% --
# literature-grade for wrist motion + HR without EEG.
_MODEL_PATH = Path(__file__).resolve().parent.parent / "models" / "sleep_stager.joblib"
_MODEL: Optional[dict] = None
_MODEL_TRIED = False


def _model_enabled() -> bool:
    # The real-PSG-trained model (PhysioNet Walch 2019) is the validated DEFAULT.
    # Set SLEEP_MODEL_ENABLED=0 to force the physiology HMM / heuristic instead.
    return os.getenv("SLEEP_MODEL_ENABLED", "1").strip().lower() not in ("0", "false", "off", "no")


def _load_model() -> Optional[dict]:
    global _MODEL, _MODEL_TRIED
    if not _MODEL_TRIED:
        _MODEL_TRIED = True
        if not _model_enabled():
            return None
        try:
            if _MODEL_PATH.exists():
                _MODEL = joblib.load(_MODEL_PATH)
        except Exception:
            _MODEL = None
    return _MODEL


def _to_epochs(values: np.ndarray, n_epochs: int) -> np.ndarray:
    """Resample/clip an arbitrary-length series to exactly n_epochs (nearest-bin mean)."""
    values = np.asarray(values, dtype=float).ravel()
    if values.size == 0:
        return np.zeros(n_epochs)
    if values.size == n_epochs:
        return values
    idx = np.linspace(0, values.size - 1, n_epochs)
    return np.interp(idx, np.arange(values.size), values)


def _reconstruct(values, sample_epochs, n_epochs: int, max_gap: int = FILL_GAP_MAX_EPOCHS):
    """Scatter SPARSE duty-cycle samples onto the full [start,end] epoch grid at their real index, then
    hold-fill short gaps and leave long gaps as holes.

    This is the heart of the duty-cycle fix. Each sample is placed at its own epoch (so a night of 30-s
    bursts lands across the true bed→wake span, not concatenated into a short block), and replayed bursts
    that share an epoch simply overwrite — they can't inflate coverage. Short gaps between samples are
    held (a quiet stretch between asleep samples reads asleep); gaps longer than ``max_gap`` stay NaN so
    the caller can mark them ``NODATA`` instead of fabricating sleep across a wakeful/off-wrist hour.

    Returns ``(filled, covered)``: ``filled`` is the grid with NaN in the holes; ``covered`` is a bool
    mask of the epochs that are real-or-bridged (everything else is a hole).
    """
    grid = np.full(n_epochs, np.nan)
    for e, v in zip(sample_epochs, np.asarray(values, dtype=float).ravel()):
        e = int(e)
        if 0 <= e < n_epochs and np.isfinite(v):
            grid[e] = v                          # duplicate epochs overwrite → replays don't add coverage
    real = np.isfinite(grid)
    filled = grid.copy()
    covered = real.copy()
    idxs = np.where(real)[0]
    for a, b in zip(idxs[:-1], idxs[1:]):         # bridge only the SHORT gaps between consecutive samples
        if b - a <= max_gap + 1:
            filled[a + 1:b] = grid[a]             # sample-and-hold across the small gap
            covered[a + 1:b] = True
    return filled, covered                        # leading/trailing (pre-first, post-last) stay holes


def _zero_to_nan(x: np.ndarray) -> np.ndarray:
    """HR of exactly 0 is 'no reading', not a real bradycardia to 0 bpm — treat it as missing so an
    all-zero HR grid can't poison the stager (which trusts HR where present)."""
    x = np.asarray(x, dtype=float)
    x[x <= 0] = np.nan
    return x


def _fill_holes(grid: np.ndarray) -> np.ndarray:
    """Fill NaN holes with the nearest finite value (forward then backward) so the stager sees a
    contiguous grid; the holes are relabelled NODATA afterwards, so these fills never reach the summary.
    An all-NaN grid becomes zeros (the 'no signal' the fallbacks already handle)."""
    g = np.asarray(grid, dtype=float).copy()
    finite = np.where(np.isfinite(g))[0]
    if finite.size == 0:
        return np.zeros_like(g)
    last = None
    for i in range(g.size):                       # forward-fill
        if np.isfinite(g[i]):
            last = g[i]
        elif last is not None:
            g[i] = last
    nxt = None
    for i in range(g.size - 1, -1, -1):           # back-fill the leading holes
        if np.isfinite(g[i]):
            nxt = g[i]
        elif nxt is not None:
            g[i] = nxt
    return g


def _rolling_std(x: np.ndarray, win: int = 5) -> np.ndarray:
    out = np.zeros_like(x, dtype=float)
    half = win // 2
    for i in range(len(x)):
        lo, hi = max(0, i - half), min(len(x), i + half + 1)
        out[i] = np.std(x[lo:hi]) if hi - lo > 1 else 0.0
    return out


def _denoise_hr(hr: np.ndarray, k: int = 5, n_sigmas: float = 3.0) -> np.ndarray:
    """Clean the RAW sparse HR *readings* (a Hampel spike-reject + a light rolling median), operating on
    the real readings IN ORDER — and it MUST run BEFORE reconstruction, not on the epoch grid.

    Our band's duty-cycled PPG HR jumps 58->80->86->49->88 between readings (median |dHR| ~5 bpm, p90 ~16)
    -- sampling noise, NOT autonomic signal. The trained stager reads multi-scale HR *variability* (rolling
    std/gradients/acceleration in sleep_features) as REM, so that jitter over-stages REM badly (~48%).

    Crucially (Henry's reseal proof, f42c3f8): filtering the POST-reconstruction grid does nothing, because
    that grid is a STEP function -- each real reading is HELD across ~5 epochs -- and a rolling median can't
    remove a sustained step, only shift its edge. So we filter the raw readings themselves: extract the
    finite readings, Hampel-reject any that sit > n_sigmas MADs from their neighbours (kills the spike
    readings at the source), then a light rolling median to tamp residual jitter, and scatter the CLEANED
    readings back. NaN gaps (unsampled epochs) are preserved for the reconstruction to hole-fill.
    """
    x = np.asarray(hr, dtype=float).ravel().copy()
    idx = np.where(np.isfinite(x))[0]
    if idx.size < 3 or k < 1:
        return x
    vals = x[idx].astype(float)          # the real readings, compacted in time order

    # 1) Hampel: replace a reading that deviates > n_sigmas robust-sigmas from its local median.
    cleaned = vals.copy()
    for i in range(vals.size):
        lo, hi = max(0, i - k), min(vals.size, i + k + 1)
        w = vals[lo:hi]
        med = np.median(w)
        sigma = 1.4826 * np.median(np.abs(w - med))
        if sigma > 0 and abs(vals[i] - med) > n_sigmas * sigma:
            cleaned[i] = med

    # 2) Light rolling median over the real readings (the operation proven on night #54).
    half = max(1, k // 2)
    smoothed = cleaned.copy()
    for i in range(cleaned.size):
        lo, hi = max(0, i - half), min(cleaned.size, i + half + 1)
        smoothed[i] = np.median(cleaned[lo:hi])

    x[idx] = smoothed
    return x


def _viterbi_path(log_emit: np.ndarray, log_trans: np.ndarray) -> np.ndarray:
    """Most-likely state path over per-epoch log emission probs + a log transition matrix.

    The trained bundle SHIPS a `logT` (4x4, validated WITH temporal smoothing), but inference used raw
    per-epoch argmax and ignored it -- a real train/inference mismatch that let impossible bouts through
    (a 69-min continuous-REM block; real REM bouts cap ~40 min). Decoding with logT enforces realistic
    stage durations. O(n*states^2) -- states=4, n bounded by MAX_EPOCHS.
    """
    n, k = log_emit.shape
    if n == 0:
        return np.zeros(0, dtype=int)
    delta = log_emit[0].astype(float).copy()
    psi = np.zeros((n, k), dtype=int)
    for t in range(1, n):
        scores = delta[:, None] + log_trans          # (from_i, to_j)
        psi[t] = np.argmax(scores, axis=0)
        delta = log_emit[t] + np.max(scores, axis=0)
    path = np.zeros(n, dtype=int)
    path[-1] = int(np.argmax(delta))
    for t in range(n - 2, -1, -1):
        path[t] = psi[t + 1][path[t + 1]]
    return path


def stage_night(
    accel_counts: list,
    hr_bpm: Optional[list] = None,
    rmssd_ms: Optional[list] = None,
    start: Optional[str] = None,
    end: Optional[str] = None,
    sample_epochs: Optional[list] = None,
) -> dict:
    """Produce a 30-s hypnogram + summary from per-epoch accel (+ HR + RMSSD).

    Default = the cardio-respiratory HMM stager (motion + HR + per-epoch HRV, Viterbi-
    decoded over physiological transitions) — robust and untrained. The opt-in trained
    model (SLEEP_MODEL_ENABLED) and the simple threshold heuristic remain as alternatives.
    All emit a per-epoch stage list over the SAME epoch grid; we smooth + summarise identically.

    ``sample_epochs`` (parallel to ``accel_counts``) is the duty-cycle path: each value's real epoch
    index in the [start,end] grid. When given, the samples are SCATTERED onto the full night span and the
    quiet gaps between them are bridged (short) or marked NODATA holes (long) — so a night of sparse 30-s
    bursts stages across its true duration instead of collapsing to the sum of the bursts. Without it, the
    old dense behaviour (linear resample of a contiguous per-epoch series) is unchanged.
    """
    accel = np.asarray(accel_counts, dtype=float).ravel()
    if accel.size == 0:
        accel = np.zeros(1)

    # Determine epoch count from the time span when available, else from accel length.
    t0 = _parse_ts(start)
    t1 = _parse_ts(end)
    if t0 and t1 and t1 > t0:
        n_epochs = max(int((t1 - t0).total_seconds() // EPOCH_SEC), 1)
        n_epochs = min(n_epochs, MAX_EPOCHS)   # clamp a runaway span (DoS guard)
    else:
        n_epochs = min(int(accel.size), MAX_EPOCHS)
        t0 = _parse_ts(start) or datetime.now(timezone.utc)

    hole_mask: Optional[np.ndarray] = None
    if sample_epochs is not None and len(sample_epochs) == accel.size and n_epochs > 1:
        # Sparse duty-cycle reconstruction: place each burst at its real epoch, hold short gaps, hole long.
        accel_grid, covered = _reconstruct(accel, sample_epochs, n_epochs)
        hole_mask = ~covered
        accel_e = _fill_holes(accel_grid)
        # Denoise the RAW sparse readings BEFORE reconstruction (see _denoise_hr) — filtering the
        # hold-filled grid afterwards can't remove the sustained step edges the model reads as REM.
        hr_arr = _denoise_hr(_zero_to_nan(np.asarray(hr_bpm, dtype=float))) if hr_bpm else None
        if hr_arr is not None and hr_arr.size == accel.size:
            hr_grid, _ = _reconstruct(hr_arr, sample_epochs, n_epochs)
            has_hr = np.isfinite(hr_grid).any()
            hr_e = _fill_holes(hr_grid)
        else:
            has_hr = False
            hr_e = np.full(n_epochs, np.nan)
        if rmssd_ms and any(v is not None for v in rmssd_ms):
            rm = _clean_floats(rmssd_ms)
            rmssd_grid, _ = _reconstruct(rm, sample_epochs, n_epochs) if rm.size == accel.size else (None, None)
            rmssd_e = _fill_holes(rmssd_grid) if rmssd_grid is not None else None
        else:
            rmssd_e = None
    else:
        accel_e = _to_epochs(accel, n_epochs)
        # Denoise the raw readings before resampling (same rationale as the sparse path).
        hr_full = _denoise_hr(_zero_to_nan(np.asarray(hr_bpm, dtype=float))) if hr_bpm else None
        hr_e = _to_epochs(hr_full, n_epochs) if hr_full is not None else np.full(n_epochs, np.nan)
        has_hr = np.isfinite(hr_e).any()
        # Only treat RMSSD as present if at least one real value exists — an all-missing list
        # must stay None (the HMM fallback handles None), never become zeros ("no HRV", a signal
        # the stagers never trained on).
        rmssd_e = (_to_epochs(_clean_floats(rmssd_ms), n_epochs)
                   if rmssd_ms and any(v is not None for v in rmssd_ms) else None)

    hypnogram: Optional[list[str]] = None

    # 1) Default: model trained on REAL PSG (needs HR). Cross-validated leave-subjects-out
    #    at sleep/wake κ≈0.46, 4-class acc≈59% — literature-grade for wrist motion+HR.
    bundle = _load_model()
    if bundle is not None and has_hr:
        try:
            stages = bundle.get("stages", ["wake", "light", "deep", "rem"])
            model = bundle["model"]
            # HR was already denoised at the RAW-reading stage (before reconstruction); hr_e here is the
            # reconstructed grid built from cleaned readings, so features no longer fire REM on spike edges.
            X = sleep_features.extract_features(accel_e, hr_e, has_hr=True)
            log_trans = bundle.get("logT")
            if log_trans is not None and hasattr(model, "predict_proba"):
                # Decode WITH the bundle's shipped transition matrix (Viterbi) instead of raw per-epoch
                # argmax — the model was validated this way; it kills impossible bouts.
                proba = np.clip(np.asarray(model.predict_proba(X), dtype=float), 1e-9, None)
                path = _viterbi_path(np.log(proba), np.asarray(log_trans, dtype=float))
                classes = list(getattr(model, "classes_", range(len(stages))))
                hypnogram = [stages[int(classes[c])] for c in path]
            else:
                preds = model.predict(X)
                # Model classes may be ints (real-PSG model) or strings (older artifact).
                hypnogram = [stages[int(p)] if isinstance(p, (int, np.integer)) else str(p) for p in preds]
        except Exception:
            hypnogram = None

    # 2) Fallback: physiology HMM (e.g. no HR, or model unreadable).
    if hypnogram is None:
        try:
            hypnogram = sleep_hmm.stage_hmm(accel_e, hr_e if has_hr else None, rmssd_e, n_epochs)
        except Exception:
            hypnogram = None

    # 3) Last-resort transparent heuristic.
    if hypnogram is None:
        hypnogram = _heuristic_stage(accel_e, hr_e, has_hr, n_epochs)

    hypnogram = _smooth_hypnogram(hypnogram)
    if hole_mask is not None:
        # Overwrite the coverage holes AFTER smoothing so a long unsampled gap reads as NODATA, never as
        # fabricated sleep or wake. Isolated held-gaps (short) stay their inferred stage.
        hypnogram = [NODATA if hole_mask[i] else s for i, s in enumerate(hypnogram)]
    return _summarize(hypnogram, t0)


def _clean_floats(seq) -> np.ndarray:
    """[float | None] → float array with None/NaN forward-filled, for resampling."""
    arr = np.array([np.nan if v is None else float(v) for v in seq], dtype=float)
    if np.isfinite(arr).any():
        med = np.nanmedian(arr)
        arr = np.where(np.isfinite(arr), arr, med)
    else:
        arr = np.zeros_like(arr)
    return arr


def _heuristic_stage(accel_e: np.ndarray, hr_e: np.ndarray, has_hr: bool, n_epochs: int) -> list[str]:
    """Transparent Walch-style fallback (used when no trained model is present)."""
    hr_floor = np.nanpercentile(hr_e, 10) if has_hr else np.nan
    hr_var = _rolling_std(np.nan_to_num(hr_e, nan=hr_floor if has_hr else 0.0)) if has_hr else np.zeros(n_epochs)
    motion_thr = max(np.percentile(accel_e, 60), 1.0)
    hr_var_thr = np.percentile(hr_var, 70) if has_hr else 0.0

    return [
        _classify_epoch(
            motion=accel_e[i],
            motion_thr=motion_thr,
            hr=hr_e[i] if has_hr else np.nan,
            hr_floor=hr_floor,
            hr_var=hr_var[i],
            hr_var_thr=hr_var_thr,
        )
        for i in range(n_epochs)
    ]


def _classify_epoch(motion, motion_thr, hr, hr_floor, hr_var, hr_var_thr) -> str:
    """Single-epoch heuristic. Motion dominates wake/sleep; HR splits the sleep stages."""
    # High motion => wake (Walch: motion is the strongest sleep/wake predictor).
    if motion >= motion_thr * 1.5:
        return WAKE

    has_hr = np.isfinite(hr) and np.isfinite(hr_floor)
    if not has_hr:
        # Accel-only: low motion => light, very still => deep (coarse).
        return DEEP if motion < motion_thr * 0.25 else LIGHT

    hr_above_floor = hr - hr_floor

    # REM: irregular HR (high local variance) near baseline, low motion.
    if hr_var >= hr_var_thr and hr_above_floor < 8:
        return REM
    # Deep: lowest, most stable HR + minimal motion.
    if hr_above_floor < 3 and motion < motion_thr * 0.4:
        return DEEP
    # Otherwise light sleep.
    return LIGHT


def _smooth_hypnogram(hyp: list[str], min_run: int = 4) -> list[str]:
    """Remove implausibly short stage runs (<2 min) by absorbing into neighbours."""
    if not hyp:
        return hyp
    out = hyp[:]
    i = 0
    while i < len(out):
        j = i
        while j < len(out) and out[j] == out[i]:
            j += 1
        run = j - i
        if run < min_run and i > 0:
            out[i:j] = [out[i - 1]] * run
        i = j
    return out


def _summarize(hyp: list[str], t0: datetime) -> dict:
    n = len(hyp)
    counts = {WAKE: 0, LIGHT: 0, DEEP: 0, REM: 0, NODATA: 0}
    for s in hyp:
        if s in counts:
            counts[s] += 1

    epoch_min = EPOCH_SEC / 60.0
    deep_min = round(counts[DEEP] * epoch_min, 1)
    rem_min = round(counts[REM] * epoch_min, 1)
    light_min = round(counts[LIGHT] * epoch_min, 1)
    awake_min = round(counts[WAKE] * epoch_min, 1)
    asleep_min = deep_min + rem_min + light_min
    duration_min = round(asleep_min, 1)
    # Fraction of the night the band actually sampled (the rest are NODATA holes). Lets the caller
    # distinguish a real thin night ("you barely wore it") from a well-covered one, and refuse to headline
    # a mostly-hole "night".
    coverage = round((n - counts[NODATA]) / max(n, 1), 3)

    # bedtime / wake_time from first and last ASLEEP epoch (never a hole or a wake epoch).
    sleep_idx = [i for i, s in enumerate(hyp) if s not in (WAKE, NODATA)]
    if sleep_idx:
        bedtime = t0 + timedelta(seconds=sleep_idx[0] * EPOCH_SEC)
        wake_time = t0 + timedelta(seconds=(sleep_idx[-1] + 1) * EPOCH_SEC)
    else:
        bedtime = t0
        wake_time = t0 + timedelta(seconds=n * EPOCH_SEC)

    time_in_bed = max(asleep_min + awake_min, 1.0)
    efficiency = asleep_min / time_in_bed
    # Simple 0-100 quality: efficiency + healthy deep/REM proportions.
    deep_ratio = deep_min / max(asleep_min, 1.0)
    rem_ratio = rem_min / max(asleep_min, 1.0)
    _q = float(np.clip(
        100 * (0.6 * efficiency + 0.2 * min(deep_ratio / 0.18, 1.0) + 0.2 * min(rem_ratio / 0.22, 1.0)),
        0,
        100,
    ))
    quality = int(_q) if np.isfinite(_q) else 0   # int(NaN) crashes; a NaN score → 0 (unknown/degenerate)

    return {
        "duration_min": duration_min,
        "deep_min": deep_min,
        "rem_min": rem_min,
        "light_min": light_min,
        "awake_min": awake_min,
        "bedtime": bedtime.isoformat(),
        "wake_time": wake_time.isoformat(),
        "quality": quality,
        "coverage": coverage,
        "hypnogram_30s": hyp,
    }


def _parse_ts(ts: Optional[str]) -> Optional[datetime]:
    if not ts:
        return None
    try:
        return datetime.fromisoformat(ts.replace("Z", "+00:00"))
    except Exception:
        return None
