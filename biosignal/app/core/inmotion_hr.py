"""In-motion heart rate from wrist PPG + accelerometer — the workout-HR keystone.

Wrist PPG is reliable at rest but corrupted by motion during exercise: the optical signal is
swamped by tissue movement, and the on-chip bpm register *cadence-locks* — it reports the
rep/stride/grip rhythm as heart rate, at high confidence. Every workout metric (HR zones, TRIMP,
VO2, strain) needs HR that survives this. The on-watch algorithm cannot; the server can, because
we also log the 3-axis accelerometer and can subtract the motion frequencies the wrist injects.

Method (validated in scripts/validate_inmotion_hr.py on PhysioNet 'Wrist PPG During Exercise',
Jarchi & Casson — wrist PPG + accel + CHEST ECG, walk/run/cycle, vs ECG ground truth):
  1. Band-pass PPG and accel-magnitude to the plausible HR band (0.7-3.7 Hz = 42-222 bpm).
  2. Welch power spectra of each (independent sample rates — PPG and accel needn't match).
  3. SUPPRESS the PPG spectrum near the accelerometer's dominant motion frequencies (cadence +
     harmonics) with soft Gaussian notches — this removes the cadence-lock the on-chip algo falls
     into. (Operates on the RAW PPG spectrum, not a pre-filtered signal — re-filtering an already
     motion-compensated value is what makes naive on-device ports worse; see the HR research memo.)
  4. SOFT tracking: weight remaining peaks by continuity with the previous estimate (HR can't jump
     between windows) — a Gaussian prior, NOT a hard lock-in, so a true HR change is still followed.
  5. Confidence from spectral concentration of the chosen peak, penalised when HR ≈ cadence (the
     irreducible failure mode: when true HR and motion frequency coincide, the wrist cannot separate
     them). Low-confidence windows HOLD the last good value and are flagged unreliable — we refuse
     to emit a confidently-wrong number.

This is wellness-scope estimation with an honest reliability flag, never a medical measurement.
"""

from __future__ import annotations

from typing import Optional, Sequence

import numpy as np
from scipy.signal import butter, filtfilt, welch

# Plausible instantaneous HR band. 0.7-3.7 Hz = 42-222 bpm — wide enough for deep rest through an
# all-out sprint, narrow enough to exclude the DC/respiration band below and high-frequency noise.
HR_LO_HZ = 0.7
HR_HI_HZ = 3.7

WIN_S = 8.0          # analysis window — long enough for ~0.125 Hz (7.5 bpm) Welch resolution at 25 Hz
STEP_S = 2.0         # hop between windows — a fresh HR estimate every 2 s
NOTCH_SIGMA = 0.15   # width (Hz) of the Gaussian suppression around each accel motion peak
NOTCH_DEPTH = 0.9    # how deeply to suppress at a motion peak (1.0 = full null)
N_ACCEL_PEAKS = 3    # number of motion frequencies (cadence + harmonics) to suppress
TRACK_SIGMA_BPM = 12.0   # continuity prior width — how far HR may plausibly move between windows
COLLISION_HZ = 0.12  # if |HR - dominant cadence| < this, flag a cadence collision (HR ≈ motion)
MIN_CONFIDENCE = 55  # below this a window is unreliable → hold last good (tunable; see validation)
# Viterbi global-tracking weights (estimate_series). A window's pull on the HR path scales with its own
# spectral peakiness (a confident window pins the path; a noisy/ambiguous one lets the smoothness prior
# carry the estimate THROUGH it — the principled replacement for greedy hold-last-good). OBS_GAIN sets
# how strongly a clean peak can overcome the continuity cost of moving (tuned so a sharp peak can follow
# a real HR change but noise cannot drag the path onto a motion harmonic). SEED_SIGMA_BPM is the prior
# width around a supplied seed HR for the first window.
OBS_GAIN = 12.0
SEED_SIGMA_BPM = 20.0


def _bandpass(x: np.ndarray, fs: float) -> np.ndarray:
    """4th-order Butterworth band-pass into the HR band. Zero-phase (filtfilt)."""
    x = np.nan_to_num(np.asarray(x, dtype=float))
    if x.size < 12 or fs <= 2 * HR_HI_HZ:
        return x
    b, a = butter(4, [HR_LO_HZ / (fs / 2), HR_HI_HZ / (fs / 2)], btype="band")
    return filtfilt(b, a, x)


