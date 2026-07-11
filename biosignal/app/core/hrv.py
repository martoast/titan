"""Pure HRV functions.

North-star metric: overnight RMSSD (the regime where PPG is most accurate — see
03-algorithms.md §1, §2). Pipeline mirrors the algorithm plan:

  raw PPG ──nk.ppg_process──▶ peaks ──▶ IBI
  IBI ──physiologic reject (<300/>2000 ms)──▶ Kubios signal_fixpeaks ──▶ HRV (time + freq)

RMSSD is the primary vagal/recovery correlate; we report it whole-night (5-min windows
are noisier, r²≈0.77 vs 0.98 whole-night — Kinnunen 2020). Resting HR = min of windowed
medians over the night (§4).

Quality gate is validated on real data: PPG-DaLiA wrist-PPG decimated to 25 Hz vs chest-ECG
gives ~195 ms RMSSD MAE raw → ~55 ms once `valid` gates the night (the template/Kubios/artifact
stack here). 55 ms ≈ the magnitude of resting RMSSD, so treat this as trend-grade, not beat-to-beat:
trust the whole-night number, flag pNN50 low-confidence. See scripts/validate_hrv_quality.py.

These functions are deliberately stateless and DB-free.
"""

from __future__ import annotations

import logging
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


def _finite_int_indices(vals) -> set:
    """Beat indices from a NeuroKit artifact list, dropping NON-FINITE entries.

    Some NeuroKit versions surface NaN placeholders for empty/degenerate artifact categories (or on a
    sparse/degraded real waveform), and `int(NaN)` raises "cannot convert float NaN to integer" — which
    500'd the whole HRV request (and blocked reprocessing real nights). A non-finite value is not a valid
    beat index, so skip it.
    """
    return {int(v) for v in np.asarray(vals, dtype=float).ravel() if np.isfinite(v)}


def correct_peaks_kubios(peaks: np.ndarray, sampling_rate: int = 1000) -> tuple[np.ndarray, float]:
    """Kubios artifact correction (Lipponen & Tarvainen 2019) via nk.signal_fixpeaks.

    Returns (corrected_peaks, corrected_pct) where corrected_pct estimates the share
    of beats NeuroKit flagged/relocated.
    """
    if nk is None:  # pragma: no cover
        raise RuntimeError("neurokit2 not installed")
    n_before = max(len(peaks) - 1, 1)
    info: dict = {}
    try:
        info, corrected = nk.signal_fixpeaks(
            peaks, sampling_rate=sampling_rate, method="kubios", show=False
        )
    except Exception:
        # Some NK versions return a single value or need iterative method; fall back.
        try:
            res = nk.signal_fixpeaks(
                peaks, sampling_rate=sampling_rate, method="neurokit", show=False
            )
            corrected = res[1] if isinstance(res, tuple) else res
        except Exception:
            corrected = peaks
    corrected = np.asarray(corrected).astype(int)

    # Correction rate = beats Kubios FLAGGED (ectopic/missed/extra/longshort), not the
    # raw before/after diff — Kubios sub-ms relocations otherwise count nearly every
    # beat as "changed" and grossly inflate the artifact %.
    flagged = set()
    if isinstance(info, dict):
        for key in ("ectopic", "missed", "extra", "longshort"):
            vals = info.get(key)
            if vals is not None:
                flagged.update(_finite_int_indices(vals))
    n_flagged = len(flagged)
    # If a NK version didn't surface indices, fall back to a count of new/dropped beats.
    if n_flagged == 0:
        n_flagged = abs(len(corrected) - len(peaks))
    corrected_pct = 100.0 * n_flagged / n_before
    return corrected, corrected_pct


