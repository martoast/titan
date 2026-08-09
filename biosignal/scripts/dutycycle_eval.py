"""Measure the stager against real PSG the way the BAND actually feeds it.

`validate_on_physionet.py` scores the stager on DENSE data — a reading every 30 s. That is not what
production sees. The band duty-cycles: one ~30-s burst every ~3 min, so roughly one epoch in six
carries a real reading and `staging._reconstruct` sample-and-holds the rest. On Alex's 2026-08-06
night that was 146 real epochs out of 877 (16.6%) — 83% of the grid held.

That matters because the model splits deep from light on HR *stability*, and a held span has
EXACTLY zero variability: `_roll(hr, w, np.std)`, `np.gradient(hr)` and the detrended-HR column all
collapse to 0 inside a hold. In the training distribution that only happens in genuinely stable NREM,
so the hold reads as deep. Hence the over-call (Tester B 2026-08-04 at 40% deep, Alex 2026-08-06 at 31.5%).

This script quantifies it against ground truth by decimating PSG-labelled subjects to the band's
cadence and pushing them through the SAME `staging.stage_night` production calls — so whatever it
reports is what the pipeline really does, not a reimplementation of it.

Usage:
    python scripts/dutycycle_eval.py ~/sleep-accel [--every 6] [--json out.json]
"""

from __future__ import annotations

import argparse
import json
import sys
from datetime import datetime, timedelta, timezone
from pathlib import Path

import numpy as np
from sklearn.metrics import accuracy_score, cohen_kappa_score

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
from app.core import staging  # noqa: E402
from scripts.validate_on_physionet import subject  # noqa: E402

STAGES = ["wake", "light", "deep", "rem"]
EPOCH_SEC = 30
T0 = datetime(2026, 1, 1, 23, 0, 0, tzinfo=timezone.utc)


def _span(n_epochs: int) -> tuple[str, str]:
    return T0.isoformat(), (T0 + timedelta(seconds=n_epochs * EPOCH_SEC)).isoformat()


def stage_dense(act: np.ndarray, hr: np.ndarray) -> list[str]:
    """The path validate_on_physionet uses: a real reading in every epoch."""
    n = len(act)
    start, end = _span(n)
    return staging.stage_night(list(act), list(hr), None, start, end)["hypnogram_30s"]


def stage_dutycycled(act: np.ndarray, hr: np.ndarray, every: int) -> list[str]:
    """The path PRODUCTION uses: sparse bursts scattered onto the full grid, gaps sample-and-held.

    Mirrors SealNightJob::stageSparse — it passes only the sampled epochs' values plus their real
    epoch indices, and staging._reconstruct does the holding.
    """
    n = len(act)
    idx = list(range(0, n, every))
    start, end = _span(n)
    return staging.stage_night(
        [float(act[i]) for i in idx],
        [float(hr[i]) for i in idx],
        None,
        start,
        end,
        sample_epochs=idx,
    )["hypnogram_30s"]


def _score(true: list[str], pred: list[str]) -> dict:
    """Compare on the epochs the stager actually committed to a stage (NODATA holes excluded)."""
    pairs = [(t, p) for t, p in zip(true, pred) if p != "nodata"]
    if not pairs:
        return {}
    t = np.array([a for a, _ in pairs])
    p = np.array([b for _, b in pairs])
    sw = lambda a: np.where(a == "wake", "wake", "sleep")  # noqa: E731

    out = {
        "n": len(pairs),
        "acc4": float(accuracy_score(t, p)),
        "k4": float(cohen_kappa_score(t, p, labels=STAGES)),
        "k2": float(cohen_kappa_score(sw(t), sw(p))),
    }
    # Stage-fraction bias — the metric this bug lives in. Fractions are of SLEEP, matching how the
    # app reports deep/rem/light and how SleepPlausibility judges them.
    for arr, tag in ((t, "true"), (p, "pred")):
        asleep = int((arr != "wake").sum()) or 1
        for s in ("deep", "rem", "light"):
            out[f"{tag}_{s}_frac"] = float((arr == s).sum() / asleep)
    for s in ("deep", "rem", "light"):
        out[f"bias_{s}"] = out[f"pred_{s}_frac"] - out[f"true_{s}_frac"]
    return out


def _mean(rows: list[dict], key: str) -> float:
    vals = [r[key] for r in rows if key in r]
    return float(np.mean(vals)) if vals else float("nan")


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("data_dir", nargs="?", default=str(Path.home() / "sleep-accel"))
    ap.add_argument("--every", type=int, default=6, help="keep 1 epoch in N (6 = the band's ~3 min)")
    ap.add_argument("--json", default=None)
    args = ap.parse_args()

    data_dir = Path(args.data_dir).expanduser()
    sids = sorted({p.name.split("_")[0] for p in (data_dir / "labels").glob("*_labeled_sleep.txt")})

    dense_rows, duty_rows, per_subject = [], [], []
    for sid in sids:
        d = subject(data_dir, sid)
        if d is None or len(d[2]) <= 120:
            continue
        act, hr, lab = d
        lab = list(lab)
        dense = _score(lab, stage_dense(act, hr))
        duty = _score(lab, stage_dutycycled(act, hr, args.every))
        if not dense or not duty:
            continue
        dense_rows.append(dense)
        duty_rows.append(duty)
        per_subject.append({"sid": sid, "dense": dense, "duty": duty})
        print(f"  {sid:>8}  true deep {duty['true_deep_frac']*100:5.1f}%   "
              f"dense {dense['pred_deep_frac']*100:5.1f}%   duty {duty['pred_deep_frac']*100:5.1f}%   "
              f"(acc4 dense {dense['acc4']*100:4.1f}% → duty {duty['acc4']*100:4.1f}%)")

    if not duty_rows:
        print("no subjects loaded — is the dataset downloaded?")
        return

    print(f"\n{len(duty_rows)} subjects · 1 epoch in {args.every} kept "
          f"({100/args.every:.1f}% real sampling)\n")
    hdr = f"{'':<10} {'acc4':>7} {'κ4':>7} {'κ sleep/wake':>13} {'deep bias':>11} {'rem bias':>10}"
    print(hdr)
    for name, rows in (("dense", dense_rows), ("duty-cycle", duty_rows)):
        print(f"{name:<10} {_mean(rows,'acc4')*100:6.1f}% {_mean(rows,'k4'):7.3f} "
              f"{_mean(rows,'k2'):13.3f} {_mean(rows,'bias_deep')*100:+10.1f}pp "
              f"{_mean(rows,'bias_rem')*100:+9.1f}pp")
    print(f"\ntrue deep fraction (PSG):      {_mean(duty_rows,'true_deep_frac')*100:.1f}%")
    print(f"predicted, dense:              {_mean(dense_rows,'pred_deep_frac')*100:.1f}%")
    print(f"predicted, duty-cycled:        {_mean(duty_rows,'pred_deep_frac')*100:.1f}%")

    if args.json:
        Path(args.json).write_text(json.dumps(
            {"every": args.every, "subjects": per_subject}, indent=2))
        print(f"\nwrote {args.json}")


if __name__ == "__main__":
    main()
