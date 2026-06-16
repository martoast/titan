"""Grade-aware energy expenditure for locomotion (Tier-2 #11).

The wrist's crude calorie proxy (MET ≈ 3 + intensity) ignores the two things that actually set the
energy cost of moving: how fast you go and whether you're going up or down hill. With GPS pace +
baro grade we can use the measured PHYSIOLOGY instead.

Cost of transport — Minetti et al. 2002 (J Appl Physiol 93:1039), the canonical incline-energetics
study: the metabolic cost per kg per metre, C(i) [J/(kg·m)], is a 5th-order polynomial in gradient i
(rise/run, valid −0.45 ≤ i ≤ 0.45), separately for running and walking. It captures that uphill is
dramatically dearer and gentle downhill is *cheaper* than the flat (a minimum near −10 to −20 %).

  EE_net = C(i) · weight · distance               (J, the locomotion cost above rest)
  EE_gross = EE_net + RMR · weight · duration      (+ resting metabolism over the bout)

We validate the FLAT engine on real measured VO₂ (the treadmill dataset) in scripts/validate_energy.py
— the implied flat cost of transport and the predicted-vs-measured EE error. The grade term is the
Minetti curve itself (extensively validated in the literature; we can't re-validate it without a
graded-VO₂ dataset, so we cite it and say so). Honest scope: a wellness ESTIMATE, not a calorimeter.
"""

from __future__ import annotations

import numpy as np

# Minetti 2002 cost-of-transport polynomials, C(i) in J/(kg·m), i = gradient (rise/run).
# Highest-order coefficient first (np.polyval order).
_RUN_COEF = (155.4, -30.4, -43.3, 46.3, 19.5, 3.6)
_WALK_COEF = (280.5, -58.7, -76.8, 51.9, 19.6, 2.5)
_GRADE_CLIP = 0.45                 # curves are fitted/valid to ±45 %
_J_PER_KCAL = 4184.0
_RMR_W_PER_KG = 1.20               # resting metabolic rate ≈ 3.5 ml O₂/kg/min ≈ 1.2 W/kg
_WALK_RUN_KMH = 8.0                # gait threshold when not told (≈ where running becomes cheaper)


def cost_of_transport(grade, gait: str = "run") -> np.ndarray:
    """Minetti net metabolic cost of locomotion, J/(kg·m), for a gradient (rise/run fraction)."""
    i = np.clip(np.asarray(grade, dtype=float), -_GRADE_CLIP, _GRADE_CLIP)
    coef = _WALK_COEF if gait == "walk" else _RUN_COEF
    return np.polyval(coef, i)


def _gait_for(speed_kmh: np.ndarray, gait):
    if gait in ("walk", "run"):
        return np.full(speed_kmh.shape, gait, dtype=object)
    return np.where(speed_kmh >= _WALK_RUN_KMH, "run", "walk")


def locomotion_kcal(speed_kmh, grade=None, weight_kg: float = 75.0, dt_s: float = 1.0,
                    gait=None, gross: bool = True) -> dict:
    """Energy expenditure (kcal) for a locomotion bout from per-sample GPS speed (+ baro grade).

    speed_kmh : per-sample speed (km/h); grade : per-sample rise/run fraction (0 if None).
    dt_s      : seconds per sample. gait : 'walk'/'run' to force, else inferred per-sample by speed.
    gross     : add resting metabolism over the bout (workout-calorie convention) vs net locomotion.

    Returns {kcal, net_kcal, distance_km, mean_cost_jkgm, duration_min}. Non-moving samples
    (speed≈0) contribute only the resting term.
    """
    v_kmh = np.asarray(speed_kmh, dtype=float).ravel()
    v_kmh = np.where(np.isfinite(v_kmh) & (v_kmh > 0), v_kmh, 0.0)
    g = (np.zeros_like(v_kmh) if grade is None
         else np.nan_to_num(np.asarray(grade, dtype=float).ravel()))
    if g.shape != v_kmh.shape:                                  # length-mismatch → ignore grade
        g = np.zeros_like(v_kmh)
    gaits = _gait_for(v_kmh, gait)

    v_ms = v_kmh / 3.6
    dist_m = v_ms * dt_s                                        # metres per sample
    moving = v_ms > 0.3                                         # ≈1 km/h: below this, not locomoting
    cost = np.where(moving, _cost_vec(g, gaits), 0.0)           # J/(kg·m) per sample
    net_j_per_kg = float(np.sum(cost * dist_m))                 # net locomotion cost, J/kg
    net_kcal = net_j_per_kg * weight_kg / _J_PER_KCAL

    duration_min = len(v_kmh) * dt_s / 60.0
    rest_kcal = _RMR_W_PER_KG * weight_kg * (len(v_kmh) * dt_s) / _J_PER_KCAL if gross else 0.0
    total_dist_km = float(np.sum(dist_m)) / 1000.0
    moving_dist = float(np.sum(dist_m[moving]))
    mean_cost = net_j_per_kg / moving_dist if moving_dist > 0 else 0.0
    return {
        "kcal": round(net_kcal + rest_kcal, 1),
        "net_kcal": round(net_kcal, 1),
        "distance_km": round(total_dist_km, 3),
        "mean_cost_jkgm": round(mean_cost, 2),
        "duration_min": round(duration_min, 1),
    }


def _cost_vec(grade: np.ndarray, gaits: np.ndarray) -> np.ndarray:
    """Per-sample cost, choosing the run/walk curve element-wise."""
    out = np.empty(grade.shape, dtype=float)
    is_walk = gaits == "walk"
    if is_walk.any():
        out[is_walk] = cost_of_transport(grade[is_walk], "walk")
    if (~is_walk).any():
        out[~is_walk] = cost_of_transport(grade[~is_walk], "run")
    return out
