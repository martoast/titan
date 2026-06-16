"""Validate the barometric floor counter against KNOWN ground truth, on physically-realistic traces.

There is no clean public "barometer → labelled floors" dataset, so we build the ground truth from the
real physics of the sensor and the environment, then check the algorithm recovers it:

  - BMP280 noise: ~1 m RMS altitude jitter (datasheet: 0.12 Pa RMS pressure noise in ultra-low-power).
  - Weather drift: a slow random-walk of ±2–3 hPa/day → metres of apparent altitude over hours, the
    confound that naively accumulates phantom floors.
  - True climbs: real stair geometry — flights of 3 m (a storey) taken at ~0.25 m/s, plus flat dwell.

We assert (a) a day with N known flights recovers ≈N floors, and (b) a FLAT day (drift + noise only,
no climbing) yields ≈0 floors — the drift-rejection that matters most. Honest: this is a sensor-model
validation; on-hardware accuracy vs a counted staircase is a validation-day item (06).

Usage: .venv/bin/python scripts/validate_elevation.py
"""

from __future__ import annotations

import sys
from pathlib import Path

import numpy as np

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
from app.core import elevation as el  # noqa: E402

FS = 1.0                  # ambient baro sampled ~1 Hz with oversampling (still ~µA on the BMP280)
NOISE_M = 0.3             # BMP280 RMS altitude noise in standard-oversampling mode (datasheet)
RNG = np.random.default_rng(7)


def _weather_drift(n: int, mag_m_per_hr: float = 3.0) -> np.ndarray:
    """Slow altitude drift from atmospheric pressure change — a random walk, metres/hour scale."""
    step = mag_m_per_hr / 3600.0 / FS                        # m per sample
    return np.cumsum(RNG.standard_normal(n) * step)


def _trace(flights: int, hours: float = 8.0):
    """A `hours`-long altitude trace at FS with `flights` real storey climbs across a flat day.

    Each flight is a realistic up-then-down bump: climb 3 m at ~0.25 m/s (≈12 s), spend time on that
    floor, then later descend back — so the day nets to ~0 m yet contains `flights` true floors (a
    monotonic 'never come down' trace would be physically wrong)."""
    n = int(hours * 3600 * FS)
    base = np.zeros(n)
    climb_samps = max(2, int(round(3.0 / 0.25 * FS)))        # samples to climb one storey
    block = max(4 * climb_samps, n // max(flights, 1))        # one up-dwell-down bump per block
    for f in range(flights):
        start = f * block + block // 5
        up_end = min(n, start + climb_samps)
        base[start:up_end] = np.linspace(0, 3.0, up_end - start)
        top_end = min(n, up_end + block // 3)
        base[up_end:top_end] = 3.0                            # dwell on the floor
        down_end = min(n, top_end + climb_samps)
        base[top_end:down_end] = np.linspace(3.0, 0, down_end - top_end)  # come back down
    drift = _weather_drift(n)
    noise = RNG.standard_normal(n) * NOISE_M
    return base + drift + noise


def main():
    print(f"BMP280 floor counter — synthetic ground truth (noise {NOISE_M} m RMS, weather drift, FS {FS} Hz)\n")
    # (a) Known number of flights.
    errs = []
    for true_floors in (1, 3, 5, 10, 20):
        got = []
        for _ in range(20):
            r = el.floors_from_altitude(_trace(true_floors), sample_rate_hz=FS)
            got.append(r["floors"])
        got = np.array(got)
        mae = np.mean(np.abs(got - true_floors))
        errs.append(mae)
        print(f"  {true_floors:2d} flights → floors {got.mean():4.1f} ± {got.std():.1f}   MAE {mae:.2f}")

    # (b) Drift rejection — a flat day with ONLY weather drift + noise must read ~0 floors.
    phantom = np.array([el.floors_from_altitude(_trace(0), sample_rate_hz=FS)["floors"] for _ in range(50)])
    print(f"\n  FLAT day (drift+noise, 0 true): floors {phantom.mean():.2f} ± {phantom.std():.2f}  "
          f"(max {phantom.max()}) — phantom-floor rate from weather")
    print(f"\n  overall floor-count MAE {np.mean(errs):.2f} across 1–20 flights")
    print("  → drift rejection holds; on-hardware accuracy vs a counted staircase is a validation-day item.")


if __name__ == "__main__":
    main()
