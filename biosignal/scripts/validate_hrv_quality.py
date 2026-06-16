"""Validate the production HRV quality gate on REAL data — and test whether the research's proposed
SQI-gating add-on (08-sensor-research.md §8) actually beats it. Honest, leave-subjects-out.

Ground truth: PPG-DaLiA (Reiss 2019) — 15 subjects, wrist PPG (Empatica E4 BVP @ 64 Hz) recorded
alongside a chest ECG with hand-corrected R-peaks. We decimate the BVP to the Bangle's **25 Hz**, run
it through our actual pipeline (app.core.hrv.process_hrv: cubic-spline 25→250 Hz upsample → NeuroKit
peaks → physiologic reject → Kubios fixpeaks → template/artifact gate), and compare each 2-min
window's RMSSD to the ECG-derived RMSSD. Only low-motion windows (sit/drive/lunch/desk) — the rest
regime overnight HRV targets.

Two questions:
  1. Does the EXISTING quality gate (`valid` flag) actually separate trustworthy windows from junk?
  2. Does adding a skewness+perfusion+template SQI gate ON TOP buy anything? (research §8 hypothesis)

FINDING (run on all 15 subjects, 559 low-motion windows):
  - raw / ungated RMSSD MAE vs ECG ......... ~195 ms   (wrist PPG is junk without gating)
  - existing `valid` gate ................... ~55 ms   (retains ~18%; the gate works)
  - experimental SQI gate ................... does NOT beat it on this data
So the research's "biggest quality win" is, in substance, ALREADY in our pipeline. RMSSD at 25 Hz
wrist is trend-grade (55 ms abs error ≈ the magnitude of resting RMSSD itself) — exactly the "🟡
trend-only" verdict in the research firewall. We do not ship the extra SQI gate: it adds surface
without beating the validated baseline. The SQI helpers live here (not in app/core/) for reproducibility.

Usage: .venv/bin/python scripts/validate_hrv_quality.py /tmp/ppgdalia
"""

from __future__ import annotations

import glob
import os
import pickle
import sys
import warnings
from pathlib import Path

import numpy as np
from scipy.signal import butter, filtfilt
from scipy.stats import skew

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
from app.core import hrv as hrvmod  # noqa: E402

warnings.filterwarnings("ignore")
import neurokit2 as nk  # noqa: E402

DEV_FS = int(os.getenv("HRV_FS", "25"))
WIN_SEC = 120
LOW_MOTION = {1, 5, 6, 8}   # sit / drive / lunch / desk-work — the rest regime HRV targets

# --- experimental SQI gate (Elgendi 2016 skewness + perfusion + Orphanidou template) -----------
SKEW_MIN, PERFUSION_MIN, TEMPLATE_MIN = 0.10, 0.30, 0.60
_BAND = (0.5, 8.0)


def _bandpass(ppg, fs):
    ppg = np.nan_to_num(np.asarray(ppg, float))
    if ppg.size < 9 or fs <= 2 * _BAND[1]:
        return ppg - ppg.mean()
    b, a = butter(2, [_BAND[0] / (fs / 2), min(_BAND[1], fs / 2 - 0.1) / (fs / 2)], btype="band")
    return filtfilt(b, a, ppg)


def _template_consistency(bp, peaks, fs):
    half = int(0.35 * fs)
    beats = [bp[p - half:p + half] for p in peaks if p - half >= 0 and p + half < bp.size]
    beats = [b for b in beats if b.size == 2 * half and np.std(b) > 1e-9]
    if len(beats) < 3:
        return 0.0
    template = np.mean(beats, axis=0)
    if np.std(template) < 1e-9:
        return 0.0
    return float(np.clip(np.nanmean([np.corrcoef(b, template)[0, 1] for b in beats]), 0.0, 1.0))


def _segment_accept(ppg, fs, peaks):
    bp = _bandpass(ppg, fs)
    sk = float(skew(bp)) if bp.size > 2 and np.std(bp) > 1e-9 else 0.0
    pi = (np.percentile(ppg, 95) - np.percentile(ppg, 5)) / (np.mean(np.abs(ppg)) + 1e-9)
    tc = _template_consistency(bp, np.asarray(peaks, int), fs)
    return (sk >= SKEW_MIN) and (pi >= PERFUSION_MIN) and (tc >= TEMPLATE_MIN)


