# Titan watch→server data pipeline — canonical reference

**The ground truth for what the DIY band actually produces, end to end. Grounded in the real firmware
wire formats AND the measured statistical fingerprint of Alex's real nights (profile 1, 2026-07).**

Purpose: make local development and the LAB simulator FAITHFUL to reality. Every serious data bug this
project has hit came from the sim (or a fix) assuming a shape/characteristic the real watch doesn't have.
This is the spec the LAB must match. Update it by re-running `lab:pipeline-reference` (see
LAB_PIPELINE_FIDELITY.md) as real data grows.

---

## 1 · Frame inventory (firmware → upload)

The watch is a dumb sensor. Frames are newline-delimited `TAG:<base64>` over Nordic UART, except
`TA/TW/TN` = `TAG:<JSON>`. **Two transport states gate every frame: connected (live BLE) vs offline
(flash ring, drained oldest→newest on reconnect).** `T10`'s tag is two digits — split on the FIRST `:`.

| Tag | Represents | Wire | Emit condition | → upload kind | array |
|---|---|---|---|---|---|
| T1 | live raw PPG + per-sample accel | 16-B hdr + count×12 (relT u32, ppg i16, ax/ay/az i16) | **connected** + raw capture (workout or sleep burst) | `ppg_raw` (w/ `accel_mag_cg`) | windows |
| T2 | offline PPG log + actigraphy count | 20-B hdr + count×i16 ppg (no accel) | **offline** rest/sleep | `ppg_raw` (accel omitted) | windows |
| T4 | GPS fix | 24 B (sats, speed, ts, alt, lat, lon) | workout w/ GPS | part of `workout` | windows |
| T5 | on-chip HR reading | 12 B (bpm u8, conf u8, sport u8, ts u64) | conf≥90; 1 Hz live / 30 s offline rest | `hr_trend` | summaries |
| T6 | offline workout accel | 16-B hdr + count×3×i16 | **offline** + workout | `workout` | windows |
| T7 | baro altitude | 16-B hdr + count×i16 Δ | 1 Hz, warmed baro | `activity`(floors) — **iOS drops it today** | — |
| T8 | step day-total + local date | 12 B (steps u32, y/m/d, epoch) | **connected**, every 15 s | `activity` | summaries |
| T9 | confirmed sleep [bed,wake] | 12 B (confirmed u8, bed u32, wake u32) | WAKE tap on Sleep face | `sleep_session` → **SealNightJob** | summaries |
| **T10** | continuous overnight motion | 12 B (motion u16 = motionEMA×1000, ts u64) | **sleep session**, every 30 s, both states | `motion_trend` | summaries |
| TA | activity kind / end (JSON) | `{k:"run"\|"strength"\|"end"}` | workout | stamps `workout.activity_kind` | — |
| TW | workout envelope (JSON) | `{s,e,k,m}` | workout end | `workout_session` → **SealActivityJob** | summaries |
| TN | live sleep UX (JSON) | `{s,t}` / `{s,bed,wake}` | **connected** sleep taps | — (UX only) | — |
| TB/TS/TP | keepalive / drain-done / telemetry | — | connected | — | — |

**Batch envelope:** one item/batch, HMAC-signed pre-gzip (key = sha256(secret), material `"<ts>.<body>"`,
headers `X-Device-Id` + `X-Titan-Signature`), POST `/api/devices/ingest`, always 202. iOS `batch_uid` =
content SHA-256 (stable across retries → server dedups).

## 2 · Upload JSON shapes (what the server receives)

- `ppg_raw` (windows[]): `{kind, start(ISO), end(ISO), sample_rate_hz, ppg:[i16], accel_mag_cg:[int]?, src:"banglejs2"}` — `accel_mag_cg` = per-sample √(x²+y²+z²)/10 centi-g, **omitted when all-zero** (the offline-sleep shape).
- `workout` (windows[]): `{kind, start, end, accel_xyz:{x,y,z}, accel_fs, accel_unit:"mg", hr_bpm:[int], accel_counts:[int/30s], gps:{speed_kmh,grade,track:[{t,lat,lon,alt?}]}, src:"banglejs2", hr_source?, hr_rr_ms?, ended?, activity_kind?}`.
- `hr_trend` (summaries[]): `{kind:"hr_trend", samples:[{t:epochSec, bpm, conf}]}` — 1 point/minute (median).
- `motion_trend` (summaries[]): `{kind:"motion_trend", samples:[{t:epochSec, motion:int}]}` — 1 point/epoch.
- `activity` (summaries[]): `{kind:"activity", date:"YYYY-MM-DD", steps}` (+ optional `altitude_m` for floors).
- `sleep_session` (summaries[]): `{kind:"sleep_session", confirmed:true, bedtime:epochSec, wake:epochSec}`.
- `workout_session` (summaries[]): `{kind:"workout_session", confirmed:true, start, end, activity_kind?, manual}`.

