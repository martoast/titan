"""Cardiorespiratory fitness (VO2max estimate) + heart-rate recovery.

Pillar 3. Wellness-scope estimates (never a medical/diagnostic claim), each grounded in real
data or established literature, with an HONEST error band — see scripts/validate_vo2max.py.

VO2max on a wrist comes from three signals, in order of how much the device actually has:
  1. Demographics (age, sex, BMI) — a transparent linear model fitted on 981 real graded
     maximal treadmill tests (PhysioNet treadmill-exercise-cardioresp), validated
     leave-SUBJECTS-out at MAE 5.6 ml/kg/min (r 0.58). This is the always-available floor.
  2. Resting HR (Uth-Sørensen: VO2max ≈ 15.3·HRmax/HRrest) — the wrist's strongest extra
     signal, from the overnight resting HR we already measure well. Literature r≈0.66 vs lab.
     We BLEND it with (1) when present (mean of two independent ~MAE-6 estimates).
  3. A logged paced run (submaximal HR-at-pace) — only nudges MAE ~6→5.4 and needs reliable
     pace, so it's an optional refinement, not the basis.

Heart-rate recovery (HRR = HR drop in the 60 s after a workout) is reported as a recovery/
autonomic TREND, not a VO2max input: on real data its single-shot correlation with VO2max was
weak (r 0.17) because cooldown intensity isn't standardised — it's meaningful relative to a
person's own baseline, not as an absolute fitness number.
"""

from __future__ import annotations

from typing import Optional

import numpy as np

# Transparent demographic VO2max model (ml/kg/min), fitted on the treadmill dataset:
#   VO2max = INTERCEPT + C_AGE·age + C_SEX·(sex==female) + C_BMI·BMI
# Coefficients from Ridge(alpha=1) over 981 tests / 846 subjects; see validate_vo2max.py.
_INTERCEPT = 83.06
_C_AGE = -0.0581
_C_SEX_FEMALE = -11.5686
_C_BMI = -1.3542
_MODEL_MAE = 5.6  # honest leave-subjects-out mean abs error of the demographic model

# ACSM/Cooper-style cardiorespiratory-fitness bands (ml/kg/min), wellness language only. Rough
# adult midpoints; we shift the cut by age/sex so "good" tracks the person's peer norm.
_BANDS = [(0, "low"), (1, "fair"), (2, "good"), (3, "high"), (4, "excellent")]


def _is_female(sex) -> bool:
    return str(sex).strip().lower() in {"1", "f", "female", "w", "woman"}


def estimate_vo2max(
    age: float,
    sex,
    weight_kg: float,
    height_cm: float,
    resting_hr: Optional[float] = None,
    hr_max: Optional[float] = None,
) -> dict:
    """Estimate VO2max (ml/kg/min) from a profile, refined by resting HR when available.

    Returns {vo2max, methods, plusminus, fitness_level, fitness_percentile_band}. `plusminus`
    is the honest ± band (the validated MAE), so the UI can show a range, not false precision.
    """
    bmi = weight_kg / (max(height_cm, 1.0) / 100.0) ** 2
    female = _is_female(sex)
    demographic = _INTERCEPT + _C_AGE * age + (_C_SEX_FEMALE if female else 0.0) + _C_BMI * bmi

    estimates = [demographic]
    methods = ["demographic"]
    if resting_hr and resting_hr > 30:
        hrm = hr_max if (hr_max and hr_max > 120) else (208 - 0.7 * age)  # Tanaka HRmax
        uth = 15.3 * hrm / resting_hr
        estimates.append(uth)
        methods.append("uth_resting_hr")

    vo2 = float(np.clip(np.mean(estimates), 15.0, 90.0))
    level, band = fitness_level(vo2, age, female)
    return {
        "vo2max": round(vo2, 1),
        "methods": methods,
        "plusminus": _MODEL_MAE,
        "fitness_level": level,
        "fitness_percentile_band": band,
    }


def fitness_level(vo2max: float, age: float, female: bool) -> tuple:
    """Map VO2max → a wellness fitness band relative to age/sex peers. Returns (label, 0-4)."""
    # Peer reference midpoint: declines ~0.4/yr from a young-adult anchor; women ~8 lower.
    anchor = (42.0 if female else 50.0) - 0.4 * max(age - 25, 0)
    # Distance from peer midpoint in MAE units → band.
    z = (vo2max - anchor) / 6.0
    idx = int(np.clip(round(z) + 2, 0, 4))
    return _BANDS[idx][1], idx


def heart_rate_recovery(hr_bpm, fs: float = 1.0, window_s: float = 60.0) -> Optional[dict]:
    """HRR = peak HR − HR `window_s` after the peak, from a workout's HR tail (the cooldown the
    wrist sees). A recovery/autonomic TREND metric (track vs the person's own baseline), not an
    absolute fitness score. Returns None if the series is too short / has no clear cooldown."""
    hr = np.asarray(hr_bpm, dtype=float)
    hr = hr[np.isfinite(hr)]
    n = len(hr)
    if n < int((window_s + 10) * fs):
        return None
    peak_i = int(np.argmax(hr))
    if peak_i >= n - int(window_s * fs * 0.5):
        return None  # peak too close to the end → no cooldown to measure
    j = min(n - 1, peak_i + int(window_s * fs))
    drop = float(hr[peak_i] - np.median(hr[max(j - int(2 * fs), peak_i + 1): j + 1]))
    return {"hrr_bpm": round(drop, 1), "peak_hr": round(float(hr[peak_i]), 0), "window_s": window_s}
