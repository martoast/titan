"""Retrain the sleep stager so it survives the band's DUTY CYCLE.

THE BUG THIS FIXES
------------------
`train_real.py` trains on DENSE PhysioNet epochs — a real reading every 30 s. Production is not
dense: the band samples one ~30-s burst every ~3 min and `staging._reconstruct` sample-and-holds the
rest, so ~83% of the grid is a repeat of the last real reading (Alex 2026-08-06: 146 real epochs of
877). Inside a held span every HR-variability feature is EXACTLY zero — `_roll(hr, w, np.std)`,
`np.gradient(hr)`, `hr - _roll(hr, 31, mean)`. In the dense training distribution that only happens
in genuinely stable NREM, so the model reads the hold as DEEP. That is the over-call: Tester B 2026-08-04
sealed 40% deep in one 119.5-min block, Alex 2026-08-06 sealed 31.5% deep in an 85-min block, both
with the "deep" span carrying HIGHER HR than the "light" span — the label inverted.

It is a train/inference MISMATCH, not a bad model. The cure is to train on what inference sees.

WHY AUGMENT RATHER THAN JUST DECIMATE
-------------------------------------
`staging.stage_night` has two live paths: the sparse duty-cycle path (`sample_epochs` given, the
normal overnight case) and the dense path (`_to_epochs`, used when a night arrives fully sampled).
Training ONLY on decimated data would fix the first and degrade the second. So each subject
contributes its dense night plus one decimated night per phase offset — same labels, same features,
different sampling density. The model then has to solve the task from signals that survive holding
(motion, HR level, circadian position) instead of leaning on micro-variability that only exists when
the band happened to be streaming.

Decimation goes through `staging._reconstruct` + `_fill_holes` — the real production functions — so
the training grid is byte-for-byte the kind of grid inference builds.

Usage:
    python scripts/train_dutycycle.py ~/sleep-accel            # cross-validate, print, don't save
    python scripts/train_dutycycle.py ~/sleep-accel --save     # also write app/models/sleep_stager.joblib
"""

from __future__ import annotations

import argparse
import sys
from pathlib import Path

import joblib
import numpy as np
from sklearn.ensemble import HistGradientBoostingClassifier
from sklearn.metrics import accuracy_score, cohen_kappa_score
from sklearn.model_selection import GroupKFold

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
from app.core import sleep_features, staging  # noqa: E402
from scripts.train_real import STAY_BONUS, get_logT, viterbi  # noqa: E402
from scripts.validate_on_physionet import subject  # noqa: E402

STAGES = ["wake", "light", "deep", "rem"]
S2I = {s: i for i, s in enumerate(STAGES)}
MODEL_OUT = Path(__file__).resolve().parent.parent / "app" / "models" / "sleep_stager.joblib"

# The band's real cadence, measured on production windows: median inter-burst gap 3.00 min over a
# 30-s epoch grid = keep 1 epoch in 6. PHASES are the offsets of the first burst; a night can start
# anywhere in the cycle, and training every phase stops the model keying on the grid's parity.
EVERY = 6
PHASES = (0, 2, 4)


def decimated_grid(act: np.ndarray, hr: np.ndarray, every: int, phase: int):
    """Dense per-epoch (activity, HR) → the HELD grid production would build from that night.

    Uses the production reconstruction so the training input matches inference exactly: scatter the
    kept samples at their real epoch index, hold short gaps, leave long gaps as holes, then fill for
    the model (staging relabels holes NODATA afterwards, which is why they are dropped below).
    """
    n = len(act)
    idx = np.arange(phase, n, every)
    if idx.size < 4:
        return None
    act_grid, covered = staging._reconstruct(act[idx], idx, n)
    # HR is denoised at the RAW-reading stage in production (before reconstruction) — mirror that.
    hr_clean = staging._denoise_hr(staging._zero_to_nan(hr[idx].astype(float)))
    hr_grid, _ = staging._reconstruct(hr_clean, idx, n)
    return staging._fill_holes(act_grid), staging._fill_holes(hr_grid), covered