def _band_spectrum(sig: np.ndarray, fs: float) -> tuple[np.ndarray, np.ndarray]:
    """Welch PSD restricted to the HR band → (freqs Hz, power)."""
    sig = np.nan_to_num(np.asarray(sig, dtype=float))
    if sig.size < 8:
        return np.empty(0), np.empty(0)
    nper = int(min(sig.size, max(64, fs * WIN_S)))
    f, p = welch(sig, fs=fs, nperseg=nper)
    m = (f >= HR_LO_HZ) & (f <= HR_HI_HZ)
    return f[m], p[m]


def estimate_window(
    ppg: np.ndarray,
    fs_ppg: float,
    ax: np.ndarray,
    ay: np.ndarray,
    az: np.ndarray,
    fs_acc: float,
    prev_bpm: Optional[float] = None,
) -> dict:
    """One window → {bpm, confidence (0-100), cadence_bpm, cadence_collision}.

    `bpm` is NaN when the window has no usable spectrum. `cadence_bpm` is the dominant motion
    frequency in bpm (the value the on-chip algo would cadence-lock onto). `cadence_collision`
    is True when the HR estimate sits on top of that motion peak — the case where even this method
    is uncertain, so the caller should lean on continuity / hold-last-good.
    """
    fp, pp = _band_spectrum(_bandpass(ppg, fs_ppg), fs_ppg)
    if pp.size < 3 or not np.any(pp > 0):
        return {"bpm": float("nan"), "confidence": 0.0, "cadence_bpm": float("nan"),
                "cadence_collision": False}

    # Accelerometer motion spectrum (magnitude — rotation-invariant). Independent sample rate.
    ax = np.nan_to_num(np.asarray(ax, dtype=float))
    ay = np.nan_to_num(np.asarray(ay, dtype=float))
    az = np.nan_to_num(np.asarray(az, dtype=float))
    cadence_hz = float("nan")
    pp_supp = pp.copy()
    if ax.size and ay.size and az.size:
        mag = np.sqrt(ax ** 2 + ay ** 2 + az ** 2)
        fa, pa = _band_spectrum(_bandpass(mag, fs_acc), fs_acc)
        if pa.size:
            top = fa[np.argsort(pa)[-N_ACCEL_PEAKS:]]
            cadence_hz = float(fa[int(np.argmax(pa))])
            for mf in top:
                pp_supp = pp_supp * (1 - NOTCH_DEPTH * np.exp(-((fp - mf) ** 2) / (2 * NOTCH_SIGMA ** 2)))

    if not np.any(pp_supp > 0):
        return {"bpm": float("nan"), "confidence": 0.0,
                "cadence_bpm": cadence_hz * 60.0 if np.isfinite(cadence_hz) else float("nan"),
                "cadence_collision": False}

    pp_norm = pp_supp / (pp_supp.max() + 1e-12)
    hz_bpm = fp * 60.0

    # Soft continuity prior: prefer peaks near the previous HR, but don't force it.
    if prev_bpm is not None and np.isfinite(prev_bpm):
        score = pp_norm * np.exp(-((hz_bpm - prev_bpm) ** 2) / (2 * TRACK_SIGMA_BPM ** 2))
    else:
        score = pp_norm
    if not np.any(score > 0):
        score = pp_norm
    k = int(np.argmax(score))
    peak_f = fp[k]
    bpm = float(peak_f * 60.0)

    # Confidence = spectral concentration of the chosen peak on the SUPPRESSED spectrum: how much of
    # the in-band power sits within ±0.12 Hz of the peak. A clean pulse is sharp (high concentration);
    # motion residue spreads the power (low). Then penalise a cadence collision, where HR ≈ motion
    # frequency and we genuinely can't be sure we didn't track a residual of the cadence.
    near = np.abs(fp - peak_f) <= COLLISION_HZ
    concentration = float(pp_supp[near].sum() / (pp_supp.sum() + 1e-12))
    confidence = float(np.clip(concentration * 140.0, 0.0, 100.0))
    collision = bool(np.isfinite(cadence_hz) and abs(peak_f - cadence_hz) <= COLLISION_HZ)
    if collision:
        confidence *= 0.5

    return {
        "bpm": bpm,
        "confidence": round(confidence, 1),
        "cadence_bpm": round(cadence_hz * 60.0, 1) if np.isfinite(cadence_hz) else float("nan"),
        "cadence_collision": collision,
    }


