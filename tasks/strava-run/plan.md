# Titan "Runs" — a Strava-style run tracker (plan)

_Goal: track a run with a GPS route, and produce the end-of-run summary the user loves — a map of the
path taken + headline stats (distance, pace/mi, fastest split, elevation, time) + splits._

## The one hard blocker (and why everything else is easy)
**Our firmware never logs GPS latitude/longitude — only speed + altitude** (`T4` frame in
`firmware/banglejs/titan.app.js`). GPS exists today purely to feed pace/grade into VO2max. The Bangle's
GPS gives coordinates fine (`Bangle.getGPS()` / the `GPS` event) — we just don't record them. **No
lat/lon = no route map.** So Phase 1 is a firmware change; it's decision-independent and unblocks all
the rest.

## What we already have (reuse, don't rebuild)
- **Firmware:** GPS power-gating, a manual/auto workout system, a "primed" activity type (run/cycle/lift),
  T4 GPS frames (speed/alt/sats/ts), T6 offline accel, T7 barometric altitude, flash logging + resync.
- **Biosignal (`biosignal/app/core/fitness.py`):** `RunCapture` (hr, speed_kmh, grade, hrr60),
  VO2max model, grade-from-altitude. Natural home for route processing (polyline, splits, best-efforts).
- **Laravel (`ActivitySession` model + migration):** stores started/ended, duration, distance_km, avg/max
  HR, hr_zones, TRIMP, calories, vo2max, hrr. Sealing pipeline (`SealActivityJob`) already pulls
  per-window speed/grade/HR. Just missing the route columns.
- **Web (`resources/views/fitness/index.blade.php`):** a session list with metrics. No detail page/map yet.
- **iOS (`WorkoutsView.swift`):** a placeholder ("No sessions yet"). No MapKit, no detail view yet.

## The gap (what's net-new)
| Layer | Add |
|---|---|
| Firmware | Log lat/lon: extend `T4` (deg×1e7, +8 B) — keep its version byte for back-compat. New **Run face** that force-arms GPS + starts a pinned "run" workout + shows live time/dist/pace. |
| Decoder (web `bridge-decode.js` + iOS `FrameRouter`) | Decode the new T4 coords → a per-run coordinate+time stream. |
| Biosignal | `route.py`: coord/time/HR stream → distance (haversine), **moving vs elapsed time**, avg/current pace, **splits** (per km/mi), elevation profile, **encoded polyline + Douglas–Peucker simplify**. (v2: GAP, best-efforts, Relative Effort.) |
| Laravel | `ActivitySession` migration: `route_polyline` (encoded string), `route_bounds`, `splits` (json), `elevation_profile` (json), `moving_time_s`. Seal job fills them. A run detail endpoint. |
| iOS | A **Run tab/detail**: MapKit `MKPolyline` over the route, stat band, splits table, elevation chart. End-of-run **shareable card** via `MKMapSnapshotter` (free, native, no API key). |
| Web | Run detail page: **Leaflet + OpenStreetMap** polyline (free), stat band, splits, elevation chart. |

## Map rendering — recommendation: free + native, no API key
- **iOS:** **MapKit** — native, free, no key; `MKPolyline` for the route, `MKMapSnapshotter` to bake the
  shareable end-of-run PNG. (Beats Mapbox here — zero deps.)
- **Web:** **Leaflet + OSM tiles** (free), or self-host `staticmaps` (py) in the biosignal service for a
  server-rendered PNG. On-brand with the self-hosted ethos; no vendor lock, no per-request cost.
- The only reusable "hard" bits — **Google polyline encoding + Douglas–Peucker simplification** — live
  once in `biosignal` and feed every renderer.

## STATUS — all phases shipped on `feat/strava-runs` (2026-06-26)
- ✅ **Phase 1** firmware GPS lat/lon capture (T4 v5) + both decoders + tests (commit 26e616a, +alt)
- ✅ **Phase 2a** biosignal `/process/route` analytics — distance/moving-time/pace/GAP/splits/elevation/
  best-efforts/Relative-Effort/polyline (commit 12c0128, 6 tests)
- ✅ **Phase 2b** Laravel storage + seal wiring (commit 90b9ad9, migration + 8 seal tests)
- ✅ **Phase 3 web** run-detail page w/ Mapbox static map (commit 2b0421c)
- ✅ **Phase 3 iOS** run list + detail (Mapbox image, stats, splits, elevation) (commit ae5af37, +API)
- ✅ **Phase 4** on-watch Run face — tap to start a GPS run, live time/dist/pace (commit 8432669)
- ✅ **Phase 5** GAP + best-efforts + Relative-Effort — computed in route.py, surfaced web + iOS
- Verified: PHP 468/0, biosignal route 6/6, TitanCore 25/25, iOS BUILD SUCCEEDED, firmware node --check.
- **Needs on-device validation:** the firmware GPS-coord logging + Run face (can't flash from here).
- **Token:** `MAPBOX_API_TOKEN` in `.env` (set). iOS uses the server-built static-map URL (no iOS token).

## Phased build
- **Phase 1 — Foundation (firmware GPS capture).** Extend T4 with lat/lon; decode both ends; store a raw
  coordinate stream through to seal. *Unblocks everything; no decisions needed.*
- **Phase 2 — Route processing (biosignal + Laravel).** `route.py` (distance/moving-time/pace/splits/
  elevation/polyline) → new `ActivitySession` columns → seal job fills them.
- **Phase 3 — The end-of-run summary (iOS first).** Run detail: map polyline + stat band + splits +
  elevation; shareable `MKMapSnapshotter` card. Then the web detail page.
- **Phase 4 — The watch Run face + live screen.** Dedicated run mode: force-arm GPS, pin a "run" workout,
  live time/dist/pace on-watch; app drops into run mode when connected.
- **Phase 5 (v2) — Analysis.** GAP, best-efforts (fastest 1k/5k/10k from your own history), Relative
  Effort from HR zones. Cheap because we own all history locally.

## Decisions (made 2026-06-26)
1. **v1 scope: FULL Strava parity** — core (record → map + distance/time/pace/splits/elevation) PLUS
   GAP, best-efforts (fastest 1k/5k/10k from history), and Relative Effort from HR zones.
2. **Map provider: Mapbox** — prettier tiles + the Static Images API `path(polyline)` one-liner for the
   share card, and Mapbox GL for the interactive map. **Needs a `MAPBOX_TOKEN`** — wire it to read from
   config/env (`.env` on web, app config on iOS); the user supplies the token (never hardcode/commit it).
   Still encode + Douglas–Peucker-simplify the polyline (Mapbox overlay cap ~2083 chars).

_Research sources: `tasks/strava-run/` (Strava feature brief + codebase map in the workflow output)._
