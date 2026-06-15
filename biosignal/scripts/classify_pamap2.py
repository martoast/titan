"""Workout detection + classification from a WRIST-realistic wearable, validated on real
labeled activity data (PAMAP2, UCI). Same rigor as the HRV/sleep work, leave-subjects-out.

PAMAP2: 9 subjects, IMUs on hand/chest/ankle (100 Hz) + a chest HR monitor, doing labeled
activities (lying, sitting, standing, walking, running, cycling, Nordic walking, stairs,
vacuuming, ironing, rope jumping). We use ONLY the HAND IMU accelerometer + HR — i.e. what a
wrist wearable like our Bangle actually sees — and classify the activity.

Windowed (5.12 s / 50% overlap) features → HistGradientBoosting, GroupKFold by subject.
Reports 12-class and a workout-grouped (rest/walk/run/cycle/stairs/other) accuracy + κ,
plus an accel-only vs accel+HR ablation (does HR help classification?).

Usage: .venv/bin/python scripts/classify_pamap2.py /tmp/pamap2/Protocol
"""

from __future__ import annotations

import sys
from pathlib import Path

import numpy as np
import pandas as pd
from scipy.signal import welch
from sklearn.ensemble import HistGradientBoostingClassifier
from sklearn.metrics import accuracy_score, cohen_kappa_score
from sklearn.model_selection import GroupKFold

import os
sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
from app.core import activity_classify as ac  # shared feature extractor + groups

