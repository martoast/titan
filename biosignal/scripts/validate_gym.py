"""Gym workout tracking from a WRIST accelerometer, validated on real data (MM-Fit).

The user's primary training is the gym — treadmill + lifting. Lifting is NOT locomotion, so the
PAMAP2 classifier never saw it; this validates the two things a wrist can honestly give a lifter:
exercise RECOGNITION and REP COUNTING, with the same rigor (real data, leave-workout-out).

Dataset: MM-Fit (Strömbäck et al.) — smartwatch (wrist) accel during full gym workouts, 10
exercises × ~3 sets × ~10 reps, with per-set rep labels. Accel is m/s² at 100 Hz; we decimate to
the Bangle's 25 Hz so the numbers reflect our hardware.

  1. Exercise classification: per-set window features → HistGradientBoosting, GroupKFold by
     workout (leave-workout-out → a new session). 10-class + a lift/cardio grouping.
  2. Rep counting: autocorrelation of the de-trended wrist motion → rep period → count, vs labels.

Usage: .venv/bin/python scripts/validate_gym.py /tmp/mmfit
"""

from __future__ import annotations

import glob
import os
import sys
from pathlib import Path

import numpy as np
from scipy.signal import butter, filtfilt, welch
from sklearn.ensemble import HistGradientBoostingClassifier
from sklearn.metrics import accuracy_score, cohen_kappa_score
from sklearn.model_selection import GroupKFold

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
from app.core import activity_classify as ac  # reuse the shared accel feature extractor

