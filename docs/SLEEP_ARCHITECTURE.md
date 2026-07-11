# How sleep works in Titan — end to end

**The canonical "how does sleep tracking actually work" reference: from the DIY band on the wrist to the
staged hypnogram, the nap sessions, the movement timeline, and the simulator that keeps it honest.**

This is the story we finally cracked in 2026-07. It ties together the two deeper references — the wire
formats + measured fingerprint in **[DATA_PIPELINE_REFERENCE.md](DATA_PIPELINE_REFERENCE.md)** and the
duty-cycle stitching in **[SEAL_ARCHITECTURE.md](SEAL_ARCHITECTURE.md)** — into one narrative. Read this
first; drop into those two for the gory details of a layer.

> **The core philosophy: a dumb sensor, a smart server.** The watch does as little as possible — sense,
> timestamp, ship. Every interpretation (peak-detect HR, HRV, sleep staging, session stitching) lives on
> the server, where it can be fixed, re-run against stored raw data, and validated. The watch never
> decides your sleep stages; it just faithfully reports what your body did.

---

## 1 · The wrist: how the band senses sleep

The band is a Bangle.js 2 (a hackable smartwatch) running `firmware/banglejs/titan.app.js`. Overnight it
captures two raw signals:

- **PPG** (green-light photoplethysmography) — the optical pulse at the wrist. Peaks in the PPG are
  heartbeats; the gaps between them are inter-beat intervals (IBI), the basis for HR and HRV.
- **Accelerometer** — 3-axis motion. Movement is the single strongest sleep/wake signal (a still body is
  probably asleep; a moving one is awake), and per-epoch movement is what separates restless from calm.

**Duty-cycling is the defining constraint.** Running the PPG LED + HR algorithm continuously would drain
the tiny battery in a few hours. So overnight the band **samples in short bursts** — roughly a **29-second
burst every few minutes** — and sleeps the sensor in between. A night therefore is **not** a continuous
stream; it's **~100–190 sparse bursts** scattered across bed→wake, covering only ~15–30% of the wall-clock
in raw PPG. Everything downstream is built around reconstructing a whole night from these sparse bursts.

**The Sleep session.** The user taps START on the watch's Sleep face (`startSleepSession`), which puts the
band into its low-power overnight mode, and taps WAKE in the morning. That WAKE tap is the **confirmed
[bed, wake] marker** — the thing that tells the server "seal this as a real night now."

**Two transport states gate everything:**
- **Connected** (phone in BLE range): frames stream live over Nordic UART.
- **Offline** (phone out of range / asleep across the room): frames are written to a **flash ring buffer**
  on the watch and drained oldest→newest on the next reconnect (store-and-forward).

---

## 2 · The frames: what the wrist actually emits

Frames are newline-delimited `TAG:<base64>` (a few are `TAG:<JSON>`). The full inventory + byte layouts
are in [DATA_PIPELINE_REFERENCE.md §1–2](DATA_PIPELINE_REFERENCE.md); the sleep-relevant ones:

| Frame | What | When | Becomes |
|---|---|---|---|
| **T1** | live raw PPG + per-sample accel | connected sleep burst | `ppg_raw` window (with motion) |
| **T2** | offline PPG log (no accel) | offline sleep burst | `ppg_raw` window (accel omitted) |
| **T5** | on-chip HR reading | ~1 Hz live / 30 s offline | `hr_trend` → the 24/7 HR graph |
| **T9** | confirmed sleep `[bed, wake]` | the WAKE tap | `sleep_session` → **triggers the seal** |
| **T10** | continuous overnight motion | every 30 s during sleep, connected-or-offline | `motion_trend` → the dense movement strip |

Two hard-won details live here:
- **T10 is two digits.** A router that split the frame tag on a fixed 3-char prefix (`"T1:"`) silently
  dropped `"T10:"`. Parse the tag as the text *before the first `:`*. (This bit us — the whole T10 chain
  was dead until the routers on iOS + the sim were generalized.)
- **T10 must emit connected-or-offline.** The first cut gated it to offline only (`if (state.connected)
  return`), so anyone who slept with their phone by the bed produced **zero** dense motion. It now emits
  live over BLE when connected and to the ring when offline.

