"""Grade-aware energy expenditure (Minetti cost-of-transport). Sanity + monotonicity; the accuracy
number lives in scripts/validate_energy.py (real measured VO2: flat CoT 3.67 vs Minetti 3.60, 10% MAPE)."""

from __future__ import annotations

import numpy as np

from app.core import activity as ac
from app.core import energy as en


def test_flat_running_cost_matches_minetti():
    assert abs(en.cost_of_transport(0.0, "run") - 3.6) < 0.01
    assert abs(en.cost_of_transport(0.0, "walk") - 2.5) < 0.01


def test_uphill_costs_more_downhill_less():
    flat = en.cost_of_transport(0.0, "run")
    up = en.cost_of_transport(0.10, "run")            # +10% grade
    gentle_down = en.cost_of_transport(-0.12, "run")  # near the cost minimum
    steep_down = en.cost_of_transport(-0.40, "run")
    assert up > flat                                  # uphill is dearer
    assert gentle_down < flat                         # gentle downhill is cheaper than flat
    assert steep_down > gentle_down                   # very steep downhill costs more again (braking)


def test_locomotion_kcal_is_physiological():
    # 70 kg running 10 km/h (2.78 m/s) for 30 min flat → ~5 km. Net ≈ 3.6·70·5000/4184 ≈ 301 kcal.
    n = 30 * 60
    out = en.locomotion_kcal([10.0] * n, weight_kg=70, dt_s=1.0, gross=False)
    assert abs(out["distance_km"] - 5.0) < 0.05
    assert 270 <= out["net_kcal"] <= 330, out
    assert abs(out["mean_cost_jkgm"] - 3.6) < 0.1

    # Gross (workout-calorie convention) adds resting metabolism over the bout → a bit higher.
    gross = en.locomotion_kcal([10.0] * n, weight_kg=70, dt_s=1.0, gross=True)
    assert gross["kcal"] > out["net_kcal"]


def test_uphill_run_burns_more_than_flat():
    n = 20 * 60
    flat = en.locomotion_kcal([10.0] * n, grade=[0.0] * n, weight_kg=75, dt_s=1.0)
    up = en.locomotion_kcal([10.0] * n, grade=[0.08] * n, weight_kg=75, dt_s=1.0)
    assert up["kcal"] > flat["kcal"] * 1.3            # 8% grade is a big energy premium


def test_refine_energy_replaces_met_proxy_for_runs():
    """A run session with GPS pace switches the calorie source from the MET proxy to the cost curve,
    fills distance, and a graded version reads higher + marks grade_cost."""
    epochs = 24                                       # 12 min at 30-s epochs
    counts = [40.0] * epochs                          # clearly "active"
    speed = [11.0] * epochs                           # steady 11 km/h run
    # flat
    flat = ac.detect_sessions(counts, weight_kg=72, speed_kmh=speed)
    s = flat["sessions"][0]
    assert s["energy_method"] == "pace_cost"
    assert s["distance_km"] is not None and s["distance_km"] > 1.0
    # uphill
    up = ac.detect_sessions(counts, weight_kg=72, speed_kmh=speed, grade=[0.06] * epochs)
    su = up["sessions"][0]
    assert su["energy_method"] == "grade_cost"
    assert su["calories_kcal"] > s["calories_kcal"]   # uphill burns more


def test_cycling_keeps_met_proxy():
    """Fast wheels (cycling speeds) must NOT get the foot cost-of-transport curve."""
    epochs = 24
    out = ac.detect_sessions([40.0] * epochs, weight_kg=72, speed_kmh=[28.0] * epochs)
    assert out["sessions"][0]["energy_method"] == "met_proxy"   # >18 km/h, unclassified → proxy kept
