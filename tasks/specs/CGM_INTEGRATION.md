# CGM INTEGRATION — continuous glucose in Titan (source-agnostic, Nightscout-first)

**Status:** BUILD NOW · **Requested by:** Alex, 2026-07-13
**One line:** Bring continuous glucose into Titan by integrating existing CGMs — the band is untouched.
Nightscout (open-source, self-hostable, CGM-agnostic) is the flagship path; Apple HealthKit is the
frictionless one; the ingestion is source-agnostic so Dexcom's official OAuth API can slot in later.
The payoff: overlay glucose on meals and fasting, see what spikes you, and let the coach reason about
your real metabolic response.

**Why not put glucose in the band:** true CGM is a skin-inserted enzymatic filament (a different,
regulated, disposable device); non-invasive optical glucose from the wrist is unsolved (Apple/Samsung
spent years and shipped nothing; the cheap "glucose smartwatches" are inaccurate). So we integrate an
existing CGM instead — exactly how Levels/Whoop/Oura do it.

**Not a medical device.** Titan visualizes and coaches on the user's own CGM data. It is NOT for
insulin dosing or diagnosis. Every glucose surface carries a plain wellness disclaimer.

---

## The provider choice (decided)

A `GlucoseProvider` abstraction with pluggable sources, so surfaces never care where data came from:

1. **Nightscout (primary, open):** MIT open-source, self-hostable (Alex can run it on the HP box),
   **CGM-agnostic** — works with a cheap Abbott Libre (via xDrip+/Juggluco) OR Dexcom. Clean REST API
   (`GET /api/v1/entries.json?count=N`, token/`api-secret` auth). This is the "open source + build on
   cleanly + self-hosted" answer and the flagship.
2. **Apple HealthKit (secondary, frictionless):** Libre/Dexcom already write
   `HKQuantityTypeIdentifierBloodGlucose` to Apple Health, and Titan's HealthKit pipeline
   (`HealthIngestService`) already ingests HRV/RHR/sleep/steps — glucose rides the same rails. Best
   for iOS users who don't want to run Nightscout.
3. **Dexcom official API (future, via the same abstraction):** cleanest *official* direct path (OAuth
   + sandbox at developer.dexcom.com), but Dexcom-only and ~3h-delayed on the public feed. Add later;
   the abstraction means no surface changes.

---

## Data model

`glucose_readings` (new table + `GlucoseReading` model):
- `profile_id`, `taken_at` (UTC), `mg_dl` (int, canonical unit), `trend` (nullable:
  rising/rising_fast/flat/falling/… as the CGM reports), `source` (nightscout|healthkit|dexcom),
  `device` (nullable), `raw` (nullable json).
- Unique/index on `(profile_id, taken_at)` for dedup + fast range reads.
- **Canonical unit mg/dL**; convert to mmol/L (÷18.0182) for display per the user's locale.
- **Volume:** a CGM emits a reading every 1–5 min (~288–1440/day). Fine at this scale, but add a
  `glucose:prune` retention command (keep full-resolution ~90 days, downsample older to 15-min means)
  so the table doesn't grow unbounded. Log what's pruned (no silent truncation).

Per-profile connection config in `settings` (encrypted): `glucose.provider`, `glucose.nightscout_url`,
`glucose.nightscout_token`, `glucose.enabled`. Never log the token.

---

## Ingestion

