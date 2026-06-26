"""Gait cadence + a GUIDED sit-to-stand functional-fitness test (Tier-2 #16).

Gait speed is the "sixth vital sign" (Studenski 2011: all-cause mortality HR 0.88 per +0.1 m/s), and
lower-body function predicts frailty/falls. Two honest things a wrist can do:

  1. CADENCE (passive) — steps/min during a walking bout, from the step-frequency of the accel. Cheap,
     always-on, physiologically bounded. We do NOT estimate passive gait SPEED from the wrist (stride
     length isn't observable there) — the research firewall's 🟡 call.

  2. A GUIDED 30-second chair-stand test (30CST) — the validated lower-body-strength/frailty screen:
     the app counts you through "stand up and sit down as many times as you can in 30 s" and the wrist
     accel counts the reps. A sit-to-stand is mechanically a body-weight squat, so this reuses the
     SAME rep-counter validated on MM-Fit squats (MAE 0.14 reps; see gym.py / validate_gym.py). We
     score reps against age/sex norms (Rikli & Jones 1999) → a function band, wellness-scope (a screen,
     never a clinical frailty diagnosis).

Validated: scripts/validate_gait.py (cadence recovers a known rate + lands physiological on real
PPG-DaLiA walking; STS counting on a known-rep signal, with squats as the real-data proxy).
"""

from __future__ import annotations

from typing import Optional

import numpy as np
from scipy.signal import butter, filtfilt

from . import activity_classify as ac

# Cadence: human walking/running step frequency band (84–210 steps/min).
CADENCE_LO_HZ, CADENCE_HI_HZ = 1.4, 3.5
# Sit-to-stand repetition band (~0.2–1.2 Hz = 6–36 reps/30 s — must include the SLOW, frail end).
STS_LO_HZ, STS_HI_HZ = 0.2, 1.2


def _principal_signals(ax, ay, az, fs, unit):
    """3-axis accel → candidate 1-D signals (each axis + the principal component), de-meaned."""
    seg = np.column_stack([ac._to_ms2(v, unit) for v in (ax, ay, az)]).astype(float)
    if len(seg) < int(2 * fs):
        return None, seg
    c = seg - seg.mean(axis=0)
    _, evecs = np.linalg.eigh(np.cov(c.T))
    mag = np.linalg.norm(c, axis=1)
    return [c[:, 0], c[:, 1], c[:, 2], c @ evecs[:, -1], mag - mag.mean()], seg


def _dominant_period(signals, fs, lo_hz, hi_hz):
    """The strongest autocorrelation period (samples) across candidate signals, in [1/hi, 1/lo] s."""
    b, a = butter(2, [lo_hz / (fs / 2), min(hi_hz, fs / 2 - 0.1) / (fs / 2)], btype="band")
    lo, hi = int(fs / hi_hz), int(fs / lo_hz)
    best_period, best_strength = None, -1.0
    for sig in signals:
        x = filtfilt(b, a, np.nan_to_num(sig))
        acf = np.correlate(x, x, "full")[len(x) - 1:]
        acf = acf / (acf[0] + 1e-9)
        band = acf[lo:min(hi, len(x) - 1)]
        if band.size < 3:
            continue
        k = int(np.argmax(band))
        if band[k] > best_strength:
            # Parabolic interpolation around the ACF peak → sub-sample period (kills the integer-lag
            # quantization that otherwise costs ±5 spm at 25 Hz).
            frac = 0.0
            if 0 < k < band.size - 1:
                y0, y1, y2 = band[k - 1], band[k], band[k + 1]
                denom = y0 - 2 * y1 + y2
                if abs(denom) > 1e-12:
                    frac = 0.5 * (y0 - y2) / denom
            best_period, best_strength = lo + k + frac, float(band[k])
    return best_period, best_strength


