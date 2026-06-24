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
    """Sliding-window in-motion HR over a whole workout window.

    Returns per-window arrays plus a summary. Low-confidence windows HOLD the previous good value
    and are flagged `reliable=False` (we never emit a fabricated number); `coverage` is the fraction
    of windows that cleared the confidence bar on their own.
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

    times: list[float] = []
    bpms: list[float] = []
    confs: list[float] = []
    reliable: list[bool] = []
    collisions: list[bool] = []

    prev = float(seed_bpm) if (seed_bpm is not None and np.isfinite(seed_bpm)) else None
    last_good = prev
    n_good = 0

    if ppg.size >= win and win > 0:
        # Map each PPG window's time span onto the accel stream (the two run at independent rates).
        for s in range(0, ppg.size - win + 1, step):
            t_center = (s + win / 2.0) / fs_ppg
            a0 = int(round((s / fs_ppg) * fs_acc))
            a1 = min(ax.size, a0 + awin) if ax.size else 0
            est = estimate_window(
                ppg[s:s + win], fs_ppg,
                ax[a0:a1] if ax.size else ax,
                ay[a0:a1] if ay.size else ay,
                az[a0:a1] if az.size else az,
                fs_acc, prev,
            )
            bpm, conf = est["bpm"], est["confidence"]
            ok = bool(np.isfinite(bpm)) and conf >= min_confidence
            if ok:
                last_good = bpm
                prev = bpm  # only advance the tracker on a trusted estimate (don't chase noise)
                n_good += 1
                out_bpm = bpm
            else:
                out_bpm = last_good if last_good is not None else None
            times.append(round(t_center, 2))
            bpms.append(round(float(out_bpm), 1) if out_bpm is not None and np.isfinite(out_bpm) else None)
            confs.append(conf)
            reliable.append(ok)
            collisions.append(bool(est["cadence_collision"]))

    rel_vals = [b for b, r in zip(bpms, reliable) if r and b is not None]
    summary = {
        "hr_mean": round(float(np.mean(rel_vals)), 1) if rel_vals else None,
        "hr_max": round(float(np.max(rel_vals)), 1) if rel_vals else None,
        "hr_min": round(float(np.min(rel_vals)), 1) if rel_vals else None,
        "coverage": round(n_good / len(bpms), 3) if bpms else 0.0,
        "n_windows": len(bpms),
    }
    return {
        "t": times,
        "bpm": bpms,
        "confidence": confs,
        "reliable": reliable,
        "cadence_collision": collisions,
        "summary": summary,
    }