## 3 · Server → DB result

- windows → `device_ingestions` row (kind, window_start/end app-tz, result_refs json, status
  received→queued→processing→processed→sealed) + raw blob on the `raw` disk + `ProcessWindowJob`.
- `ppg_raw`/`ibi` → biosignal HRV → `recovery_logs` + per-window `result_refs` (below); seal → `sleep_logs`.
- `workout` → `SealActivityJob` → `activity_sessions`.
- `hr_trend` → `hr_samples` (recorded_at **connection tz**, unique profile+recorded_at).
- `motion_trend` → `motion_samples` (recorded_at **APP tz** — matches window_start; unique profile+recorded_at).
- `activity` → `daily_activity` (per-day MAX merge).

### result_refs per ppg_raw window (post ProcessWindowJob)
`{rmssd, sdnn?, resp_rate?, artifact_pct, ibi_ms:[~26], epoch_hr:[1], epoch_motion:[1], epoch_rmssd:[1],
recovery_log_id?, sleep_log_id?(sealed), sealed?, skipped?}`
**Key fact: one ppg_raw window = ONE 30 s epoch** (epoch_* arrays are length 1). A night is ~100-190 of
these sparse bursts, NOT a continuous stream.

## 4 · The REAL statistical fingerprint (measured, profile 1 — the LAB MUST match these)

| Quantity | Real value | Note |
|---|---|---|
| ppg_raw window length | **29 s** | one duty-cycle burst |
| epochs per ppg_raw window | **1** | `epoch_hr[1]`, `epoch_motion[1]` |
| IBI beats per burst | **~26** | `ibi_ms[26]` |
| overnight coverage | **~100-190 bursts/night** (~15-30% of epochs raw) | duty-cycled; rest hole-filled |
| **HR jitter** | **median \|ΔHR\| ~7 bpm, p90 ~18** | THE thing that over-staged REM; sim had clean HR |
| epoch HR range | ~40-90 bpm | |
| epoch_rmssd (post-fix) | ~40-180 ms (p50 ~120) | was pinned 250 pre-fix |
| **epoch_motion (proxy)** | **np.std scale, ~1.5-3 (p50 ~1.8)** | from accel_mag_cg / ppg-quality |
| **T10 motion_samples (dense)** | **milli-g EMA scale, ~14-199 (p50 ~31)** | DIFFERENT UNIT from epoch_motion! 80% coverage |
| T10 overnight coverage | **~80%** (1 per 30 s) | vs ~15-30% for the burst proxy |

**Two motion channels, two scales, two coverages.** Any code (stager, viz, LAB) touching motion must know
which channel it has. The proxy (~1-3) and T10 (~14-199) are NOT interchangeable raw.

## 5 · Where the LAB diverges from reality (fix these — see LAB_PIPELINE_FIDELITY.md)

1. **VirtualBand defaults to `kind=ibi` — the real band NEVER emits `ibi`.** Overnight is `T2 → ppg_raw`
   (server peak-detects). `ibi`+`confidence` is a shape iOS never produces. → stream `ppg_raw`.
2. LAB never emits **`hr_trend`(T5)** or **`motion_trend`(T10)** → `hr_samples`/`motion_samples` stay
   empty → LAB nights have **empty HR/motion strips** and can't exercise the dense-motion stager/timeline.
3. LAB never emits **`activity`(T8 steps)**, T4 GPS-only, T7 floors.
4. LAB HR is CLEAN — real HR has median \|ΔHR\| ~7 jitter. The sim must inject the measured jitter or it
   will never reproduce the staging behavior of a real night.
5. Motion scale/units unmodeled — LAB must emit BOTH the proxy-scale accel and the T10 milli-g EMA.
6. `src:"simulator"` (workout) vs real `"banglejs2"`; fresh ULID vs content-SHA batch uid.

---

*If it doesn't match section 4's fingerprint, the sim is lying. Validate the LAB against these numbers, not
against a clean idealized night.*
