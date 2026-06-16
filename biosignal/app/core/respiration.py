"""Respiratory rate from PPG alone — overnight wellness *trend* (breaths/min during sleep).

The Bangle's overnight T2 log is a **PPG-only waveform at ~25 Hz** (no accel in that stream), so RR
here must come from the pulse wave alone. Breathing modulates the PPG three ways (Charlton 2016, an
assessment of RR-from-PPG algorithms; Pimentel 2017):
  - RIIV — respiratory-induced *intensity* variation: the baseline wanders up/down with each breath.
  - RIAV — respiratory-induced *amplitude* variation: pulse height shrinks/grows with each breath.
  - RIFV — respiratory-induced *frequency* variation: heart rate speeds on inhale / slows on exhale
    (respiratory sinus arrhythmia) → the IBI series itself carries the breathing rhythm.

We extract all three as beat-sampled series, resample to a uniform grid, band-pass to the breathing
band (0.1–0.5 Hz = 6–30 br/min), and read each one's dominant spectral peak. Then **Smart Fusion**
(Karlen 2013): if the three estimates agree (spread ≤ AGREE_BR_MIN) the window is trustworthy and we
report their mean; if they disagree the window is motion/artifact-corrupted and we DISCARD it rather
than emit a number we don't believe. Validated on BIDMC (real PPG + manual breath annotations) at the
Bangle's 25 Hz — see scripts/validate_respiration.py.

CLAIM DISCIPLINE: this is a resting/sleep RR *trend* (what Oura/Whoop report), wellness vocabulary
only. It is **not** apnea/respiratory-event detection — that stays ❌ behind the honesty firewall.
"""

from __future__ import annotations

from typing import Optional

import numpy as np
from scipy.signal import butter, detrend, filtfilt

try:
    import neurokit2 as nk
except Exception:  # pragma: no cover
    nk = None

PROC_HZ = 250          # upsample low-rate PPG before peak detection (mirrors hrv.py)
RESAMP_HZ = 4.0        # uniform grid for the beat-sampled respiratory signals
RR_LO_HZ, RR_HI_HZ = 0.1, 0.5    # 6–30 breaths/min plausibility band
WIN_SEC = 60           # per-estimate window (a few respiratory cycles; Karlen used 32–64 s)
STEP_SEC = 30          # slide
AGREE_BR_MIN = 2.0     # Smart-Fusion: discard a window if the 3 estimates spread wider than this.
#                        Tuned on BIDMC: 2.0 trades coverage (≈38%) for accuracy (MAE 2.9 vs 3.2 br/min)
#                        — the right call overnight, where hundreds of windows make coverage ample.
MIN_BEATS = 20         # need enough beats in a window for stable respiratory sampling
IBI_CV_MAX = 0.30      # beats must be periodic (clean pulse CV≈0.05; non-pulsatile noise ≈0.35) —
#                        a cheap pulsatility backstop, since RR runs on quality-gated PPG upstream


def _to_array(x) -> np.ndarray:
    return np.asarray(x, dtype=float).ravel()


def _beats(ppg: np.ndarray, fs: float):
    """Detect beats on a PPG window → (clean_waveform, peak_idx, proc_fs). Upsamples low-rate PPG
    first (sub-sample beat timing), same as the HRV path so RIFV timing isn't sample-quantized."""
    if nk is None:  # pragma: no cover
        raise RuntimeError("neurokit2 not installed")
    proc_fs = fs
    if fs < 100 and ppg.size >= 4:
        from scipy.interpolate import CubicSpline
        t = np.arange(ppg.size) / fs
        t_up = np.arange(0.0, t[-1], 1.0 / PROC_HZ)
        ppg = CubicSpline(t, ppg)(t_up)
        proc_fs = PROC_HZ
    sig, info = nk.ppg_process(ppg, sampling_rate=proc_fs)
    clean = np.asarray(sig.get("PPG_Clean", ppg), float)
    peaks = np.asarray(info.get("PPG_Peaks", []), int)
    return clean, peaks, proc_fs


def _respiratory_series(clean: np.ndarray, peaks: np.ndarray, proc_fs: float):
    """Build the three breathing-modulated series, each as (time_s, value) sampled at beats."""
    if peaks.size < 4:
        return None
    t_pk = peaks / proc_fs
    # RIIV — baseline intensity at each peak.
    riiv = (t_pk, clean[peaks].astype(float))
    # RIAV — pulse amplitude (peak minus the preceding trough).
    troughs = np.array([peaks[i - 1] + int(np.argmin(clean[peaks[i - 1]:peaks[i]]))
                        for i in range(1, peaks.size)])
    riav = (t_pk[1:], clean[peaks[1:]] - clean[troughs])
    # RIFV — instantaneous IBI (s), the RSA carrier, placed at the later beat of each pair.
    rifv = (t_pk[1:], np.diff(t_pk))
    return riiv, riav, rifv


