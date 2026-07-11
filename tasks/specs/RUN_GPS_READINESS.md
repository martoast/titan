# Run GPS — pre-run readiness & manual start (routes have NEVER tracked)

**Spec for the dev agent · from Alex + Henry · 2026-07-10**

## The problem, proven in data

Alex went on a walk (session #24), it sealed fine on HR (14 min, TRIMP 72.5) but **dist=0,
route=null**. Then the kicker: profile 1 has **ZERO GPS/route ingestions EVER** — the only ingestion
kinds in the whole history are `ppg_raw` and `workout`. **No run has ever recorded a route.**

## Why — the architecture gap

The Bangle.js 2 **has no GPS chip** (confirmed in-code, `RunLocationTracker.swift:4`: "the band has no
GPS chip, so the route + distance come from the iPhone's GNSS while a run is live"). So routes depend
entirely on **phone GPS**, which today only starts when the **watch auto-detects a run**:
`AppModel.startRunIfNeeded()` (`:1261`) → `updateLocator()` starts CoreLocation — and it's only called
from the watch's `sport==1` rising edge (`:1191`). Three ways that silently yields no route:

1. **Walks never trigger it.** A gentle walk doesn't hit the watch's run-grade `sport==1` classifier, so
   `startRunIfNeeded()` never fires, phone GPS never starts, and it seals HR-only.
2. **No manual start.** There is NO in-app "Start Run/Walk" button — a run can ONLY begin from the
   watch's auto-detection. The user can't say "I'm heading out, track me."
3. **Silent permission failure.** If Location permission is `notDetermined`/`denied` or **Precise
   Location is OFF**, GPS yields no usable fixes — and the user gets no warning; the run just seals with
   no route.

## What already exists (reuse, don't rebuild)

- `RunLocationTracker` — full CoreLocation tracker, background mode, precise-accuracy detection.
- **A GPS self-test** — `AppModel.startGpsTest()` (`:1485`), `gpsTestRequiredFixes = 3`, and states for
  `.denied` / `.preciseOff` / weak-signal (`:1100-1105`, `gpsPreciseOff`, `locationDenied`,
  `gpsTestProgress`). It proves a run-grade fix stream lands. **But it's buried in DevicesView (`:296`)**
  — nowhere near the run flow.
- Live route rendering (`LiveRunView`, `RunsView`) and phone-fix→route plumbing
  (`FrameRouter.addGps`, `AppModel.handlePhoneFix:1423`).

The machinery is built. It's just not surfaced where a person decides to go for a run.

## The design

### 1 · A GPS readiness chip on the run entry point (RunsView / home)
Always-visible status the user sees BEFORE moving, driven by the existing gpsTest state:
- **✅ GPS Ready** — permission granted, Precise ON, ≥3 good fixes. "Your route will track."
- **📍 Enable Location** (tap → permission prompt) — permission not granted.
- **⚠️ Turn on Precise Location** (tap → deep-link Settings) — authorized but reduced accuracy;
  `gpsPreciseOff == true`. A run can't track at ~km accuracy.
- **🛰️ Acquiring GPS…** (spinner + `gpsTestProgress`/3) — fixes coming in.
- **🚫 Weak signal (indoors?)** — test ran, best accuracy poor. Suggest stepping outside.

Auto-run the self-test when the user opens the run screen (or taps Start), so readiness is known up
front, not discovered after the run is routeless.

### 2 · A manual "Start Run / Walk" button
Add an explicit in-app start that calls `startRunIfNeeded()` directly (independent of the watch's
`sport==1`), pops the live sheet, and guarantees GPS engages. This:
- **Covers walks** and any activity the watch's run classifier misses.
- Gives the user agency — the Whoop/Strava mental model is "I start my run," not "I hope my watch
  noticed." Keep the watch auto-start too (it's great when it fires); the manual path is the floor.
- Consider a Run/Walk mode chip (walk = same GPS route, lower pace expectations).

### 3 · Request permission at an intentional moment
Move the Location permission ask to onboarding or first visit to the run screen, with a one-line
rationale ("Titan uses your phone's GPS to map your runs — the band has no GPS"). Today it's requested
lazily inside `RunLocationTracker.start()` (`:43`) — i.e. mid-run, where the dialog interrupts and a
denial silently kills the route. Ask before, explain why, and reflect the result in the readiness chip.

### 4 · Honest post-run state
If a run/walk seals with no route (permission off, indoors, or walk-not-tracked), the summary should say
so — "No GPS route (Location was off)" with a tap to fix for next time — instead of showing dist=0 as if
that were the truth.

## The walk as real data (Alex's second ask)
Session #24 is real locomotion (HR + accel), useful as a reference for the WORKOUT LAB's run/walk
scenario (real pace/HR/TRIMP envelope). BUT it has **no GPS**, so it can't validate the route/distance
path — and neither can anything else, because no route data exists anywhere yet. **First get GPS
capturing** (this spec); THEN a real routed run becomes the ground truth to harden the seal's
distance/pace/route pass (`BiosignalClient::route` → `biosignal app/core/route.py`) and to add a
GPS-routed scenario to the WORKOUT LAB.

## Acceptance
- Readiness chip reflects each state (grant/deny/preciseOff/acquiring/ready) — verify by toggling
  Location + Precise in Settings.
- Manual Start begins a tracked run with GPS active even with the watch idle; a short outdoor walk seals
  WITH a route + non-zero distance (the first route this device has ever produced — watch for it in
  `device_ingestions` gaining a route-bearing window / the session's `route_polyline` going non-null).
- Permission denied → run still seals on HR, summary clearly states "no GPS route," no silent dist=0.

*The band can't see where you go — the phone can. Right now nothing tells the user that, so every route
is lost. Make GPS readiness a thing you glance at before you head out, and let the user press start.*
