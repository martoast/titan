"""Run route analytics — turn a raw GPS track into the Strava-style summary.

Pure, dependency-light functions (math + the stdlib). Given a list of track points
``{t, lat, lon, alt}`` (epoch-ms timestamps, degrees, metres) plus a 1 Hz HR series, this
computes everything the end-of-run card needs:

* distance (haversine), moving vs elapsed time, average + grade-adjusted pace
* per-km AND per-mile splits (pace, elevation Δ, avg HR)
* elevation gain/loss + a downsampled profile (altitude vs distance)
* best efforts — fastest rolling time at benchmark distances (400 m … half-marathon)
* Relative Effort — HR-zone-weighted cardiovascular load
* an encoded, Douglas–Peucker-simplified polyline + bounds for the map

Wellness scope: estimates with honest limits, never a diagnosis. The watch logs a coarse
GPS fix (~1 Hz when moving), so distances are GPS-grade, not survey-grade.
"""

from __future__ import annotations

import math
from typing import List, Optional, Sequence, Tuple

EARTH_R = 6_371_000.0  # mean Earth radius, metres
MILE_M = 1609.344
KM_M = 1000.0

# Benchmark distances for best-efforts (metres) → label.
BEST_EFFORT_DISTS = [
    (400.0, "400m"), (KM_M, "1k"), (MILE_M, "1mi"), (5 * KM_M, "5k"),
    (10 * KM_M, "10k"), (15 * KM_M, "15k"), (10 * MILE_M, "10mi"),
    (20 * KM_M, "20k"), (21_097.5, "half"),
]

MOVING_SPEED_MIN = 0.5  # m/s below this counts as stopped (Strava-style moving-time gate)

# Auto-pause (Strava-style): instantaneous speed alone can't tell a slow jog from standing still with
# GPS drift — a stationary scribble HAS speed but makes no net PROGRESS. So a second gate asks "over the
# last few seconds, did the runner actually get anywhere?": net straight-line displacement over a short
# trailing window. Below this net pace the runner is parked (a red light, or pure drift) and the time
# doesn't count as moving. A real run/walk clears it by a wide margin; only jitter-in-place fails.
AUTOPAUSE_WINDOW_S = 30.0       # trailing window over which to measure real progress (long enough that
                                # random GPS jitter averages out to ~no net displacement)
AUTOPAUSE_NET_SPEED_MIN = 0.3   # m/s of NET displacement below which you're stopped, not moving


# ----- geometry -------------------------------------------------------------------------

def haversine(lat1: float, lon1: float, lat2: float, lon2: float) -> float:
    """Great-circle distance between two lat/lon points, in metres."""
    p1, p2 = math.radians(lat1), math.radians(lat2)
    dphi = math.radians(lat2 - lat1)
    dlmb = math.radians(lon2 - lon1)
    a = math.sin(dphi / 2) ** 2 + math.cos(p1) * math.cos(p2) * math.sin(dlmb / 2) ** 2
    return 2 * EARTH_R * math.asin(min(1.0, math.sqrt(a)))


def drop_spikes(track: Sequence[dict], max_speed_mps: float = 50.0) -> List[dict]:
    """Drop GPS outliers — a point implying an impossible speed from the last KEPT point is sensor
    error, not movement, and would spike the polyline / inflate distance. 50 m/s (180 km/h) is well
    above any run/walk/bike, so real movement is never dropped. Defense-in-depth: the phone already
    accuracy-gates fixes, but this guards the route against any stray point from any source."""
    pts = [p for p in track if p.get("lat") is not None and p.get("lon") is not None]
    if len(pts) < 2:
        return pts
    out = [pts[0]]
    for p in pts[1:]:
        dt = (p["t"] - out[-1]["t"]) / 1000.0
        d = haversine(out[-1]["lat"], out[-1]["lon"], p["lat"], p["lon"])
        if dt > 0 and d / dt > max_speed_mps:
            continue
        out.append(p)
    return out


def cumulative_distance(track: Sequence[dict]) -> List[float]:
    """Cumulative distance (metres) at each track point; first point = 0."""
    cum = [0.0]
    for i in range(1, len(track)):
        d = haversine(track[i - 1]["lat"], track[i - 1]["lon"], track[i]["lat"], track[i]["lon"])
        cum.append(cum[-1] + d)
    return cum


# ----- polyline (Google encoded polyline algorithm, precision 5) ------------------------

