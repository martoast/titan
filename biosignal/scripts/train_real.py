"""Train + cross-validate a sleep stager on REAL PSG data (PhysioNet Walch 2019).

Leave-subjects-out (GroupKFold) so we measure generalisation to PEOPLE the model never
saw — the only honest metric. Reports 4-class accuracy + Cohen's κ and sleep/wake κ for:
  (a) the gradient-boosted model per-epoch, and
  (b) the same model + Viterbi temporal smoothing (transition matrix learned from labels).

Usage: .venv/bin/python scripts/train_real.py /tmp/sleep-accel [--save]
Literature ceiling (wrist motion+HR, no EEG): sleep/wake κ≈0.5, 4-class acc≈65-70%.
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
from scripts.validate_on_physionet import subject  # reuse the PSG loader
from app.core import sleep_features

STAGES = ["wake", "light", "deep", "rem"]
S2I = {s: i for i, s in enumerate(STAGES)}
MODEL_OUT = Path(__file__).resolve().parent.parent / "app" / "models" / "sleep_stager.joblib"


def _roll(x, w, fn):
    out = np.zeros_like(x, dtype=float)
    h = w // 2
    for i in range(len(x)):
        out[i] = fn(x[max(0, i - h):min(len(x), i + h + 1)])
    return out


def _pctrank(x):
    return np.argsort(np.argsort(x)) / max(1, len(x) - 1)


def _runlen(b):
    out = np.zeros(len(b)); c = 0.0
    for i, v in enumerate(b):
        c = c + 1 if v > 0 else 0.0
        out[i] = c
    return out


def features(activity: np.ndarray, hr: np.ndarray) -> np.ndarray:
    """Rich per-epoch features from activity + HR (Walch-style: motion context, HR
    level/variability/dynamics, circadian clock). Only averaged HR is available in this
    dataset (no IBI), so REM signal must come from HR variability + surges."""
    n = len(activity)
    a = np.log1p(np.maximum(activity, 0))
    cols = [a]
    for w in (3, 5, 9, 15, 31):
        cols.append(_roll(a, w, np.mean))
    cols.append(_roll(a, 9, np.max))
    cols.append((a <= np.percentile(a, 35)).astype(float))   # "still" indicator

    floor = np.percentile(hr, 5)
    cols.append(hr - floor)
    for w in (3, 9, 15, 31):
        cols.append(_roll(hr, w, np.mean) - floor)
    for w in (5, 11, 21):                                     # multi-scale HR variability (REM ↑)
        cols.append(_roll(hr, w, np.std))
    cols.append(np.gradient(hr))                              # HR derivative
    cols.append(np.gradient(np.gradient(hr)))                # HR acceleration
    cols.append(hr - _roll(hr, 31, np.mean))                 # detrended HR — REM surges
    cols.append(_pctrank(hr))                                # HR rank within the night
    cols.append(_roll(hr, 21, np.min) - floor)               # rolling-min HR (deep ≈ floor)

    still = (a <= np.percentile(a, 40)).astype(float)
    cols.append(_runlen(still))                              # consecutive still epochs (depth)
    cols.append(np.cumsum(still) / (np.arange(n) + 1))       # cumulative sleep pressure

    t = np.linspace(0.0, 1.0, n)
    cols += [t, np.cos(2 * np.pi * t), np.cos(np.pi * t)]     # circadian (deep early, REM late)
    return np.nan_to_num(np.column_stack(cols))


STAY_BONUS = 0.7  # extra log-weight on staying in a stage (enforce realistic durations)


def learn_transitions(y_int_lists) -> np.ndarray:
    T = np.ones((4, 4))  # Laplace prior
    for y in y_int_lists:
        for i in range(1, len(y)):
            T[y[i - 1], y[i]] += 1
    return T / T.sum(1, keepdims=True)


def get_logT(y_int_lists) -> np.ndarray:
    logT = np.log(learn_transitions(y_int_lists) + 1e-9)
    di = np.diag_indices(4)
    logT[di] += STAY_BONUS
    return logT


def viterbi(logp: np.ndarray, logT: np.ndarray) -> list[int]:
    n, k = logp.shape
    delta = logp[0].copy()
    back = np.zeros((n, k), int)
    for i in range(1, n):
        s = delta[:, None] + logT
        back[i] = np.argmax(s, 0)
        delta = np.max(s, 0) + logp[i]
    path = [int(np.argmax(delta))]
    for i in range(n - 1, 0, -1):
        path.append(int(back[i][path[-1]]))
    return path[::-1]


def kappas(true_i, pred_i):
    t = np.array(true_i); p = np.array(pred_i)
    k4 = cohen_kappa_score(t, p, labels=[0, 1, 2, 3])
    acc4 = accuracy_score(t, p)
    sw = lambda a: (a == 0).astype(int)  # wake=1 else sleep
    k2 = cohen_kappa_score(sw(t), sw(p))
    return acc4, k4, k2


def main():
    data_dir = Path(sys.argv[1] if len(sys.argv) > 1 else "/tmp/sleep-accel")
    save = "--save" in sys.argv
    sids = sorted({p.name.split("_")[0] for p in (data_dir / "labels").glob("*_labeled_sleep.txt")})

    data = []
    for s in sids:
        d = subject(data_dir, s)
        if d is not None and len(d[2]) > 120:
            act, hr, lab = d
            X = sleep_features.extract_features(act, hr)
            y = np.array([S2I[s] for s in lab])
            data.append((s, X, y))
    print(f"loaded {len(data)} subjects with PSG labels\n")
    if len(data) < 4:
        print("need >=4 subjects — download more"); return

    groups = np.concatenate([[i] * len(y) for i, (_, _, y) in enumerate(data)])
    Xall = np.vstack([X for _, X, _ in data])
    yall = np.concatenate([y for _, _, y in data])

    n_splits = min(5, len(data))
    gkf = GroupKFold(n_splits=n_splits)
    raw, smoothed = [], []
    for tr, te in gkf.split(Xall, yall, groups):
        clf = HistGradientBoostingClassifier(max_depth=6, max_iter=450, learning_rate=0.06,
                                             l2_regularization=0.1, class_weight="balanced", random_state=0)
        clf.fit(Xall[tr], yall[tr])
        train_subj = np.unique(groups[tr])
        logT = get_logT([data[j][2] for j in train_subj])
        # evaluate per held-out subject (so Viterbi runs over a whole night)
        for j in np.unique(groups[te]):
            mask = groups == j
            proba = np.clip(clf.predict_proba(Xall[mask]), 1e-6, 1)
            yi = yall[mask]
            raw.append(kappas(yi, np.argmax(proba, 1)))
            smoothed.append(kappas(yi, viterbi(np.log(proba), logT)))

    for name, vals in [("model (per-epoch)", raw), ("model + Viterbi", smoothed)]:
        v = np.array(vals).mean(0)
        print(f"  {name:<20} 4-class acc {v[0]*100:5.1f}%   4-class κ {v[1]:.3f}   sleep/wake κ {v[2]:.3f}")

    if save:
        clf = HistGradientBoostingClassifier(max_depth=6, max_iter=450, learning_rate=0.06,
                                             l2_regularization=0.1, class_weight="balanced", random_state=0).fit(Xall, yall)
        logT = get_logT([y for _, _, y in data])
        MODEL_OUT.parent.mkdir(parents=True, exist_ok=True)
        joblib.dump({"model": clf, "stages": STAGES, "logT": logT, "source": "physionet-walch2019",
                     "n_subjects": len(data)}, MODEL_OUT)
        print(f"\nsaved → {MODEL_OUT} (trained on all {len(data)} subjects)")


if __name__ == "__main__":
    main()
