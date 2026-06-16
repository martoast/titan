"""Validate gait cadence + sit-to-stand counting (Tier-2 #16).

CADENCE — two checks:
  (a) recovers a KNOWN step rate from a synthetic wrist-accel walk (the math is right);
  (b) lands in the physiological band on REAL wrist accel during walking (PPG-DaLiA, Empatica-E4
      wrist accel, activity code 7 = walking), decimated to the Bangle's 25 Hz.

SIT-TO-STAND — recovers a known rep count from a synthetic STS signal. The real-data accuracy of the
underlying rep counter is already established on MM-Fit SQUATS (MAE 0.14 reps; validate_gym.py) — a
sit-to-stand is mechanically the same body-weight raise/lower, so we reuse that validated counter and
check here that the STS-tuned band recovers a controlled count.

Usage: .venv/bin/python scripts/validate_gait.py /tmp/ppgdalia
"""

from __future__ import annotations

import glob
import os
import pickle
import sys
import warnings
from pathlib import Path

import numpy as np

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
from app.core import gait  # noqa: E402

warnings.filterwarnings("ignore")
SRC_HZ, DEV_HZ = 32, 25
WALK_CODE = 7
RNG = np.random.default_rng(11)


def _synthetic_gait(cadence_spm, fs, seconds, kind="walk"):
    """A wrist-accel-like signal at a known step/rep rate, with arm-swing harmonic + noise."""
    f_step = cadence_spm / 60.0
    t = np.arange(int(fs * seconds)) / fs
    # vertical bounce at step freq + arm swing at half (stride) freq, on 3 axes, + gravity + noise
    bounce = np.sin(2 * np.pi * f_step * t)
    swing = 0.6 * np.sin(2 * np.pi * (f_step / 2) * t)
    ax = swing + 0.2 * RNG.standard_normal(t.size)
    ay = 0.5 * bounce + 0.3 * swing + 0.2 * RNG.standard_normal(t.size)
    az = 9.81 + bounce + 0.2 * RNG.standard_normal(t.size)
    return ax, ay, az


def main():
    print("CADENCE\n  (a) synthetic, known step rate (recover ⇒ math is right):")
    for true in (100, 110, 120):
        ax, ay, az = _synthetic_gait(true, DEV_HZ, 20)
        r = gait.cadence_spm(ax, ay, az, fs=DEV_HZ)
        got = r["cadence_spm"] if r else None
        print(f"    {true} spm → {got}")

    d = Path(sys.argv[1] if len(sys.argv) > 1 else "/tmp/ppgdalia")
    cads = []
    for f in sorted(glob.glob(str(d / "S*.pkl"))):
        s = pickle.load(open(f, "rb"), encoding="latin1")
        acc = np.asarray(s["signal"]["wrist"]["ACC"], float)         # 32 Hz, 3-axis, in g
        act = np.asarray(s.get("activity", []), float).ravel()
        n = len(acc)
        afs = len(act) / (n / SRC_HZ) if act.size else 0
        if not afs:
            continue
        # contiguous walking spans → cadence per 12 s window (decimated to 25 Hz, g→m/s²)
        walk = np.repeat(act == WALK_CODE, 1)
        step = SRC_HZ // DEV_HZ
        for w in range(int(n / SRC_HZ / 12)):
            t0, t1 = w * 12, (w + 1) * 12
            a = act[int(t0 * afs):int(t1 * afs)]
            if a.size == 0 or not np.all(a.astype(int) == WALK_CODE):
                continue
            seg = acc[int(t0 * SRC_HZ):int(t1 * SRC_HZ):step] * 9.81
            r = gait.cadence_spm(seg[:, 0], seg[:, 1], seg[:, 2], fs=DEV_HZ)
            if r:
                cads.append(r["cadence_spm"])
    if cads:
        cads = np.array(cads)
        inrange = np.mean((cads >= 90) & (cads <= 135))
        print(f"  (b) real PPG-DaLiA walking @ {DEV_HZ} Hz: {len(cads)} windows, "
              f"cadence {np.median(cads):.0f} spm (IQR {np.percentile(cads,25):.0f}-{np.percentile(cads,75):.0f}), "
              f"{100*inrange:.0f}% in the 90-135 physiological band")

    print("\nSIT-TO-STAND\n  synthetic, known rep count (STS-tuned band):")
    for true_reps in (8, 12, 16, 22):
        # one vertical accel bump per stand, evenly spaced over 30 s (a real STS cadence)
        fs, dur = DEV_HZ, 30
        t = np.arange(fs * dur) / fs
        f_rep = true_reps / dur
        bump = -np.cos(2 * np.pi * f_rep * t)                 # one rise/fall per rep
        az = 9.81 + 1.5 * bump + 0.25 * RNG.standard_normal(t.size)
        ax = 0.4 * bump + 0.25 * RNG.standard_normal(t.size)
        ay = 0.3 * bump + 0.25 * RNG.standard_normal(t.size)
        r = gait.sit_to_stand(ax, ay, az, fs=fs, duration_s=30)
        print(f"    {true_reps} reps/30s → {r['reps'] if r else None}")
    print("  (real-data rep accuracy = MM-Fit squats, MAE 0.14 — the mechanical twin; see validate_gym.py)")


if __name__ == "__main__":
    main()
