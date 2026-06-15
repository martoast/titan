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

from pathlib import Path
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

# --- Run-calibrated VO2max (needs GPS pace + baro grade) --------------------------------------
# A learned model on profile + HR-at-known-pace features from a logged run. On real treadmill
# data this lands MAE 5.4 ml/kg/min (r 0.59) leave-subjects-out — about the same cross-sectional
# accuracy as demographic+resting-HR, but unlike demographics it RESPONDS to training: as you get
# fitter your HR at a given GPS pace drops, so this is the estimate that tracks your own trend.
# NB: a naive ACSM "extrapolate demand to HRmax" estimate was tried and REJECTED — it overestimates
# by ~13 ml/kg/min on real data because metabolic demand outruns actual VO2 past the aerobic ceiling.
_RUN_MODEL_PATH = Path(__file__).resolve().parent.parent / "models" / "vo2max_run.joblib"
_RUN_MODEL: Optional[dict] = None
_RUN_TRIED = False
RUN_FEATURES = ["age", "sex_female", "bmi", "hrmax", "hr_at_8", "hr_at_10", "hr_at_12",
                "hr_speed_slope", "hrr60"]
_STD_SPEEDS = (8.0, 10.0, 12.0)


def grade_adjusted_speed(speed_kmh, grade):
    """Convert outdoor (speed, grade) → equivalent FLAT running speed, so HR-at-pace is
    comparable on hills. From ACSM running (0.2·v_eq = 0.2·v + 0.9·v·grade): v_eq = v·(1+4.5·grade).
    `grade` is rise/run as a fraction (baro Δaltitude / GPS Δdistance), clipped to ±15%."""
    v = np.asarray(speed_kmh, float)
    g = np.clip(np.asarray(grade, float), -0.15, 0.15)
    return v * (1.0 + 4.5 * g)


def run_feature_vector(age, sex_female, bmi, hrmax, hrr60, hr, speed_kmh, grade=None) -> np.ndarray:
    """Profile + HR-at-grade-adjusted-pace features for the run-calibrated model. Shared by
    training (scripts/validate_vo2max.py) and inference so they can't drift. Missing features
    are NaN (the gradient-boosting model handles NaN natively → partial runs still estimate)."""
    hr = np.asarray(hr, float)
    v = grade_adjusted_speed(speed_kmh, grade if grade is not None else np.zeros_like(speed_kmh))
    feats = {"age": float(age), "sex_female": 1.0 if sex_female else 0.0, "bmi": float(bmi),
             "hrmax": float(hrmax) if hrmax else np.nan, "hrr60": float(hrr60) if hrr60 is not None else np.nan}
    run = (v > 6.0) & np.isfinite(hr) & (hr > 50)
    for s in _STD_SPEEDS:
        near = run & (np.abs(v - s) <= 1.0)
        feats[f"hr_at_{int(s)}"] = float(np.median(hr[near])) if near.sum() >= 3 else np.nan
    sub = run & (hr < 0.9 * (hrmax if hrmax else 200))
    if sub.sum() >= 15 and np.std(v[sub]) > 0.5:
        feats["hr_speed_slope"] = float(np.polyfit(v[sub], hr[sub], 1)[0])
    else:
        feats["hr_speed_slope"] = np.nan
    return np.array([feats[k] for k in RUN_FEATURES], dtype=float)


def _load_run_model():
    global _RUN_MODEL, _RUN_TRIED
    if not _RUN_TRIED:
        _RUN_TRIED = True
        try:
            if _RUN_MODEL_PATH.exists():
                import joblib
                _RUN_MODEL = joblib.load(_RUN_MODEL_PATH)
        except Exception:
            _RUN_MODEL = None
    return _RUN_MODEL


def _is_female(sex) -> bool:
    return str(sex).strip().lower() in {"1", "f", "female", "w", "woman"}


def estimate_vo2max(
    age: float,
    sex,
    weight_kg: float,
    height_cm: float,
    resting_hr: Optional[float] = None,
    hr_max: Optional[float] = None,
    run: Optional[dict] = None,
) -> dict:
    """Estimate VO2max (ml/kg/min) from a profile, refined by resting HR and/or a logged run.

    `run`, when present, is a paced workout the wrist can capture with its GPS + barometer:
        {"hr": [...], "speed_kmh": [...], "grade": [...] (optional), "hrr60": float (optional)}
    A fitter person has lower HR at the same GPS pace, so this is the estimate that tracks
    training — see the run-calibrated model. Falls back to demographic(+resting-HR) without it.

    Returns {vo2max, methods, plusminus, fitness_level, fitness_percentile_band}. `plusminus`
    is the honest ± band (the validated MAE), so the UI can show a range, not false precision.
    """
    bmi = weight_kg / (max(height_cm, 1.0) / 100.0) ** 2
    female = _is_female(sex)
    demographic = _INTERCEPT + _C_AGE * age + (_C_SEX_FEMALE if female else 0.0) + _C_BMI * bmi

    # Pick the PRIMARY estimate. The run-calibrated model already includes age/sex/BMI, so when a
    # run is present it SUBSUMES the demographic equation (don't average them → double-counting).
    run_est = _run_calibrated_vo2max(age, female, bmi, hr_max, run) if run else None
    if run_est is not None:
        estimates, methods = [run_est], ["run_calibrated"]
    else:
        estimates, methods = [demographic], ["demographic"]

    # Resting HR (Uth-Sørensen) is an INDEPENDENT signal (resting, not exercise) → blend it in.
    if resting_hr and resting_hr > 30:
        hrm = hr_max if (hr_max and hr_max > 120) else (208 - 0.7 * age)  # Tanaka HRmax
        estimates.append(15.3 * hrm / resting_hr)
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


def _run_calibrated_vo2max(age, female, bmi, hr_max, run: dict) -> Optional[float]:
    """Run a logged paced workout through the learned HR-at-pace model. None if no model /
    the run lacks usable pace+HR (e.g. GPS off)."""
    model = _load_run_model()
    hr = run.get("hr")
    speed = run.get("speed_kmh")
    if model is None or hr is None or speed is None or len(hr) < 30:
        return None
    hrmax = hr_max if (hr_max and hr_max > 120) else float(np.nanmax(np.asarray(hr, float)))
    x = run_feature_vector(age, female, bmi, hrmax, run.get("hrr60"), hr, speed, run.get("grade"))
    # Need at least one HR-at-pace anchor or the run carries no calibration signal.
    if not np.any(np.isfinite(x[4:7])):
        return None
    return float(np.clip(model["model"].predict(x.reshape(1, -1))[0], 15.0, 90.0))


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
    # Smooth before locating the peak — real PPG HR is noisy and a single spike must NOT read as
    # the peak (it would collapse the measured drop). ~5 s moving average over the HR series.
    k = max(1, int(5 * fs))
    sm = np.convolve(hr, np.ones(k) / k, mode="same")
    peak_i = int(np.argmax(sm))
    if peak_i >= n - int(window_s * fs * 0.5):
        return None  # peak too close to the end → no cooldown to measure
    j = min(n - 1, peak_i + int(window_s * fs))
    drop = float(sm[peak_i] - np.median(sm[max(j - int(2 * fs), peak_i + 1): j + 1]))
    return {"hrr_bpm": round(drop, 1), "peak_hr": round(float(sm[peak_i]), 0), "window_s": window_s}
