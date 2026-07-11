# SLEEP TIMELINE v2 — the movement layer & the peaks of the night

**Spec for the dev agent · from Alex + Henry · 2026-07-10 · extends SLEEP_TIMELINE.md (Phase 2)**
**Status: BUILD. This is the Whoop-parity sleep-visualization push.**

## 0 · What Alex actually asked for

> "I want to see the point where I was awake and where I was tossing and moving around vs when I
> was truly getting good sleep… visualize how the sleep was and the peaks and points throughout
> the night."

The stage ribbon (v1) answers *what stage, when*. It does **not** answer the two questions Alex is
asking: **when was I restless** (tossing/turning) and **where were the peaks** (HR/arousal spikes).
Those are two data channels we ALREADY compute per-epoch and just hardened — they're simply not
exposed or drawn. This spec adds them. This is the difference between "a Whoop-ish chart" and "I can
*see* my night."

## 1 · The data is real and it separates — proven, not assumed

Pulled from a real sealed night (profile 1, 07-08), per-epoch motion by stage:

```
DEEP  (true rest)   motion mean 2.0   peak 2.6     ← dead calm
REM                 motion mean 3.0   peak 9.0
LIGHT (restless)    motion mean 3.3   peak 16.1    ← the tossing lives here
WAKE                motion mean 2.8   peak 11.6
```

Deep sleep is flat; light/wake carry the movement spikes. **"Tossing and turning" is a visible,
measured signal** — not something we need to invent. Same story for HR: `epoch_hr` is per-epoch and
now honest (post the RMSSD/motion fixes). These two overlays are the payload.

**Hard constraint — the band duty-cycles.** Only ~30% of epochs carry raw motion/HR (that night: 228
of 744 epochs had motion; stages are continuous because the stager interpolates, the raw channels are
sparse). **Movement and HR MUST render only where measured — sparse points/segments with honest gaps,
never a fabricated continuous line.** This is the same "show what we measured" ethos as the NODATA
holes. Painting a smooth HR curve across a band-off hour would be the exact dishonesty v1 was built to
avoid.

## 2 · The design — three legible layers, one timeline

The existing `SleepTimeline` (`ios/Titan/Sources/Design/Components.swift:354`) becomes a **layered**
chart over the same clock axis (bedtime→wake, user tz). Top to bottom:

1. **HR peaks line/area (overlay, top band).** A thin HR trace drawn ONLY across contiguous measured
   runs; gaps where no data. Peaks (arousal surges — the "3am spike") read at a glance. Y-scaled to the
   night's own HR range. Faint fill under it so it reads as an envelope, not a nervous line.
2. **Stage ribbon (unchanged, the middle — stays the hero).** The v1 stepped 4-lane ribbon
   (Awake/REM/Light/Deep) + NODATA hatching. Do not regress it.
3. **Movement strip (new, thin lane under the ribbon).** A per-epoch restlessness bar: height/opacity
   ∝ `epoch_motion`, drawn only where measured. Calm deep sleep → nearly empty; restless light/wake →
   visible teeth. This is the literal "where I was tossing" band. One glance separates a calm night
   from a thrashy one. Consider a subtle threshold tint (e.g. motion ≥ a night-relative percentile =
   "restless") but keep it continuous underneath — don't over-bucket.

