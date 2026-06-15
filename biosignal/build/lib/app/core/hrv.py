"""Pure HRV functions.

North-star metric: overnight RMSSD (the regime where PPG is most accurate — see
03-algorithms.md §1, §2). Pipeline mirrors the algorithm plan:

  raw PPG ──nk.ppg_process──▶ peaks ──▶ IBI
  IBI ──physiologic reject (<300/>2000 ms)──▶ Kubios signal_fixpeaks ──▶ HRV (time + freq)

RMSSD is the primary vagal/recovery correlate; we report it whole-night (5-min windows
are noisier, r²≈0.77 vs 0.98 whole-night — Kinnunen 2020). Resting HR = min of windowed
medians over the night (§4).

These functions are deliberately stateless and DB-free.
"""

from __future__ import annotations

from typing import Optional

import numpy as np

try:  # NeuroKit2 is the workhorse; import lazily-friendly so tests can introspect.
    import neurokit2 as nk
except Exception:  # pragma: no cover
    nk = None


# Physiologic plausibility bounds for inter-beat intervals (ms).
# <300 ms => >200 bpm (artifact / double-count); >2000 ms => <30 bpm (missed beat).
IBI_MIN_MS = 300.0
IBI_MAX_MS = 2000.0

# Quality gates (03-algorithms.md §1: require >=97% beat accuracy before trusting HRV).
MAX_ARTIFACT_PCT = 5.0          # reject window if >5% of beats are implausible/corrected
MIN_VALID_BEATS = 60           # need a reasonable count for stable RMSSD


def _to_array(x) -> np.ndarray:
    return np.asarray(x, dtype=float).ravel()


def reject_implausible_ibi(ibi_ms: np.ndarray) -> tuple[np.ndarray, float]:
    """Drop physiologically implausible IBIs (<300 / >2000 ms).

    Returns (clean_ibi, rejected_pct). rejected_pct is the fraction of the original
    series that was implausible — our first-pass artifact estimate.
    """
    ibi_ms = _to_array(ibi_ms)
    if ibi_ms.size == 0:
        return ibi_ms, 100.0
    mask = (ibi_ms >= IBI_MIN_MS) & (ibi_ms <= IBI_MAX_MS)
    rejected_pct = 100.0 * (1.0 - mask.mean())
    return ibi_ms[mask], rejected_pct


def ibi_to_peaks(ibi_ms: np.ndarray, sampling_rate: int = 1000) -> np.ndarray:
    """Convert an IBI series (ms) to cumulative peak sample indices.

    NeuroKit2's fixpeaks/HRV functions operate on peak indices at a sampling rate.
    We synthesize peaks at 1000 Hz so 1 sample == 1 ms (clean unit mapping).
    """
    ibi_ms = _to_array(ibi_ms)
    # First peak at t=0, subsequent peaks at cumulative IBI.
    cumulative_ms = np.concatenate([[0.0], np.cumsum(ibi_ms)])
    peaks = np.round(cumulative_ms * sampling_rate / 1000.0).astype(int)
    return peaks


def correct_peaks_kubios(peaks: np.ndarray, sampling_rate: int = 1000) -> tuple[np.ndarray, float]:
    """Kubios artifact correction (Lipponen & Tarvainen 2019) via nk.signal_fixpeaks.

    Returns (corrected_peaks, corrected_pct) where corrected_pct estimates the share
    of beats NeuroKit flagged/relocated.
    """
    if nk is None:  # pragma: no cover
        raise RuntimeError("neurokit2 not installed")
    n_before = max(len(peaks) - 1, 1)
    try:
        _info, corrected = nk.signal_fixpeaks(
            peaks, sampling_rate=sampling_rate, method="kubios", show=False
        )
    except Exception:
        # Some NK versions return a single value or need iterative method; fall back.
        try:
            corrected = nk.signal_fixpeaks(
                peaks, sampling_rate=sampling_rate, method="neurokit", show=False
            )
            if isinstance(corrected, tuple):
                corrected = corrected[1]
        except Exception:
            corrected = peaks
    corrected = np.asarray(corrected).astype(int)
    # Estimate correction rate from how many intervals changed.
    n_changed = abs(len(corrected) - len(peaks))
    ibi_before = np.diff(peaks)
    ibi_after = np.diff(corrected)
    m = min(len(ibi_before), len(ibi_after))
    if m > 0:
        n_changed += int(np.sum(np.abs(ibi_before[:m] - ibi_after[:m]) > 1))
    corrected_pct = 100.0 * n_changed / n_before
    return corrected, corrected_pct


def ppg_to_ibi(ppg: np.ndarray, sample_rate_hz: int) -> tuple[np.ndarray, float]:
    """Raw PPG → IBI (ms) using nk.ppg_process (band-pass 0.5-8 Hz, Elgendi peaks).

    Returns (ibi_ms, quality_mean) where quality_mean is the mean nk PPG quality
    (template-matching SQI, Orphanidou 2015) over the window in [0, 1].
    """
    if nk is None:  # pragma: no cover
        raise RuntimeError("neurokit2 not installed")
    ppg = _to_array(ppg)
    signals, info = nk.ppg_process(ppg, sampling_rate=sample_rate_hz)
    peaks_idx = np.asarray(info.get("PPG_Peaks", []), dtype=int)
    if peaks_idx.size < 2:
        return np.array([]), 0.0
    ibi_ms = np.diff(peaks_idx) / sample_rate_hz * 1000.0
    quality_mean = float(np.nanmean(signals.get("PPG_Quality", [0.0])))
    return ibi_ms, quality_mean


