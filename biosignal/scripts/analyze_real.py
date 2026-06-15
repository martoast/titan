"""What did the sleep model learn? Out-of-fold (leave-subjects-out) confusion matrix,
per-stage precision/recall, and permutation feature importance — on real PSG data.

Usage: .venv/bin/python scripts/analyze_real.py /tmp/sleep-accel
"""

from __future__ import annotations

import sys
from pathlib import Path

import numpy as np
from sklearn.ensemble import HistGradientBoostingClassifier
from sklearn.inspection import permutation_importance
from sklearn.metrics import classification_report, cohen_kappa_score, confusion_matrix
from sklearn.model_selection import GroupKFold

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
from app.core import sleep_features
from scripts.validate_on_physionet import subject

STAGES = ["wake", "light", "deep", "rem"]
S2I = {s: i for i, s in enumerate(STAGES)}


def main():
    data_dir = Path(sys.argv[1] if len(sys.argv) > 1 else "/tmp/sleep-accel")
    sids = sorted({p.name.split("_")[0] for p in (data_dir / "labels").glob("*_labeled_sleep.txt")})
    data = []
    for s in sids:
        d = subject(data_dir, s)
        if d is not None and len(d[2]) > 120:
            data.append((sleep_features.extract_features(d[0], d[1]), np.array([S2I[x] for x in d[2]])))
    print(f"{len(data)} subjects\n")

    groups = np.concatenate([[i] * len(y) for i, (_, y) in enumerate(data)])
    X = np.vstack([x for x, _ in data]); y = np.concatenate([yy for _, yy in data])

    # Out-of-fold predictions (each subject predicted by a model that never saw them).
    oof = np.zeros_like(y)
    for tr, te in GroupKFold(min(5, len(data))).split(X, y, groups):
        clf = HistGradientBoostingClassifier(max_depth=6, max_iter=450, learning_rate=0.06,
                                             l2_regularization=0.1, class_weight="balanced", random_state=0).fit(X[tr], y[tr])
        oof[te] = clf.predict(X[te])

    print("CONFUSION MATRIX (rows=true PSG, cols=predicted), % of each true stage:")
    cm = confusion_matrix(y, oof, labels=[0, 1, 2, 3]).astype(float)
    cmp = cm / cm.sum(1, keepdims=True) * 100
    print("           " + "".join(f"{s:>8}" for s in STAGES))
    for i, s in enumerate(STAGES):
        print(f"  {s:>6} " + "".join(f"{cmp[i, j]:7.0f}%" for j in range(4)))
    print("\nPER-STAGE precision / recall / F1:")
    print(classification_report(y, oof, labels=[0, 1, 2, 3], target_names=STAGES, digits=2, zero_division=0))
    print(f"4-class κ {cohen_kappa_score(y, oof):.3f}   "
          f"sleep/wake κ {cohen_kappa_score((y==0).astype(int), (oof==0).astype(int)):.3f}")

    # Permutation importance on one held-out fold.
    tr = groups < len(data) - 2; te = ~tr
    clf = HistGradientBoostingClassifier(max_depth=6, max_iter=450, learning_rate=0.06,
                                         l2_regularization=0.1, class_weight="balanced", random_state=0).fit(X[tr], y[tr])
    imp = permutation_importance(clf, X[te], y[te], n_repeats=5, random_state=0, scoring="accuracy")
    names = (["log_act"] + [f"act_roll{w}" for w in (3, 5, 9, 15, 31)] + ["act_max", "still"]
             + ["hr_rel"] + [f"hr_roll{w}" for w in (3, 9, 15, 31)] + [f"hr_std{w}" for w in (5, 11, 21)]
             + ["hr_d1", "hr_d2", "hr_detrend", "hr_rank", "hr_min", "still_runlen", "sleep_pressure",
                "t", "cos2pi_t", "cospi_t"])
    order = np.argsort(imp.importances_mean)[::-1]
    print("\nTOP FEATURES (permutation importance):")
    for k in order[:10]:
        nm = names[k] if k < len(names) else f"f{k}"
        print(f"  {nm:<16} {imp.importances_mean[k]:.3f}")


if __name__ == "__main__":
    main()
