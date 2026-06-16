"""Validate the locomotion energy-expenditure engine on REAL measured VO2 (flat case).

Dataset: PhysioNet 'Treadmill Maximal Exercise Tests' (Mongin et al.) — breath-by-breath measured
VO2 + speed during graded maximal treadmill runs. The treadmill is FLAT (grade 0), so this validates
the core cost-of-transport engine: does Minetti's flat running cost predict the energy a real person
actually spends at a given pace? (The GRADE term is the Minetti curve itself, validated in the
literature — we cite it; we can't re-validate it here without a graded-VO2 dataset.)

Method: on near-STEADY SUBMAXIMAL running samples (running pace, HR < 85% max, so VO2≈demand and the
anaerobic/lag confound is small), compare:
   measured gross EE rate (W/kg) = VO2[ml/kg/min] · 20.9[J/ml O2] / 60
   predicted gross EE rate (W/kg) = Minetti Cr(0)·v + RMR
and report the implied real cost of transport vs Minetti's 3.6 J/(kg·m), plus the EE error.

Usage: .venv/bin/python scripts/validate_energy.py /tmp   (dir with the two CSVs)
"""

from __future__ import annotations

import sys
from pathlib import Path

import numpy as np
import pandas as pd

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
from app.core import energy as en  # noqa: E402

J_PER_ML_O2 = 20.9      # energy per ml O2 at mixed RER (~0.95)
KMH_TO_MS = 1 / 3.6


def main():
    d = Path(sys.argv[1] if len(sys.argv) > 1 else "/tmp")
    info = pd.read_csv(d / "subject-info.csv").dropna(subset=["Weight", "Age", "Sex", "ID"])
    meas = pd.read_csv(d / "test_measure.csv")

    meas_cot, pred_w, meas_w = [], [], []   # measured cost-of-transport; predicted/measured EE rate
    n_tests = 0
    for tid, g in meas.groupby("ID_test"):
        rec = info[info.ID_test == tid]
        if rec.empty:
            continue
        g = g.sort_values("time")
        hr = g.HR.to_numpy(float); vo2 = g.VO2.to_numpy(float); spd = g.Speed.to_numpy(float)
        w = float(rec.iloc[0].Weight)
        vo2_kg = vo2 / w                                          # ml/kg/min
        hrmax = np.nanmax(hr)
        if not (hrmax > 120):
            continue
        # Submaximal RUNNING, near steady: running pace, below 85% HRmax, plausible VO2.
        v_ms = spd * KMH_TO_MS
        sub = (spd >= 8.0) & (hr < 0.85 * hrmax) & np.isfinite(vo2_kg) & (vo2_kg > 8) & (v_ms > 2)
        if sub.sum() < 10:
            continue
        n_tests += 1
        ee_meas = vo2_kg[sub] * J_PER_ML_O2 / 60.0               # gross W/kg measured
        # implied NET cost of transport: (gross − RMR) / speed
        cot = (ee_meas - en._RMR_W_PER_KG) / v_ms[sub]
        meas_cot.extend(cot.tolist())
        # predicted gross EE rate from Minetti flat running cost
        ee_pred = en.cost_of_transport(np.zeros(sub.sum()), "run") * v_ms[sub] + en._RMR_W_PER_KG
        pred_w.extend(ee_pred.tolist()); meas_w.extend(ee_meas.tolist())

    cot = np.array(meas_cot); pw = np.array(pred_w); mw = np.array(meas_w)
    cot = cot[(cot > 1) & (cot < 8)]                             # physiologic running CoT window
    print(f"\n{n_tests} treadmill tests · {len(mw)} submaximal running samples (flat, grade 0)")
    print(f"  measured running cost of transport: {np.median(cot):.2f} J/(kg·m) "
          f"[IQR {np.percentile(cot,25):.2f}–{np.percentile(cot,75):.2f}]  vs Minetti 3.60")
    err = np.abs(pw - mw)
    print(f"  EE rate — predicted vs measured:  MAE {err.mean():.2f} W/kg  "
          f"({100*np.mean(err/mw):.0f}% MAPE)  bias {np.mean(pw-mw):+.2f}")
    print(f"  → a {np.median(cot)/3.6:.2f}× scale would null the flat bias; the GRADE shape is "
          f"Minetti's (cited, not re-fit).")


if __name__ == "__main__":
    main()