- **`GlucoseProvider` interface:** `fetchSince(Profile, Carbon $since): array<GlucoseReading>`.
- **NightscoutProvider:** poll `GET {url}/api/v1/entries.json?count=N&find[dateString][$gte]=…` with the
  user's token; map `sgv`(mg/dL)+`dateString`+`direction` → readings; upsert by `(profile_id,
  taken_at)`. A scheduled `glucose:sync` command every ~5 min for enabled profiles (respect Nightscout
  rate limits; incremental since last reading).
- **HealthKit:** extend `HealthIngestService` to accept a `glucose[]` array from the app's HealthKit
  sync (the app reads `HKQuantityTypeIdentifierBloodGlucose` and posts it), upsert the same way.
- Dedup by timestamp; tolerate backfill and out-of-order (like the seal pipeline — see
  [[project-... ]] the "late data" lesson; late glucose just upserts).

---

## The surfaces (the payoff — build in this order)

### Phase 1 — see the glucose
- **Glucose day curve:** glucose over the day, drawn with the existing chart components
  (`TrendMetricChart` / the HR-graph style). Time-in-range band shaded (e.g. 70–140 mg/dL for a
  non-diabetic), gaps honest (no sensor = no line, like the sleep hatching).
- **Headline metrics:** average glucose, **time-in-range %**, **glucose variability** (SD/CV — a real
  metabolic-health signal), estimated GMI ("estimated A1c"), fasting/overnight glucose.

### Phase 2 — connect it to food (the killer feature)
- **Meal overlay:** plot logged meals on the glucose curve. For each meal compute its **glucose
  response** — baseline, peak Δ, time-to-peak, time-to-baseline, and 2-hour AUC. Surface it on the
  meal card: *"This meal spiked you +55 mg/dL, back to baseline in 1h40."* This is the Levels/
  Nutrisense core loop, and Titan already has the meal timeline to hang it on.
- **"Your spikiest / steadiest meals"** — rank the user's foods by glucose response; feed the coach's
  meal suggestions ("swap the white rice — it spiked you most this week").

### Phase 3 — coach + fasting + longevity tie-ins
- **`glucose` coach card + tool:** the coach reads glucose, explains a spike, suggests swaps, and —
  tying into the science Titan already cites in `MovementBreaks.php` (Dunstan et al.: a 2-min walk
  cuts postprandial glucose ~24–30%) — **prompts a walk after a spiky meal because it can SEE the
  spike.** That's a genuinely novel, honest nudge.
- **Fasting integration:** show glucose flattening during the eating-window/fast — the fasting
  feature gains a real metabolic readout (glucose is the cleanest signal of "am I actually fasted").
- **Longevity/metabolic tie-in:** time-in-range + glucose variability are metabolic-health markers —
  feed the Longevity Index / biopanel context (calibrated, honest, non-diagnostic).

### Setup UX
- Coach-guided connect (like band pairing): "connect my glucose monitor" → choose **Nightscout**
  (paste URL + token — with a one-line "how to self-host Nightscout" pointer) or **HealthKit** (grant
  permission). A `glucose_status` tool/card shows connection + last reading + freshness.

---

## Honesty & safety framing (non-negotiable)
- **Wellness, not medical:** clear disclaimer on the glucose surfaces; never advise on insulin/meds;
  "talk to your doctor" for anything clinical. Titan reads the user's own device data.
- **Don't pathologize normal physiology:** postprandial spikes are normal; non-diabetic ranges differ
  from diabetic targets; frame variability/TIR as *optimization*, not diagnosis. Calibrate the coach
  copy the same way we did the longevity guardrails (see `LongevityKnowledge`).
- **Units + locale:** mg/dL (US) vs mmol/L — respect the user; store mg/dL canonical.

## Design principles
- **Source-agnostic:** every surface reads `glucose_readings`; providers are swappable. Adding Dexcom
  later touches only a provider class.
- **Reuse the app:** chart components, the meal timeline/card, the streak/week patterns, the coach
  card + honesty-chip infra. This is assembly on top of what exists, not new invention.
- **Honest gaps:** no sensor = visible gap, never interpolated over (same rule as sleep coverage).

## Acceptance (by phase)
- [ ] **P1:** `glucose_readings` + `GlucoseProvider`; NightscoutProvider + HealthKit ingest working;
      `glucose:sync` scheduled; a glucose day curve + TIR/average/variability/GMI render on real data.
- [ ] **P2:** meals overlaid on the curve; per-meal glucose response computed + shown on the meal
      card; spikiest/steadiest ranking.
- [ ] **P3:** `glucose` coach card + tool with calibrated framing; walk-after-spike nudge; fasting +
      longevity tie-ins.
- [ ] Setup: connect via Nightscout (URL+token) or HealthKit; `glucose_status` shows freshness.
- [ ] Field check (Henry, on Alex): once Alex points Titan at a Nightscout (or grants HealthKit),
      verify readings ingest, the curve + metrics compute correctly, and a logged meal shows its real
      glucose response. Until then, verify with a Nightscout test feed / seeded readings.

## Dependency note
Alex to decide his personal source: **self-host Nightscout on the HP box** (fits the ethos; he wears a
Libre/Dexcom, phone runs xDrip+/Juggluco → pushes to his Nightscout → Titan reads it) OR just use
HealthKit. The build supports both; his choice only affects which connect flow he uses to dogfood.
