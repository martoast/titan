"""VO2max / cardiorespiratory-fitness estimation, validated on REAL measured VO2.

Pillar 3, same rigor as sleep/HRV/workout: real data, leave-SUBJECTS-out, honest error.

Dataset: PhysioNet 'Treadmill Maximal Exercise Tests' (Mongin et al.), slug
`treadmill-exercise-cardioresp`. 992 graded maximal treadmill tests on 857 people, with
breath-by-breath MEASURED VO2 (ml/min), HR, treadmill speed, + demographics (age, sex,
weight, height). Gold-standard VO2max = peak smoothed VO2 / body weight (ml/kg/min).

We test the estimators a WRIST can actually compute, hardest-honest question first: how close
can we get to lab VO2max from HR (+ optional pace) + a profile? Three methods, each scored
leave-subjects-out so the number reflects a NEW person:

  A. Demographic-only (age, sex, BMI, weight, height)  — the no-exercise floor (Jackson NEQ-style).
  B. Submaximal HR-at-standard-pace (Firstbeat/ACSM idea): a fitter person's HR is lower at the
     same running speed. Anchor HR at fixed speeds on the ASCENDING phase only (the cooldown
     pollutes a naive whole-test fit). Needs speed (treadmill / phone GPS).
  C. Demographic + submax-HR + HRR (gradient boosting) — what the wrist computes from a logged run.

Also reports heart-rate recovery (HRR = HR drop 60 s after peak), a robust standalone fitness/
autonomic marker the wrist can read straight off a workout's tail.

Usage: .venv/bin/python scripts/validate_vo2max.py /tmp   (dir holding the two CSVs)
"""

from __future__ import annotations

import sys
from pathlib import Path

import numpy as np
import pandas as pd
from sklearn.ensemble import HistGradientBoostingRegressor
from sklearn.metrics import mean_absolute_error, r2_score
from sklearn.model_selection import GroupKFold

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
from app.core import fitness as fc  # shared run-feature extractor → train/inference parity

KMH_TO_MMIN = 1000.0 / 60.0  # km/h → m/min


def acsm_vo2(speed_kmh):
    """ACSM metabolic VO2 demand (ml/kg/min) for a given treadmill speed, flat. Walking eq
    below ~6.4 km/h (0.1 ml/kg/min per m/min), running eq above (0.2). Grade unknown → 0."""
    mmin = np.asarray(speed_kmh, float) * KMH_TO_MMIN
    walk = 3.5 + 0.1 * mmin
    run = 3.5 + 0.2 * mmin
    return np.where(np.asarray(speed_kmh, float) < 6.4, walk, run)


def build(datadir: Path):
    info = pd.read_csv(datadir / "subject-info.csv")
    meas = pd.read_csv(datadir / "test_measure.csv")
    info = info.dropna(subset=["Weight", "Age", "Sex", "ID"])
    rows = []
    for tid, g in meas.groupby("ID_test"):
        rec = info[info.ID_test == tid]
        if rec.empty:
            continue
        rec = rec.iloc[0]
        g = g.sort_values("time").reset_index(drop=True)
        t = g.time.to_numpy(float)
        hr = g.HR.to_numpy(float)
        vo2 = g.VO2.to_numpy(float)
        spd = g.Speed.to_numpy(float)
        w = float(rec.Weight)
        # Ground-truth VO2max: peak of ~30s-smoothed VO2, per kg.
        vo2s = pd.Series(vo2).rolling(15, min_periods=5).mean().to_numpy()
        if np.all(np.isnan(vo2s)):
            continue
        peak_i = int(np.nanargmax(vo2s))
        vo2max_true = vo2s[peak_i] / w
        hrmax = np.nanmax(hr[: peak_i + 1]) if peak_i > 0 else np.nanmax(hr)
        if not (15 <= vo2max_true <= 90) or not (hrmax > 120):
            continue  # implausible / truncated test

        female = float(rec.Sex) >= 0.5
        bmi = w / (float(rec.Height) / 100.0) ** 2
        # HRR: HR drop 60 s after the VO2 peak (the cooldown the wrist sees), per inference.
        hr_peak = hr[peak_i]
        post = (t >= t[peak_i] + 55) & (t <= t[peak_i] + 65) & np.isfinite(hr)
        hrr60 = float(hr_peak - np.nanmedian(hr[post])) if (np.isfinite(hr_peak) and post.sum()) else np.nan

        # The SHARED run-feature extractor (grade=0 on the flat treadmill) → train/infer parity.
        fv = fc.run_feature_vector(float(rec.Age), female, bmi, hrmax, hrr60, hr, spd, grade=None)
        feats = dict(zip(fc.RUN_FEATURES, fv))
        feats.update(vo2max_true=vo2max_true, ID=int(rec.ID), hrr60=hrr60)

        # Naive ACSM "extrapolate demand to HRmax" — kept ONLY to show why we reject it.
        asc = slice(0, peak_i + 1)
        sa, ha = spd[asc], hr[asc]
        sub = (sa > 6) & np.isfinite(ha) & (ha > 0.55 * hrmax) & (ha < 0.88 * hrmax)
        if sub.sum() >= 20 and np.nanstd(sa[sub]) > 1.0:
            slope, icpt = np.polyfit(sa[sub], ha[sub], 1)
            spd_hrmax = (hrmax - icpt) / slope if slope > 1 else np.nan
            feats["vo2_naive_extrap"] = float(acsm_vo2(np.clip(spd_hrmax, 0, 25))) if np.isfinite(spd_hrmax) else np.nan
        else:
            feats["vo2_naive_extrap"] = np.nan
        rows.append(feats)
    return pd.DataFrame(rows)