---

## 3 · Ingestion: frames → the server

The band (or, for the recovery band, the iOS `FrameRouter` / a Web-Bluetooth bridge) reassembles frames
into **windows** and **summaries**, batches them, **HMAC-signs** each batch (key = `sha256(secret)`, over
`"<ts>.<body>"`), and POSTs to **`/api/devices/ingest`**. `batch_uid` is a content hash so a retry dedups
instead of double-ingesting.

`DeviceIngestionService` routes by array:
- **`windows[]`** (`ppg_raw`, `workout`) → a **`device_ingestions`** row (kind, `window_start`/`end`, status
  `received→queued→processing→processed→sealed`) + the raw blob on the `raw` object store + a
  **`ProcessWindowJob`**.
- **`summaries[]`** (`hr_trend`, `motion_trend`, `sleep_session`, `activity`, …) → written straight to their
  target tables (`hr_samples`, `motion_samples`, `daily_activity`) or, for `sleep_session`, used to
  **trigger the night seal**.

---

## 4 · Per-window processing: bursts → epoch features

`ProcessWindowJob` sends each `ppg_raw` window to the Python **biosignal** service (`biosignal/app/core`),
which peak-detects the PPG, computes HRV, and writes per-epoch features into the window's `result_refs`:

```
result_refs = { rmssd, artifact_pct, ibi_ms:[~26], epoch_hr:[1], epoch_motion:[1], epoch_rmssd:[1], … }
```

**The single most important fact about the whole system: one `ppg_raw` window = ONE 30-second epoch.** The
`epoch_*` arrays are length 1. A night is ~100–190 of these sparse single-epoch bursts — not a continuous
series. Every "why is my night 17 minutes long" bug has been code that concatenated bursts instead of
placing them at their real clock positions.

Two motion measurements exist, and **they are different channels at different scales — never mix them raw:**
- **`epoch_motion` (the proxy)** — `np.std` of the burst's accel (or `(1 − ppg_quality)` when offline
  T2 carries no accel). Scale ~1.5–3. Sparse (only where a burst landed).
- **T10 `motion_samples` (dense)** — the band's `motionEMA` (milli-g EMA of |Δaccel|). Scale ~14–199, and
  **~80% coverage** (one per 30 s). This is the channel that powers the dense movement strip.

---

## 5 · The seal: sparse bursts → one saved session

`SealNightJob` (fired by the T9 marker, or by an hourly cron for offline nights) turns the sparse windows
into a saved `sleep_logs` row. The full mechanics are in [SEAL_ARCHITECTURE.md](SEAL_ARCHITECTURE.md); the
essentials:

- **Reconstruction (`stageSparse` → biosignal `staging.py`).** Each burst's epoch is placed at its **real
  index** in the night (`i = (window_start − bed) / 30 s`), NOT concatenated. Short gaps between bursts are
  **held** (a quiet stretch between asleep samples reads asleep); long gaps stay **NODATA holes** — never
  smeared into fabricated sleep.
- **Night vs nap = sessions.** A confirmed session under ~4 h is a **nap**: its own `sleep_logs` row keyed
  on `session_start`, so it never clobbers the overnight. A full night keys on `slept_at` (the wake date).
  This is what makes "sleep as sessions" possible (see §7).
- **Timezone is stored profile-local.** `window_start` and nap `session_start` are stored in the profile
  tz (e.g. Tijuana), NOT the app tz — read them back with `getRawOriginal(...)` parsed in the profile tz,
  never the naive Eloquent cast. This trap has bitten every layer.

---

## 6 · Staging: epochs → a hypnogram (the part we finally cracked)

The staged four-stage hypnogram (wake / light / deep / REM, one code per 30 s) comes from a trained model
in `biosignal/app/core/staging.py` — a `HistGradientBoostingClassifier` cross-validated on real
polysomnography (PhysioNet Walch 2019). It reads **motion + HR features only** (rolling HR std at 5/11/21
epochs, HR gradients, detrended surges — plus per-epoch motion). No EEG; this is literature-grade for
wrist motion+HR.