def compute_hrv_metrics(clean_ibi_ms: np.ndarray) -> dict:
    """Time + frequency HRV from a cleaned IBI series.

    Uses NeuroKit2's hrv_time / hrv_frequency where available, with a NumPy fallback
    for the time-domain primaries so the service degrades gracefully.
    """
    clean_ibi_ms = _to_array(clean_ibi_ms)
    metrics: dict[str, Optional[float]] = {
        "rmssd": None,
        "sdnn": None,
        "pnn50": None,
        "lf_hf": None,
    }
    if clean_ibi_ms.size < 2:
        return metrics

    # NumPy fallback / ground truth for time-domain (always computed).
    diffs = np.diff(clean_ibi_ms)
    metrics["rmssd"] = float(np.sqrt(np.mean(diffs**2)))
    metrics["sdnn"] = float(np.std(clean_ibi_ms, ddof=1)) if clean_ibi_ms.size > 1 else 0.0
    metrics["pnn50"] = float(100.0 * np.mean(np.abs(diffs) > 50.0))

    # Frequency domain via NeuroKit2 (Welch on interpolated RR). Best-effort.
    if nk is not None and clean_ibi_ms.size >= 20:
        try:
            peaks = ibi_to_peaks(clean_ibi_ms, sampling_rate=1000)
            freq = nk.hrv_frequency(peaks, sampling_rate=1000, show=False)
            lf = float(freq.get("HRV_LF", [np.nan])[0]) if "HRV_LF" in freq else np.nan
            hf = float(freq.get("HRV_HF", [np.nan])[0]) if "HRV_HF" in freq else np.nan
            if np.isfinite(lf) and np.isfinite(hf) and hf > 0:
                metrics["lf_hf"] = float(lf / hf)
        except Exception:
            pass  # freq HRV is secondary; never fail the request on it.

    return metrics


def resting_hr_from_ibi(ibi_ms: np.ndarray, window_beats: int = 30) -> Optional[float]:
    """Resting HR = min of windowed medians over the night (03-algorithms.md §4).

    HR = 60000 / IBI(ms). We take rolling windows of `window_beats`, the median HR of
    each, and return the minimum — the most trustworthy wearable metric.
    """
    ibi_ms = _to_array(ibi_ms)
    if ibi_ms.size == 0:
        return None
    hr = 60000.0 / ibi_ms
    if ibi_ms.size < window_beats:
        return float(np.median(hr))
    medians = [
        np.median(hr[i : i + window_beats])
        for i in range(0, len(hr) - window_beats + 1, max(window_beats // 2, 1))
    ]
    return float(np.min(medians))


def process_hrv(
    ibi_ms: Optional[list] = None,
    ppg: Optional[list] = None,
    sample_rate_hz: Optional[int] = None,
) -> dict:
    """End-to-end overnight HRV pipeline. Returns the metrics dict for the API.

    Accepts either an IBI series (band default) or raw PPG (audit/reprocess path).
    """
    quality_mean = 1.0  # IBI path has no waveform SQI; assume edge already gated.

    if ppg is not None and sample_rate_hz:
        ibi_arr, quality_mean = ppg_to_ibi(ppg, sample_rate_hz)
    elif ibi_ms is not None:
        ibi_arr = _to_array(ibi_ms)
    else:
        raise ValueError("Provide either ibi_ms or (ppg + sample_rate_hz).")

    n_raw = int(ibi_arr.size)

    # 1. Physiologic rejection.
    clean, rejected_pct = reject_implausible_ibi(ibi_arr)

    # 2. Kubios artifact correction on the synthesized peak train.
    corrected_pct = 0.0
    if nk is not None and clean.size >= 3:
        peaks = ibi_to_peaks(clean, sampling_rate=1000)
        corrected_peaks, corrected_pct = correct_peaks_kubios(peaks, sampling_rate=1000)
        corr_ibi = np.diff(corrected_peaks).astype(float)
        # Re-reject any implausible intervals introduced by correction.
        corr_ibi, _ = reject_implausible_ibi(corr_ibi)
        if corr_ibi.size >= 2:
            clean = corr_ibi

    artifact_pct = float(rejected_pct + corrected_pct)

    # 3. HRV metrics.
    hrv = compute_hrv_metrics(clean)
    rhr = resting_hr_from_ibi(clean)

    # 4. Quality gate (03-algorithms.md §1: >=97% beat accuracy; suppress poor signal).
    valid = (
        clean.size >= MIN_VALID_BEATS
        and artifact_pct <= MAX_ARTIFACT_PCT
        and quality_mean >= 0.86
        and hrv["rmssd"] is not None
    )

    return {
        "hrv_ms": round(hrv["rmssd"], 2) if hrv["rmssd"] is not None else None,
        "resting_hr": round(rhr, 1) if rhr is not None else None,
        "rmssd": round(hrv["rmssd"], 2) if hrv["rmssd"] is not None else None,
        "sdnn": round(hrv["sdnn"], 2) if hrv["sdnn"] is not None else None,
        "pnn50": round(hrv["pnn50"], 2) if hrv["pnn50"] is not None else None,
        "lf_hf": round(hrv["lf_hf"], 3) if hrv["lf_hf"] is not None else None,
        "artifact_pct": round(artifact_pct, 2),
        "valid": bool(valid),
        "n_beats_raw": n_raw,
        "n_beats_clean": int(clean.size),
    }