def cadence_spm(ax, ay, az, fs: int = 25, unit: str = "ms2") -> Optional[dict]:
    """Walking/running cadence (steps/min) from a wrist-accel bout. None if no clear periodic gait."""
    signals, _ = _principal_signals(ax, ay, az, fs, unit)
    if signals is None:
        return None
    period, strength = _dominant_period(signals, fs, CADENCE_LO_HZ, CADENCE_HI_HZ)
    if period is None or strength < 0.45:                     # weak periodicity → not steady gait (noise ≈0.3)
        return None
    cadence = 60.0 * fs / period
    if not (80 <= cadence <= 210):
        return None
    return {"cadence_spm": round(cadence, 1), "periodicity": round(strength, 2)}


def estimate_distance(ax, ay, az, fs, duration_s, height_cm, activity_type=None, unit="ms2"):
    """
    Step-count distance ESTIMATE for an indoor / no-GPS run — so you still get distance + pace when
    GPS never locked (or there's no Mapbox key for the map). Stride isn't directly observable at the
    wrist, so we scale a height-based step length by gait type + cadence: walking step ≈ 0.41×height
    (Bohannon), running step grows with cadence (~0.6×h at 150 spm → ~0.9×h fast). Distance =
    cadence × minutes × step_length. Label it as an estimate in the UI. None if no clear periodic gait.
    """
    cad = cadence_spm(ax, ay, az, fs, unit)
    if cad is None or duration_s <= 0 or height_cm <= 0:
        return None
    cadence = cad["cadence_spm"]
    steps = cadence * (duration_s / 60.0)
    h = height_cm / 100.0
    running = activity_type == "run" or cadence >= 150
    if running:
        factor = 0.60 + min(0.30, max(0.0, (cadence - 150.0) / 35.0 * 0.30))
    else:
        factor = 0.41
    step_m = factor * h
    return {
        "cadence_spm": cadence,
        "steps": int(round(steps)),
        "stride_m": round(step_m, 3),
        "distance_km": round(steps * step_m / 1000.0, 2),
    }


def sit_to_stand(ax, ay, az, fs: int = 25, unit: str = "ms2", duration_s: Optional[float] = None) -> Optional[dict]:
    """Count sit-to-stand reps in a guided test from wrist accel (reuses the squat-validated counter).

    Returns {reps, sec_per_rep, duration_s}. duration_s defaults to the signal length; pass the
    protocol length (e.g. 30) for a 30-second chair-stand test.
    """
    signals, seg = _principal_signals(ax, ay, az, fs, unit)
    if signals is None:
        return None
    period, strength = _dominant_period(signals, fs, STS_LO_HZ, STS_HI_HZ)
    dur = duration_s if duration_s else len(seg) / fs
    if period is None or strength < 0.2:
        return {"reps": 0, "sec_per_rep": None, "duration_s": round(dur, 1)}
    reps = int(round((len(seg)) / period))
    return {
        "reps": max(0, reps),
        "sec_per_rep": round(period / fs, 2),
        "duration_s": round(dur, 1),
    }


# 30-second chair-stand norms (Rikli & Jones 1999, Senior Fitness Test) — below-average cut by age/sex
# (reps in 30 s under this = below the normative range → flag). Younger adults sit well above these.
_30CST_BELOW = {
    "m": [(60, 14), (65, 12), (70, 12), (75, 11), (80, 10), (85, 8), (90, 7)],
    "f": [(60, 12), (65, 11), (70, 10), (75, 10), (80, 9), (85, 8), (90, 4)],
}


def chair_stand_score(reps: int, age: float, female: bool) -> dict:
    """Band a 30-second chair-stand rep count against age/sex norms. Wellness screen, not a diagnosis."""
    table = _30CST_BELOW["f" if female else "m"]
    below = table[0][1]
    for a, cut in table:
        if age >= a:
            below = cut
    # A loose "good for age" upper guide: ~50% above the below-average cut.
    good = below * 1.5 if age >= 60 else max(below * 1.6, 18)
    band, label = (
        ("below", "Below average for your age — worth building lower-body strength") if reps < below else
        ("good", "Strong lower-body function for your age") if reps >= good else
        ("average", "Average lower-body function for your age")
    )
    return {"reps": reps, "band": band, "label": label, "age_below_cut": below}