**The bug that defined this project: our band's HR jitter reads as REM.** The model's entire REM signal is
*HR variability*. It was trained on clean averaged Apple-Watch HR. But our band's duty-cycled PPG HR
**jitters** epoch-to-epoch (measured **median |ΔHR| ≈ 7 bpm, p90 ≈ 18**, with spikes like 58→86→49→88) —
device sampling noise, not autonomic signal. The model reads that jitter as REM and over-stages it to
**~48%** on real nights, while under-calling deep.

The fixes, in the order they were proven on real nights:
1. **Denoise the RAW readings before reconstruction** (`_denoise_hr`). A Hampel filter rejects spike
   readings that sit >3 robust-σ from their neighbours, then a light window-3 median tamps residual jitter
   — applied to the **real sparse readings**, *before* they're scattered + hold-filled into the epoch grid.
   (Filtering the reconstructed step grid does nothing — a rolling median can't remove a sustained held
   step. This is the subtlety that made the first fix "pass in the isolated test, fail in production.")
2. **Apply the shipped Viterbi.** The model bundle ships a `logT` transition matrix and was validated *with*
   temporal smoothing, but inference used raw per-epoch argmax and ignored it — a train/inference mismatch
   that let impossible bouts through (a 69-minute continuous-REM block). Decoding with `logT` enforces
   realistic stage durations.
3. **Low-confidence flag.** Some nights are a per-night model outlier no HR filter can fix. When the split
   itself is physiologically implausible (REM < 5% or a single stage > 70%), `stages_low_confidence` marks
   the night so the coach/UI caveats it instead of stating it as fact.
4. **The durable fix (in progress): retrain on band-realistic HR** — augment the PSG training HR with the
   band's measured jitter so train and inference distributions match (see `tasks/specs/RETRAIN_SLEEP_STAGER.md`).