# Peak detection on a low-rate PPG quantizes each beat to the sample step (40 ms @
# 25 Hz), which inflates/poisons RMSSD — the very metric we care about. The fix
# (Béres & Hejjel 2021; Choi & Shin 2017; and the Bora Band 25 Hz→200 Hz validation,
# Lee 2022) is to cubic-spline upsample the raw waveform BEFORE peak detection so beat
# timing is recovered sub-sample. With this, 25 Hz PPG yields RMSSD statistically
# equivalent to ECG; without it, 25 Hz is below the ~50 Hz no-interpolation floor.
PPG_PROC_HZ = 250  # interpolate low-rate PPG up to this before peak detection
SLEEP_EPOCH_SEC = 30  # per-epoch grid for sleep-staging features


def _upslope_fiducials(clean: np.ndarray, peaks: np.ndarray) -> np.ndarray:
    """Beat-timing reference at the MAX-UPSLOPE point, not the systolic apex.

    The systolic peak is the flattest part of the pulse (low dV/dt), so sample noise translates into
    large horizontal jitter there — inflating RMSSD. The steepest-rise point (first-derivative maximum
    on the upstroke into each beat) has the highest dV/dt and is the lowest-jitter fiducial vs the ECG
    R-peak (fiducial-point literature: PMC9280335, arXiv 2301.02906). For each beat we take the
    derivative maximum in the interval (previous systolic peak, this systolic peak]. One fiducial per
    beat after the first; returns an empty array if it can't (caller falls back to the systolic peaks).
    """
    peaks = np.asarray(peaks, dtype=int)
    if clean.size < 3 or peaks.size < 2:
        return np.empty(0, dtype=int)
    d = np.gradient(clean)
    fids: list[int] = []
    for i in range(1, peaks.size):
        a, b = int(peaks[i - 1]), int(peaks[i])
        if b - a < 2 or a < 0 or b > d.size:
            continue
        fids.append(a + int(np.argmax(d[a:b])))
    return np.asarray(fids, dtype=int)


def _epoch_rmssd_ms(seg: np.ndarray) -> float:
    """Artifact-robust per-epoch RMSSD (ms). `seg` is IBIs pre-filtered to the physiologic [300, 2000] ms band.

    Low-rate ppg peak detection injects missed/doubled beats: a single spurious interval makes ONE successive
    difference enormous, so raw sqrt(mean(d^2)) saturates — whole nights were pinning at the 250 ms ceiling
    (real overnight RMSSD is ~20-100 ms). That flattened the deep(high)/REM(low) HRV discriminator, so REM
    read as light, AND poisoned the recovery HRV number. Reject the successive differences that are
    physiologically implausible before the RMS — a real beat-to-beat change is a small fraction of the interval
    (RSA ~5-15%); a jump beyond ~20% (floor 200 ms) is a detection artifact, not vagal tone — so RMSSD reflects
    variability, not detection noise. An epoch that is essentially all-artifact returns NaN (honestly unknown)
    rather than a fabricated 250.
    """
    if seg.size < 3:
        return float("nan")
    d = np.diff(seg)
    thr = max(200.0, 0.2 * float(np.median(seg)))
    d = d[np.abs(d) <= thr]
    if d.size < 2:
        return float("nan")
    return float(min(np.sqrt(np.mean(d * d)), 250.0))