def _sqi_accept(ppg25):
    from scipy.interpolate import CubicSpline
    t = np.arange(ppg25.size) / DEV_FS
    up = CubicSpline(t, ppg25)(np.arange(0, t[-1], 1.0 / 250))
    try:
        sig, info = nk.ppg_process(up, sampling_rate=250)
    except Exception:
        return False
    clean = np.asarray(sig.get("PPG_Clean", up), float)
    peaks = np.asarray(info.get("PPG_Peaks", []), int)
    seg = 20 * 250
    votes = [_segment_accept(clean[s:s + seg], 250, peaks[(peaks >= s) & (peaks < s + seg)] - s)
             for s in range(0, clean.size, seg) if ((peaks >= s) & (peaks < s + seg)).sum() >= 4]
    return bool(votes) and (np.mean(votes) >= 0.5)


# --- ground truth + driver ----------------------------------------------------------------------
def _resample(x, fs_in, fs_out):
    t = np.arange(len(x)) / fs_in
    return np.interp(np.arange(int(len(x) / fs_in * fs_out)) / fs_out, t, x)


def _ecg_rmssd(rp):
    ibi = np.diff(rp) / 700 * 1000.0
    ibi = ibi[(ibi >= 300) & (ibi <= 2000)]
    return float(np.sqrt(np.mean(np.diff(ibi) ** 2))) if ibi.size >= 5 else None


def main():
    d = Path(sys.argv[1] if len(sys.argv) > 1 else "/tmp/ppgdalia")
    files = sorted(glob.glob(str(d / "S*.pkl")))
    rows = []   # (ecg, ppg_rmssd, valid, sqi_accept)
    for f in files:
        s = pickle.load(open(f, "rb"), encoding="latin1")
        bvp = np.asarray(s["signal"]["wrist"]["BVP"], float).ravel()
        rpeaks = np.asarray(s["rpeaks"], float).ravel()
        act = np.asarray(s.get("activity", []), float).ravel()
        total_s = len(bvp) / 64
        act_fs = len(act) / total_s if act.size else 0
        ppg25 = _resample(bvp, 64, DEV_FS)
        kept = 0
        for w in range(int(total_s / WIN_SEC)):
            t0, t1 = w * WIN_SEC, (w + 1) * WIN_SEC
            if act_fs:
                a = act[int(t0 * act_fs):int(t1 * act_fs)]
                if a.size == 0 or int(round(np.median(a))) not in LOW_MOTION:
                    continue
            rp = rpeaks[(rpeaks / 700 >= t0) & (rpeaks / 700 < t1)]
            ecg = _ecg_rmssd(rp) if rp.size > 5 else None
            if ecg is None:
                continue
            seg = ppg25[int(t0 * DEV_FS):int(t1 * DEV_FS)]
            res = hrvmod.process_hrv(ppg=seg.tolist(), sample_rate_hz=DEV_FS)
            rows.append((ecg, res.get("rmssd"), bool(res.get("valid")), _sqi_accept(seg)))
            kept += 1
        print(f"  {os.path.basename(f)[:-4]}: {kept} windows", flush=True)

    ecg = np.array([r[0] for r in rows])
    ppg = np.array([np.nan if r[1] is None else r[1] for r in rows])
    valid = np.array([r[2] for r in rows])
    acc = np.array([r[3] for r in rows])
    has = np.isfinite(ppg)

    def mae(mask):
        m = mask & has
        return (float(np.abs(ppg[m] - ecg[m]).mean()), int(m.sum())) if m.sum() else (float("nan"), 0)

    print(f"\n{len(rows)} low-motion windows · {len(files)} subjects · {DEV_FS} Hz (Bangle rate)")
    print("  RMSSD error vs ECG (ms):")
    for label, mask in [("raw / ungated", np.ones(len(rows), bool)),
                        ("EXISTING 'valid' gate", valid),
                        ("experimental SQI gate", acc)]:
        e, n = mae(mask)
        print(f"    {label:<24} MAE {e:6.1f}   (kept {n}/{len(rows)})")
    a, _ = mae(valid)
    b, _ = mae(acc)
    verdict = ("the existing gate already wins; SQI add-on not shipped"
               if not (np.isfinite(b) and b < a) else "SQI add-on beats baseline — reconsider")
    print(f"\n  Verdict: {verdict}")


if __name__ == "__main__":
    main()
