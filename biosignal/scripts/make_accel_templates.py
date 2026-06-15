"""Extract compact REAL wrist-accel templates from PAMAP2, one per activity, so the workout
simulator can REPLAY genuine motion (synthetic sinusoids misclassify — the classifier learned
real signatures). Saves app/data/accel_templates.npz (m/s², 25 Hz). Run once.

Usage: .venv/bin/python scripts/make_accel_templates.py /tmp/pamap2/PAMAP2_Dataset/Protocol
"""

from __future__ import annotations

import sys
from pathlib import Path

import numpy as np
import pandas as pd

# activityID → our group label; subject to pull a clean contiguous slice from.
WANT = {1: "rest", 4: "walk", 5: "run", 6: "cycle", 12: "stairs"}
SECS = 20
FS = 25  # PAMAP2 is 100 Hz → take every 4th sample


def main():
    d = Path(sys.argv[1] if len(sys.argv) > 1 else "/tmp/pamap2/PAMAP2_Dataset/Protocol")
    out = {}
    for f in sorted(d.glob("subject*.dat")):
        df = pd.read_csv(f, sep=r"\s+", header=None, usecols=[1, 4, 5, 6], na_values=["NaN"])
        for act, label in WANT.items():
            if label in out:
                continue
            seg = df[df[1] == act].dropna()
            a = seg[[4, 5, 6]].to_numpy(float)[::4]  # → 25 Hz
            if len(a) >= SECS * FS:
                out[label] = np.round(a[: SECS * FS], 3).astype(np.float32)
        if len(out) == len(WANT):
            break
    missing = set(WANT.values()) - set(out)
    if missing:
        print(f"WARNING: no clean slice for {missing}")
    dest = Path(__file__).resolve().parent.parent / "app" / "data" / "accel_templates.npz"
    dest.parent.mkdir(parents=True, exist_ok=True)
    np.savez_compressed(dest, fs=FS, **out)
    print(f"saved {len(out)} templates ({', '.join(out)}) @ {FS}Hz, {SECS}s each → {dest}")


if __name__ == "__main__":
    main()