Two motion channels feed the picture: the seal **prefers the dense T10 channel** for the movement strip
when it exists (it's continuous), falling back to the sparse burst proxy — and the natural next lever is to
feed that dense motion to the *stager* too (`tasks/specs/DENSE_MOTION_TO_STAGER.md`), because deep needs the
sustained-stillness signal the sparse hold-fill degrades.

---

## 7 · Sessions & the daily total (sleep is like workouts)

Sleep is not "last night" — it's **every session you actually slept**. `SleepDetail::sessionsForDay`
returns the day's overnight **plus each nap** as individual, revisitable sessions (each with its own
stages + hypnogram + movement/HR timeline), above a **daily aggregate** (total asleep = Σ sessions,
combined stage minutes). A nap **adds to the total and chips into sleep debt** but is never scored as a
night (its performance/need/debt stay null so it can't inflate the picture). Grouped on the user's local
day. `/api/me/sleep` exposes this as `today: { sessions[], aggregate }`.

---

## 8 · The timeline: seeing the night

The v2 sleep timeline (`ios/.../Design/Components.swift` `SleepTimeline`) is three aligned layers on one
clock axis:
- the **stage ribbon** (a stepped four-lane hypnogram, depth reads as depth),
- the **movement strip** (the dense T10 motion, normalized to the night's own max — restless vs calm),
- the **HR overlay** (per-epoch peaks).

All three share the `hypnogram`'s epoch grid (epoch *i*'s clock = `epoch_sec + i·30`), and **only measured
epochs are drawn** — a duty-cycle/charge gap is an honest hole, never interpolated.

> Gotcha that hid the whole thing: the v2 server change dropped the per-stage `color` field, but the iOS
> model still *required* it — so the entire `SleepResponse` decode threw and the timeline silently
> vanished. Fields the server may omit must be optional on the client.

---

## 9 · The simulator & the LAB: keeping it honest

The reason sleep was so hard to get right: **fixes kept passing in the simulator and failing on the
watch.** The SLEEP LAB simulated *physiology* (stages, sessions) but not the *wire pipeline* with the real
watch's characteristics — clean HR, no jitter, the wrong frame kind, no dense motion. So it couldn't
reproduce the very bugs that mattered.

The fix (`tasks/specs/LAB_PIPELINE_FIDELITY.md`) makes the sim faithful:
- **`VirtualBand`** (`app/Services/Lab/`) is the firmware's method actor: it renders a `NightScript` into
  the exact wire windows a real night arrives as (now **`ppg_raw`, not the synthetic `ibi`**), emits the
  summary channels it used to skip (**`hr_trend`, `motion_trend`, `activity`**), and **injects the measured
  HR jitter** as a knob (`--jitter=real`) so a LAB night reproduces a real night's staging.
- **`PipelineFingerprint`** measures what actually reached the server (jitter distribution, burst geometry,
  both motion channels + coverage) — the same introspection `lab:pipeline-reference` uses to snapshot a
  real profile into `biosignal/tests/fixtures/pipeline_reference.json`.
- **`FidelityGate`** asserts a rendered LAB night matches that reference within tolerance, as a section of
  the LAB scorecard. **This is the gate that would have caught every sim-vs-real gap.** Proven: with
  `--jitter=real`, the LAB now reproduces the real REM-over-staging (rem 29% vs a clean 9%) — the bug is
  finally reproducible locally.

**Run it:** `php artisan sleep:lab --scenario=perfect-night` (idealized) or `--jitter=real` (reproduce the
real wrist). Refresh the reference from real data: `php artisan lab:pipeline-reference --profile=1`.

---

## 10 · The hard-won lessons (don't relearn these)

1. **The sim must match the wire, not just the physiology.** If the LAB doesn't reproduce
   [DATA_PIPELINE_REFERENCE.md §4](DATA_PIPELINE_REFERENCE.md)'s fingerprint, it's lying — validate against
   the fidelity gate, not a clean idealized night.
2. **A night is sparse bursts, not a stream.** One `ppg_raw` window = one epoch. Place bursts at their real
   clock index; never concatenate.
3. **Two motion channels, two scales, two coverages.** The `epoch_motion` proxy (~1–3) and T10 (~14–199)
   are not interchangeable raw — code that touches motion must know which it has.
4. **HR jitter is not autonomic signal.** The band's duty-cycled HR jitters; the stager reads that as REM.
   Denoise the raw readings *before* reconstruction (not the hold-filled grid).
5. **Timezone is stored profile-local.** Read `session_start`/`window_start` in the profile tz via the raw
   value, never the app-tz Eloquent cast (Tijuana ≠ Mexico_City).
6. **Validate on real nights, not held-out PSG.** The isolated model test passed while production failed;
   the real-night regression (`biosignal/tests/fixtures/real_nights_profile1.json`) is the load-bearing check.

---

## Key files

| Layer | Where |
|---|---|
| Firmware (frames, duty-cycle, T10) | `firmware/banglejs/titan.app.js` |
| iOS frame routing / upload | `ios/Titan/Sources/BandSync/FrameRouter.swift`, `TitanCore/…/FrameDecoder.swift` |
| Ingestion | `app/Services/Wearables/DeviceIngestionService.php` |
| Per-window HRV / features | `biosignal/app/core/hrv.py` |
| Seal (reconstruction, night/nap) | `app/Jobs/SealNightJob.php`, `docs/SEAL_ARCHITECTURE.md` |
| Staging (denoise, Viterbi, flag) | `biosignal/app/core/staging.py` |
| Sessions API + aggregate | `app/Support/SleepDetail.php`, `app/Http/Controllers/Api/MobileSleepController.php` |
| Timeline UI | `ios/Titan/Sources/Design/Components.swift` (`SleepTimeline`) |
| Simulator / LAB fidelity | `app/Services/Lab/`, `app/Support/Lab/`, `app/Console/Commands/SleepLab.php` |
| Wire reference + fingerprint | `docs/DATA_PIPELINE_REFERENCE.md` |

*We spent months thinking the sleep algorithm was wrong. It wasn't — the band's HR jitter was reading as
dream sleep, and the simulator was too clean to show it. Make the sim tell the truth about the wrist,
denoise the signal the model reads, and the night reads right. That's how sleep works.*