def build_dataset(data_dir: Path, every: int, phases, dense_weight: int = 1):
    """→ (X, y, groups, meta) over dense + duty-cycled variants of every usable subject."""
    sids = sorted({p.name.split("_")[0] for p in (data_dir / "labels").glob("*_labeled_sleep.txt")})
    Xs, ys, gs, seqs = [], [], [], []
    kept = []
    for gi, sid in enumerate(sids):
        d = subject(data_dir, sid)
        if d is None or len(d[2]) <= 120:
            continue
        act, hr, lab = d
        y = np.array([S2I[s] for s in lab])
        kept.append(sid)

        for _ in range(dense_weight):                       # the dense path stays supported
            Xs.append(sleep_features.extract_features(act, hr))
            ys.append(y)
            gs.append(np.full(len(y), gi))
            seqs.append(y)

        for ph in phases:                                   # the duty-cycled path production uses
            grid = decimated_grid(act, hr, every, ph)
            if grid is None:
                continue
            act_e, hr_e, covered = grid
            X = sleep_features.extract_features(act_e, hr_e, has_hr=True)
            # Holes become NODATA at inference and are never scored, so they must not train either.
            m = np.asarray(covered, dtype=bool)
            Xs.append(X[m])
            ys.append(y[m])
            gs.append(np.full(int(m.sum()), gi))
            seqs.append(y[m])

    if not Xs:
        return None
    return np.vstack(Xs), np.concatenate(ys), np.concatenate(gs), (kept, seqs)


def _clf() -> HistGradientBoostingClassifier:
    # Same hyperparameters as train_real.py — the change under test is the DATA, not the model.
    return HistGradientBoostingClassifier(
        max_depth=6, max_iter=450, learning_rate=0.06,
        l2_regularization=0.1, class_weight="balanced", random_state=0)


def _report(true_i, pred_i) -> dict:
    t, p = np.asarray(true_i), np.asarray(pred_i)
    sw = lambda a: (a == 0).astype(int)  # noqa: E731
    asleep_t = max(int((t != 0).sum()), 1)
    asleep_p = max(int((p != 0).sum()), 1)
    return {
        "acc4": accuracy_score(t, p),
        "k4": cohen_kappa_score(t, p, labels=[0, 1, 2, 3]),
        "k2": cohen_kappa_score(sw(t), sw(p)),
        "deep_true": (t == 2).sum() / asleep_t,
        "deep_pred": (p == 2).sum() / asleep_p,
        "rem_pred": (p == 3).sum() / asleep_p,
        "rem_true": (t == 3).sum() / asleep_t,
    }