def _suppressed_psd(
    ppg_win: np.ndarray, fs_ppg: float,
    ax: np.ndarray, ay: np.ndarray, az: np.ndarray, fs_acc: float,
) -> tuple[np.ndarray, Optional[np.ndarray], float]:
    """One window → (freqs Hz, accel-suppressed in-band PPG PSD, dominant cadence Hz).

    This is the spectral front-end shared by estimate_window and the Viterbi tracker: band-pass the
    PPG, take its Welch PSD over the HR band, and soft-notch the accelerometer's cadence + harmonics.
    Returns psd=None when the window has no usable spectrum (the Viterbi pass treats that as a
    no-information window and lets continuity carry the estimate through it).
    """
    fp, pp = _band_spectrum(_bandpass(ppg_win, fs_ppg), fs_ppg)
    if pp.size < 3 or not np.any(pp > 0):
        return fp, None, float("nan")
    pp_supp = pp.copy()
    cadence_hz = float("nan")
    ax = np.nan_to_num(np.asarray(ax, dtype=float))
    ay = np.nan_to_num(np.asarray(ay, dtype=float))
    az = np.nan_to_num(np.asarray(az, dtype=float))
    if ax.size and ay.size and az.size:
        mag = np.sqrt(ax ** 2 + ay ** 2 + az ** 2)
        fa, pa = _band_spectrum(_bandpass(mag, fs_acc), fs_acc)
        if pa.size:
            top = fa[np.argsort(pa)[-N_ACCEL_PEAKS:]]
            cadence_hz = float(fa[int(np.argmax(pa))])
            for mf in top:
                pp_supp = pp_supp * (1 - NOTCH_DEPTH * np.exp(-((fp - mf) ** 2) / (2 * NOTCH_SIGMA ** 2)))
    if not np.any(pp_supp > 0):
        return fp, None, cadence_hz
    return fp, pp_supp, cadence_hz


def _parabolic_bpm(psd: np.ndarray, bpm_grid: np.ndarray, k: int) -> float:
    """Sub-bin peak location (parabolic interpolation over the 3 bins around k) → bpm. Removes the
    Welch bin-quantization error from the chosen peak. Falls back to the bin center at the edges."""
    if k <= 0 or k >= psd.size - 1:
        return float(bpm_grid[k])
    y0, y1, y2 = float(psd[k - 1]), float(psd[k]), float(psd[k + 1])
    denom = y0 - 2 * y1 + y2
    if denom == 0:
        return float(bpm_grid[k])
    offset = 0.5 * (y0 - y2) / denom
    offset = max(-1.0, min(1.0, offset))
    return float(bpm_grid[k] + offset * (bpm_grid[1] - bpm_grid[0]))


def _confidence_at(psd: np.ndarray, fp: np.ndarray, k: int, cadence_hz: float) -> tuple[float, bool]:
    """Confidence (0-100) of the peak at bin k = spectral concentration within ±COLLISION_HZ, halved on
    a cadence collision. Same definition as estimate_window, evaluated at the Viterbi-chosen bin."""
    peak_f = float(fp[k])
    near = np.abs(fp - peak_f) <= COLLISION_HZ
    concentration = float(psd[near].sum() / (psd.sum() + 1e-12))
    confidence = float(np.clip(concentration * 140.0, 0.0, 100.0))
    collision = bool(np.isfinite(cadence_hz) and abs(peak_f - cadence_hz) <= COLLISION_HZ)
    if collision:
        confidence *= 0.5
    return round(confidence, 1), collision


