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

Auto-run the self-test when the user opens the run screen AND when a watch-started run begins (the
`startRunIfNeeded` path), so readiness is known up front — and if GPS isn't ready when the watch kicks
off a run, fire a prominent nudge ("Location is off — this run won't map. Tap to fix.") rather than
letting it seal routeless in silence. Ideally also mirror a one-word GPS status to the watch Run face
(Ready / No-GPS) so the user sees it on-wrist before they start moving.

### 2 · NO app button — the watch Run face is the entry (works for walk OR run)
Decision (Alex): do NOT add an in-app Start button. The **watch Run face already IS the start** — press
it, it opens a workout with `sport==1`, the phone's `startRunIfNeeded()` fires, and phone GPS engages.
We proved this chain works: the walk sealed as `type=run`, so the watch→phone trigger already fired.
The entry is fine; the job is making the phone GPS that it kicks off actually **capture** — for walks
and runs alike (same code path, the watch Run face doesn't care about pace). So: keep the watch face as
the one entry, and fix everything downstream of it (readiness + permission + honest failure) so a
watch-started activity reliably records a route.

Corollary — the "walk didn't trigger" theory was wrong: it DID trigger (sealed as run). The route was
lost purely because phone GPS produced nothing (permission/precise/fix), silently. That's the whole bug.

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
- A run/walk STARTED FROM THE WATCH Run face, with Location granted + Precise on, seals WITH a route +
  non-zero distance (the first route this device has ever produced — watch for it in `device_ingestions`
  gaining a route-bearing window / the session's `route_polyline` going non-null). Same result whether
  the pace is a walk or a run — the watch face and phone GPS don't care.
- Permission denied → run still seals on HR, summary clearly states "no GPS route," no silent dist=0.

*The band can't see where you go — the phone can. Right now nothing tells the user that, so every route
is lost. Make GPS readiness a thing you glance at before you head out, and let the user press start.*
