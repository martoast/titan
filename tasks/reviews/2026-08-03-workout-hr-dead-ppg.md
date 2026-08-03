# REVIEW REQUEST: workout HR — the PPG waveform dies mid-session and nothing notices

**Author:** Henry (server) · from Alex's 2026-08-03 bike ride · 2026-08-03
**Fix commit:** `0bf129a` (firmware only) · **Status:** written, syntax-checked, **NOT validated on-device**
**Reviewer: please read [What I want challenged](#what-i-want-challenged) first — the root cause is a
hypothesis, and one of my earlier "findings" in this investigation was wrong and had to be reverted.**

---

## The report

Alex, mid-ride, pedalling hard: the watch showed a steady **64 bpm**. The sealed workout came back with
**no heart rate at all** — `avg_hr`, `max_hr`, `hr_quality`, `trimp` all NULL.

## What the data actually shows (FACT, reproducible)

`activity_sessions` id **111**, profile 1, 2026-08-03, 21 min, `updated_via=biosignal:sealed-session-marker`.
That `updated_via` is itself the first signal: it means the seal fell through to the **"GUARANTEED write"**
path in `SealActivityJob::sealConfirmedSession` — *"no usable windows (airplane / never uploaded /
biosignal down)"* — and built the row from the marker envelope alone.

Only **3 windows** were ingested all day, and exactly **one** `ppg_raw` for the whole ride:

| id | kind | window | status | result_refs |
|---|---|---|---|---|
| 8877 | workout | 01:20:02..01:20:02 | sealed | — |
| 9025 | workout | 09:12:24..09:12:43 | sealed | — |
| 9026 | ppg_raw | **14:24:12..14:26:12** | processed | `{"skipped":"invalid_signal","artifact_pct":100}` |

The raw waveform (`raw/1/2026-08-03/c66be67f4b807fbc501862f2a4f3cd93.ppg.gz`, MinIO `raw` disk):

```
2929 samples @ 24 Hz = 122 s
PPG zeros:            2879/2929 = 98.3%
last non-zero sample: t = 2.0 s
accel_mag_cg:         streaming cleanly all 122 s (mean 100.7 cg ≈ 1.00 g, sd 3–35)
```

Per-10s: the first block is 20.8% non-zero, **every block after it is 0.0%**.

Three things follow, and they are what make this diagnosable rather than a guess:

1. **The band was alive, worn, and streaming.** The accelerometer never faltered. This is not a
   dropped connection or an off-wrist window.
2. **The samples arrived — the values were zero.** `PpgWindowBuilder` (TitanCore/Windowing.swift) does
   **not** pad: it splits on gaps >2 s and builds from real samples. A full-length 2929-sample array at
   24 Hz means 2929 frames genuinely came off the band, each carrying a zero PPG value.
3. **`extractPPG()` returns 0 when none of `CFG.PPG_FIELDS` (`raw`/`vcPPG`/`filt`/`adc`) is present**
   (and sets `ppgFieldCode = 255`). So either the field vanished from the HRM-raw event, or the sensor
   genuinely read 0. **We cannot tell which from the stored data** — `ppgFieldCode` rides in the T1 frame
   header (byte 1) but is not preserved past the phone. *(A worthwhile follow-up: persist it.)*

So the 64 bpm was **hold-last-good being displayed as live**, not a mis-read. There was no signal to read.
Biosignal did the right thing at every step; nothing downstream is at fault.

**This is not new and not caused by this week's firmware work.** Across Alex's real history
(2,356 `ppg_raw` windows, from the pre-demo snapshot):

| outcome | n | median artifact |
|---|---|---|
| `short_window_aggregate_only` | 1382 | 0% |
| `invalid_signal` | 973 | **15.2%** |
| fully processed | **1** | — |