def ppg_to_ibi(ppg: np.ndarray, sample_rate_hz: int) -> tuple[np.ndarray, float, Optional[dict]]:
    """Raw PPG → IBI (ms) using nk.ppg_process (band-pass 0.5-8 Hz, Elgendi peaks).

    Low-rate PPG (e.g. a Bangle.js at 25 Hz) is cubic-spline upsampled to PPG_PROC_HZ
    first, so inter-beat intervals aren't quantized to the native sample step — the
    condition under which 25 Hz HRV is valid in the literature.

    Returns (ibi_ms, quality_mean) where quality_mean is the mean nk PPG quality
    (template-matching SQI, Orphanidou 2015) over the window in [0, 1].
    """
    if nk is None:  # pragma: no cover
        raise RuntimeError("neurokit2 not installed")
    ppg = _to_array(ppg)

    proc_rate = sample_rate_hz
    if sample_rate_hz < 100 and ppg.size >= 4:
        from scipy.interpolate import CubicSpline

        t = np.arange(ppg.size) / sample_rate_hz
        t_up = np.arange(0.0, t[-1], 1.0 / PPG_PROC_HZ)
        ppg = CubicSpline(t, ppg)(t_up)
        proc_rate = PPG_PROC_HZ

    try:
        signals, info = nk.ppg_process(ppg, sampling_rate=proc_rate)
    except Exception as exc:
        # nk.ppg_process can raise on a pathological window (near-flat / too few detectable cycles at a low
        # sample rate): its own quality step feeds a NaN cycle duration into epochs_create's int(), which blows
        # up. A single bad window out of a night's hundreds must NOT 500 the HRV request (that aborts the whole
        # night's reprocess) — treat it as an unusable, low-quality window and move on, exactly like the
        # too-few-peaks case below.
        logging.getLogger(__name__).warning(
            "nk.ppg_process failed on a degenerate ppg window (%s) — treating as low-quality / no beats", exc)

        return np.array([]), 0.0, None
    peaks_idx = np.asarray(info.get("PPG_Peaks", []), dtype=int)
    if peaks_idx.size < 2:
        return np.array([]), 0.0, None
    # Beat timing off the MAX-UPSLOPE fiducial (lower jitter than the flat systolic apex → cleaner
    # RMSSD). Falls back to the systolic peaks if the derivative pass can't produce a usable series.
    clean_sig = np.asarray(signals.get("PPG_Clean", []), dtype=float)
    fid_idx = _upslope_fiducials(clean_sig, peaks_idx) if clean_sig.size else np.empty(0, dtype=int)
    if fid_idx.size < 2:
        fid_idx = peaks_idx
    ibi_ms = np.diff(fid_idx) / proc_rate * 1000.0
    quality = np.asarray(signals.get("PPG_Quality", []), dtype=float)
    quality_mean = float(np.nanmean(quality)) if quality.size else 0.0

    # Per-30s-epoch sleep features: HR + a motion proxy. Movement corrupts the optical
    # signal, so (1 - PPG quality) tracks motion; the Walch stager uses it RELATIVELY, so
    # absolute scale doesn't matter. Lets us stage sleep from the same raw PPG, no accel.
    epochs = None
    dur_sec = ppg.size / proc_rate if proc_rate else 0.0
    if dur_sec >= 1.0:
        n_ep = max(1, int(round(dur_sec / SLEEP_EPOCH_SEC)))
        peak_t = peaks_idx / proc_rate  # systolic beat times (s) — for coarse per-epoch HR
        fid_t = fid_idx / proc_rate     # upslope fiducial times — these align with ibi_ms above
        ibi_t = fid_t[1:] if fid_t.size >= 2 else peak_t[1:]  # each ibi_ms[i] ends at fid_t[i+1]
        ep_hr, ep_motion, ep_rmssd = [], [], []
        for e in range(n_ep):
            lo, hi = e * SLEEP_EPOCH_SEC, (e + 1) * SLEEP_EPOCH_SEC
            beats = peak_t[(peak_t >= lo) & (peak_t < hi)]
            if beats.size >= 2:
                ep_hr.append(float(60000.0 / np.mean(np.diff(beats) * 1000.0)))
            else:
                ep_hr.append(float(beats.size * (60.0 / SLEEP_EPOCH_SEC)))
            # Per-epoch RMSSD — the deep(high)/REM(low) HRV discriminator. Reject
            # implausible IBIs first, clip to a physiologic ceiling.
            seg = ibi_ms[(ibi_t >= lo) & (ibi_t < hi)]
            seg = seg[(seg >= 300) & (seg <= 2000)]
            ep_rmssd.append(_epoch_rmssd_ms(seg))
            if quality.size:
                q = quality[int(lo * proc_rate):int(hi * proc_rate)]
                ep_motion.append(float((1.0 - np.nanmean(q)) * 100.0) if q.size else 0.0)
            else:
                ep_motion.append(0.0)
        epochs = {"hr": ep_hr, "motion": ep_motion, "rmssd": ep_rmssd}

    return ibi_ms, quality_mean, epochs


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
    """Resting HR = a LOW PERCENTILE of windowed medians over the night (03-algorithms.md §4).

    HR = 60000 / IBI(ms). We take rolling windows of `window_beats` and the median HR of each,
    then return the 5th percentile of those medians — the night's "floor" without being the single
    lowest window. The old `min` was structurally biased toward the one lowest window, so a short
    stretch of surviving doubled/missed-beat IBIs (each still < 2000 ms = 30 bpm, so it passes the
    physiologic gate) or a brief bradycardic dip pulled RHR far too low — and RHR feeds Uth-Sørensen
    VO2max (15.3·HRmax/HRrest), which is steeply inflated by a too-low RHR. The 5th percentile keeps
    the resting-floor meaning while rejecting a lone bad window.
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
    return float(np.percentile(medians, 5))


def _epochs_from_ibi(ibi_ms: np.ndarray) -> Optional[dict]:
    """Per-30s-epoch sleep features (HR + RMSSD) from an IBI series.

    The band's OVERNIGHT DEFAULT is an IBI stream (+ accel), not raw PPG — but the epoch grid
    that sleep staging needs was only ever built on the PPG path. Without this, a watch-ended
    night has no per-epoch features, so it seals with a duration but 0% on every stage. Motion
    is left at zero here and filled from the device's own accelerometer by the caller (real
    actigraphy beats any proxy). HR/RMSSD mirror the PPG-path epoch computation above.
    """
    ibi_ms = ibi_ms[np.isfinite(ibi_ms)]
    total_ms = float(np.sum(ibi_ms)) if ibi_ms.size else 0.0
    if ibi_ms.size < 2 or total_ms <= 0:
        return None
    n_ep = max(1, int(round(total_ms / 1000.0 / SLEEP_EPOCH_SEC)))
    end_t = np.cumsum(ibi_ms)  # each interval ends at end_t[i] ms from the window start
    ep_hr, ep_rmssd = [], []
    for e in range(n_ep):
        lo, hi = e * SLEEP_EPOCH_SEC * 1000.0, (e + 1) * SLEEP_EPOCH_SEC * 1000.0
        seg = ibi_ms[(end_t >= lo) & (end_t < hi)]
        seg = seg[(seg >= 300) & (seg <= 2000)]
        ep_hr.append(float(60000.0 / np.mean(seg)) if seg.size else 0.0)
        ep_rmssd.append(_epoch_rmssd_ms(seg))
    return {"hr": ep_hr, "motion": [0.0] * n_ep, "rmssd": ep_rmssd}


def process_hrv(
    ibi_ms: Optional[list] = None,
    ppg: Optional[list] = None,
    sample_rate_hz: Optional[int] = None,
    accel: Optional[list] = None,
    want_resp: bool = True,
) -> dict:
    """End-to-end overnight HRV pipeline. Returns the metrics dict for the API.

    Accepts either an IBI series (band default) or raw PPG (audit/reprocess path).

    want_resp=False skips the per-window waveform respiratory-rate estimate — the single
    most expensive step on the PPG path (~1s/window). The overnight sealer recomputes RR
    once, whole-night, from the aggregated IBI (RSA) and *prefers* that value, so per-window
    RR is pure waste on the sleep-staging critical path. Set it False there so a full night's
    windows stage in seconds instead of a minute-plus; leave it True for one-off/spot reads.
    """
    quality_mean = 1.0  # IBI path has no waveform SQI; assume edge already gated.

    from_ppg = ppg is not None and sample_rate_hz is not None
    epochs = None
    if from_ppg:
        ibi_arr, quality_mean, epochs = ppg_to_ibi(ppg, sample_rate_hz)
    elif ibi_ms is not None:
        ibi_arr = _to_array(ibi_ms)
        epochs = _epochs_from_ibi(ibi_arr)  # band default is IBI → still build the staging grid
    else:
        raise ValueError("Provide either ibi_ms or (ppg + sample_rate_hz).")

    # Real actigraphy beats the PPG-quality motion proxy for sleep/wake — if the device sent per-sample
    # activity, override each epoch's motion with its per-epoch MOVEMENT. Use the accel STANDARD DEVIATION,
    # NOT sum(|accel|): the wrist stream is magnitude in centi-g (~100/sample at 1g), so the raw sum was
    # gravity-dominated (~tens of thousands per 30 s epoch) — a thousandfold off the stager's ~0-50 scale —
    # which floored its percentile motion threshold and classified every sampled epoch as WAKE, producing
    # all-awake / stage-less nights. std removes the constant gravity DC and lands in ~0-50, isolating the
    # actual movement (a still wrist ≈ 0-5, restlessness ≈ 20-40).
    if epochs and accel:
        a = _to_array(accel)
        if a.size and len(epochs["motion"]):
            parts = np.array_split(a, len(epochs["motion"]))
            epochs["motion"] = [round(float(np.std(p)) if p.size else 0.0, 2) for p in parts]
            # Unit-scale sanity assertion: after normalization the proxy is ~0-50. A value in the thousands
            # means the accel arrived on the wrong scale (a firmware/unit regression) — log LOUDLY so this
            # whole class of "sleep logged but no stages" can't silently degrade again.
            _mx = max(epochs["motion"], default=0.0)
            if _mx > 1000:
                logging.getLogger(__name__).warning(
                    "epoch motion proxy implausibly large (max=%.0f) — unnormalized accel? staging will degrade", _mx)

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

    # 3b. Respiratory rate (PPG path only) — breaths/min from the pulse wave's breathing
    # modulations, Smart-Fusion gated (app/core/respiration.py). A resting/sleep wellness trend.
    resp_rate = None
    if from_ppg and want_resp:
        try:
            from .respiration import estimate_respiratory_rate
            rr = estimate_respiratory_rate(ppg, sample_rate_hz)
            resp_rate = rr["resp_rate"] if rr["valid"] else None
        except Exception:
            resp_rate = None  # RR is secondary; never fail the HRV request on it.

    # 3c. Whole-night respiration from the IBI series (RSA) when the waveform path couldn't give it —
    # the band's 30 s overnight HRV bursts are too short for waveform respiration, but the aggregated
    # whole-night RR carries respiratory sinus arrhythmia. Robust with hundreds+ of beats.
    if resp_rate is None and clean.size >= 120:
        try:
            from .respiration import resp_from_ibi
            resp_rate = resp_from_ibi(clean)
        except Exception:
            resp_rate = None

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
        "resp_rate": round(resp_rate, 1) if resp_rate is not None else None,
        "artifact_pct": round(artifact_pct, 2),
        "valid": bool(valid),
        "n_beats_raw": n_raw,
        "n_beats_clean": int(clean.size),
        # The clean, artifact-corrected IBI series — only echoed for the PPG path so the
        # platform can persist it per window and aggregate a true WHOLE-NIGHT RMSSD at seal
        # time (raw blobs store ppg, not ibi). Omitted for the IBI path to avoid echoing a
        # whole-night series straight back.
        "ibi_ms": [round(float(x), 1) for x in clean] if from_ppg else None,
        # Per-30s-epoch sleep features (PPG path only) — persisted per window and
        # concatenated whole-night at seal time to stage sleep without an accelerometer.
        "epoch_hr": [round(x, 1) for x in epochs["hr"]] if epochs else None,
        "epoch_motion": [round(x, 2) for x in epochs["motion"]] if epochs else None,
        "epoch_rmssd": [None if np.isnan(x) else round(x, 1) for x in epochs["rmssd"]] if epochs else None,
    }
