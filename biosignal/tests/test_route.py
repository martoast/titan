"""Route analytics — a synthetic ~2 km out-and-back run with a hill, plus edge cases."""

import math

from app.core import route


def _hill_run():
    """333 pts out (east, climb 0→50 m) + 333 back (descend), 1 Hz, ~3 m/s."""
    lat0, lon0 = 37.7749, -122.4194
    m_per_deg_lon = 111_320 * math.cos(math.radians(lat0))
    step_m, n, t = 3.0, 333, 1_750_000_000_000
    track = []
    for i in range(n):
        track.append({"t": t + i * 1000, "lat": lat0,
                      "lon": lon0 + (i * step_m) / m_per_deg_lon, "alt": 50.0 * (i / n)})
    for i in range(n):
        track.append({"t": t + (n + i) * 1000, "lat": lat0,
                      "lon": lon0 + ((n - i) * step_m) / m_per_deg_lon, "alt": 50.0 * ((n - i) / n)})
    hr1 = [140 + 20 * math.sin(i / 60) for i in range(len(track))]
    return track, hr1, lat0, lon0, n


def test_analyze_full():
    track, hr1, lat0, lon0, _ = _hill_run()
    r = route.analyze(track, hr1=hr1, hr_max=190, units="metric")
    assert r["valid"]
    assert 1.9 <= r["distance_km"] <= 2.1
    assert 45 <= r["elevation_gain_m"] <= 55
    assert 45 <= r["elevation_loss_m"] <= 55
    assert len(r["splits_km"]) >= 2 and len(r["splits_mi"]) >= 1
    assert r["splits_km"][0]["pace_s_per_unit"] > 0
    assert "1k" in r["best_efforts"] and r["best_efforts"]["1k"]["pace_s_per_km"] > 0
    assert r["relative_effort"] and r["relative_effort"] > 0
    assert r["avg_pace_s_per_km"] and r["gap_s_per_km"]
    # polyline stays under Mapbox's overlay cap and round-trips near the start
    assert r["polyline_points"] <= 500 and len(r["polyline"]) < 2083
    dlat, dlon = _decode_first(r["polyline"])
    assert abs(dlat - lat0) < 1e-4 and abs(dlon - lon0) < 1e-4


def test_moving_time_excludes_a_pause():
    track, hr1, _, _, n = _hill_run()
    base = route.analyze(track, hr1=hr1, hr_max=190)
    paused = track[:n] + \
        [{"t": track[n - 1]["t"] + 60_000, "lat": track[n - 1]["lat"], "lon": track[n - 1]["lon"], "alt": 50.0}] + \
        [{"t": p["t"] + 60_000, "lat": p["lat"], "lon": p["lon"], "alt": p["alt"]} for p in track[n:]]
    r = route.analyze(paused, hr1=hr1, hr_max=190)
    assert r["elapsed_time_s"] > base["elapsed_time_s"] + 50      # the stop counts in elapsed
    assert r["moving_time_s"] < r["elapsed_time_s"] - 50          # but not in moving time


def test_autopause_excludes_stationary_gps_drift():
    """Standing still with GPS drift scribbles a path that HAS speed but makes no net progress — it must
    not count as moving time (Strava-style auto-pause), while a real run's moving time ≈ its elapsed."""
    import random
    rng = random.Random(1)
    lat0, lon0 = 37.7749, -122.4194
    m_per_deg = 111_320.0
    t0 = 1_750_000_000_000
    # 300 s of ~5 m jitter around one spot (each hop > MOVING_SPEED_MIN, but net displacement ~0).
    drift = [{"t": t0 + i * 1000,
              "lat": lat0 + rng.uniform(-5, 5) / m_per_deg,
              "lon": lon0 + rng.uniform(-5, 5) / m_per_deg, "alt": 10.0} for i in range(300)]
    d = route.analyze(drift)
    assert d["moving_time_s"] < d["elapsed_time_s"] * 0.4   # most of the 300 s is auto-paused
    assert d["elapsed_time_s"] > 250                        # …but the wall-clock still elapsed

    run, *_ = _hill_run()                     # a genuine 3 m/s run is untouched by the gate
    r = route.analyze(run)
    assert r["moving_time_s"] > r["elapsed_time_s"] * 0.9


def test_haversine_known_distance():
    # ~111.32 km per degree of longitude at the equator (1 deg).
    d = route.haversine(0.0, 0.0, 0.0, 1.0)
    assert abs(d - 111_195) < 500


