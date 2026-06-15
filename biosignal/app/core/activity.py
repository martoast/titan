"""Activity / strain estimation from accelerometer counts.

Detects continuous active sessions from per-epoch accel counts and produces a simple
TRIMP (Edwards eTRIMP-style) + calorie estimate. See 03-algorithms.md §6.

Honest scope: this is a v1 estimate. In-motion HR (and thus accurate TRIMP from HR
zones) is deliberately last in the algorithm roadmap (§10, P5) — without reliable
in-motion HR we approximate intensity from accel-count magnitude, then optionally refine
with HR when the caller supplies it.
"""

from __future__ import annotations

from datetime import datetime, timedelta, timezone
from typing import Optional

import numpy as np

EPOCH_SEC = 30

# A session is a run of epochs above the activity threshold lasting >= MIN_SESSION_MIN.
MIN_SESSION_MIN = 10
ACTIVE_COUNT_THRESHOLD = 5  # accel counts/epoch above which the epoch is "active"


def _parse_ts(ts: Optional[str]):
    if not ts:
        return None
    try:
        return datetime.fromisoformat(ts.replace("Z", "+00:00"))
    except Exception:
        return None


def detect_sessions(
    accel_counts: list,
    start: Optional[str] = None,
    hr_bpm: Optional[list] = None,
    hr_max: int = 190,
    hr_rest: int = 55,
    weight_kg: float = 75.0,
) -> dict:
    """Detect active sessions and compute per-session + total TRIMP/calories."""
    accel = np.asarray(accel_counts, dtype=float).ravel()
    n = accel.size
    t0 = _parse_ts(start) or datetime.now(timezone.utc)
    hr = np.asarray(hr_bpm, dtype=float).ravel() if hr_bpm else None

    active = accel >= ACTIVE_COUNT_THRESHOLD
    min_epochs = int(MIN_SESSION_MIN * 60 / EPOCH_SEC)

    sessions = []
    i = 0
    while i < n:
        if not active[i]:
            i += 1
            continue
        j = i
        # Allow brief (<=1 min) dips so a session isn't fragmented.
        gap = 0
        while j < n and (active[j] or gap < 2):
            if active[j]:
                gap = 0
            else:
                gap += 1
            j += 1
        # Trim trailing inactive epochs.
        end = j
        while end > i and not active[end - 1]:
            end -= 1
        if end - i >= min_epochs:
            sessions.append(_summarize_session(accel, hr, i, end, t0, hr_max, hr_rest, weight_kg))
        i = j

    total_trimp = round(sum(s["trimp"] for s in sessions), 1)
    total_kcal = round(sum(s["calories_kcal"] for s in sessions), 0)
    total_active_min = round(sum(s["duration_min"] for s in sessions), 1)

    return {
        "sessions": sessions,
        "session_count": len(sessions),
        "total_active_min": total_active_min,
        "total_trimp": total_trimp,
        "total_calories_kcal": total_kcal,
    }


def _summarize_session(accel, hr, i, end, t0, hr_max, hr_rest, weight_kg) -> dict:
    seg = accel[i:end]
    duration_min = round(len(seg) * EPOCH_SEC / 60.0, 1)
    start_dt = t0 + timedelta(seconds=i * EPOCH_SEC)
    end_dt = t0 + timedelta(seconds=end * EPOCH_SEC)

    # Intensity proxy in [0,1]. If HR available, use heart-rate reserve; else accel.
    if hr is not None and len(hr) >= end:
        hr_seg = hr[i:end]
        hrr = np.clip((hr_seg - hr_rest) / max(hr_max - hr_rest, 1), 0, 1)
        intensity = float(np.mean(hrr))
        # Banister TRIMP (male coefficient).
        trimp = float(duration_min * intensity * 0.64 * np.exp(1.92 * intensity))
        mean_hr = round(float(np.mean(hr_seg)), 1)
    else:
        # Accel-only proxy: normalize counts to a 0-1 intensity (cap at 60 counts/epoch).
        intensity = float(np.clip(np.mean(seg) / 60.0, 0, 1))
        # Edwards-style: minutes * zone-weight (1..5 from intensity).
        zone_weight = 1 + int(np.floor(intensity * 5))  # 1..5 (approx)
        trimp = float(duration_min * zone_weight)
        mean_hr = None

    # Calorie estimate: MET- based. MET scales ~3..12 with intensity; kcal = MET*kg*hours.
    met = 3.0 + intensity * 9.0
    calories = met * weight_kg * (duration_min / 60.0)

    return {
        "start": start_dt.isoformat(),
        "end": end_dt.isoformat(),
        "duration_min": duration_min,
        "mean_accel_counts": round(float(np.mean(seg)), 1),
        "mean_hr": mean_hr,
        "intensity": round(intensity, 3),
        "trimp": round(trimp, 1),
        "calories_kcal": round(calories, 0),
    }