Only **89** of the rejected windows (9%) are at 100% artifact — this ride is one of those. See
[Open item 2](#2-973-windows-discarded-at-a-5-artifact-threshold) for the much larger, separate problem
that table exposes.

## Root cause: HYPOTHESIS, not established

`WORKOUT` is the **only** operating point that turns off the VC31B's adaptive green-LED loop and pins a
fixed current:

```js
REST:    { sampleRate: 25, ledCurrent: "auto", analysisDepth: "hr"   }
STILL:   { sampleRate: 25, ledCurrent: "auto", analysisDepth: "hr"   }
WORKOUT: { sampleRate: 50, ledCurrent: 0xE0,   analysisDepth: "full" }   // hrmGreenAdjust:false + hrmWr(0x17,0xE0)
```

The failure is workout-specific and begins ~2 s after that point is applied. Introduced in `58b5d26`
(the operating-point controller) — it predates this week.

**I have not proven it.** An over- or under-driven LED with the adaptive loop disabled is a plausible way
to blind the sensor, but "PPG reads zero" is also consistent with the HRM-raw event losing its field after
`Bangle.setOptions({hrmSportMode})` is applied to a *running* HRM (the poll rate doesn't change during a
workout, so `applyOperatingPoint` does **not** power-cycle it — see the `rateChanged` guard).

**The test that settles it** (needs the band, ~2 minutes):

```js
// mid-workout, once HR has gone flat:
setForcedOP({ id: "t", sampleRate: 50, ledCurrent: "auto", analysisDepth: "full" })
```

If the waveform returns → it's the LED, and the permanent fix is dropping `0xE0` rather than relying on
the watchdog. If it doesn't → look at the `setOptions`-on-a-running-HRM path instead. The diagnostics CSV
already logs `ledCurrent` per tick, so a profiler capture across the failure is decisive either way.

## The fix (`0bf129a`, firmware only — needs a REFLASH, not a TestFlight build)

### 1. Dead-PPG watchdog — deliberately cause-agnostic

`onHRMRaw` tracks a run of zero / `ppgFieldCode==255` samples. After `CONTROLLER.ppgDeadMs` (12 s) it hands
the LED back to the adaptive loop and power-cycles the HRM, rate-limited by `ppgRecoverCooldownMs` (60 s)
and counted as `state.ppgRecoveries` (new last column in the diagnostics CSV).

`ledAdaptiveOverride` **latches for the session** — without it the controller's next pass re-pins the fixed
current and blinds the sensor again. It resets in `startStreaming()`, so a new session gets the configured
operating point back and re-earns the override only if the sensor dies again.

This is the fix I have most confidence in *because* it doesn't depend on the hypothesis being right: it
recovers the session whatever the cause, and it turns a silent 21-minute failure into a counted, visible one.

### 2. The still-wrist gate was latching

`hrmAlgoModeFor()` dropped to normal mode whenever the wrist was still, unless `state.bpm >=
stillWorkHrMin` (100). But **`state.bpm` is the hold-last-good value**, so "never acquired" and "stale" are
indistinguishable from "genuinely resting". The comment justifying it — *"we enter the still phase FROM a
moving/elevated state, so state.bpm is a trustworthy 'still working' signal"* — does not hold for cycling:
the wrist is still on the bars from the first pedal stroke, so bpm never rises, normal mode under-reads,
and the under-read keeps bpm below the threshold. **Self-reinforcing for the whole session.**

Now a **fresh confident lock** is required before bpm is trusted to mean "at rest":

```js
if (!hrLockFresh()) return tag;   // no trustworthy HR → honour the workout's declared sport
```

`hrLockFresh()` reuses `lastGoodHrMs` + `CONTROLLER.holdMaxMs`, so "the UI stopped trusting this number"
and "the mode gate stopped trusting this number" can never disagree.

Asymmetry that justifies the default: sport mode while genuinely resting costs a slightly noisy resting HR
for one workout; normal mode while working costs the entire session.

---

## What I want challenged

1. **Is the watchdog's recovery action safe?** It calls `setHRMPower(0)` then `(1)` mid-session. Does that
   drop the HR series, disturb `state.workout`, or interact badly with `setRawCapture`'s listener
   discipline? I could not test this.
2. **Is `v === 0` the right death signal?** Can a legitimate PPG sample read exactly 0 for 12 s straight on
   a healthy sensor (very dark skin, extreme cold, a specific gain state)? If so the watchdog will
   power-cycle a working HRM once a minute. I chose 12 s to be well clear of noise, but I have no
   on-device distribution to justify the number.
3. **Does the latch leak?** `ledAdaptiveOverride` persists until `startStreaming()`. If a session can end
   and a new one begin without passing through `startStreaming` (auto-detect? sleep bursts?), the override
   would silently outlive its session and cost battery/quality.
4. **Is `hrLockFresh()` too permissive?** With no lock at all (`lastGoodHrMs == 0`) it returns false, so we
   keep sport mode. That's intentional for a ride, but it means a workout that starts genuinely at rest
   now sits in sport mode until the first confident lock. Is the noisy-resting-HR cost acceptable?
5. **`ppgFieldCode` is not persisted past the phone.** Worth adding? It would have collapsed the ambiguity
   in FACT-3 above into a one-line answer.

## Retracted — do NOT re-do this

Earlier in this investigation I reported, as a confirmed bug, that the session was **stamped 6 hours in
the future** (`started_at 20:24` for a 14:24 ride) and traced it to
`SealActivityJob:819 CarbonImmutable::createFromTimestamp($startEpoch, 'UTC')` being written without a
timezone conversion. **That was wrong.**

`activity_sessions` **deliberately stores UTC wall-clock**. It is documented in the same file
(`'ended_at' => ...->setTimezone('UTC')`, *"the activity_sessions convention (started_at is written from a
Zulu ISO; Strain/readers query with UTC bounds)"*) and `Support/Strain.php` queries it with `$startUtc` /
`$endUtc`. Alex's real sessions 41/42/43 all store `started_at` exactly 6 h ahead of the windows that fed
them. `20:24` was correct and consistent.

`device_ingestions` uses the **opposite** convention (app-tz, converted in `DeviceIngestionService`, with
its own explanatory comment). Two tables, two deliberate conventions — that is the trap I fell into.

I had changed the code, written a test asserting the wrong contract, and rewritten session 111's row.
`ActivitySealTest::test_guaranteed_write_then_late_windows_stays_one_row` caught the code change (the
guaranteed-write row is keyed on `started_at`, so shifting one path broke its merge with the window-based
seal, which builds `started_at` from a Zulu ISO). **All three are reverted**;
session 111 is back to `20:24:12`. My supporting argument — that `created_at < started_at` is "impossible"
— was also wrong: `created_at` is app-tz and `started_at` is UTC, so that gap is *expected* in a
negative-offset zone and is not a bug signature.

If a reviewer sees those 8 rows and reaches for the same fix: don't.

## Open items (NOT addressed by this commit)

### 1. The LED hypothesis is unconfirmed
See the on-device test above. Until it runs, the watchdog is a safety net over an unknown.

### 2. 973 windows discarded at a 5% artifact threshold
`ProcessWindowJob` (~line 168) classifies a non-valid window as `short_window_aggregate_only` only when
`artifact_pct <= 5.0`; otherwise `invalid_signal`, and it **nulls `ibi_ms` / `rmssd` / `resp_rate`**.

Distribution across the 973 rejected windows: **1% at 0–5%, 64% at 5–20%, 23% at 20–50%, 3% at 50–99%,
9% at 100%.** So the large majority carry modest artifact and probably usable beats, and are being thrown
away wholesale. This is a much bigger data-loss surface than the ride that started this investigation, and
it affects recovery/HRV rather than workouts. Deliberately untouched — it deserves its own review.

### 3. Duplicate `wearable_connections`
Profile 1 has 15 rows for `source=bangle`. Possibly related to the cross-band-binding work in `28236d1`.
Noted only; not investigated.

## Reproducing the evidence

```bash
# the session and its windows
docker compose -f docker-compose.prod.yml --profile blue exec -T app-blue php artisan tinker --execute='
  print_r(DB::table("activity_sessions")->find(111));
  print_r(DB::table("device_ingestions")->where("profile_id",1)->where("created_at",">=","2026-08-03")->get()->all());'

# the raw waveform (the decisive artifact)
... php artisan tinker --execute='
  $j = json_decode(gzdecode(Storage::disk("raw")->get(
      "raw/1/2026-08-03/c66be67f4b807fbc501862f2a4f3cd93.ppg.gz")), true);
  $p = $j["ppg"]; printf("zeros %d/%d\n", count(array_filter($p, fn($v)=>$v==0)), count($p));'
```

The pre-demo history table came from the profile snapshot at
`~/deploy/titan-demo/restored-*.sql.gz` (gzipped SQL; grep `INSERT INTO \`device_ingestions\``).

## Verification status

- Firmware: `node --check` passes; ES5-clean; reuses the `getTime()*1000` clock convention so the
  boot-floor and time-jump handling apply. **No on-device run.**
- PHP suite: unchanged by this commit — 41 distinct failures, identical set to the HEAD baseline
  (all environment-dependent: biosignal connections, filesystem writes).
- On-device acceptance: ride with it, then check `ppgRecoveries` in the diagnostics CSV. `0` with HR that
  tracks effort = fixed upstream (gate fix was enough). Non-zero with good HR = the watchdog is carrying
  it, and the LED hypothesis needs settling. Non-zero with still-bad HR = neither fix was the cause.
