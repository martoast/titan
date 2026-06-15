"""Workout digital-twin: synthesize a full multi-sensor Bangle.js 2 workout so the whole
activity + fitness pipeline can be exercised before the hardware arrives.

Honesty: the 3-axis accel is a REPLAY of real PAMAP2 motion templates (synthetic sinusoids
misclassify — the classifier learned real signatures), tiled with mild jitter. HR / GPS pace /
baro grade are physiological synth — fine, because the metrics they feed (TRIMP, VO2max, HRR)
were validated on real data and don't hinge on micro-signatures. So a simulated workout tests
the PLUMBING end-to-end (classification → session → VO2max), not algorithm accuracy.

simulate_workout() returns exactly the fields the /process/activity and /process/fitness
endpoints accept, so a caller can POST them straight through.
"""

from __future__ import annotations

from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Optional

import numpy as np

_TEMPLATES = Path(__file__).resolve().parent.parent / "data" / "accel_templates.npz"
_CACHE: Optional[dict] = None

# Per-activity steady-state targets: GPS speed (km/h) and HR intensity (fraction of HR reserve).
_PROFILE = {
    "rest":   {"speed": 0.0,  "hrr_frac": 0.10},
    "walk":   {"speed": 5.2,  "hrr_frac": 0.45},
    "run":    {"speed": 10.5, "hrr_frac": 0.78},
    "cycle":  {"speed": 22.0, "hrr_frac": 0.68},
    "stairs": {"speed": 2.5,  "hrr_frac": 0.72},
}


def _templates() -> dict:
    global _CACHE
    if _CACHE is None:
        z = np.load(_TEMPLATES)
        _CACHE = {"fs": int(z["fs"]), **{k: z[k] for k in z.files if k != "fs"}}
    return _CACHE


def simulate_workout(
    activity: str = "run",
    minutes: float = 30.0,
    fitness: float = 0.5,
    hills: float = 0.4,
    age: float = 33.0,
    sex: str = "M",
    weight_kg: float = 78.0,
    height_cm: float = 180.0,
    seed: int = 0,
    start: Optional[str] = None,
) -> dict:
    """Synthesize one workout. `fitness` 0..1 (fitter → lower HR + faster pace), `hills` 0..1
    scales the grade profile. Returns a dict with the device profile + every sensor stream."""
    tmpl = _templates()
    fs = tmpl["fs"]
    if activity not in tmpl:
        raise ValueError(f"no template for {activity!r}; have {[k for k in tmpl if k!='fs']}")
    rng = np.random.default_rng(seed)
    n = int(minutes * 60 * fs)
    t0 = datetime.fromisoformat(start.replace("Z", "+00:00")) if start else datetime.now(timezone.utc)

    # --- 3-axis accel: REPLAY the real template, tiled to length with mild jitter ---
    base = tmpl[activity]
    # MIRROR-tile (…, base, reversed(base), base, …) so each junction is continuous — a plain
    # tile creates a hard seam every loop, and windows straddling it misclassify as "other".
    blocks, cur, flip = [], 0, False
    while cur < n:
        b = base[::-1] if flip else base
        blocks.append(b); cur += len(b); flip = not flip
    acc = np.concatenate(blocks)[:n].astype(float)
    # Jitter RELATIVE to each axis's own motion, so a low-amplitude signature (cycle) keeps its
    # shape — a fixed-size jitter would swamp it and the classifier would lose the activity.
    axis_sd = np.clip(base.std(axis=0, keepdims=True), 0.05, None)
    acc += rng.normal(0, 1, acc.shape) * (0.03 * axis_sd)   # stride-to-stride variation
    acc *= (1 + rng.normal(0, 0.03, (1, 3)))                # slight per-axis gain drift
    ax, ay, az = acc[:, 0], acc[:, 1], acc[:, 2]

    # --- HR: warmup ramp → steady (cardiac drift) → EXPONENTIAL cooldown decay (real HRR shape) ---
    hr_rest = 46 + (1 - fitness) * 18
    hr_max = 208 - 0.7 * age
    target = hr_rest + _PROFILE[activity]["hrr_frac"] * (hr_max - hr_rest) * (1.05 - 0.10 * fitness)
    sec = np.arange(n) / fs
    dur = minutes * 60
    warm = min(120, dur * 0.2)
    t_cool = dur - min(180, dur * 0.25)              # exercise stops here; the tail is recovery
    tau = 150.0                                       # HR recovery time constant (s) → HRR-60 ≈ 30% of reserve
    ramp = np.clip(sec / warm, 0, 1)
    drift = 1 + 0.06 * np.clip((sec - warm) / max(t_cool - warm, 1), 0, 1)   # cardiac drift
    hr = hr_rest + (target - hr_rest) * ramp * drift
    cooling = sec > t_cool
    peak_val = hr_rest + (target - hr_rest) * (1 + 0.06)
    hr[cooling] = hr_rest + (peak_val - hr_rest) * np.exp(-(sec[cooling] - t_cool) / tau)
    hr += rng.normal(0, 1.2, n)                                            # beat-to-beat
    hr_persec = hr[:: fs]                                                  # 1 Hz series for endpoints

    # --- GPS speed (km/h) + baro grade from a hill profile ---
    grade = hills * 0.05 * np.sin(2 * np.pi * sec / max(dur / 2.5, 1)) + rng.normal(0, 0.004, n)
    spd_target = _PROFILE[activity]["speed"] * (0.9 + 0.25 * fitness)
    effort = ramp * np.where(sec > t_cool, np.exp(-(sec - t_cool) / 90.0), 1.0)   # eases off in cooldown
    speed = spd_target * effort * (1 - 1.5 * np.clip(grade, 0, None))             # slow uphill
    speed = np.clip(speed + rng.normal(0, 0.3, n), 0, None)
    speed_persec, grade_persec = speed[:: fs], grade[:: fs]

    # --- accel activity counts per 30 s epoch (drives the counts-based session detector) ---
    mag = np.sqrt(ax ** 2 + ay ** 2 + az ** 2)
    motion = np.abs(np.diff(mag, prepend=mag[0]))
    epoch = fs * 30
    counts = [float(np.clip(motion[i:i + epoch].sum() * 2.0, 0, 300))
              for i in range(0, n, epoch)]

    # HRR from the cooldown tail (peak HR − HR 60 s later).
    peak_i = int(np.argmax(hr_persec))
    hrr60 = None
    if peak_i + 60 < len(hr_persec):
        hrr60 = round(float(hr_persec[peak_i] - np.median(hr_persec[peak_i + 58: peak_i + 62])), 1)

    return {
        "activity_intended": activity,
        "fs": fs,
        "start": t0.replace(microsecond=0).isoformat().replace("+00:00", "Z"),
        "profile": {"age": age, "sex": sex, "weight_kg": weight_kg, "height_cm": height_cm,
                    "resting_hr": round(hr_rest), "hr_max": round(hr_max)},
        # → /process/activity
        "accel_counts": counts,
        "hr_epoch_bpm": [round(float(v), 1) for v in hr[:: epoch]],
        "accel_xyz": {"x": ax.round(3).tolist(), "y": ay.round(3).tolist(), "z": az.round(3).tolist()},
        "accel_unit": "ms2",
        # → /process/fitness (the GPS-paced run + the HR tail for HRR)
        "run": {"hr": hr_persec.round(1).tolist(), "speed_kmh": speed_persec.round(2).tolist(),
                "grade": grade_persec.round(4).tolist(), "hrr60": hrr60},
        "workout_hr_bpm": hr_persec.round(1).tolist(),
        "distance_km": round(float(speed.sum() / fs / 3600.0), 2),
    }