def encode_polyline(coords: Sequence[Tuple[float, float]], precision: int = 5) -> str:
    """Encode [(lat, lon), …] to a Google-encoded polyline string."""
    factor = 10 ** precision
    out: List[str] = []
    prev_lat = prev_lon = 0

    def _enc(delta: int) -> None:
        v = ~(delta << 1) if delta < 0 else (delta << 1)
        while v >= 0x20:
            out.append(chr((0x20 | (v & 0x1F)) + 63))
            v >>= 5
        out.append(chr(v + 63))

    for lat, lon in coords:
        ilat = int(round(lat * factor))
        ilon = int(round(lon * factor))
        _enc(ilat - prev_lat)
        _enc(ilon - prev_lon)
        prev_lat, prev_lon = ilat, ilon
    return "".join(out)


def _perp_dist(pt: Tuple[float, float], a: Tuple[float, float], b: Tuple[float, float]) -> float:
    """Perpendicular distance from pt to segment a→b, in (scaled) degree space — fine for simplify."""
    (px, py), (ax, ay), (bx, by) = pt, a, b
    dx, dy = bx - ax, by - ay
    if dx == 0 and dy == 0:
        return math.hypot(px - ax, py - ay)
    t = ((px - ax) * dx + (py - ay) * dy) / (dx * dx + dy * dy)
    t = max(0.0, min(1.0, t))
    return math.hypot(px - (ax + t * dx), py - (ay + t * dy))


def douglas_peucker(points: Sequence[Tuple[float, float]], epsilon: float) -> List[Tuple[float, float]]:
    """Ramer–Douglas–Peucker simplification. epsilon in degrees (~1e-4 ≈ 11 m)."""
    if len(points) < 3:
        return list(points)
    dmax, idx = 0.0, 0
    for i in range(1, len(points) - 1):
        d = _perp_dist(points[i], points[0], points[-1])
        if d > dmax:
            dmax, idx = d, i
    if dmax > epsilon:
        left = douglas_peucker(points[: idx + 1], epsilon)
        right = douglas_peucker(points[idx:], epsilon)
        return left[:-1] + right
    return [points[0], points[-1]]


def simplify_to_limit(coords: Sequence[Tuple[float, float]], max_points: int = 500) -> List[Tuple[float, float]]:
    """Simplify a track until it's under max_points (keeps the encoded polyline within Mapbox's
    ~2083-char overlay cap). Ramps epsilon geometrically; always returns ≥2 points."""
    pts = list(coords)
    if len(pts) <= max_points:
        return pts
    eps = 1e-5
    for _ in range(40):
        simp = douglas_peucker(pts, eps)
        if len(simp) <= max_points:
            return simp
        eps *= 1.6
    return [pts[0], pts[-1]]


# ----- time -----------------------------------------------------------------------------

def moving_time_s(track: Sequence[dict]) -> float:
    """Seconds in motion — sum of inter-point Δt for segments that are BOTH fast enough (instantaneous
    speed ≥ MOVING_SPEED_MIN) AND actually progressing (net displacement over a short trailing window ≥
    AUTOPAUSE_NET_SPEED_MIN). The second gate is Strava-style auto-pause: it drops time spent standing
    still with GPS drift (a scribble that has speed but goes nowhere) and time paused at a light, without
    penalising a real slow run. Falls back toward elapsed if timestamps are sparse."""
    total = 0.0
    left = 0  # sliding-window start: oldest point still within AUTOPAUSE_WINDOW_S of point i
    for i in range(1, len(track)):
        dt = (track[i]["t"] - track[i - 1]["t"]) / 1000.0
        if dt <= 0:
            continue
        d = haversine(track[i - 1]["lat"], track[i - 1]["lon"], track[i]["lat"], track[i]["lon"])
        if d / dt < MOVING_SPEED_MIN:
            continue
        # Advance the window start so [left, i] spans ~AUTOPAUSE_WINDOW_S, then measure NET progress.
        while left < i and (track[i]["t"] - track[left]["t"]) / 1000.0 > AUTOPAUSE_WINDOW_S:
            left += 1
        win_dt = (track[i]["t"] - track[left]["t"]) / 1000.0
        net = haversine(track[left]["lat"], track[left]["lon"], track[i]["lat"], track[i]["lon"])
        # With a full window, require real net progress; if the window is too short to judge (sparse
        # fixes at the very start), fall back to the instantaneous gate we already passed.
        if win_dt >= AUTOPAUSE_WINDOW_S * 0.5 and net / win_dt < AUTOPAUSE_NET_SPEED_MIN:
            continue
        total += dt
    return total


def elapsed_time_s(track: Sequence[dict]) -> float:
    if len(track) < 2:
        return 0.0
    return max(0.0, (track[-1]["t"] - track[0]["t"]) / 1000.0)


# ----- splits ---------------------------------------------------------------------------