def evaluate(data_dir: Path, every: int, phases) -> None:
    """Leave-SUBJECTS-out CV comparing TWO training regimes on identical folds.

    The control ("dense-only") reproduces what `train_real.py` does, so it is the shipped model's
    recipe trained on exactly the same subjects. Comparing the augmented model against the *deployed*
    artifact instead would confound the augmentation with a different subject count, and any win
    could just be more data. Both arms are then scored on BOTH renderings of each held-out night: a
    model is only fixed if it survives the duty-cycled one WITHOUT giving up the dense one.
    """
    dense_built = build_dataset(data_dir, every, phases=())      # control: no decimated variants
    aug_built = build_dataset(data_dir, every, phases)           # treatment
    if dense_built is None or aug_built is None:
        print("no subjects loaded — is the dataset downloaded?")
        return
    Xd_all, yd_all, gd_all, (sids, _) = dense_built
    Xa_all, ya_all, ga_all, _ = aug_built
    print(f"{len(sids)} subjects · control {Xd_all.shape[0]} epochs · "
          f"augmented {Xa_all.shape[0]} epochs · {Xd_all.shape[1]} features "
          f"(phases {phases} at 1-in-{every})\n")

    raw_sub = {}
    for sid in sids:
        act, hr, lab = subject(data_dir, sid)
        raw_sub[sid] = (act, hr, np.array([S2I[s] for s in lab]))

    subj_idx = np.arange(len(sids))
    gkf = GroupKFold(n_splits=min(5, len(sids)))
    results = {("dense-only", "dense"): [], ("dense-only", "duty"): [],
               ("augmented", "dense"): [], ("augmented", "duty"): []}

    # Fold on SUBJECTS so both arms see identical splits.
    for tr_s, te_s in gkf.split(subj_idx, subj_idx, subj_idx):
        train_subj, test_subj = set(subj_idx[tr_s]), subj_idx[te_s]
        arms = {}
        for arm, (X, y, g) in (("dense-only", (Xd_all, yd_all, gd_all)),
                               ("augmented", (Xa_all, ya_all, ga_all))):
            m = np.isin(g, list(train_subj))
            clf = _clf().fit(X[m], y[m])
            logT = get_logT([y[m][g[m] == j] for j in np.unique(g[m])])
            arms[arm] = (clf, logT)

        for j in test_subj:
            act, hr, yj = raw_sub[sids[j]]
            Xdense = sleep_features.extract_features(act, hr)
            grid = decimated_grid(act, hr, every, 0)
            for arm, (clf, logT) in arms.items():
                p = viterbi(np.log(np.clip(clf.predict_proba(Xdense), 1e-6, 1)), logT)
                results[(arm, "dense")].append(_report(yj, p))
                if grid is None:
                    continue
                act_e, hr_e, covered = grid
                mk = np.asarray(covered, dtype=bool)
                Xq = sleep_features.extract_features(act_e, hr_e, has_hr=True)
                pq = viterbi(np.log(np.clip(clf.predict_proba(Xq), 1e-6, 1)), logT)
                results[(arm, "duty")].append(_report(yj[mk], np.asarray(pq)[mk]))

    print(f"{'training':<12} {'rendering':<11} {'acc4':>7} {'κ4':>7} {'κ s/w':>7} "
          f"{'deep pred':>10} {'deep true':>10} {'deep bias':>10} {'rem pred':>9}")
    for arm in ("dense-only", "augmented"):
        for rend in ("dense", "duty"):
            rows = results[(arm, rend)]
            if not rows:
                continue
            m = {k: float(np.mean([r[k] for r in rows])) for k in rows[0]}
            print(f"{arm:<12} {rend:<11} {m['acc4']*100:6.1f}% {m['k4']:7.3f} {m['k2']:7.3f} "
                  f"{m['deep_pred']*100:9.1f}% {m['deep_true']*100:9.1f}% "
                  f"{(m['deep_pred']-m['deep_true'])*100:+9.1f}pp {m['rem_pred']*100:8.1f}%")


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("data_dir", nargs="?", default=str(Path.home() / "sleep-accel"))
    ap.add_argument("--every", type=int, default=EVERY)
    ap.add_argument("--phases", type=str, default=",".join(str(p) for p in PHASES))
    ap.add_argument("--save", action="store_true")
    args = ap.parse_args()
    phases = tuple(int(p) for p in args.phases.split(",") if p != "")
    data_dir = Path(args.data_dir).expanduser()

    evaluate(data_dir, args.every, phases)

    if args.save:
        built = build_dataset(data_dir, args.every, phases)
        X, y, g, (sids, seqs) = built
        clf = _clf().fit(X, y)
        logT = get_logT(seqs)
        MODEL_OUT.parent.mkdir(parents=True, exist_ok=True)
        joblib.dump({"model": clf, "stages": STAGES, "logT": logT,
                     "source": "physionet-walch2019+dutycycle",
                     "n_subjects": len(sids), "every": args.every,
                     "phases": list(phases), "stay_bonus": STAY_BONUS}, MODEL_OUT)
        print(f"\nsaved → {MODEL_OUT} ({len(sids)} subjects, dense + phases {phases})")


if __name__ == "__main__":
    main()
