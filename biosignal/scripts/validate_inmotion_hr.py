"""The keystone for workout data: how accurate is wrist-PPG heart rate DURING exercise?

Every workout metric (TRIMP, HR zones, VO2, strain) needs reliable in-motion HR — exactly
where wrist PPG struggles, because movement corrupts the optical signal. Measured honestly
on real data with a gold-standard reference.

Dataset: PhysioNet 'Wrist PPG During Exercise' (Jarchi & Casson, slug `wrist`): wrist PPG +
3-axis wrist accelerometer + CHEST ECG, during walking, running, and cycling, 8 subjects.

Estimators on 8-s / 2-s sliding windows, vs ECG ground truth (MAE in bpm):
  (a) naive   : PPG peak detection (our HRV pipeline's approach) — breaks in motion
  (b) accel+track: PPG spectrum, suppress the accelerometer's motion frequencies, then a
      tracking step (HR can't jump between windows) — i.e. use the accel we already log.

Usage: .venv/bin/python scripts/validate_inmotion_hr.py /tmp/wrist
"""

from __future__ import annotations

import sys
import warnings
from pathlib import Path

import numpy as np
import wfdb
import neurokit2 as nk
from scipy.signal import butter, filtfilt, welch

warnings.filterwarnings("ignore")
FS = 256
WIN, STEP = 8 * FS, 2 * FS
HR_LO, HR_HI = 0.7, 3.7  # 42–222 bpm


def _band(x):
    b, a = butter(4, [HR_LO / (FS / 2), HR_HI / (FS / 2)], btype="band")
    return filtfilt(b, a, np.nan_to_num(x))


def _spectrum(sig):
    f, p = welch(sig, fs=FS, nperseg=min(len(sig), 2048))
    m = (f >= HR_LO) & (f <= HR_HI)
    return f[m], p[m]


def _ref_hr(ecg_win):
    try:
        _, info = nk.ecg_peaks(nk.ecg_clean(np.nan_to_num(ecg_win), sampling_rate=FS), sampling_rate=FS)
        pk = np.asarray(info["ECG_R_Peaks"], float)
        return 60.0 / np.median(np.diff(pk) / FS) if pk.size >= 2 else np.nan
    except Exception:
        return np.nan


def _naive_hr(ppg_raw_win):
    try:
        _, info = nk.ppg_process(np.nan_to_num(ppg_raw_win), sampling_rate=FS)
        pk = np.asarray(info.get("PPG_Peaks", []), float)
        return 60.0 / np.median(np.diff(pk) / FS) if pk.size >= 2 else np.nan
    except Exception:
        return np.nan


def _accel_track_hr(ppg_win, ax, ay, az, prev):
    fp, pp = _spectrum(ppg_win)
    if pp.size < 3:
        return np.nan
    # suppress bins near the accel motion peaks (cadence + harmonics)
    mag = _band(np.sqrt(ax ** 2 + ay ** 2 + az ** 2))
    fa, pa = _spectrum(mag)
    if pa.size:
        for mf in fa[np.argsort(pa)[-3:]]:
            pp = pp * (1 - 0.9 * np.exp(-((fp - mf) ** 2) / (2 * 0.15 ** 2)))
    pp = pp / (pp.max() + 1e-12)
    hz = fp * 60.0
    # SOFT tracking: balance spectral evidence with continuity (no hard lock-in).
    score = pp * np.exp(-((hz - prev) ** 2) / (2 * 12.0 ** 2)) if prev is not None else pp
    return float(hz[np.argmax(score)])


def process(rec: str):
    r = wfdb.rdrecord(rec)
    ch = {n: i for i, n in enumerate(r.sig_name)}
    sig = np.nan_to_num(r.p_signal)
    ecg = sig[:, ch["chest_ecg"]]
    ppg_raw = sig[:, ch["wrist_ppg"]]
    ppg = _band(ppg_raw)
    ax, ay, az = (sig[:, ch[f"wrist_low_noise_accelerometer_{a}"]] for a in "xyz")
    ax, ay, az = np.array(ax), np.array(ay), np.array(az)

    ref, naive, acc = [], [], []
    prev = None
    for s in range(0, len(ppg) - WIN, STEP):
        w = slice(s, s + WIN)
        rh = _ref_hr(ecg[w])
        if not (40 < rh < 210):
            continue
        ref.append(rh)
        nv = _naive_hr(ppg_raw[w])
        naive.append(nv)
        if prev is None and np.isfinite(nv):
            prev = nv  # seed the tracker from the first peak-detection estimate
        h = _accel_track_hr(ppg[w], ax[w], ay[w], az[w], prev)
        acc.append(h)
        if np.isfinite(h):
            prev = h
    return np.array(ref), np.array(naive), np.array(acc)


def main():
    d = Path(sys.argv[1] if len(sys.argv) > 1 else "/tmp/wrist")
    agg = {"naive": {}, "accel+track": {}}
    for r in sorted(p.stem for p in d.glob("*.hea")):
        act = "bike" if "bike" in r else ("run" if "run" in r else "walk")
        try:
            ref, naive, acc = process(str(d / r))
        except Exception as e:
            print(f"  skip {r}: {e}"); continue
        for name, est in [("naive", naive), ("accel+track", acc)]:
            m = np.isfinite(ref) & np.isfinite(est)
            if m.sum() >= 5:
                agg[name].setdefault(act, []).append(float(np.mean(np.abs(est[m] - ref[m]))))
        mn = np.nanmean(np.abs(naive - ref)) if np.isfinite(naive).any() else float("nan")
        ma = np.nanmean(np.abs(acc - ref)) if np.isfinite(acc).any() else float("nan")
        print(f"  {r:<28} ref {np.nanmean(ref):5.0f}bpm   naive MAE {mn:5.1f}   accel+track MAE {ma:5.1f}")

    print("\nMEAN ABSOLUTE ERROR vs ECG (bpm), method × activity:")
    acts = ["walk", "run", "bike"]
    print(f"  {'method':<14}" + "".join(f"{a:>9}" for a in acts) + f"{'ALL':>9}")
    for name in ("naive", "accel+track"):
        allv = [v for vs in agg[name].values() for v in vs]
        row = "".join(f"{np.mean(agg[name][a]):9.1f}" if agg[name].get(a) else f"{'—':>9}" for a in acts)
        print(f"  {name:<14}{row}{(np.mean(allv) if allv else float('nan')):9.1f}")


if __name__ == "__main__":
    main()
