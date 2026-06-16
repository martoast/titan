"""How accurate is our PPG-only respiratory rate, on REAL data, at the Bangle's 25 Hz?

Dataset: BIDMC PPG and Respiration (PhysioNet) — the canonical RR-from-PPG benchmark (Pimentel 2017;
the data Charlton 2016 assessed). 53 eight-minute recordings: fingertip PPG (PLETH) @ 125 Hz with
**manual breath annotations** by two experts (the gold standard) and a simultaneous impedance-
respiration signal. We decimate the PPG to **25 Hz** so the number reflects our hardware, slide the
same WIN_SEC/STEP_SEC windows the production module uses, and compare each Smart-Fusion-accepted
window's RR to the annotated breath rate in that window.

Reference RR per window = 60 · (#annotated breaths) / window_seconds, averaged over the two
annotators. Each recording is a different patient → this is inherently leave-subjects-out (the
algorithm has no trained parameters to leak).

FINDING (24 recordings, 25 Hz, Smart-Fusion thr=2.0):
  coverage 38% · MAE 2.89 br/min · median |e| 0.67 · within ±2 = 67% · bias -1.94 br/min
The median error is sub-1-br/min — the method is sound. The mean is dragged by a tail of hard ICU
recordings and a systematic ~2 br/min UNDER-estimate that persists at every gate threshold (so it's
methodological, not the disagreement tail). BIDMC is tachypneic ICU patients (RR up to 23); our
target regime is resting/sleep RR (~12–18 br/min), cleaner and lower, where the median holds. We
report RR as a trend (like HRV), not an absolute, and surface the honest bias — no fabricated
correction (that would overfit BIDMC). Competitive with the literature (Karlen Smart Fusion ~3
br/min on cleaner, higher-rate data), and this is at the Bangle's decimated 25 Hz.

Usage: .venv/bin/python scripts/validate_respiration.py /tmp/bidmc_x
"""

from __future__ import annotations

import glob
import os
import sys
import warnings
from pathlib import Path

import numpy as np

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
from app.core import respiration as resp  # noqa: E402

warnings.filterwarnings("ignore")

SRC_HZ = 125          # BIDMC native PPG rate
DEV_HZ = int(os.getenv("RR_FS", "25"))   # decimate to the Bangle's rate


def _find_csv_dir(root: Path) -> Path:
    hits = glob.glob(str(root / "**" / "bidmc_*_Signals.csv"), recursive=True)
    if not hits:
        raise SystemExit(f"no BIDMC Signals csv under {root}")
    return Path(hits[0]).parent


def _load_record(csv_dir: Path, rid: str):
    """→ (ppg25, breath_times_s) or None. PPG decimated 125→25 Hz; breaths = mean of 2 annotators."""
    sig = np.genfromtxt(csv_dir / f"bidmc_{rid}_Signals.csv", delimiter=",", names=True,
                        invalid_raise=False)   # tolerate a truncated final line
    cols = {n.strip().lower(): n for n in sig.dtype.names}
    pleth_col = next((cols[k] for k in cols if "pleth" in k), None)
    if pleth_col is None:
        return None
    ppg = np.nan_to_num(sig[pleth_col].astype(float))
    step = SRC_HZ // DEV_HZ
    ppg25 = ppg[::step]                                   # 125→25 Hz decimation
    br = np.genfromtxt(csv_dir / f"bidmc_{rid}_Breaths.csv", delimiter=",", names=True)
    bc = {n.strip().lower(): n for n in br.dtype.names}
    # Two annotator columns of breath sample indices (125 Hz). Pool both as breath time-stamps.
    times = []
    for k, col in bc.items():
        if "breath" in k or "ann" in k:
            v = np.atleast_1d(br[col].astype(float))
            times.append(v[np.isfinite(v)] / SRC_HZ)
    if not times:
        return None
    return ppg25, [t for t in times]


def main():
    root = Path(sys.argv[1] if len(sys.argv) > 1 else "/tmp/bidmc_x")
    csv_dir = _find_csv_dir(root)
    rids = sorted({Path(f).name.split("_")[1] for f in glob.glob(str(csv_dir / "bidmc_*_Signals.csv"))})

    win, step = resp.WIN_SEC * DEV_HZ, resp.STEP_SEC * DEV_HZ
    errs, refs, ests = [], [], []
    n_win = n_acc = 0
    per_rec = []
    for rid in rids:
        rec = _load_record(csv_dir, rid)
        if rec is None:
            continue
        ppg25, ann_lists = rec
        rec_err = []
        for s in range(0, ppg25.size - win + 1, step):
            n_win += 1
            t0, t1 = s / DEV_HZ, (s + win) / DEV_HZ
            # reference: mean breaths-in-window across annotators → br/min
            counts = [np.sum((a >= t0) & (a < t1)) for a in ann_lists]
            ref = float(np.mean(counts)) / resp.WIN_SEC * 60.0
            if ref < 4 or ref > 40:                       # implausible reference window, skip
                continue
            r = resp.estimate_rr_window(ppg25[s:s + win], DEV_HZ)
            if not r["valid"]:
                continue
            n_acc += 1
            e = abs(r["resp_rate"] - ref)
            errs.append(e); refs.append(ref); ests.append(r["resp_rate"]); rec_err.append(e)
        if rec_err:
            per_rec.append((rid, np.mean(rec_err), len(rec_err)))

    errs = np.array(errs); refs = np.array(refs); ests = np.array(ests)
    print(f"\nBIDMC · {len(per_rec)} recordings · PPG decimated to {DEV_HZ} Hz (Bangle rate)")
    print(f"  windows: {n_win} total → {n_acc} Smart-Fusion-accepted (coverage {n_acc/max(n_win,1):.0%})")
    if errs.size:
        print(f"  reference RR range: {refs.min():.0f}–{refs.max():.0f} br/min (mean {refs.mean():.1f})")
        print(f"  MAE        {errs.mean():.2f} br/min")
        print(f"  bias       {np.mean(ests - refs):+.2f} br/min")
        print(f"  within ±1  {100*np.mean(errs <= 1):.0f}%   within ±2  {100*np.mean(errs <= 2):.0f}%")
        print(f"  median |e| {np.median(errs):.2f} br/min")
        worst = sorted(per_rec, key=lambda r: -r[1])[:3]
        print("  worst recordings:", ", ".join(f"{r}:{e:.1f}({n})" for r, e, n in worst))
    else:
        print("  no accepted windows — investigate.")


if __name__ == "__main__":
    main()