def estimate_series(
    ppg: Sequence[float],
    fs_ppg: float,
    accel_x: Sequence[float],
    accel_y: Sequence[float],
    accel_z: Sequence[float],
    fs_acc: float,
    seed_bpm: Optional[float] = None,
    win_s: float = WIN_S,
    step_s: float = STEP_S,
    min_confidence: float = MIN_CONFIDENCE,
) -> dict:
    """Sliding-window in-motion HR over a whole workout window, tracked with a GLOBAL Viterbi pass.

    Instead of greedily committing each window to its own spectral peak (and holding-last-good when a
    window is poor — which freezes through cadence collisions and drifts on dropouts), we treat the HR
    track as the most-likely path through the time × frequency trellis: each window's accel-suppressed
    PSD is the observation likelihood (weighted by its own peakiness, so a clean window pins the path
    and a noisy one defers to continuity), and a Gaussian transition prior (TRACK_SIGMA_BPM) enforces
    that HR changes slowly. Because the path sees the whole recording, it bridges a bad window using the
    good windows on BOTH sides and won't lock onto a motion harmonic — the WFPV/BeliefPPG result.

    Per-window arrays follow the path; `reliable` still flags low-confidence windows (we never claim a
    fabricated number is trustworthy), and `coverage` is the fraction of windows that clear the bar on
    their own. The chosen peak is parabolically interpolated for sub-bin (sub-Welch-quantization) bpm.
    """
    ppg = np.nan_to_num(np.asarray(ppg, dtype=float))
    fs_ppg = float(fs_ppg)
    fs_acc = float(fs_acc)
    ax = np.asarray(accel_x, dtype=float)
    ay = np.asarray(accel_y, dtype=float)
    az = np.asarray(accel_z, dtype=float)

    win = int(round(win_s * fs_ppg))
    step = max(1, int(round(step_s * fs_ppg)))
    awin = int(round(win_s * fs_acc))

    empty = {
        "t": [], "bpm": [], "confidence": [], "reliable": [], "cadence_collision": [],
        "summary": {"hr_mean": None, "hr_max": None, "hr_min": None, "coverage": 0.0, "n_windows": 0},
    }
    if win <= 0 or ppg.size < win:
        return empty

    # --- Pass 1: per-window accel-suppressed spectra on a COMMON frequency grid ----------------------
    times: list[float] = []
    psds: list[Optional[np.ndarray]] = []
    cadences: list[float] = []
    fp_ref: Optional[np.ndarray] = None
    for s in range(0, ppg.size - win + 1, step):
        times.append(round((s + win / 2.0) / fs_ppg, 2))
        a0 = int(round((s / fs_ppg) * fs_acc))
        a1 = min(ax.size, a0 + awin) if ax.size else 0
        fp, psd, cad = _suppressed_psd(
            ppg[s:s + win], fs_ppg,
            ax[a0:a1] if ax.size else ax,
            ay[a0:a1] if ay.size else ay,
            az[a0:a1] if az.size else az,
            fs_acc,
        )
        if psd is not None and fp_ref is None:
            fp_ref = fp
        psds.append(psd)
        cadences.append(cad)

    n = len(times)
    # No window produced a usable spectrum → all unknown.
    if fp_ref is None:
        return {
            "t": times, "bpm": [None] * n, "confidence": [0.0] * n,
            "reliable": [False] * n, "cadence_collision": [False] * n,
            "summary": {"hr_mean": None, "hr_max": None, "hr_min": None, "coverage": 0.0, "n_windows": n},
        }

    fp = fp_ref
    k_bins = fp.size
    bpm_grid = fp * 60.0

    # Observation matrix: obs[t, k] = OBS_GAIN · peakiness_t² · normalized_PSD_t[k]. The SQUARED
    # peakiness is what separates a clean window (sharp peak ⇒ strong pull on the path) from a
    # noisy/ambiguous one (flat spectrum ⇒ ~zero pull, so the transition prior carries the estimate
    # THROUGH it — the principled, interpolated replacement for greedy hold-last-good; without the
    # square, residual noise peakiness accumulates over many windows and can drag the path off-track).
    # A missing or grid-mismatched window contributes a flat (zero) row → pure continuity.
    obs = np.zeros((n, k_bins), dtype=float)
    peakiness = np.zeros(n, dtype=float)
    for t, psd in enumerate(psds):
        if psd is None or psd.size != k_bins:
            continue
        total = psd.sum()
        if total <= 0:
            continue
        norm = psd / total
        peak = float(norm.max())
        peakiness[t] = peak
        obs[t] = OBS_GAIN * (peak ** 2) * norm

    # --- Pass 2: Viterbi over the window sequence ---------------------------------------------------
    diff = bpm_grid[None, :] - bpm_grid[:, None]            # diff[j, k] = bpm[k] - bpm[j]
    trans = -(diff ** 2) / (2.0 * TRACK_SIGMA_BPM ** 2)     # log transition prior (rows=from, cols=to)

    delta = obs[0].copy()
    if seed_bpm is not None and np.isfinite(seed_bpm):
        delta = delta - ((bpm_grid - float(seed_bpm)) ** 2) / (2.0 * SEED_SIGMA_BPM ** 2)
    psi = np.zeros((n, k_bins), dtype=int)
    for t in range(1, n):
        scored = delta[:, None] + trans                    # K×K: best previous bin j for each next bin k
        psi[t] = np.argmax(scored, axis=0)
        delta = scored[psi[t], np.arange(k_bins)] + obs[t]

    path = np.zeros(n, dtype=int)
    path[-1] = int(np.argmax(delta))
    for t in range(n - 1, 0, -1):
        path[t - 1] = psi[t, path[t]]

    # --- Pass 3: read out bpm / confidence along the path ------------------------------------------
    bpms: list[Optional[float]] = []
    confs: list[float] = []
    reliable: list[bool] = []
    collisions: list[bool] = []
    n_good = 0
    last_good: Optional[float] = None
    for t in range(n):
        k = int(path[t])
        psd = psds[t]
        if psd is not None and psd.size == k_bins and peakiness[t] > 0:
            conf, collision = _confidence_at(psd, fp, k, cadences[t])
            ok = conf >= min_confidence
            if ok:
                bpm = round(_parabolic_bpm(psd, bpm_grid, k), 1)   # trusted → sub-bin refine
                last_good = bpm
                n_good += 1
            else:
                bpm = round(float(bpm_grid[k]), 1)                 # not trusted → the smooth held path value
        else:
            conf, collision, ok = 0.0, False, False
            bpm = round(float(bpm_grid[k]), 1)                     # no spectrum → carried by continuity
        bpms.append(bpm)
        confs.append(conf)
        reliable.append(ok)
        collisions.append(collision)
        _ = last_good  # path is already continuous; last_good kept only for parity/debugging

    rel_vals = [b for b, r in zip(bpms, reliable) if r and b is not None]
    summary = {
        "hr_mean": round(float(np.mean(rel_vals)), 1) if rel_vals else None,
        "hr_max": round(float(np.max(rel_vals)), 1) if rel_vals else None,
        "hr_min": round(float(np.min(rel_vals)), 1) if rel_vals else None,
        "coverage": round(n_good / n, 3) if n else 0.0,
        "n_windows": n,
    }
    return {
        "t": times,
        "bpm": bpms,
        "confidence": confs,
        "reliable": reliable,
        "cadence_collision": collisions,
        "summary": summary,
    }