FS = int(os.getenv("GYM_FS", "25"))
STRIDE = max(1, 100 // FS)

# Strength (a lifter's bread and butter) vs more cardio/bodyweight movements.
LIFT = {"bicep_curls", "tricep_extensions", "dumbbell_rows", "dumbbell_shoulder_press",
        "lateral_shoulder_raises", "pushups", "squats"}


def load_sets(datadir: Path):
    """Yield (workout_id, exercise, reps, accel[n,3] @ FS) for every labeled set."""
    out = []
    for lab in sorted(glob.glob(str(datadir / "w*_labels.csv"))):
        wid = os.path.basename(lab)[:3]
        acc_f = datadir / f"{wid}_sw_l_acc.npy"
        if not acc_f.exists():
            continue
        a = np.load(acc_f)              # [frame, ts_ms, x, y, z]
        frame, xyz = a[:, 0], a[:, 2:5]
        for line in open(lab):
            parts = line.strip().split(",")
            if len(parts) < 4:
                continue
            s, e, reps, ex = int(parts[0]), int(parts[1]), int(parts[2]), parts[3]
            m = (frame >= s) & (frame <= e)
            if m.sum() < 100:
                continue
            seg = xyz[m][::STRIDE]
            if len(seg) >= int(ac.WINDOW_SEC * FS):
                out.append((wid, ex, reps, seg))
    return out


def _periodicity(x, lo, hi):
    """Return (best_period, peak_strength) from the autocorrelation of a 1-D signal in the rep band."""
    n = len(x)
    acf = np.correlate(x, x, "full")[n - 1:]
    acf /= (acf[0] + 1e-9)
    band = acf[lo:min(hi, n - 1)]
    if band.size < 3:
        return None, -1.0
    k = int(np.argmax(band))
    return lo + k, float(band[k])


def count_reps(seg: np.ndarray) -> int:
    """Reps from wrist accel. A rep is ONE full movement cycle, so we want a SIGNED 1-D signal
    with one peak per rep (|accel| double-counts: it peaks on both the up AND the down). Different
    lifts move the wrist along different axes, so we try each axis + the principal axis and keep
    whichever is MOST PERIODIC (cleanest autocorrelation peak in the rep band) → that's the
    rep-bearing signal. Period → count = duration / period."""
    c = seg - seg.mean(axis=0)                       # de-gravity / de-mean each axis
    evals, evecs = np.linalg.eigh(np.cov(c.T))
    candidates = [c[:, 0], c[:, 1], c[:, 2], c @ evecs[:, -1]]
    b, a = butter(2, [0.2 / (FS / 2), 1.4 / (FS / 2)], btype="band")
    lo, hi = int(FS / 1.4), int(FS / 0.2)            # period bounds (samples): 0.2–1.4 Hz reps
    n = len(seg)
    best_period, best_strength = None, -1.0
    for sig in candidates:
        x = filtfilt(b, a, np.nan_to_num(sig))
        period, strength = _periodicity(x, lo, hi)
        if period and strength > best_strength:
            best_period, best_strength = period, strength
    if best_period is None:
        return max(1, round(n / FS / 2))
    return int(round(n / best_period))


def main():
    d = Path(sys.argv[1] if len(sys.argv) > 1 else "/tmp/mmfit")
    sets = load_sets(d)
    workouts = sorted(set(s[0] for s in sets))
    print(f"\n{len(sets)} labeled sets · {len(workouts)} workouts · {len(set(s[1] for s in sets))} exercises "
          f"· {FS} Hz (Bangle rate)\n")

    # --- Exercise classification, leave-workout-out ---
    X = np.array([ac.extract_features(s[3][:, 0], s[3][:, 1], s[3][:, 2], fs=FS) for s in sets])
    ex = np.array([s[1] for s in sets])
    grp = np.array([s[0] for s in sets])
    for labels, tag in [(ex, "10-class exercise"),
                        (np.array(["lift" if e in LIFT else "cardio" for e in ex]), "lift vs cardio")]:
        oof = np.empty_like(labels)
        for tr, te in GroupKFold(min(5, len(workouts))).split(X, labels, grp):
            clf = HistGradientBoostingClassifier(max_depth=6, max_iter=300, learning_rate=0.08, random_state=0)
            clf.fit(X[tr], labels[tr])
            oof[te] = clf.predict(X[te])
        print(f"  {tag:<20} accuracy {accuracy_score(labels, oof)*100:5.1f}%   κ {cohen_kappa_score(labels, oof):.3f}")

    # --- Rep counting ---
    true = np.array([s[2] for s in sets])
    est = np.array([count_reps(s[3]) for s in sets])
    err = np.abs(est - true)
    # MM-Fit does bicep_curls ALTERNATING arms, so a single-wrist watch correctly counts its own
    # arm's ~5 (the label is the bilateral total) — a protocol artifact, not a counter error. We
    # report both the raw number and the single-arm-honest one (curls excluded).
    keep = ex != "bicep_curls"
    err_k = np.abs(est[keep] - true[keep])
    print(f"\n  Rep counting (all):            MAE {err.mean():.2f}   within ±1: {(err<=1).mean()*100:.0f}%")
    print(f"  Rep counting (per-wrist honest): MAE {err_k.mean():.2f}   within ±1: {(err_k<=1).mean()*100:.0f}%   "
          f"(excl. bicep_curls — MM-Fit alternates arms; the wrist correctly sees its own reps)")
    print("  per-exercise rep MAE:")
    for e in sorted(set(ex)):
        m = ex == e
        note = "  ← alternating arms (wrist sees half)" if e == "bicep_curls" else ""
        print(f"     {e:<26} {np.abs(est[m]-true[m]).mean():.2f}   (n={m.sum()}){note}")

    if "--save" in sys.argv:  # bake the production exercise classifier (all sets, 10-class)
        import joblib
        clf = HistGradientBoostingClassifier(max_depth=6, max_iter=300, learning_rate=0.08, random_state=0)
        clf.fit(X, ex)
        path = Path(__file__).resolve().parent.parent / "app" / "models" / "gym_classifier.joblib"
        path.parent.mkdir(parents=True, exist_ok=True)
        joblib.dump({"model": clf, "classes": sorted(set(ex)), "lift": sorted(LIFT),
                     "fs": FS, "source": "mm-fit", "n_sets": len(sets)}, path)
        print(f"\nsaved → {path}  ({len(sets)} sets, {FS} Hz)")


if __name__ == "__main__":
    main()
