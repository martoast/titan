"""Can our PPG read ARTERIAL STIFFNESS? Tested on real wrist PPG vs age — and the answer is NO.

The acceleration plethysmogram (APG / 2nd derivative of the pulse) has waves a,b,c,d,e whose ratios —
b/a, the aging index (b−c−d−e)/a — rise with arterial aging on FINGERTIP PPG (Takazawa 1998; Otsuka
2017). The research firewall predicted this would fail on our hardware: a single-wavelength wrist
green-LED PPG is timing-rich but MORPHOLOGY-POOR. This script tested that prediction on real data.

Dataset: PPG-DaLiA (wrist Empatica-E4 green PPG — same sensor class as our VC31B), 15 subjects with
AGE + HEIGHT. Per subject: low-motion BVP → beat-ensemble (average many clean beats before
differentiating, since the 2nd derivative amplifies noise) → APG aging index, median over windows →
correlate with age across the 15 subjects.

RESULT (honest negative): the aging index does NOT track age on wrist PPG —
    @ 25 Hz (our rate): aging-index vs age r ≈ -0.32, b/a vs age r ≈ -0.35  (WEAK and WRONG SIGN)
    @ 64 Hz (native):   aging-index vs age r ≈ -0.04                         (no signal at all)
The index should RISE with age; it doesn't, at either rate — so it's not a sampling-rate limit, the
wrist green-LED morphology just doesn't carry the arterial-stiffness signal fingertip PPG does. We do
NOT ship a vascular-age / stiffness metric (it would be fabricated). Recorded as a kept negative
(doc 09). The APG extraction lives here, self-contained, for reproducibility and for any future
multi-wavelength / higher-SNR hardware revision.

Usage: .venv/bin/python scripts/validate_vascular.py /tmp/ppgdalia   (VAS_FS=64 for the native-rate run)
"""

from __future__ import annotations

import glob
import os
import pickle
import sys
import warnings
from pathlib import Path

import numpy as np
from scipy.interpolate import CubicSpline
from scipy.signal import butter, filtfilt, find_peaks, savgol_filter

warnings.filterwarnings("ignore")

SRC_HZ, DEV_HZ = 64, int(os.getenv("VAS_FS", "25"))
LOW_MOTION = {1, 5, 6, 8}
WIN_SEC = 90
PROC_HZ = 250
MIN_BEATS, BEAT_CORR_MIN = 30, 0.8


# --- APG (acceleration plethysmogram) extraction — kept self-contained (not shipped to app/core) ---
def _bandpass(x, fs):
    b, a = butter(2, [0.5 / (fs / 2), min(8.0, fs / 2 - 0.1) / (fs / 2)], btype="band")
    return filtfilt(b, a, np.nan_to_num(x))


def _beat_ensemble(ppg, fs):
    ppg = np.asarray(ppg, float).ravel()
    if fs < 100 and ppg.size >= 4:
        t = np.arange(ppg.size) / fs
        ppg = CubicSpline(t, ppg)(np.arange(0, t[-1], 1.0 / PROC_HZ))
        fs = float(PROC_HZ)
    clean = _bandpass(ppg, fs)
    peaks, _ = find_peaks(clean, distance=int(0.4 * fs), prominence=np.std(clean) * 0.3)
    if peaks.size < MIN_BEATS + 1:
        return None, fs
    pre, post = int(0.30 * fs), int(0.50 * fs)
    beats = []
    for p in peaks:
        if p - pre < 0 or p + post >= clean.size:
            continue
        seg = clean[p - pre:p + post]
        rng = seg.max() - seg.min()
        if rng > 1e-9:
            beats.append((seg - seg.min()) / rng)
    if len(beats) < MIN_BEATS:
        return None, fs
    beats = np.array(beats)
    tmpl = np.median(beats, axis=0)
    keep = [b for b in beats if np.corrcoef(b, tmpl)[0, 1] >= BEAT_CORR_MIN]
    return (np.mean(keep, axis=0) if len(keep) >= MIN_BEATS else None), fs


def _apg_indices(tmpl, fs):
    if tmpl is None or tmpl.size < 20:
        return None
    win = max(5, int(0.04 * fs) | 1)
    if win >= tmpl.size:
        return None
    apg = savgol_filter(tmpl, win, 3, deriv=2)
    pre = int(0.30 * fs)
    early = apg[:pre + int(0.05 * fs)]
    if early.size < 3:
        return None
    a_i = int(np.argmax(early))
    a = apg[a_i]
    if a <= 0 or apg[a_i + 1:].size < 6:
        return None
    b_i = a_i + 1 + int(np.argmin(apg[a_i + 1:]))
    b = apg[b_i]
    seg = apg[b_i:b_i + int(0.45 * fs)]
    mx, _ = find_peaks(seg)
    mn, _ = find_peaks(-seg)
    if mx.size < 2 or mn.size < 1:
        return None
    c_i = mx[0]
    lm = mn[mn > c_i]
    if lm.size < 1:
        return None
    d_i = lm[0]
    lx = mx[mx > d_i]
    e = float(seg[lx[0]] if lx.size else seg[mx[-1]])
    c, d = float(seg[c_i]), float(seg[d_i])
    return {"ba": float(b / a), "aging_index": float((b - c - d - e) / a)}


def main():
    d = Path(sys.argv[1] if len(sys.argv) > 1 else "/tmp/ppgdalia")
    rows = []
    for f in sorted(glob.glob(str(d / "S*.pkl"))):
        s = pickle.load(open(f, "rb"), encoding="latin1")
        age = float(s["questionnaire"]["AGE"])
        bvp = np.asarray(s["signal"]["wrist"]["BVP"], float).ravel()
        act = np.asarray(s.get("activity", []), float).ravel()
        total_s = len(bvp) / SRC_HZ
        afs = len(act) / total_s if act.size else 0
        ppg = bvp[:: max(1, SRC_HZ // DEV_HZ)]
        agis, bas = [], []
        for w in range(int(total_s / WIN_SEC)):
            t0, t1 = w * WIN_SEC, (w + 1) * WIN_SEC
            if afs:
                a = act[int(t0 * afs):int(t1 * afs)]
                if a.size == 0 or int(round(np.median(a))) not in LOW_MOTION:
                    continue
            tmpl, fs = _beat_ensemble(ppg[int(t0 * DEV_HZ):int(t1 * DEV_HZ)], DEV_HZ)
            idx = _apg_indices(tmpl, fs)
            if idx:
                agis.append(idx["aging_index"]); bas.append(idx["ba"])
        if len(agis) >= 3:
            rows.append((age, float(np.median(agis)), float(np.median(bas))))
        print(f"  {os.path.basename(f)[:-4]}: age {age:.0f}, {len(agis)} clean windows", flush=True)

    if len(rows) < 5:
        print("\n  too few subjects — inconclusive."); return
    age = np.array([r[0] for r in rows])
    print(f"\nPPG-DaLiA · {len(rows)}/15 subjects · wrist PPG @ {DEV_HZ} Hz (age {age.min():.0f}-{age.max():.0f})")
    print(f"  aging index vs age:  r {np.corrcoef(age, [r[1] for r in rows])[0,1]:+.2f}")
    print(f"  b/a ratio   vs age:  r {np.corrcoef(age, [r[2] for r in rows])[0,1]:+.2f}")
    print("  → should be POSITIVE and strong; it isn't. Honest negative — vascular age NOT shipped.")


if __name__ == "__main__":
    main()
