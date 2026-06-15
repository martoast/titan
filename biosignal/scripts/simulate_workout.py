"""Drive a simulated workout through the REAL biosignal endpoints end-to-end and print the
athlete-facing result — proving the activity + fitness pipeline works before the watch arrives.

  .venv/bin/python scripts/simulate_workout.py run 30      # 30-min run
  .venv/bin/python scripts/simulate_workout.py cycle 45 --fitness 0.8

Uses FastAPI's in-process TestClient (no running server needed). The accel is REAL replayed
motion so classification is genuine; HR/GPS/baro are physiological synth (see app/sim/workout.py).
"""

from __future__ import annotations

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from fastapi.testclient import TestClient

from app.main import app
from app.sim.workout import simulate_workout


def main():
    activity = sys.argv[1] if len(sys.argv) > 1 and not sys.argv[1].startswith("-") else "run"
    minutes = float(sys.argv[2]) if len(sys.argv) > 2 and not sys.argv[2].startswith("-") else 30.0
    fitness = float(sys.argv[sys.argv.index("--fitness") + 1]) if "--fitness" in sys.argv else 0.5

    w = simulate_workout(activity=activity, minutes=minutes, fitness=fitness)
    p = w["profile"]
    client = TestClient(app)

    act = client.post("/process/activity", json={
        "accel_counts": w["accel_counts"], "hr_bpm": w["hr_epoch_bpm"], "start": w["start"],
        "hr_max": p["hr_max"], "hr_rest": p["resting_hr"], "weight_kg": p["weight_kg"],
        "accel_xyz": w["accel_xyz"], "accel_fs": w["fs"], "accel_unit": w["accel_unit"],
        "accel_start": w["start"],
    }).json()["metrics"]

    fit = client.post("/process/fitness", json={
        "age": p["age"], "sex": p["sex"], "weight_kg": p["weight_kg"], "height_cm": p["height_cm"],
        "resting_hr": p["resting_hr"], "hr_max": p["hr_max"], "run": w["run"],
        "workout_hr_bpm": w["workout_hr_bpm"], "hr_fs": 1.0,
    }).json()

    s = act["sessions"][0] if act["sessions"] else None
    print(f"\n=== Simulated {activity} · {minutes:.0f} min · fitness {fitness:.1f} "
          f"(intended: {w['activity_intended']}) ===")
    print(f"  device: rest HR {p['resting_hr']} · HRmax {p['hr_max']} · {w['distance_km']} km GPS\n")
    if s:
        print(f"  CLASSIFIED   {s['activity_type']}  (conf {s['activity_confidence']})  "
              f"{'✓' if s['activity_type'] == w['activity_intended'] else '✗ MISMATCH'}")
        print(f"  mix          {s.get('activity_mix')}")
        print(f"  session      {s['duration_min']} min · TRIMP {s['trimp']} · {s['calories_kcal']:.0f} kcal · mean HR {s['mean_hr']}")
    else:
        print("  no session detected")
    hrr = (fit.get("hrr") or {}).get("hrr_bpm")
    print(f"  VO2MAX       {fit['vo2max']} ± {fit['plusminus']} ml/kg/min  ({fit['fitness_level']})  via {', '.join(fit['methods'])}")
    print(f"  HRR-60s      {hrr} bpm" + ("" if hrr is None else "  (recovery trend)"))
    print()


if __name__ == "__main__":
    main()
