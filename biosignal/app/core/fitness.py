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
  3. A logged paced run (run-calibrated model) — the best estimate when available, and the one
     that RESPONDS to training. Sharpened with Firstbeat-style %HR-reserve features to MAE 5.3
     (r 0.65), up from 5.6: the winning signal is "speed at a fixed cardiac cost" (a fitter person
     runs faster at the same %HR-reserve). When a run is present it subsumes (1) and (2).

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
# A learned model on profile + submaximal cardiac-cost features from a logged run. Sharpened with
# Firstbeat-style %HR-reserve features (validate_vo2max.py): leave-SUBJECTS-out MAE 5.2 ml/kg/min,
# r 0.67, R² 0.44 on 981 real maximal tests — up from MAE 5.6 / r 0.59 for absolute HR-at-pace. The
# winning signal is "speed at a fixed %HR-reserve": a fitter person runs FASTER at the same cardiac
# cost (running economy × cardiac fitness), which normalizes out the absolute HR a raw hr-at-pace
# feature couldn't. Robust to the resting-HR source: shifting HRrest ±12 bpm at inference moved MAE
# <0.1 (so overnight RHR vs an in-run proxy doesn't matter). Unlike demographics it RESPONDS to
# training — the estimate that tracks your own trend.
# NB1: a naive ACSM "extrapolate demand to HRmax" was tried and REJECTED (+8 ml/kg/min bias) —
# metabolic demand outruns actual VO2 past the aerobic ceiling.
# NB2: the %HRR=%VO2R method's CEILING is MAE 4.5 (r 0.86) WITH true submaximal VO2, but ACSM
# speed→VO2 demand fails on ramp data (HR/VO2 both lag), so the wrist can't reach that ceiling here.
_RUN_MODEL_PATH = Path(__file__).resolve().parent.parent / "models" / "vo2max_run.joblib"
_RUN_MODEL: Optional[dict] = None
_RUN_TRIED = False
_RUN_MODEL_MAE = 5.2  # validated leave-subjects-out MAE of the run-calibrated model
RUN_FEATURES = ["age", "sex_female", "bmi", "uth",
                "speed_at_60hrr", "speed_at_70hrr", "speed_at_80hrr",
                "pcthrr_at_10", "pcthrr_at_12"]
_HRR_LEVELS = (0.60, 0.70, 0.80)   # fixed cardiac-cost points; fitter = faster at each
_STD_SPEEDS = (10.0, 12.0)         # standard paces; fitter = lower %HRR at each


def grade_adjusted_speed(speed_kmh, grade):
    """Convert outdoor (speed, grade) → equivalent FLAT running speed, so HR-at-pace is
    comparable on hills. From ACSM running (0.2·v_eq = 0.2·v + 0.9·v·grade): v_eq = v·(1+4.5·grade).
    `grade` is rise/run as a fraction (baro Δaltitude / GPS Δdistance), clipped to ±15%."""
    v = np.asarray(speed_kmh, float)
    g = np.clip(np.asarray(grade, float), -0.15, 0.15)
    return v * (1.0 + 4.5 * g)


