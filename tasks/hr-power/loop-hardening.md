# Making the loop work like Whoop — punch list & resolutions (2026-07-06)

After finalizing the continuous-HR firmware, three read-only scouts mapped the full
watch → phone → server → dashboard loop. Verdict: **the loop is built and works** — ingest,
seals, recovery/strain/sleep, HR graph, and the dashboard are all real and wired; the
historical 422 batch-drop is confirmed fixed. What remained was making the new continuous-HR
work *visible & live*, plus polish/hardening. All actioned items below are shipped.

## Tier 1 — make the continuous HR live & visible
- **I1 · HR graph lagged 30 min / could lose the tail** — `HrTrendBuilder` was RAM-only,
  shipped only after 30 closed minutes or on a BLE disconnect (rare under always-on). Fix:
  `TitanApp .background → AppModel.flushWindowsForBackground() → router.flush(live:true)`
  (drains HR trend + trailing PPG; live run kept via `deferWorkoutFlush`), and
  `FLUSH_AT 30 → 5`. Commit `3bb6fe9`.
- **D1 · 24/7 HR invisible on Today** — Heart card was an inert nav stub; the real graph was
  a tap deeper and not loaded until opened. Fix: card shows today's resting bpm + a live
  `HrGraph` sparkline (made the component internal), and `DashboardView` loads HR in
  `.task`/`.refreshable`. Commit `3bb6fe9`.

## Tier 2 — Whoop parity + first impression
- **S1 · All-day strain from the continuous HR stream** — day strain was workout-TRIMP +
  steps/MVPA only, so 24/7 elevated HR with no logged workout scored ~0. Added
  `Strain::hrZoneLoad` (Banister TRIMP over the day's non-workout `hr_samples`; Karvonen
  reserve; Tanaka maxHR; resting HR from latest sealed recovery_log). Stronger-of
  {ambient, HR} (no double-count); in-workout minutes excluded. hr_samples queried by
  LOCAL-day bounds (they're stored owner-local, like MobileHrController). +2 tests. Commit `b0db76a`.
- **D2 · Cold-launch empty-rings flash** — added a `.loading && nil` skeleton
  (`HeroRingsSkeleton`). Commit `0e8f90a`.

## Tier 3 — hardening
- **D3 · Strain silent dead-end** — `loadStrain` now drives a `strainPhase` (skeleton +
  retry row like the other pillars). Commit `c6b8789`.
- **S5 · SealNightJob rethrew on the auto pass** (contradicting its own comment) — now only
  a targeted `--night=` reseal rethrows; the scheduled pass swallows a bad night. Commit `c6b8789`.
- **S3 · HR endpoint uncapped** — `MobileHrController` collapses to 1 pt/min when a day
  exceeds ~1500 samples (bounded regardless of bridge cadence). Commit `c6b8789`.

## Deferred (intentional, low impact for V0)
- **T7 band barometric floors** dropped on the iOS side (`FrameRouter` `case "T7:": break`);
  runs derive elevation from phone GPS. Wire it or delete `decodeT7` later.
- **S4 batch-level LIKE dedup** can drop not-yet-written windows on a mid-batch crash +
  same-uid resend (rare); per-window dedup already self-heals most cases.
- **S2 HR-graph tz** — query uses `app.timezone`; fine for single-user self-hosted (device
  tz defaults to it). Only matters if a device is paired with an explicitly different tz.

## Known pre-existing failure (NOT introduced here, NOT a product bug)
`IngestSealEndToEndTest > signed ended lift window seals into an activity session` — the
`gym sets logged` assertion (a `Workout` row) fails because the test feeds **motionless
accel** (x=y=0, z=1000 const); `SealActivityJob::sealGym` correctly detects 0 sets from flat
data (`processGym` → no reps → no Workout row, `SealActivityJob.php:802-804`). The core seal
(activity_session → `strength`) passes. This is stale synthetic test data, not a defect —
fixing it means authoring realistic rep-shaped accel for the gym detector; deferred.