def test_relative_effort_needs_hr_and_max():
    track, hr1, *_ = _hill_run()
    assert route.analyze(track, hr1=None, hr_max=190)["relative_effort"] is None
    assert route.analyze(track, hr1=hr1, hr_max=None)["relative_effort"] is None


def test_relative_effort_drops_cadence_lock_artifacts():
    # A-6: a sustained ~220 bpm PPG cadence-lock artifact must NOT score as top-zone max effort. For a fit
    # user (hr_max 195) the fractional 1.15x clamp alone is porous (220/195 = 1.13 < 1.15) — the absolute
    # 215-bpm ceiling is what rejects it. 10 min of artifact would otherwise add ~60 Relative Effort.
    artifact = [220.0] * 600  # 10 min at 1 Hz
    assert route.relative_effort(artifact, hr_max=195) == 0
    # A genuine hard effort just under the ceiling still scores.
    real = [185.0] * 600
    assert route.relative_effort(real, hr_max=195) > 0


def test_relative_effort_cadence_lock_leaves_re_unmoved():
    # WORKOUT_LAB §4 `cadence-lock`: a 10-min 220 bpm PPG cadence-lock artifact on a runner with HRmax 200
    # must leave Relative Effort UNMOVED vs the same run WITHOUT the artifact (delta ≈ 0). A clamped sample
    # must contribute NOTHING — dropping it cannot dilute or shift the aggregate, because RE is a plain
    # per-sample sum with no denominator and no per-minute bin.
    hr_max = 200
    # A physiologically coherent run: 5 min warmup → 15 min tempo → 5 min cooldown, all plausible.
    clean = ([130.0] * 300) + ([170.0] * 900) + ([120.0] * 300)
    # The same run with a 10-min (600 s) cadence-lock block spliced into the middle.
    artifact = clean[:750] + ([220.0] * 600) + clean[750:]
    re_clean = route.relative_effort(clean, hr_max=hr_max)
    re_artifact = route.relative_effort(artifact, hr_max=hr_max)
    assert re_clean > 0
    assert re_artifact == re_clean          # the artifact block scores 0 → RE is exactly unmoved (delta = 0)
    # …and the clamp must never suppress REAL effort: a 4-min 195 bpm max-effort finisher MOVES RE.
    with_finisher = clean + ([195.0] * 240)
    assert route.relative_effort(with_finisher, hr_max=hr_max) > re_clean


def test_too_short_track_is_invalid():
    assert route.analyze([{"t": 1, "lat": 1.0, "lon": 2.0, "alt": 0.0}])["valid"] is False
    assert route.analyze([])["valid"] is False


def test_drop_spikes_removes_gps_outlier():
    # A realistic ~3 m/s track with one injected teleport spike (~5 km jump for a single sample).
    track = [{"t": i * 1000, "lat": 37.7694 + i * 0.00003, "lon": -122.4862, "alt": 0.0} for i in range(20)]
    track[10] = {"t": 10_000, "lat": 37.82, "lon": -122.40, "alt": 0.0}   # spike
    clean = route.drop_spikes(track)
    assert len(clean) == 19                                   # exactly the spike dropped
    assert all(p["lat"] < 37.78 for p in clean)              # the outlier is gone
    # And analyze() (which calls drop_spikes) gives a sane distance, not one inflated by the 5 km jump.
    assert route.analyze(track)["distance_km"] < 0.2


def test_drop_spikes_keeps_real_movement():
    # Fast but legitimate (cycling ~15 m/s) must NOT be dropped.
    track = [{"t": i * 1000, "lat": 37.0 + i * 0.000135, "lon": -122.0, "alt": 0.0} for i in range(10)]
    assert len(route.drop_spikes(track)) == 10


def test_polyline_encoding_matches_reference():
    # Google's canonical example.
    poly = route.encode_polyline([(38.5, -120.2), (40.7, -120.95), (43.252, -126.453)])
    assert poly == "_p~iF~ps|U_ulLnnqC_mqNvxq`@"


def _decode_first(poly):
    idx = [0]
    def dec():
        shift = result = 0
        while True:
            b = ord(poly[idx[0]]) - 63; idx[0] += 1
            result |= (b & 0x1F) << shift; shift += 5
            if b < 0x20:
                break
        return (~(result >> 1) if (result & 1) else (result >> 1)) / 1e5
    return dec(), dec()