FS = int(os.getenv('PAMAP_FS', '100'))
STRIDE = max(1, 100 // FS)
WIN = int(5.12 * FS); STEP = WIN // 2  # 5.12 s, 50% overlap

ACT = {1: "lying", 2: "sitting", 3: "standing", 4: "walking", 5: "running", 6: "cycling",
       7: "nordic_walk", 12: "stairs_up", 13: "stairs_down", 16: "vacuum", 17: "ironing", 24: "rope_jump"}
GROUP = {"lying": "rest", "sitting": "rest", "standing": "rest", "ironing": "other", "vacuum": "other",
         "walking": "walk", "nordic_walk": "walk", "running": "run", "cycling": "cycle",
         "stairs_up": "stairs", "stairs_down": "stairs", "rope_jump": "other"}

# PAMAP2 columns: 0=ts, 1=activityID, 2=HR, hand-accel16g = cols 4,5,6.
USECOLS = [0, 1, 2, 4, 5, 6]


def _feats(ax, ay, az, hr, use_hr):
    mag = np.sqrt(ax ** 2 + ay ** 2 + az ** 2)
    cols = []
    for ch in (ax, ay, az, mag):
        cols += [ch.mean(), ch.std(), ch.min(), ch.max(),
                 np.mean(np.abs(np.diff(ch))), np.percentile(ch, 75) - np.percentile(ch, 25)]
    cols += [np.corrcoef(ax, ay)[0, 1], np.corrcoef(ax, az)[0, 1], np.corrcoef(ay, az)[0, 1]]
    f, p = welch(mag - mag.mean(), fs=FS, nperseg=min(len(mag), 256))
    p = p + 1e-12
    cols += [f[np.argmax(p)], p[(f >= 0.5) & (f < 3)].sum(), p[(f >= 3) & (f < 8)].sum(),
             -np.sum((p / p.sum()) * np.log(p / p.sum()))]  # spectral entropy
    if use_hr:
        cols += [np.nanmean(hr), np.nanstd(hr)]
    return cols


def windows(df, use_hr):
    a = df.to_numpy()
    X, y = [], []
    for s in range(0, len(a) - WIN, STEP):
        w = a[s:s + WIN]
        acts = w[:, 1]
        # window must be a single (non-transient) activity
        vals, cnts = np.unique(acts[~np.isnan(acts)], return_counts=True)
        if vals.size == 0:
            continue
        act = int(vals[np.argmax(cnts)])
        if act not in ACT or cnts.max() < 0.9 * WIN:
            continue
        ax, ay, az = w[:, 3], w[:, 4], w[:, 5]
        if np.isnan(ax).any():
            continue
        X.append(_feats(ax, ay, az, w[:, 2], use_hr))
        y.append(ACT[act])
    return np.array(X, dtype=float), np.array(y)


def evaluate(subjects, use_hr, grouped):
    Xs, ys, gs = [], [], []
    for i, (sid, df) in enumerate(subjects):
        X, y = windows(df, use_hr)
        if len(y) == 0:
            continue
        if grouped:
            y = np.array([GROUP[s] for s in y])
        Xs.append(X); ys.append(y); gs.append(np.full(len(y), i))
    X = np.nan_to_num(np.vstack(Xs)); y = np.concatenate(ys); g = np.concatenate(gs)
    oof = np.empty_like(y)
    for tr, te in GroupKFold(min(5, len(Xs))).split(X, y, g):
        clf = HistGradientBoostingClassifier(max_depth=6, max_iter=300, learning_rate=0.08, random_state=0).fit(X[tr], y[tr])
        oof[te] = clf.predict(X[te])
    return accuracy_score(y, oof), cohen_kappa_score(y, oof), y, oof


def main():
    d = Path(sys.argv[1] if len(sys.argv) > 1 else "/tmp/pamap2/Protocol")
    subjects = []
    for f in sorted(d.glob("subject*.dat")):
        df = pd.read_csv(f, sep=r"\s+", header=None, usecols=USECOLS, na_values=["NaN"])
        df = df.sort_index(axis=1)
        df.columns = range(df.shape[1])
        df[2] = df[2].ffill()  # HR is sparse → forward-fill
        if STRIDE > 1: df = df.iloc[::STRIDE].reset_index(drop=True)  # decimate to the Bangle's rate
        subjects.append((f.stem, df))
    print(f"{len(subjects)} subjects loaded\n")
    if len(subjects) < 4:
        print("need >=4 subjects"); return

    for grouped, tag in [(False, "12-class"), (True, "workout-grouped (rest/walk/run/cycle/stairs/other)")]:
        print(f"== {tag} ==")
        for use_hr, lbl in [(False, "accel only "), (True, "accel + HR ")]:
            acc, k, y, oof = evaluate(subjects, use_hr, grouped)
            print(f"   {lbl}  accuracy {acc*100:5.1f}%   κ {k:.3f}")
        # confusion for grouped accel+HR
        if grouped:
            import collections
            classes = sorted(set(y))
            print("   confusion (rows=true, cols=pred, %):")
            for c in classes:
                m = y == c
                row = collections.Counter(oof[m])
                print(f"     {c:<8}" + "".join(f"{row.get(cc,0)/m.sum()*100:6.0f}" for cc in classes))
            print("     " + " " * 8 + "".join(f"{cc[:5]:>6}" for cc in classes))
        print()

    if "--save" in sys.argv:  # train the production model: grouped, accel-only, shared extractor
        import joblib
        Xs, ys = [], []
        for sid, df in subjects:
            a = df.to_numpy()
            for s in range(0, len(a) - WIN, STEP):
                w = a[s:s + WIN]
                vals, cnts = np.unique(w[:, 1][~np.isnan(w[:, 1])], return_counts=True)
                if vals.size == 0:
                    continue
                act = int(vals[np.argmax(cnts)])
                if act not in ACT or cnts.max() < 0.9 * WIN or np.isnan(w[:, 3]).any():
                    continue
                Xs.append(ac.extract_features(w[:, 3], w[:, 4], w[:, 5], fs=FS))
                ys.append(GROUP[ACT[act]])
        clf = HistGradientBoostingClassifier(max_depth=6, max_iter=300, learning_rate=0.08, random_state=0)
        clf.fit(np.array(Xs), np.array(ys))
        ac._MODEL_PATH.parent.mkdir(parents=True, exist_ok=True)
        joblib.dump({"model": clf, "groups": ac.GROUPS, "fs": FS, "source": "pamap2-accel", "n_subjects": len(subjects)}, ac._MODEL_PATH)
        print(f"saved → {ac._MODEL_PATH}  ({len(Xs)} windows, fs={FS}, accel-only grouped)")


if __name__ == "__main__":
    main()
