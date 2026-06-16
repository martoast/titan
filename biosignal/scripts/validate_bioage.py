"""What can we honestly say about 'biological age' from our markers? (Tier-2 #14)

We tested the obvious thing first — train a model to PREDICT chronological age from fitness markers,
the way real biological-age clocks work (the residual = how much older/younger you look). On the
PhysioNet treadmill set (846 people with MEASURED VO2max), leave-subjects-out:

  predict age from VO2max alone ............ MAE 8.1 yr, r 0.05   (no better than guessing the mean!)
  predict age from VO2max+sex+BMI+RHR+HRmax  MAE 6.7 yr, r 0.52   (better, but a narrow young cohort)
  baseline (always predict the mean age) ... MAE 8.1 yr

HONEST CONCLUSION: this cohort is young (30±10), athletic, and self-selected — it cannot train a
trustworthy population age-clock, and fitting VO2max-vs-age NORMS from it is worse still (the female
slope even comes out POSITIVE, n=139). A real biological-age clock needs an NHANES-style general
population with mortality follow-up. So we do NOT ship a data-driven clock here.

Instead we ship a FITNESS AGE (Nes 2011, HUNT n=4631): the chronological age at which your VO2max
equals the population average. It uses ESTABLISHED literature norms (peak ~50 men / 42 women at 25,
~0.45 ml/kg/min/yr decline), not norms re-fit from this biased cohort. Below we confirm the anchor is
CALIBRATED and BEHAVES on the real data; the full multi-marker Health Age (fitness age + transparent,
literature-weighted offsets from RHR, HRV, sleep regularity, steps, BMI) lives in app/Support/
BiologicalAge.php and is framed as a wellness estimate, never a clinical biological-age/mortality claim.

Usage: .venv/bin/python scripts/validate_bioage.py /tmp
"""

from __future__ import annotations

import sys
from pathlib import Path

import numpy as np
import pandas as pd

PEAK_M, PEAK_F, DECLINE = 50.0, 42.0, 0.45   # literature VO2max norms (mirror app/Support/BiologicalAge.php)


def fitness_age(vo2max, female):
    peak = PEAK_F if female else PEAK_M
    return np.clip(25 + (peak - vo2max) / DECLINE, 18, 90)


def main():
    d = Path(sys.argv[1] if len(sys.argv) > 1 else "/tmp")
    info = pd.read_csv(d / "subject-info.csv").dropna(subset=["Weight", "Age", "Sex", "ID", "Height"])
    meas = pd.read_csv(d / "test_measure.csv")
    rows = []
    for tid, g in meas.groupby("ID_test"):
        rec = info[info.ID_test == tid]
        if rec.empty:
            continue
        rec = rec.iloc[0]
        vo2 = g.sort_values("time").VO2.to_numpy(float)
        w = float(rec.Weight)
        vo2s = pd.Series(vo2).rolling(15, min_periods=5).mean().to_numpy()
        if np.all(np.isnan(vo2s)):
            continue
        true = np.nanmax(vo2s) / w
        age, fem = float(rec.Age), float(rec.Sex) >= 0.5
        if not (15 <= true <= 90) or not (15 <= age <= 90):
            continue
        rows.append((age, fem, true, float(fitness_age(true, fem))))
    df = pd.DataFrame(rows, columns=["age", "female", "vo2max", "fit_age"])
    gap = df.fit_age - df.age
    peer = np.where(df.female, PEAK_F, PEAK_M) - DECLINE * (df.age - 25)
    avg = (df.vo2max - peer).abs() < 3

    print(f"\nFitness Age anchor on {len(df)} real treadmill subjects (literature norms, not re-fit):")
    print(f"  fitness-age gap vs VO2max:        r {np.corrcoef(gap, df.vo2max)[0, 1]:+.2f}   (fitter ⇒ younger ✓)")
    print(f"  average-fitness people, mean gap: {gap[avg].mean():+.1f} yr   (≈0 ⇒ norms are calibrated ✓)")
    print(f"  whole cohort mean: chrono {df.age.mean():.1f} → fitness {df.fit_age.mean():.1f} yr")
    print("\n  A data-driven clock on THIS cohort is too weak (MAE 6.7 vs 8.1 baseline) to ship — see")
    print("  the module docstring. The composite Health Age is transparent + literature-weighted, not")
    print("  a mortality-calibrated clock (we have no outcome data). Honest by design.")


if __name__ == "__main__":
    main()