# --- Time-domain peak tracker (the DEFAULT in-motion estimator) ------------------------------------
# Why this is the default and the spectral notch (estimate_series) is not: measured on real
# ECG-referenced data (PhysioNet Wrist-PPG-During-Exercise, Jarchi & Casson, scripts/proto_inmotion_
# compare.py), the accel cadence-notch only beats simple peak detection for CYCLING (wrist still, legs
# at a distinct frequency). For WALKING and RUNNING the wrist swings at a cadence whose harmonics
# blanket the HR band, so notching deletes the pulse — the notch scored ~74-84 bpm MAE there vs ~12
# (walk) / ~24 (run) for time-domain peak detection. So we detect beats on the pulse itself and apply
# a causal continuity tracker; the notch path stays available for an explicit cycling caller.
PEAKTRACK_MAX_JUMP_BPM = 12.0   # reject a per-window estimate that jumps more than this (half/double lock)


def _detect_beats(ppg: np.ndarray, fs: float) -> np.ndarray:
    """Beat times (s) over the whole signal. NeuroKit's PPG pipeline (Elgendi) when available — the
    detector our HRV path uses and the proto validated — else a scipy band-pass + find_peaks fallback."""
    ppg = np.nan_to_num(np.asarray(ppg, dtype=float))
    try:
        import neurokit2 as nk  # lazy: keeps the module importable without nk (e.g. unit tests)
        _, info = nk.ppg_process(ppg, sampling_rate=fs)
        pk = np.asarray(info.get("PPG_Peaks", []), dtype=float)
        if pk.size >= 2:
            return pk / fs
    except Exception:
        pass
    from scipy.signal import find_peaks
    sig = _bandpass(ppg, fs)
    min_dist = max(1, int(fs / HR_HI_HZ))                 # >= 0.27 s apart (<=222 bpm)
    pk, _ = find_peaks(sig, distance=min_dist, prominence=0.3 * np.std(sig) + 1e-9)
    return pk.astype(float) / fs


