"""Train the sleep-stage classifier → app/models/sleep_stager.joblib.

Two data sources:

  --source synthetic   (default, runs anywhere)
      Generates labeled nights whose per-epoch (accel, HR) features follow the
      established physiology — WAKE=high motion; DEEP=HR at the night floor + very
      stable; REM=HR near baseline but irregular (high local variance) + atonia;
      LIGHT=in between. This trains a *physiologically sensible baseline*. It is NOT a
      PSG-validated model — swap in real data below for real accuracy.

  --source physionet --data <dir>
      The real path. Use the PhysioNet sleep-accel dataset via
      github.com/ojwalch/sleep_classifiers (motion + HR + PSG labels). Load each
      subject's per-epoch motion + HR + scored stage, map stages → {wake,light,deep,rem},
      and feed through the SAME app.core.sleep_features.extract_features so train/inference
      never drift. (Loader left as a clearly-marked stub — fill in once the data is local.)

Usage:
    .venv/bin/python scripts/train_sleep_model.py            # synthetic baseline
    .venv/bin/python scripts/train_sleep_model.py --source physionet --data path/
"""

from __future__ import annotations

import argparse
import sys
from pathlib import Path

import joblib
import numpy as np
from sklearn.ensemble import HistGradientBoostingClassifier
from sklearn.metrics import classification_report
from sklearn.model_selection import train_test_split

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
from app.core.sleep_features import extract_features  # noqa: E402

STAGES = ["wake", "light", "deep", "rem"]
MODEL_OUT = Path(__file__).resolve().parent.parent / "app" / "models" / "sleep_stager.joblib"


# --------------------------------------------------------------------------- synthetic
def _hypnogram(rng: np.random.Generator, n_epochs: int) -> list[str]:
    """A believable scripted hypnogram: sleep-onset wake, ~90-min cycles (deep-heavy
    early, REM-heavy late), brief mid-night arousals, morning wake."""
    hyp: list[str] = ["wake"] * rng.integers(4, 12)  # sleep latency
    cycle_len = 180  # ~90 min of 30-s epochs
    n_cycles = max(3, n_epochs // cycle_len)
    for c in range(n_cycles):
        prog = c / max(1, n_cycles - 1)  # 0 early → 1 late
        deep = int(np.clip(30 * (1 - prog), 4, 30))
        rem = int(np.clip(8 + 34 * prog, 8, 45))
        hyp += ["light"] * 20 + ["deep"] * deep + ["light"] * 16 + ["rem"] * rem
        if rng.random() < 0.6:  # brief arousal between cycles
            hyp += ["wake"] * rng.integers(1, 5)
    hyp += ["wake"] * rng.integers(2, 8)  # final wake
    return hyp[:n_epochs] if len(hyp) >= n_epochs else hyp + ["light"] * (n_epochs - len(hyp))


def _realize(rng: np.random.Generator, hyp: list[str]) -> tuple[np.ndarray, np.ndarray]:
    """Stage labels → raw per-epoch (accel counts, HR bpm) with realistic signatures."""
    floor = rng.uniform(46, 56)
    accel, hr = [], []
    for s in hyp:
        if s == "wake":
            accel.append(float(np.exp(rng.normal(8.0, 1.0))))          # high motion
            hr.append(floor + rng.normal(12, 4))
        elif s == "light":
            accel.append(float(np.exp(rng.normal(4.0, 1.2))))          # low, occasional twitch
            hr.append(floor + rng.normal(5, 2))
        elif s == "deep":
            accel.append(float(np.exp(rng.normal(2.0, 0.7))))          # minimal motion
            hr.append(floor + rng.normal(1, 0.8))                      # at floor, stable
        else:  # rem
            accel.append(float(np.exp(rng.normal(2.0, 0.7))))          # atonia (very still)
            hr.append(floor + rng.normal(3, 1) + rng.normal(0, 6))     # near baseline but IRREGULAR
    return np.asarray(accel), np.asarray(hr)


def synthetic_dataset(n_nights: int = 40, seed: int = 7):
    rng = np.random.default_rng(seed)
    X_all, y_all = [], []
    for _ in range(n_nights):
        n_ep = int(rng.integers(820, 980))
        hyp = _hypnogram(rng, n_ep)
        accel, hr = _realize(rng, hyp)
        X = extract_features(accel, hr, has_hr=True)
        X_all.append(X)
        y_all.append(np.asarray(hyp[: len(X)]))
    return np.vstack(X_all), np.concatenate(y_all)


# --------------------------------------------------------------------------- physionet
def physionet_dataset(data_dir: str):
    """STUB — fill in once the PhysioNet sleep-accel data is local.

    For each subject: load per-epoch motion + HR + PSG stage; map PSG {W,N1,N2,N3,REM}
    → {wake, light, light, deep, rem}; run accel+HR through extract_features(); stack.
    Reference: github.com/ojwalch/sleep_classifiers (data + feature scripts).
    """
    raise NotImplementedError(
        "PhysioNet loader is a stub. Download ojwalch/sleep_classifiers data into "
        f"{data_dir!r}, then implement the per-subject motion/HR/label load here."
    )


# --------------------------------------------------------------------------- main
def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--source", choices=["synthetic", "physionet"], default="synthetic")
    ap.add_argument("--data", default="data/")
    ap.add_argument("--nights", type=int, default=40)
    args = ap.parse_args()

    if args.source == "physionet":
        X, y = physionet_dataset(args.data)
    else:
        X, y = synthetic_dataset(n_nights=args.nights)

    Xtr, Xte, ytr, yte = train_test_split(X, y, test_size=0.25, random_state=0, stratify=y)
    clf = HistGradientBoostingClassifier(max_depth=4, max_iter=200, learning_rate=0.1,
                                         class_weight="balanced", random_state=0)
    clf.fit(Xtr, ytr)

    print(f"source={args.source}  train={Xtr.shape[0]}  test={Xte.shape[0]}")
    print(classification_report(yte, clf.predict(Xte), labels=STAGES, zero_division=0))

    MODEL_OUT.parent.mkdir(parents=True, exist_ok=True)
    joblib.dump({"model": clf, "stages": STAGES, "source": args.source}, MODEL_OUT)
    print(f"saved → {MODEL_OUT}  ({MODEL_OUT.stat().st_size // 1024} KB)")


if __name__ == "__main__":
    main()
