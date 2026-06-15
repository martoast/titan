"""Train ONE sleep stager on the union of two real PSG datasets that each lack a signal:
  Walch (sleep-accel): motion + HR, no RMSSD
  slpdb (MIT-BIH PSG):  HR + RMSSD, no motion
HistGradientBoosting handles the missing blocks (NaN) natively, so the model learns motion
from Walch and RMSSD from slpdb. At inference our Bangle supplies ALL THREE.

Evaluates leave-subjects-out PER dataset (does combining hurt either?), then saves a model
trained on everything. Usage: .venv/bin/python scripts/train_combined.py [--save]
"""

from __future__ import annotations

import sys
from pathlib import Path

import joblib
import numpy as np
from sklearn.ensemble import HistGradientBoostingClassifier
from sklearn.metrics import accuracy_score, cohen_kappa_score
from sklearn.model_selection import GroupKFold

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
from app.core import sleep_features
from scripts.validate_on_physionet import subject as walch_subject
from scripts.ablate_rmssd import process as slpdb_process

STAGES = ["wake", "light", "deep", "rem"]
S2I = {s: i for i, s in enumerate(STAGES)}
MODEL_OUT = Path(__file__).resolve().parent.parent / "app" / "models" / "sleep_stager.joblib"


def load(walch_dir="/tmp/sa", slpdb_dir="/tmp/slpdb"):
    rows = []  # (dataset, subject_id, X, y)
    wd = Path(walch_dir)
    for s in sorted({p.name.split("_")[0] for p in (wd / "labels").glob("*_labeled_sleep.txt")}):
        d = walch_subject(wd, s)
        if d and len(d[2]) > 120:
            X = sleep_features.extract_features(d[0], d[1], rmssd=None)
            rows.append(("walch", "w" + s, X, np.array([S2I[x] for x in d[2]])))
    sd = Path(slpdb_dir)
    for r in sorted({p.stem for p in sd.glob("*.hea")}):
        try:
            hr, rmssd, lab = slpdb_process(str(sd / r))
        except Exception:
            continue
        if len(lab) > 60:
            X = sleep_features.extract_features(None, hr, rmssd)
            rows.append(("slpdb", "s" + r.rstrip("abx"), X, np.array([S2I[x] for x in lab])))
    return rows


def kap(true, pred):
    k4 = cohen_kappa_score(true, pred, labels=[0, 1, 2, 3])
    sw = lambda a: (np.array(a) == 0).astype(int)
    return accuracy_score(true, pred), k4, cohen_kappa_score(sw(true), sw(pred))


def main():
    rows = load()
    ds = np.array([r[0] for r in rows])
    print(f"{len(rows)} subject-records: {int((ds=='walch').sum())} Walch + {int((ds=='slpdb').sum())} slpdb\n")

    # subject groups across both datasets
    subj = [r[1] for r in rows]
    uniq = {s: i for i, s in enumerate(sorted(set(subj)))}
    groups = np.concatenate([[uniq[rows[i][1]]] * len(rows[i][3]) for i in range(len(rows))])
    dsrow = np.concatenate([[rows[i][0]] * len(rows[i][3]) for i in range(len(rows))])
    X = np.vstack([r[2] for r in rows]); y = np.concatenate([r[3] for r in rows])

    oof = np.full_like(y, -1)
    for tr, te in GroupKFold(min(6, len(uniq))).split(X, y, groups):
        clf = HistGradientBoostingClassifier(max_depth=6, max_iter=400, learning_rate=0.06,
                                             l2_regularization=0.1, class_weight="balanced", random_state=0).fit(X[tr], y[tr])
        oof[te] = clf.predict(X[te])

    print("COMBINED model, leave-subjects-out, scored per dataset:")
    for d in ("walch", "slpdb"):
        m = (dsrow == d) & (oof >= 0)
        acc, k4, k2 = kap(y[m], oof[m])
        print(f"  {d:<6} 4-class κ {k4:.3f}  acc {acc*100:4.1f}%  sleep/wake κ {k2:.3f}")
    print("\nBaselines for comparison (each dataset trained on itself):")
    print("  walch (motion+HR only)   4-class κ ~0.32   sleep/wake κ ~0.50")
    print("  slpdb (HR+RMSSD only)    4-class κ ~0.24 (vs 0.13 HR-only — RMSSD lift)")

    if "--save" in sys.argv:
        clf = HistGradientBoostingClassifier(max_depth=6, max_iter=400, learning_rate=0.06,
                                             l2_regularization=0.1, class_weight="balanced", random_state=0).fit(X, y)
        joblib.dump({"model": clf, "stages": STAGES, "source": "walch+slpdb (motion+HR+RMSSD)",
                     "n_subjects": len(rows)}, MODEL_OUT)
        print(f"\nsaved → {MODEL_OUT}")


if __name__ == "__main__":
    main()
