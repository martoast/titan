"""Prototype: how much would BeliefPPG beat OUR in-motion HR on real ECG-referenced data?

Unlike validate_inmotion_hr.py (which uses a small inline copy of the greedy method), this calls the
ACTUAL shipped estimator — app.core.inmotion_hr.estimate_series (global Viterbi) — so the baseline is
exactly what runs in production. It compares, per 8 s / 2 s window vs chest-ECG ground truth:

  naive   : PPG peak-detection HR (no motion handling) — the floor.
  ours    : estimate_series (accel cadence-notch + Viterbi tracking) — what we ship.
  belief  : BeliefPPG (NN HR-distribution + offline HMM smoothing) — ONLY if `beliefppg` importable.

Dataset: PhysioNet 'Wrist PPG During Exercise' (Jarchi & Casson). Download once with
  python -c "import wfdb; wfdb.dl_database('wrist','/tmp/wrist')"

Usage: python scripts/proto_inmotion_compare.py [/tmp/wrist]

NOTE on interpretation: this dataset is STEADY walk/run/bike — the regime where our method is already
strong. BeliefPPG's documented 2-5x advantage is on IRREGULAR real-world activity (PPG-DaLiA) and the
unbenchmarked lifting/HIIT case. So little delta here is expected and does NOT mean BeliefPPG is useless
for our actual hardest cases — it means this is the wrong benchmark to reveal it.
"""

from __future__ import annotations

import sys
import warnings
from pathlib import Path

import numpy as np
import wfdb
import neurokit2 as nk

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from app.core import inmotion_hr as core  # noqa: E402

warnings.filterwarnings("ignore")
FS = 256
WIN, STEP = 8 * FS, 2 * FS

try:
    from beliefppg import infer_hr  # type: ignore
    HAVE_BELIEF = True
except Exception:
    HAVE_BELIEF = False


def _ref_hr(ecg_win: np.ndarray) -> float:
    try:
        _, info = nk.ecg_peaks(nk.ecg_clean(np.nan_to_num(ecg_win), sampling_rate=FS), sampling_rate=FS)
        pk = np.asarray(info["ECG_R_Peaks"], float)
        return 60.0 / np.median(np.diff(pk) / FS) if pk.size >= 2 else np.nan
    except Exception:
        return np.nan


def _naive_hr(ppg_win: np.ndarray) -> float:
    try:
        _, info = nk.ppg_process(np.nan_to_num(ppg_win), sampling_rate=FS)
        pk = np.asarray(info.get("PPG_Peaks", []), float)
        return 60.0 / np.median(np.diff(pk) / FS) if pk.size >= 2 else np.nan
    except Exception:
        return np.nan


def process(rec: str):
    r = wfdb.rdrecord(rec)
    ch = {n: i for i, n in enumerate(r.sig_name)}
    sig = np.nan_to_num(r.p_signal)
    ecg = sig[:, ch["chest_ecg"]]
    ppg_raw = sig[:, ch["wrist_ppg"]]
    ax, ay, az = (np.array(sig[:, ch[f"wrist_low_noise_accelerometer_{a}"]]) for a in "xyz")

    # OURS: one global Viterbi solve over the whole record (exactly the production call). Keep the
    # production confidence gate so we can separate "confidently wrong" from "honestly refused".
    out = core.estimate_series(ppg_raw, FS, ax, ay, az, FS, min_confidence=core.MIN_CONFIDENCE)
    ours_at = {round(t, 2): b for t, b in zip(out["t"], out["bpm"]) if b is not None}
    ours_rel = {round(t, 2): r for t, r in zip(out["t"], out["reliable"])}

    # PEAKTRACK: the shipped time-domain default (core.peaktrack_series).
    pt = core.peaktrack_series(ppg_raw, FS, min_confidence=core.MIN_CONFIDENCE)
    pt_at = {round(t, 2): b for t, b in zip(pt["t"], pt["bpm"]) if b is not None}

    # BELIEF: whole-record inference (accel as (N,3)); align to window centers afterward.
    belief_at = {}
    if HAVE_BELIEF:
        try:
            acc = np.stack([ax, ay, az], axis=1)
            hr, t_idx = infer_hr(ppg_raw.reshape(-1, 1), FS, acc, FS)  # t_idx in SAMPLES (window midpts)
            belief_at = {round(float(t) / FS, 2): float(h) for t, h in zip(t_idx, hr)}
        except Exception as e:
            print(f"    belief failed on {Path(rec).stem}: {e}")

    ref, naive, ours, ours_r, belief, peaktrack = [], [], [], [], [], []
    for s in range(0, len(ppg_raw) - WIN, STEP):
        w = slice(s, s + WIN)
        rh = _ref_hr(ecg[w])
        if not (40 < rh < 210):
            continue
        tc = round((s + WIN / 2.0) / FS, 2)
        ref.append(rh)
        naive.append(_naive_hr(ppg_raw[w]))
        ours.append(_nearest(ours_at, tc))
        ours_r.append(bool(ours_rel.get(tc, False)))
        belief.append(_nearest(belief_at, tc) if belief_at else np.nan)
        peaktrack.append(_nearest(pt_at, tc))
    return (np.array(ref, float), np.array(naive, float), np.array(ours, float),
            np.array(ours_r, bool), np.array(belief, float), np.array(peaktrack, float))