def _interp_time_at(cum: Sequence[float], times: Sequence[float], target_m: float) -> float:
    """Linear-interpolate the time (s) at which cumulative distance crosses target_m."""
    for i in range(1, len(cum)):
        if cum[i] >= target_m:
            span = cum[i] - cum[i - 1]
            frac = (target_m - cum[i - 1]) / span if span > 0 else 0.0
            return times[i - 1] + frac * (times[i] - times[i - 1])
    return times[-1]


def splits(track: Sequence[dict], cum: Sequence[float], hr1: Optional[Sequence[float]], unit_m: float) -> List[dict]:
    """Per-unit (km or mile) splits: pace (s per unit), elevation Δ, avg HR within the split."""
    if not track or cum[-1] < unit_m:
        return []
    times = [(p["t"] - track[0]["t"]) / 1000.0 for p in track]
    t0 = track[0]["t"]
    out: List[dict] = []
    n_full = int(cum[-1] // unit_m)
    for s in range(n_full + 1):
        d_start, d_end = s * unit_m, min((s + 1) * unit_m, cum[-1])
        if d_end - d_start < 1.0:
            continue
        ts = _interp_time_at(cum, times, d_start)
        te = _interp_time_at(cum, times, d_end)
        dist = d_end - d_start
        secs = te - ts
        pace = secs / (dist / unit_m) if dist > 0 else 0.0  # seconds per full unit
        # elevation Δ + avg HR over the points falling in this split (single pass over cum/alt/hr)
        elev_delta, hrs = 0.0, []
        first_alt = last_alt = None
        for i, p in enumerate(track):
            if d_start <= cum[i] <= d_end:
                a = p.get("alt")
                if a is not None:
                    if first_alt is None:
                        first_alt = a
                    last_alt = a
                if hr1 is not None and 0 <= int(times[i]) < len(hr1):
                    hv = hr1[int(times[i])]
                    if hv:
                        hrs.append(hv)
        if first_alt is not None and last_alt is not None:
            elev_delta = last_alt - first_alt
        out.append({
            "index": s + 1,
            "distance_m": round(dist, 1),
            "elapsed_s": round(secs, 1),
            "pace_s_per_unit": round(pace, 1),
            "elev_delta_m": round(elev_delta, 1),
            "avg_hr": round(sum(hrs) / len(hrs)) if hrs else None,
            "partial": dist < unit_m - 1.0,
        })
    return out


# ----- elevation ------------------------------------------------------------------------

def elevation(track: Sequence[dict], cum: Sequence[float], samples: int = 100) -> dict:
    """Cumulative ascent/descent (with a small noise deadband) + a downsampled altitude-vs-distance
    profile for the chart."""
    alts = [(cum[i], p["alt"]) for i, p in enumerate(track) if p.get("alt") is not None]
    if len(alts) < 2:
        return {"gain_m": 0, "loss_m": 0, "profile": []}
    gain = loss = 0.0
    deadband = 0.5  # metres — ignore GPS/baro jitter below this
    prev = alts[0][1]
    for _, a in alts[1:]:
        d = a - prev
        if abs(d) >= deadband:
            if d > 0:
                gain += d
            else:
                loss += -d
            prev = a
    # downsample the profile to ~samples evenly-spaced distance buckets
    total = alts[-1][0]
    profile: List[dict] = []
    if total > 0:
        step = total / samples
        j = 0
        for k in range(samples + 1):
            dk = k * step
            while j < len(alts) - 1 and alts[j][0] < dk:
                j += 1
            profile.append({"d_km": round(dk / 1000.0, 3), "alt_m": round(alts[j][1], 1)})
    return {"gain_m": round(gain), "loss_m": round(loss), "profile": profile}


# ----- best efforts ---------------------------------------------------------------------

def best_efforts(cum: Sequence[float], times: Sequence[float], total_m: float) -> dict:
    """For each benchmark distance ≤ total, the fastest elapsed time over any rolling window that
    covers it (the classic two-pointer sweep over the distance axis)."""
    out: dict = {}
    n = len(cum)
    for dist_m, label in BEST_EFFORT_DISTS:
        if dist_m > total_m:
            continue
        best = math.inf
        j = 0
        for i in range(n):
            while j < n and cum[j] - cum[i] < dist_m:
                j += 1
            if j >= n:
                break
            # interpolate the crossing for sub-point precision
            span = cum[j] - cum[j - 1]
            frac = (dist_m - (cum[j - 1] - cum[i])) / span if span > 0 else 0.0
            t_cross = times[j - 1] + frac * (times[j] - times[j - 1])
            best = min(best, t_cross - times[i])
        if best != math.inf and best > 0:
            out[label] = {"distance_m": dist_m, "elapsed_s": round(best, 1),
                          "pace_s_per_km": round(best / (dist_m / 1000.0), 1)}
    return out


# ----- grade-adjusted pace (Minetti cost of running on a gradient) ----------------------

def _minetti_factor(g: float) -> float:
    """Energy cost of running at gradient g relative to flat (Minetti 2002, normalized to g=0)."""
    g = max(-0.45, min(0.45, g))
    cost = 155.4 * g ** 5 - 30.4 * g ** 4 - 43.3 * g ** 3 + 46.3 * g ** 2 + 19.5 * g + 3.6
    return cost / 3.6


def grade_adjusted_pace_s_per_km(track: Sequence[dict], cum: Sequence[float], moving_s: float, dist_m: float) -> Optional[float]:
    """Average flat-equivalent pace: weight each segment's time by its Minetti grade cost, so a hilly
    run reports the pace it 'felt' like on the flat."""
    if dist_m <= 0 or moving_s <= 0:
        return None
    flat_equiv_m = 0.0
    for i in range(1, len(track)):
        seg = cum[i] - cum[i - 1]
        a0, a1 = track[i - 1].get("alt"), track[i].get("alt")
        if seg <= 0:
            continue
        grade = ((a1 - a0) / seg) if (a0 is not None and a1 is not None) else 0.0
        flat_equiv_m += seg * _minetti_factor(grade)
    if flat_equiv_m <= 0:
        return None
    # GAP pace = moving time spread over the flat-equivalent distance
    return round(moving_s / (flat_equiv_m / 1000.0), 1)


# ----- relative effort (HR-zone-weighted load) ------------------------------------------

# Zone upper bounds as a fraction of HRmax, and the per-zone weight (intensity ≫ duration).
_ZONES = [(0.60, 0.0), (0.70, 1.0), (0.80, 2.0), (0.90, 4.0), (1.01, 6.0)]

def relative_effort(hr1: Optional[Sequence[float]], hr_max: Optional[float]) -> Optional[int]:
    """Strava-style Relative Effort: minutes in each HR zone × a progressively higher weight, summed.
    Needs HRmax + a 1 Hz HR series; returns None otherwise (we never fabricate it)."""
    if not hr1 or not hr_max or hr_max <= 0:
        return None
    score = 0.0
    for bpm in hr1:
        if not bpm:
            continue
        frac = bpm / hr_max
        for ub, w in _ZONES:
            if frac < ub:
                score += w / 60.0  # one sample = 1 s; weight is per-minute
                break
    return int(round(score)) if score > 0 else 0


# ----- top-level assembly ---------------------------------------------------------------

def analyze(track: Sequence[dict], hr1: Optional[Sequence[float]] = None,
            hr_max: Optional[float] = None, units: str = "metric") -> dict:
    """Full run-route analysis. ``track`` = [{t(ms), lat, lon, alt?}, …] (coord-bearing fixes only,
    ascending time). ``hr1`` = 1 Hz HR aligned to the run start. ``units`` picks the headline split
    unit (we always compute both km and mile splits)."""
    track = drop_spikes(track)   # coord-only + impossible-jump rejection → clean route/distance/polyline
    if len(track) < 2:
        return {"valid": False, "reason": "insufficient_track"}

    cum = cumulative_distance(track)
    total_m = cum[-1]
    times = [(p["t"] - track[0]["t"]) / 1000.0 for p in track]
    moving_s = moving_time_s(track) or elapsed_time_s(track)
    elapsed_s = elapsed_time_s(track)

    coords = [(p["lat"], p["lon"]) for p in track]
    simplified = simplify_to_limit(coords, max_points=500)
    lats = [c[0] for c in coords]
    lons = [c[1] for c in coords]

    avg_pace = round(moving_s / (total_m / 1000.0), 1) if total_m > 0 else None
    elev = elevation(track, cum)

    return {
        "valid": True,
        "distance_m": round(total_m, 1),
        "distance_km": round(total_m / 1000.0, 3),
        "moving_time_s": round(moving_s),
        "elapsed_time_s": round(elapsed_s),
        "avg_pace_s_per_km": avg_pace,
        "gap_s_per_km": grade_adjusted_pace_s_per_km(track, cum, moving_s, total_m),
        "elevation_gain_m": elev["gain_m"],
        "elevation_loss_m": elev["loss_m"],
        "elevation_profile": elev["profile"],
        "splits_km": splits(track, cum, hr1, KM_M),
        "splits_mi": splits(track, cum, hr1, MILE_M),
        "best_efforts": best_efforts(cum, times, total_m),
        "relative_effort": relative_effort(hr1, hr_max),
        "polyline": encode_polyline(simplified),
        "polyline_points": len(simplified),
        "bounds": {"min_lat": min(lats), "min_lon": min(lons), "max_lat": max(lats), "max_lon": max(lons)},
        "units": units,
    }
