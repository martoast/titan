# WORKOUT STORY — the session you can see, not the receipt you get

**Spec for the dev agent · from Alex + Henry · 2026-07-10 · companion to SLEEP_TIMELINE.md**
**Status: BUILD WITH WORKOUT LAB (its W-4 FK fix is this spec's data prerequisite).**

## 1 · The problem

We compute zones, TRIMP, splits, best efforts, elevation, relative effort — and the workout detail
shows totals and (for runs) a map. **There is no time axis anywhere.** No HR curve across the session.
The single most recognizable strain visual in this product category — the effort line that shows the
warmup climb, the interval peaks, the rest valleys, the moment you emptied the tank — does not exist.
The raw HR sits per-window in the ingestion blobs, computed and then never shown to the human who
earned it.

For lifts it's worse: sets (when logged) live in a separate list with no connection to time or effort.

## 2 · The design

### 2.1 The effort timeline (hero of the workout detail)
Horizontal time axis, session start → end, real clock times:

- **HR curve** — downsampled series (~10s resolution) as the primary line, filled beneath with the
  ZONE color band it's in at that moment: the session reads as colored terrain — blue warmup foothills,
  orange tempo ridge, red interval peaks.
- **Zone bands** — time-in-zone becomes *where-in-time*: the aggregate pie stays as secondary, but the
  timeline shows WHEN you were in z4, not just how long.
- **Set markers (lifts)** — each logged set pins to its timestamp on the curve: tap → "Squats · set 3 ·
  8 reps @ 100kg · HR 148→152". The moment sets and effort become one story, logging sets starts
  feeling worth it. (Requires WORKOUT LAB's `sets-belong` FK — sets attach by id, never by proximity.)
- **Splits (runs)** — km markers on the axis; tap a split → pace/GAP/HR for that km, synced highlight
  on the map polyline. Elevation as a faint profile behind the HR curve.
- **Honest gaps** — strap dropouts/off-wrist stretches render as gaps (same NODATA vocabulary and
  shared component DNA as SLEEP TIMELINE), never interpolated into fake effort.
- **Work/rest segmentation (lifts)** — the active-epoch detection already distinguishes effort bursts
  from rest; render rest valleys dimmed so interval structure is visible without any manual logging.

### 2.2 The summary card
Session list rows and the post-workout summary get a mini effort-line (sparkline of the HR/zone
terrain, ~40pt) next to the totals — you recognize your interval session by its SHAPE before you read
a number. Post-seal push deep-links to the detail.

### 2.3 States
Computing/late-seal → skeleton timeline between known start/end (same pattern as sleep). Duration-only
sessions (no usable HR) → span bar + "effort curve unavailable — low HR signal" label. Zones missing →
curve without the fill.

## 3 · Data contract (the one real API addition)
Add to the session seal: `hr_series` — downsampled [t_offset_s, bpm] pairs (~10s buckets, positive
samples only, gaps preserved as nulls) stored on the session row (~360 points/hour, small JSON), plus
`set_events` derived from the FK'd workout's sets (timestamp, exercise, reps, weight). Everything else
(zones, splits, elevation_profile, bounds) already ships. Web parity via the shared timeline partial.

## 4 · Acceptance (LAB-enforced)
Every WORKOUT LAB scenario asserts its story: the interval script's peaks appear at scripted offsets,
set markers land on their timestamps, strap-dropout scenarios show gaps not interpolation, the
cadence-lock artifact never draws a max-effort spike (clamped upstream), summary sparkline matches the
detail curve, computing→final transition renders. One snapshot per scenario, web + iOS.

*Totals say you trained. The terrain shows the training. Ship the terrain.*