def _rr_from_series(t: np.ndarray, x: np.ndarray) -> Optional[float]:
    """Dominant breathing-band frequency of one respiratory series → breaths/min (or None)."""
    if t.size < 4 or (t[-1] - t[0]) < 8.0:
        return None
    grid = np.arange(t[0], t[-1], 1.0 / RESAMP_HZ)
    if grid.size < 16:
        return None
    xi = detrend(np.interp(grid, t, x))
    if np.std(xi) < 1e-9:
        return None
    b, a = butter(2, [RR_LO_HZ / (RESAMP_HZ / 2), RR_HI_HZ / (RESAMP_HZ / 2)], btype="band")
    xf = filtfilt(b, a, xi)
    n = xf.size
    nfft = max(2048, 8 * n)                      # zero-pad for fine frequency resolution
    spec = np.abs(np.fft.rfft(xf * np.hanning(n), nfft)) ** 2
    freq = np.fft.rfftfreq(nfft, 1.0 / RESAMP_HZ)
    band = (freq >= RR_LO_HZ) & (freq <= RR_HI_HZ)
    if not band.any() or spec[band].max() <= 0:
        return None
    f_peak = freq[band][int(np.argmax(spec[band]))]
    return float(f_peak * 60.0)


def estimate_rr_window(ppg: np.ndarray, fs: float) -> dict:
    """One window → {resp_rate, valid, estimates, spread}. Smart-Fusion gated.

    valid is False (resp_rate None) when the three modulations disagree (> AGREE_BR_MIN) or there
    aren't enough beats — i.e. we'd rather say nothing than emit an untrustworthy breath rate.
    """
    ppg = _to_array(ppg)
    clean, peaks, proc_fs = _beats(ppg, fs)
    if peaks.size < MIN_BEATS:
        return {"resp_rate": None, "valid": False, "estimates": [], "spread": None}
    # Pulsatility backstop: a clean pulse has regular beats; reject non-pulsatile windows so we
    # never read a "breathing" rhythm off noise (the 3 modulations can coincidentally agree on it).
    ibi = np.diff(peaks) / proc_fs
    ibi = ibi[(ibi >= 0.3) & (ibi <= 2.0)]
    if ibi.size < MIN_BEATS - 1 or (np.std(ibi) / max(np.mean(ibi), 1e-9)) > IBI_CV_MAX:
        return {"resp_rate": None, "valid": False, "estimates": [], "spread": None}
    series = _respiratory_series(clean, peaks, proc_fs)
    if series is None:
        return {"resp_rate": None, "valid": False, "estimates": [], "spread": None}
    ests = [r for r in (_rr_from_series(t, x) for (t, x) in series) if r is not None]
    if len(ests) < 2:
        return {"resp_rate": None, "valid": False, "estimates": ests, "spread": None}
    spread = float(max(ests) - min(ests))
    if spread > AGREE_BR_MIN:
        return {"resp_rate": None, "valid": False, "estimates": ests, "spread": spread}
    return {"resp_rate": float(np.mean(ests)), "valid": True,
            "estimates": ests, "spread": spread}


def estimate_respiratory_rate(ppg, sample_rate_hz: int) -> dict:
    """Overnight/rest PPG window → a single trustworthy RR (breaths/min) by sliding WIN_SEC windows
    and taking the median of the Smart-Fusion-accepted ones.

    Returns {resp_rate, valid, n_valid, n_windows, coverage}. resp_rate is None (valid False) if no
    window passes — honest silence beats a fabricated number.
    """
    ppg = _to_array(ppg)
    fs = int(sample_rate_hz)
    if ppg.size < WIN_SEC * fs // 2:                    # need ~½ a window minimum
        r = estimate_rr_window(ppg, fs)
        return {"resp_rate": r["resp_rate"], "valid": r["valid"],
                "n_valid": int(r["valid"]), "n_windows": 1, "coverage": float(r["valid"])}
    win, step = WIN_SEC * fs, STEP_SEC * fs
    rrs = []
    n = 0
    for s in range(0, ppg.size - win + 1, step):
        n += 1
        r = estimate_rr_window(ppg[s:s + win], fs)
        if r["valid"]:
            rrs.append(r["resp_rate"])
    if not rrs:
        return {"resp_rate": None, "valid": False, "n_valid": 0, "n_windows": n, "coverage": 0.0}
    return {"resp_rate": round(float(np.median(rrs)), 1), "valid": True,
            "n_valid": len(rrs), "n_windows": n, "coverage": round(len(rrs) / max(n, 1), 2)}