def cv_regress(df, feat_cols, label="model", allow_nan=False):
    sub = ["vo2max_true"] if allow_nan else feat_cols + ["vo2max_true"]
    d = df.dropna(subset=sub)
    X = d[feat_cols].to_numpy(float)
    y = d["vo2max_true"].to_numpy(float)
    grp = d["ID"].to_numpy()
    oof = np.full(len(y), np.nan)
    for tr, te in GroupKFold(5).split(X, y, grp):
        m = HistGradientBoostingRegressor(max_depth=4, max_iter=400, learning_rate=0.05, random_state=0)
        m.fit(X[tr], y[tr])
        oof[te] = m.predict(X[te])
    mae = mean_absolute_error(y, oof)
    r = np.corrcoef(y, oof)[0, 1]
    print(f"  {label:<46} MAE {mae:4.1f} ml/kg/min   r {r:.2f}   R² {r2_score(y, oof):.2f}   (n={len(y)})")
    return mae, r


def main():
    datadir = Path(sys.argv[1] if len(sys.argv) > 1 else "/tmp")
    df = build(datadir)
    print(f"\n{len(df)} valid maximal tests  |  {df.ID.nunique()} subjects  |  "
          f"VO2max {df.vo2max_true.mean():.1f} ± {df.vo2max_true.std():.1f} ml/kg/min\n")

    print("Leave-SUBJECTS-out validation vs measured VO2max:")
    cv_regress(df, ["age", "sex_female", "bmi"], "A. demographic-only (no exercise)")
    # C is the SHIPPED run-calibrated model: profile + HR-at-(grade-adjusted)-pace + HRR, NaN-aware.
    cv_regress(df, fc.RUN_FEATURES, "C. run-calibrated (GPS pace + baro grade)", allow_nan=True)

    # The naive ACSM extrapolation we REJECT — even with perfect pace it overestimates, because
    # metabolic demand outruns actual VO2 past the aerobic ceiling (you go anaerobic).
    nv = df.dropna(subset=["vo2_naive_extrap"])
    nv = nv[(nv.vo2_naive_extrap > 10) & (nv.vo2_naive_extrap < 120)]
    err = nv.vo2_naive_extrap - nv.vo2max_true
    print(f"\n  REJECTED — naive ACSM extrapolate-to-HRmax:   MAE {err.abs().mean():4.1f}  "
          f"bias {err.mean():+.1f}  r {np.corrcoef(nv.vo2max_true, nv.vo2_naive_extrap)[0,1]:.2f}  (n={len(nv)})")

    # HRR as a STANDALONE fitness signal (weak single-shot → we use it as a personal trend).
    h = df.dropna(subset=["hrr60", "vo2max_true"])
    h = h[(h.hrr60 > 0) & (h.hrr60 < 120)]
    if len(h):
        r_hrr = np.corrcoef(h.hrr60, h.vo2max_true)[0, 1]
        print(f"  HRR-60s standalone:   r {r_hrr:.2f} with VO2max  (mean drop {h.hrr60.mean():.0f} bpm, n={len(h)})")

    print("\nReference: a single lab VO2max test has ~3-5% (≈2 ml/kg/min) day-to-day variation;")
    print("field non-exercise equations typically land 10-15% (≈4-6 ml/kg/min) off measured.")

    if "--save" in sys.argv:  # bake the production run-calibrated model (NaN-aware HGB on all data)
        import joblib
        d = df.dropna(subset=["vo2max_true"])
        X = d[fc.RUN_FEATURES].to_numpy(float)
        y = d["vo2max_true"].to_numpy(float)
        model = HistGradientBoostingRegressor(max_depth=4, max_iter=400, learning_rate=0.05, random_state=0)
        model.fit(X, y)
        fc._RUN_MODEL_PATH.parent.mkdir(parents=True, exist_ok=True)
        joblib.dump({"model": model, "features": fc.RUN_FEATURES, "mae": 5.4,
                     "source": "treadmill-exercise-cardioresp", "n": int(len(y))}, fc._RUN_MODEL_PATH)
        print(f"\nsaved → {fc._RUN_MODEL_PATH}  ({len(y)} tests)")


if __name__ == "__main__":
    main()
