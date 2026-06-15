"""Validate the sleep stagers against REAL polysomnography labels.

Dataset: PhysioNet "Motion and heart rate from a wrist-worn wearable and labeled sleep
from polysomnography" (Walch 2019, sleep-accel/1.0.0) — wrist accelerometer + HR + PSG
stages, the exact signals our pipeline produces. Download a few subjects into DATA_DIR:

    BASE=https://physionet.org/files/sleep-accel/1.0.0
    for s in 46343 759667 ...; do
      curl -sS -o motion/${s}_acceleration.txt    $BASE/motion/${s}_acceleration.txt
      curl -sS -o heart_rate/${s}_heartrate.txt   $BASE/heart_rate/${s}_heartrate.txt
      curl -sS -o labels/${s}_labeled_sleep.txt   $BASE/labels/${s}_labeled_sleep.txt
    done

Then: .venv/bin/python scripts/validate_on_physionet.py /tmp/sleep-accel
"""

from __future__ import annotations

import sys
from pathlib import Path

import numpy as np
from sklearn.ensemble import HistGradientBoostingClassifier
from sklearn.metrics import accuracy_score, cohen_kappa_score

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
from app.core import sleep_features, sleep_hmm, staging  # noqa: E402

EPOCH = 30
# PSG stage code → our 4-class label. -1 = unscored (skip).
STAGE_MAP = {0: "wake", 1: "light", 2: "light", 3: "deep", 4: "deep", 5: "rem"}


def _load(path: Path) -> np.ndarray:
    txt = path.read_text().strip().replace(",", " ")
    return np.array([[float(x) for x in ln.split()] for ln in txt.splitlines() if ln.strip()])


def subject(data_dir: Path, sid: str):
    """→ (activity[], hr[], labels[]) per scored 30-s epoch, or None if missing."""
    try:
        mot = _load(data_dir / "motion" / f"{sid}_acceleration.txt")     # t, x, y, z
        hr = _load(data_dir / "heart_rate" / f"{sid}_heartrate.txt")     # t, bpm
        lab = _load(data_dir / "labels" / f"{sid}_labeled_sleep.txt")    # t, stage
    except Exception:
        return None
    if mot.ndim != 2 or mot.shape[1] < 4 or lab.size == 0:
        return None

    mt, mx, my, mz = mot[:, 0], mot[:, 1], mot[:, 2], mot[:, 3]
    order = np.argsort(mt); mt, mx, my, mz = mt[order], mx[order], my[order], mz[order]
    ht, hb = hr[:, 0], hr[:, 1]

    activity, hrate, labels = [], [], []
    for t, code in lab:
        st = STAGE_MAP.get(int(code))
        if st is None:
            continue
        lo, hi = np.searchsorted(mt, t), np.searchsorted(mt, t + EPOCH)
        if hi - lo >= 3:
            act = float(np.sum(np.abs(np.diff(mx[lo:hi])) + np.abs(np.diff(my[lo:hi])) + np.abs(np.diff(mz[lo:hi]))))
        else:
            act = 0.0
        hlo, hhi = np.searchsorted(ht, t), np.searchsorted(ht, t + EPOCH)
        bpm = float(np.mean(hb[hlo:hhi])) if hhi > hlo else np.nan
        activity.append(act); hrate.append(bpm); labels.append(st)

    hrate = np.array(hrate)
    if np.isnan(hrate).any():  # forward/median fill HR gaps
        hrate = np.where(np.isfinite(hrate), hrate, np.nanmedian(hrate))
    return np.array(activity), hrate, np.array(labels)


def metrics(true, pred):
    t4, p4 = np.array(true), np.array(pred)
    acc4 = accuracy_score(t4, p4)
    k4 = cohen_kappa_score(t4, p4, labels=["wake", "light", "deep", "rem"])
    sw = lambda a: np.where(a == "wake", "wake", "sleep")
    acc2 = accuracy_score(sw(t4), sw(p4))
    k2 = cohen_kappa_score(sw(t4), sw(p4))
    return acc4, k4, acc2, k2


def main():
    data_dir = Path(sys.argv[1] if len(sys.argv) > 1 else "/tmp/sleep-accel")
    sids = sorted({p.name.split("_")[0] for p in (data_dir / "labels").glob("*_labeled_sleep.txt")})
    subs = [(s, subject(data_dir, s)) for s in sids]
    subs = [(s, d) for s, d in subs if d is not None and len(d[2]) > 60]
    print(f"loaded {len(subs)} subjects: {', '.join(s for s, _ in subs)}\n")

    # ---- 1) Untrained stagers (HMM, heuristic) per subject vs PSG ----
    rows = {"HMM (motion+HR)": [], "Heuristic": []}
    for sid, (act, hr, lab) in subs:
        n = len(lab)
        hmm = sleep_hmm.stage_hmm(act, hr, None, n)
        heur = staging._heuristic_stage(
            staging._to_epochs(act, n), staging._to_epochs(hr, n), True, n
        )
        rows["HMM (motion+HR)"].append(metrics(lab, hmm))
        rows["Heuristic"].append(metrics(lab, heur))

    print("UNTRAINED stagers — mean over subjects (vs PSG ground truth):")
    print(f"  {'stager':<18} {'4-stage acc':>11} {'4-stage κ':>10} {'sleep/wake acc':>15} {'s/w κ':>7}")
    for name, vals in rows.items():
        v = np.array(vals).mean(0)
        print(f"  {name:<18} {v[0]*100:>10.1f}% {v[1]:>10.2f} {v[2]*100:>14.1f}% {v[3]:>7.2f}")

    # ---- 2) Model trained on REAL data, leave-subjects-out ----
    X = [sleep_features.extract_features(a, h, True) for _, (a, h, l) in subs]
    Y = [l for _, (a, h, l) in subs]
    n_test = max(1, len(subs) // 3)
    Xtr = np.vstack(X[:-n_test]); ytr = np.concatenate(Y[:-n_test])
    clf = HistGradientBoostingClassifier(max_depth=4, max_iter=250, class_weight="balanced", random_state=0)
    clf.fit(Xtr, ytr)
    held = [metrics(Y[i], list(clf.predict(X[i]))) for i in range(len(subs) - n_test, len(subs))]
    v = np.array(held).mean(0)
    print(f"\nMODEL trained on real data (leave-{n_test}-subjects-out):")
    print(f"  trained on {len(subs)-n_test} subjects, tested on {n_test}")
    print(f"  4-stage acc {v[0]*100:.1f}%  κ {v[1]:.2f}   sleep/wake acc {v[2]*100:.1f}%  κ {v[3]:.2f}")

    print("\nReference (Walch 2019, same data): sleep/wake κ≈0.5-0.6; 4-stage is hard "
          "without EEG — wrist actigraphy+HR tops out around κ≈0.4-0.5.")


if __name__ == "__main__":
    main()
