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

        feats = {
            "Age": float(rec.Age), "Sex": float(rec.Sex), "Weight": w,
            "Height": float(rec.Height),
            "BMI": w / (float(rec.Height) / 100.0) ** 2,
            "hrmax": hrmax, "vo2max_true": vo2max_true, "ID": int(rec.ID),
        }

        # --- B: HR at standardized running speeds, ASCENDING phase only ---
        asc = slice(0, peak_i + 1)
        sa, ha = spd[asc], hr[asc]
        m = (sa > 0) & np.isfinite(ha) & (ha > 50)
        for std_spd in (8.0, 10.0, 12.0):
            near = m & (np.abs(sa - std_spd) <= 1.0)
            feats[f"hr_at_{int(std_spd)}"] = float(np.nanmedian(ha[near])) if near.sum() >= 3 else np.nan
        # per-person HR-vs-speed slope on the ascending submaximal part
        sub = m & (ha < 0.9 * hrmax)
        if sub.sum() >= 15 and np.nanstd(sa[sub]) > 0.5:
            slope, icpt = np.polyfit(sa[sub], ha[sub], 1)
            feats["hr_speed_slope"] = slope
            # naive extrapolation: speed the person could reach at HRmax → ACSM VO2 of that speed
            spd_at_hrmax = (hrmax - icpt) / slope if slope > 1 else np.nan
            feats["vo2_extrap"] = float(acsm_vo2(np.clip(spd_at_hrmax, 0, 25))) if np.isfinite(spd_at_hrmax) else np.nan
        else:
            feats["hr_speed_slope"] = np.nan
            feats["vo2_extrap"] = np.nan

        # --- HRR: HR drop 60 s after the VO2 peak (the cooldown the wrist would see) ---
        hr_peak = hr[peak_i]
        post = (t >= t[peak_i] + 55) & (t <= t[peak_i] + 65) & np.isfinite(hr)
        feats["hrr60"] = float(hr_peak - np.nanmedian(hr[post])) if (np.isfinite(hr_peak) and post.sum()) else np.nan
        rows.append(feats)
    return pd.DataFrame(rows)


def cv_regress(df, feat_cols, label="model"):
    d = df.dropna(subset=feat_cols + ["vo2max_true"])
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
    print(f"  {label:<42} MAE {mae:4.1f} ml/kg/min   r {r:.2f}   R² {r2_score(y, oof):.2f}   (n={len(y)})")
    return mae, r


def main():
    datadir = Path(sys.argv[1] if len(sys.argv) > 1 else "/tmp")
    df = build(datadir)
    print(f"\n{len(df)} valid maximal tests  |  {df.ID.nunique()} subjects  |  "
          f"VO2max {df.vo2max_true.mean():.1f} ± {df.vo2max_true.std():.1f} ml/kg/min\n")

    print("Leave-SUBJECTS-out validation vs measured VO2max:")
    cv_regress(df, ["Age", "Sex", "BMI", "Weight", "Height"], "A. demographic-only (no exercise)")
    cv_regress(df, ["Age", "Sex", "BMI", "hr_at_8", "hr_at_10", "hr_at_12", "hr_speed_slope"],
               "B. demographic + HR-at-standard-pace")
    cv_regress(df, ["Age", "Sex", "BMI", "hrmax", "hr_at_8", "hr_at_10", "hr_at_12",
                    "hr_speed_slope", "hrr60"], "C. + HRR + HRmax (full wrist-from-a-run)")

    # HRR as a STANDALONE fitness signal.
    h = df.dropna(subset=["hrr60", "vo2max_true"])
    h = h[(h.hrr60 > 0) & (h.hrr60 < 120)]
    if len(h):
        r_hrr = np.corrcoef(h.hrr60, h.vo2max_true)[0, 1]
        print(f"\n  HRR-60s standalone: r {r_hrr:.2f} with VO2max  (mean drop {h.hrr60.mean():.0f} bpm, n={len(h)}) "
              f"— higher recovery ↔ fitter")

    print("\nReference: a single lab VO2max test has ~3-5% (≈2 ml/kg/min) day-to-day variation;")
    print("field non-exercise equations typically land 10-15% (≈4-6 ml/kg/min) off measured.")


if __name__ == "__main__":
    main()