def peaktrack_series(
    ppg: Sequence[float],
    fs_ppg: float,
    seed_bpm: Optional[float] = None,
    win_s: float = WIN_S,
    step_s: float = STEP_S,
    min_confidence: float = MIN_CONFIDENCE,
    max_jump_bpm: float = PEAKTRACK_MAX_JUMP_BPM,
) -> dict:
    """In-motion HR by time-domain peak detection + causal continuity tracking (no accelerometer).

    Same return shape as estimate_series. Per window: HR = 60 / median(plausible inter-beat interval);
    confidence = beat-interval regularity (a clean pulse is metronomic, motion scatters it). A causal
    tracker rejects per-window jumps > max_jump_bpm (the peak detector's occasional half/double lock),
    easing toward instead of snapping. Low-confidence windows are flagged unreliable (held, not trusted).
    """
    ppg = np.nan_to_num(np.asarray(ppg, dtype=float))
    fs_ppg = float(fs_ppg)
    win = int(round(win_s * fs_ppg))
    step = max(1, int(round(step_s * fs_ppg)))
    empty = {
        "t": [], "bpm": [], "confidence": [], "reliable": [], "cadence_collision": [],
        "summary": {"hr_mean": None, "hr_max": None, "hr_min": None, "coverage": 0.0, "n_windows": 0},
    }
    if win <= 0 or ppg.size < win:
        return empty

    beats = _detect_beats(ppg, fs_ppg)
    ibi_lo, ibi_hi = 1.0 / HR_HI_HZ, 1.0 / HR_LO_HZ        # plausible IBI band (s): 0.27 .. 1.43

    times: list[float] = []
    raw_bpm: list[float] = []
    confs: list[float] = []
    for s in range(0, ppg.size - win + 1, step):
        times.append(round((s + win / 2.0) / fs_ppg, 2))
        lo, hi = s / fs_ppg, (s + win) / fs_ppg
        seg = beats[(beats >= lo) & (beats < hi)]
        bpm, conf = float("nan"), 0.0
        if seg.size >= 3:
            ibis = np.diff(seg)
            ibis = ibis[(ibis >= ibi_lo) & (ibis <= ibi_hi)]
            if ibis.size >= 2:
                bpm = float(60.0 / np.median(ibis))
                cv = float(np.std(ibis) / (np.mean(ibis) + 1e-9))   # interval regularity
                conf = float(np.clip((1.0 - min(cv, 0.4) / 0.4) * 100.0, 0.0, 100.0))
        raw_bpm.append(bpm)
        confs.append(conf)

    bpms: list[Optional[float]] = []
    reliable: list[bool] = []
    n_good = 0
    prev = float(seed_bpm) if (seed_bpm is not None and np.isfinite(seed_bpm)) else None
    for bpm, conf in zip(raw_bpm, confs):
        ok = bool(np.isfinite(bpm)) and conf >= min_confidence
        if np.isfinite(bpm):
            if prev is None or abs(bpm - prev) <= max_jump_bpm:
                prev = bpm
            else:
                prev = prev + np.sign(bpm - prev) * max_jump_bpm * 0.5   # ease toward, don't snap
        if ok:
            n_good += 1
        bpms.append(round(float(prev), 1) if prev is not None and np.isfinite(prev) else None)
        reliable.append(ok)

    n = len(times)
    rel_vals = [b for b, r in zip(bpms, reliable) if r and b is not None]
    summary = {
        "hr_mean": round(float(np.mean(rel_vals)), 1) if rel_vals else None,
        "hr_max": round(float(np.max(rel_vals)), 1) if rel_vals else None,
        "hr_min": round(float(np.min(rel_vals)), 1) if rel_vals else None,
        "coverage": round(n_good / n, 3) if n else 0.0,
        "n_windows": n,
    }
    return {
        "t": times,
        "bpm": bpms,
        "confidence": confs,
        "reliable": reliable,
        "cadence_collision": [False] * n,
        "summary": summary,
    }