def _naive_track(naive: np.ndarray, max_jump: float = 12.0) -> np.ndarray:
    """Time-domain peak-detection HR + a causal continuity tracker: accept a new estimate only if it's
    within max_jump of the running value, else hold (reject the peak-detector's occasional half/double
    locks). The zero-dependency alternative to the spectral notch."""
    out = np.full(naive.size, np.nan)
    prev = None
    for i, x in enumerate(naive):
        if np.isfinite(x):
            if prev is None or abs(x - prev) <= max_jump:
                prev = x
            else:
                prev = prev + np.sign(x - prev) * max_jump * 0.5  # ease toward, don't snap
        out[i] = prev if prev is not None else np.nan
    return out


def _nearest(d: dict, tc: float) -> float:
    if not d:
        return np.nan
    if tc in d:
        return d[tc]
    k = min(d, key=lambda x: abs(x - tc))
    return d[k] if abs(k - tc) <= 2.0 else np.nan


def main():
    d = Path(sys.argv[1] if len(sys.argv) > 1 else "/tmp/wrist")
    # naive / peaktrack(shipped default) / ours(notch+Viterbi) / ours_rel(gate-trusted) / belief
    methods = ["naive", "peaktrack", "ours", "ours_rel", "belief"]
    agg = {m: {} for m in methods}
    cov = []  # fraction of windows our gate marks reliable
    print(f"BeliefPPG available: {HAVE_BELIEF}\n")
    for r in sorted(p.stem for p in d.glob("*.hea")):
        act = "bike" if "bike" in r else ("run" if "run" in r else "walk")
        try:
            ref, naive, ours, ours_r, belief, peaktrack = process(str(d / r))
        except Exception as e:
            print(f"  skip {r}: {e}"); continue
        coverage = float(ours_r.mean()) if ours_r.size else 0.0
        cov.append(coverage)
        ests = {"naive": naive, "peaktrack": peaktrack, "ours": ours, "ours_rel": ours, "belief": belief}
        cells = []
        for m in methods:
            est = ests[m]
            mask = np.isfinite(ref) & np.isfinite(est)
            if m == "ours_rel":
                mask = mask & ours_r          # only windows our gate trusts
            if mask.sum() >= 5:
                mae = float(np.mean(np.abs(est[mask] - ref[mask])))
                agg[m].setdefault(act, []).append(mae)
                cells.append(f"{m} {mae:5.1f}")
            else:
                cells.append(f"{m}   —")
        print(f"  {r:<30} ref {np.nanmean(ref):3.0f}bpm cov {coverage:4.0%}  " + "  ".join(cells))

    acts = ["walk", "run", "bike"]
    for stat, fn in [("MEAN", np.mean), ("MEDIAN", np.median)]:
        print(f"\n{stat} per-record MAE vs ECG (bpm), method x activity:")
        print(f"  {'method':<11}" + "".join(f"{a:>9}" for a in acts) + f"{'ALL':>9}")
        for m in methods:
            allv = [v for vs in agg[m].values() for v in vs]
            row = "".join(f"{fn(agg[m][a]):9.1f}" if agg[m].get(a) else f"{'—':>9}" for a in acts)
            print(f"  {m:<11}{row}{(fn(allv) if allv else float('nan')):9.1f}")
    # Walk+run only (the activities that matter here), with the worst-record (outlier) per method.
    print("\nWALK+RUN focus — median / worst-record MAE:")
    for m in methods:
        vals = (agg[m].get("walk", []) + agg[m].get("run", []))
        if vals:
            print(f"  {m:<11} median {np.median(vals):5.1f}   worst {np.max(vals):5.1f}   n={len(vals)}")
    print(f"\n  ours mean coverage (windows passing the confidence gate): {np.mean(cov):.0%}")


if __name__ == "__main__":
    main()