### 2.1 Interaction — finish the placeholder that's already there
`Components.swift:516-517` literally has the Phase-2 TODO ("append per-epoch HR here once /api/me/sleep
exposes a downsampled series"). Wire the scrub tooltip to show, for the touched epoch:
`3:12 AM · Deep · HR 52 · calm` (or `· restless` when motion is high). When the epoch has no measured
HR/motion, say so honestly (`· signal gap`) rather than showing a stale/interpolated value.

### 2.2 Where it appears (respect v1 hierarchy)
- **Sleep detail (`SleepView`, RecoveryView.swift:154)** — full layered timeline, the hero. All three
  layers, interactive.
- **Morning summary card (`SleepSummaryView.swift:224`, mini)** — mini timeline keeps the stage ribbon
  + adds the movement strip (the restlessness shape is the at-a-glance "how'd I sleep"); HR overlay is
  optional in mini to avoid clutter — a faint peak line is fine, the scrub is detail-screen only.
- **Web parity (`resources/views/sleep/_timeline.blade.php`)** — same three layers, same shared
  mapping. Web is where Alex studies patterns.

## 3 · Data contract — the one real API change

`app/Support/SleepDetail.php` (the payload builder, hypnogram at line 106) must add two **downsampled,
gap-honest** per-epoch series aligned to the SAME `epoch_sec` + 30s grid as `hypnogram`:

```
'hr_series'     => [ {i: <epochIndex>, v: <bpm>}, … ]   // only measured epochs; downsample to ≤~180 pts
'motion_series' => [ {i: <epochIndex>, v: <0..N>}, … ]  // only measured epochs
```

- **Index-based, sparse** (`{i, v}`), NOT a dense array with nulls — the sparsity IS the signal and it
  keeps the payload small. Epoch i's clock = `epoch_sec + i×30`, identical to the hypnogram, so the app
  aligns all three layers off one axis with zero extra math.
- **Source:** these come from the per-window `result_refs.epoch_hr` / `epoch_motion` (what SLEEP LAB
  and the reprocess just corrected), stitched whole-night the same way the seal builds the hypnogram.
  If the seal already assembles a whole-night epoch grid, expose it; if not, stitch at read time in
  SleepDetail from the night's windows (bounded by bed/wake) — but prefer persisting it at seal time so
  the read is cheap.
- **Downsample** to a sane cap (≤~180 points each) for the payload; keep peaks (use max-pooling per
  bucket, not mean, so an arousal spike survives downsampling — a Whoop-grade chart must not smooth
  away the 3am spike).
- iOS: add `hr_series` / `motion_series` to `SleepResponse.Detail` (`Models.swift:166`). Web: read the
  same fields.

## 4 · One vocabulary — and kill the stray copies while here
v1 consolidated iOS onto `SleepStage` + the single `color` extension (`Components.swift:324`). This
spec adds `restless`/`calm` and an HR-scale as **derived visual state, not new stages** — do NOT add
stages. While in this code, delete the three known divergent color copies the map audit found:
- `app/Support/SleepDetail.php:47-54` — the `color` name-string copy that iOS decodes but never uses
  (iOS re-derives from `key`). Remove the field or point it at `SleepStages.php`.
- `resources/views/welcome.blade.php:451-459` and `simulator/index.blade.php:625` — hardcoded swatches;
  drive them from `SleepStages.php:30` (the canonical web map) instead.

## 5 · Acceptance — LAB-enforced (this is why SLEEP LAB exists)
Extend SLEEP LAB's morning-story assertions so a scripted night proves the new layers, not just eyeballs:
1. **Movement fidelity:** a scenario with a scripted restless bout (high motion window at a known time)
   → `motion_series` shows elevated motion at those epoch indices and calm during scripted deep sleep.
   The restless minutes are visible where the script put them.
2. **Peak survival:** a scripted HR arousal spike survives downsampling (max-pool) — `hr_series` shows
   the peak at the scripted epoch, not a smoothed-away average.
3. **Gap honesty:** a scripted band-off/charge gap → NO hr/motion points in that span (holes, not
   interpolation), aligned exactly with the NODATA hypnogram epochs.
4. **Alignment:** hr/motion/stage all index off the one `epoch_sec` grid — a scrub at epoch N returns a
   consistent (stage, hr, motion) triple.
5. **Snapshot:** summary mini (ribbon+movement), detail hero (all 3 layers), web parity — one snapshot
   per scenario, all reading the shared mapping.

## 6 · Why this order (Alex's "choose for me")
Ship this visualization BEFORE the sequence-aware REM render (SLEEP_LAB option-1). Reasoning:
- **This is the visible product** — it's what makes the sleep feature feel Whoop-grade. REM-render is a
  test-fidelity investment the user never sees.
- **It de-risks the REM gap.** REM/light labeling is the one soft spot (they're marginal twins). But a
  night shown with a movement strip + HR peaks tells its story *even where the REM/light label is
  uncertain* — the user sees "calm & deep here, restless & spiky there," which is the truth regardless
  of the exact stage token. The visualization makes REM precision less load-bearing.
- REM-render (option-1) still belongs on the roadmap — it's how we eventually PROVE REM accuracy to
  Whoop standard — but after the thing Alex can hold.

*v1 turned four numbers into a shape. v2 turns the shape into the night: the calm valleys, the restless
teeth, the arousal peaks. That's the Whoop moment — and ours is more honest, because every mark is
something we measured.*
