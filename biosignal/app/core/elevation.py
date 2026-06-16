"""Floors climbed + elevation gain from the barometer (Tier-2 #13).

Climbing stairs is one of the few longevity signals that is nearly FREE on our hardware: the BMP280
runs continuously at ~2.7 µA, no GPS, and ≥35 floors/week tracks to all-cause mortality HR 0.84
(Harvard Alumni Study). A floor is, by convention, ~3 m of vertical ascent (Fitbit/Garmin).

The whole problem is separating real climbing from two confounds:
  1. SENSOR NOISE — BMP280 relative noise is ~0.12 Pa RMS ≈ ~1 m of apparent altitude jitter.
  2. WEATHER DRIFT — atmospheric pressure wanders 1–3 hPa over hours, i.e. metres of apparent
     altitude over a day. Left unchecked it silently accumulates phantom floors.

The discriminator is RATE. Stairs gain ~3 m in 10–20 s (≈0.15–0.4 m/s) and a walked hill ~0.05–
0.15 m/s; weather drifts ~100× slower (~0.005 m/s). So we smooth the altitude, take its vertical
rate, and only accumulate ascent while the rate exceeds a climb threshold — which rejects drift by
construction, no fragile absolute-pressure calibration needed.

Honest scope: validated on physically-realistic synthetic traces built from the BMP280 datasheet
noise + real weather-drift magnitudes + true stair geometry (scripts/validate_elevation.py). There is
no clean public "barometer → labelled floors" dataset, so on-hardware accuracy is a validation-day
item (06-validation-day.md), like in-motion HR. A wellness estimate, never a medical claim.
"""

from __future__ import annotations

import numpy as np

FLOOR_M = 3.0              # metres of ascent per "floor" (industry convention)
MIN_CLIMB_RATE = 0.03     # m/s averaged over a climb; below → weather drift (~0.003 m/s), not climbing
MAX_CLIMB_RATE = 3.0      # m/s; above this is a pressure glitch (door, lift, wind gust), reject
SMOOTH_SEC = 7.0          # altitude smoothing window. Ambient baro is sampled ~1 Hz with oversampling
#                           (~0.3 m noise), so a ~7 s smooth drives jitter well below the ½-storey gate.
MIN_SEGMENT_M = 1.5       # a climb must gain at least this (½ storey) to count (noise guard)
DROP_HYST_M = 1.0         # confirm a climb has ended once we fall this far below its peak (hysteresis)


def _smooth(x: np.ndarray, win: int) -> np.ndarray:
    if win < 2 or x.size < win:
        return x
    # Edge-pad before the boxcar so the ends aren't biased toward 0 (a plain 'same' convolution
    # averages fewer points at the edges → a flat tail drifts toward zero and fakes a climb/drop).
    left = win // 2
    right = win - 1 - left
    xp = np.pad(x, (left, right), mode="edge")
    return np.convolve(xp, np.ones(win) / win, mode="valid")


def floors_from_altitude(altitude_m, sample_rate_hz: float = 0.25, floor_m: float = FLOOR_M) -> dict:
    """Barometric altitude series → floors climbed + ascent/descent metres.

    altitude_m : per-sample barometric altitude (m), any cadence; sample_rate_hz its rate
                 (the Bangle ambient baro is ~0.25 Hz = one reading / 4 s).

    Peak-valley detector with a per-CLIMB rate gate: we find each trough→peak rise, and count it only
    if it gained ≥ ½ storey AND rose fast enough to be a human climb. Weather drift spans hours so its
    rate (~0.003 m/s) falls far below the gate; a flight of stairs spans seconds (~0.2 m/s) and passes.
    DROP_HYST stops sensor noise from prematurely ending a real climb.

    Returns {floors, ascent_m, descent_m, n_climbs}. descent_m is the matching downhill (not floors).
    """
    alt = np.asarray(altitude_m, dtype=float).ravel()
    alt = alt[np.isfinite(alt)]
    if alt.size < 3 or sample_rate_hz <= 0:
        return {"floors": 0, "ascent_m": 0.0, "descent_m": 0.0, "n_climbs": 0}

    s = _smooth(alt, max(2, int(round(SMOOTH_SEC * sample_rate_hz))))
    dt = 1.0 / sample_rate_hz

    ascent = 0.0
    descent = 0.0
    n_climbs = 0
    trough = peak = s[0]
    trough_i = peak_i = 0

    def _close_climb(end_i):
        """Evaluate the trough→peak rise ending at end_i. Rate is measured to the FIRST near-peak
        attainment (not the global max), so noise nudging a slightly-higher sample late in a dwell
        can't dilute the climb's rate and get it wrongly rejected as drift."""
        nonlocal ascent, n_climbs
        seg = s[trough_i:end_i + 1]
        if seg.size < 2:
            return 0.0
        top_rel = int(np.argmax(seg))
        top_i = trough_i + top_rel
        # Robust climb height: short medians around the launch and top, not noise-biased min/max
        # extremes (raw min/max inflate every climb by ~2·noise and overcount floors).
        lo = float(np.median(s[max(0, trough_i - 2):trough_i + 3]))
        hi = float(np.median(s[max(0, top_i - 2):top_i + 3]))
        climb = hi - lo
        if climb >= MIN_SEGMENT_M:
            pk = float(seg.max())
            eff = int(np.argmax(seg >= pk - 0.3))            # first sample within 0.3 m of the peak
            rate = climb / max(eff * dt, dt)
            if MIN_CLIMB_RATE <= rate <= MAX_CLIMB_RATE:     # fast enough → a real climb, not drift
                ascent += climb
                n_climbs += 1
                return climb
        return 0.0

    for i in range(1, s.size):
        if s[i] > peak:                                      # running max → arm the descent trigger
            peak = s[i]
            peak_i = i
        elif peak - s[i] >= DROP_HYST_M:                     # fell clear of the peak → climb ended
            counted = _close_climb(i)
            descent += (peak - s[i]) if counted else 0.0     # the descent off a counted climb
            trough = peak = s[i]                             # restart the search from this low
            trough_i = peak_i = i
        # Before a climb has developed, keep the launch point at the most recent low (so the climb's
        # rate isn't diluted by a long flat dwell that preceded it).
        if peak - trough < MIN_SEGMENT_M and s[i] <= trough + 0.3:
            trough = min(trough, s[i])
            trough_i = i
    _close_climb(s.size - 1)                                 # finalize an open climb at the end

    floors = int(round(ascent / floor_m))
    return {"floors": floors, "ascent_m": round(ascent, 1),
            "descent_m": round(descent, 1), "n_climbs": n_climbs}