def run_feature_vector(age, sex_female, bmi, hrmax, hrrest, hr, speed_kmh, grade=None) -> np.ndarray:
    """Profile + submaximal %HR-reserve features for the run-calibrated model. Shared by training
    (scripts/validate_vo2max.py) and inference so they can't drift. Missing features are NaN (the
    gradient-boosting model handles NaN natively → an easy run that never reaches a level still
    estimates, falling back toward demographics+uth).

    %HR-reserve frac = (HR − HRrest)/(HRmax − HRrest) (Karvonen). The strong signals:
      - speed_at_{60,70,80}hrr — the grade-adjusted speed at each fixed cardiac cost (fitter=faster)
      - pcthrr_at_{10,12}      — the %HRR held at a standard pace (fitter=lower)
      - uth = 15.3·HRmax/HRrest — the resting-HR anchor (Uth-Sørensen), folded in here so a run
        subsumes the standalone resting-HR estimate instead of double-counting it.
    """
    hr = np.asarray(hr, float)
    v = grade_adjusted_speed(speed_kmh, grade if grade is not None else np.zeros_like(speed_kmh))
    hrmax = float(hrmax) if hrmax else np.nan
    hrrest = float(hrrest) if hrrest else np.nan
    reserve = hrmax - hrrest if (np.isfinite(hrmax) and np.isfinite(hrrest)) else np.nan
    has_reserve = np.isfinite(reserve) and reserve > 20

    feats = {"age": float(age), "sex_female": 1.0 if sex_female else 0.0, "bmi": float(bmi),
             "uth": (15.3 * hrmax / hrrest) if (np.isfinite(hrmax) and np.isfinite(hrrest) and hrrest > 0) else np.nan}
    frac = (hr - hrrest) / reserve if has_reserve else np.full(hr.shape, np.nan)
    run = (v > 4.0) & np.isfinite(hr) & np.isfinite(v) & (hr > 50)
    # Use the LOADING phase only (up to peak HR). The cooldown has high HR at low speed and would
    # corrupt the speed↔%HRR curve. Shared by train + inference, so they stay in lockstep.
    if run.any():
        peak_hr_i = int(np.nanargmax(np.where(run, hr, -np.inf)))
        load = np.zeros(hr.shape, bool)
        load[: peak_hr_i + 1] = True
        run = run & load

    # speed at a fixed %HR-reserve (interpolate the frac→speed curve; fitter reaches it faster).
    if has_reserve and run.sum() >= 10:
        fr, sv = frac[run], v[run]
        order = np.argsort(fr)
        fro, svo = fr[order], sv[order]
        for lvl in _HRR_LEVELS:
            feats[f"speed_at_{int(lvl * 100)}hrr"] = (
                float(np.interp(lvl, fro, svo)) if fro.min() < lvl < fro.max() else np.nan)
    else:
        for lvl in _HRR_LEVELS:
            feats[f"speed_at_{int(lvl * 100)}hrr"] = np.nan

    # %HR-reserve at a standard pace (fitter holds a lower cardiac cost at the same speed).
    for s in _STD_SPEEDS:
        near = run & (np.abs(v - s) <= 1.0)
        feats[f"pcthrr_at_{int(s)}"] = float(np.median(frac[near])) if (has_reserve and near.sum() >= 3) else np.nan

    return np.array([feats[k] for k in RUN_FEATURES], dtype=float)


def _run_resting_proxy(hr) -> Optional[float]:
    """A resting-HR proxy from a run's own HR (its quiet low percentile), for when overnight RHR
    isn't supplied. Mirrors how validate_vo2max.py derives HRrest, so train/infer stay consistent."""
    hr = np.asarray(hr, float)
    hr = hr[np.isfinite(hr) & (hr > 30)]
    if hr.size < 10:
        return None
    return float(np.clip(np.percentile(hr, 5), 35.0, 110.0))


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

    # Pick the PRIMARY estimate. The run-calibrated model already folds in age/sex/BMI AND the
    # resting-HR (uth) anchor, so when a usable run is present it SUBSUMES both the demographic
    # equation and the standalone Uth blend (don't add them → double-counting).
    run_est = _run_calibrated_vo2max(age, female, bmi, resting_hr, hr_max, run) if run else None
    if run_est is not None:
        vo2 = run_est
        methods, plusminus = ["run_calibrated"], _RUN_MODEL_MAE
    else:
        estimates, methods = [demographic], ["demographic"]
        # Resting HR (Uth-Sørensen) is an INDEPENDENT signal (resting, not exercise) → blend it in.
        if resting_hr and resting_hr > 30:
            hrm = hr_max if (hr_max and hr_max > 120) else (208 - 0.7 * age)  # Tanaka HRmax
            estimates.append(15.3 * hrm / resting_hr)
            methods.append("uth_resting_hr")
        vo2 = float(np.mean(estimates))
        plusminus = _MODEL_MAE

    vo2 = float(np.clip(vo2, 15.0, 90.0))
    level, band = fitness_level(vo2, age, female)
    return {
        "vo2max": round(vo2, 1),
        "methods": methods,
        "plusminus": plusminus,
        "fitness_level": level,
        "fitness_percentile_band": band,
    }


def _run_calibrated_vo2max(age, female, bmi, resting_hr, hr_max, run: dict) -> Optional[float]:
    """Run a logged paced workout through the learned %HR-reserve model. None if no model /
    the run lacks usable pace+HR (e.g. GPS off). Uses overnight resting HR for the %HRR features
    when supplied, else a low-percentile proxy from the run itself (validated robust to the source)."""
    model = _load_run_model()
    hr = run.get("hr")
    speed = run.get("speed_kmh")
    if model is None or hr is None or speed is None or len(hr) < 30:
        return None
    hrmax = hr_max if (hr_max and hr_max > 120) else float(np.nanmax(np.asarray(hr, float)))
    hrrest = resting_hr if (resting_hr and resting_hr > 30) else _run_resting_proxy(hr)
    x = run_feature_vector(age, female, bmi, hrmax, hrrest, hr, speed, run.get("grade"))
    # Need at least one submaximal cardiac-cost feature (uth or a %HRR feature) or the run carries
    # no calibration signal beyond demographics → defer to the demographic/Uth path.
    if not np.any(np.isfinite(x[3:])):
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
