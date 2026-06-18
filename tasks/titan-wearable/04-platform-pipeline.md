# 04 — Platform & Pipeline Plan

*Target stack (existing Titan): Laravel 13 / PHP 8.4, MySQL 8.4, Redis, Docker. Tables already present:
`recovery_logs`, `sleep_logs`, `workouts`, `body_metrics`, `wearable_connections`. Services: `TerraClient`
(reuse its HMAC verifier), `CoachService`, `AiService`.*

**Design principle:** the open-source band is *just another ingestion source*. Our API accepts **raw** PPG/IBI/
accel (which no vendor gives you) and routes it through a Python biosignal service into the **same Titan tables**.

## 1. End-to-end architecture
```
Band ──BLE(IBI+accel)──▶ Phone bridge ──HTTPS signed batches──▶ Laravel Ingestion API
 EDGE: peak-detect, motion-gate,                              1. verify HMAC  2. dedupe(batch_uid)
       store-and-forward buffer                              3. raw → MinIO   4. queue
                                                                    │
   device_ingestions (MySQL ledger) ◀──▶ MinIO (raw/{profile}/{date}/*.ndjson.gz)
                     │ dispatch ProcessWindowJob
                     ▼
   Laravel worker (BiosignalClient) ──HTTP──▶ FastAPI: /process/{hrv|sleep|activity}
                     │  metrics (idempotent upsert)            NeuroKit2 · Walch · TRIMP
                     ▼
   recovery_logs / sleep_logs / workouts ──event MetricsProcessed──▶ CoachService (briefing, nudges)
```
**Edge vs cloud:** on the band → PPG peak-detect to **IBI**, accel **activity counts**, motion-gating,
store-and-forward. In the cloud → heavy DSP: NeuroKit2 HRV (rejects IBI <300/>2000 ms, clips >250 ms jumps),
Walch-style actigraphy sleep staging. Default ships IBI+accel; optionally a short raw PPG snippet for audit/
reprocess. API accepts all three shapes.

## 2. Device-agnostic ingestion API
**Generalize `wearable_connections`** (Terra-shaped → source-agnostic): add `source` (terra|titan_band|bangle|
polar|apple_health), `device_token_hash` (sha256 of per-device secret), `device_id`, `timezone`; make
`terra_user_id` nullable.

**Auth — per-device tokens + signed payloads (reuse Titan's existing HMAC):**
1. Pair a band → issue `device_id` + 32-byte secret (shown once, stored as hash).
2. Each request: `X-Device-Id` + `X-Titan-Signature: t=<ts>,v1=<hmac>` where `hmac = HMAC_SHA256("<ts>.<body>",
   secret)` — **identical to `TerraClient::verifySignature()`**, one verifier covers both. Reject if `|now−ts|>300s`.

**`POST /api/devices/ingest`** accepts one of three shapes (batched, gzip NDJSON):
```jsonc
// A — IBI/RR + accel (band default, cheapest)
{ "batch_uid":"ULID", "device_id":"tb_…", "timezone":"America/Mexico_City",
  "windows":[{ "kind":"ibi", "start":"…Z","end":"…Z",
    "ibi_ms":[812,798,805,1190,801], "accel_counts":[3,1,0,0,2], "confidence":0.94 }] }
// B — raw PPG waveform (optional snippets for audit/reprocess)
{ …, "windows":[{ "kind":"ppg_raw","sample_rate_hz":64,"ppg":[…int16…],"accel_xyz":[[…]] }] }
// C — summary metrics (Apple Health export, Polar nightly, any provider)
{ …, "summaries":[
    {"kind":"sleep","date":"2026-06-14","duration_min":433,"deep_min":61,"rem_min":95,"light_min":255,"awake_min":22,"bedtime":"23:48","wake_time":"07:01","quality":78},
    {"kind":"recovery","date":"2026-06-14","hrv_ms":64,"resting_hr":52},
    {"kind":"body","taken_at":"…Z","weight_kg":78.4,"body_fat_pct":14.2} ] }
```
Response always **202** (async): `{accepted, batch_uid, windows_queued, windows_rejected, duplicate}`.
- **Idempotency:** `batch_uid` UNIQUE in `device_ingestions`; dup returns 202 `duplicate:true`, queues nothing
  (Redis `Cache::lock("ingest:$uid")` for atomicity).
- **Signal sanity gate (`WindowSanity`):** the HMAC proves WHO sent a batch, not that the signal is real. Each
  raw window is sanity-checked BEFORE it's stored/queued; clearly-corrupt windows are dropped (counted in
  `windows_rejected`, logged with a reason) while clean windows in the same batch still flow. Drop reasons:
  `ppg_flatline` (sensor saturated/detached — all samples identical), `ppg_non_finite` (NaN/Inf), `ppg_too_short`
  (<8 samples), `bad_sample_rate` (missing or outside 10–1000 Hz), `sample_rate_mismatch` (claimed rate vs
  timestamp-implied rate off by >2×, i.e. a wrong clock that would poison beat timing), `ibi_empty`,
  `ibi_implausible` (no interval in 250–2500 ms), `ibi_flatline` (≥6 identical intervals), `ibi_non_finite`,
  `end_before_start`, `timestamp_in_future` (>1 day ahead), `window_too_long` (>6 h). It's deliberately
  conservative — real-but-noisy signal passes through to the DSP's own quality gates, which remain the authority
  on "is this HRV trustworthy". **Firmware should still self-gate** (don't stream a detached-sensor flatline), but
  the server no longer trusts the wire blindly.
- **Limits:** ≤5 MB gzip, ≤500 windows; raw PPG (B) tighter (60/profile/hr). **Time zones:** UTC on the wire +
  IANA tz → server computes the correct calendar date for `slept_at`/`logged_at`. **Rate:** throttle by device_id.
- **Supporting:** `POST /api/devices/pair`, `DELETE /api/devices/{id}`, `GET /api/devices/ingestions?since=`
  (store-and-forward catch-up), `POST /api/devices/{id}/reprocess`.

## 3. Raw data storage
**MinIO object storage for raw waveforms + MySQL `device_ingestions` ledger. Not a TSDB** (raw = write-once/
read-rarely blobs; TSDB ingest/index cost isn't justified; keeps OLTP DB lean).
- Layout: `raw/{profile_id}/{yyyy-mm-dd}/{batch_uid}.ndjson.gz` (+ `.ppg.gz`). Wire as a Laravel S3 disk (`raw`).
- **Ledger** `device_ingestions`: `batch_uid` (unique ULID), profile_id, source, kind, object_key, window_
  start/end, status (received|queued|processed|failed), `algo_version`, `result_refs` (json).
- **Retention:** raw PPG → 30 d (audit); IBI/accel → 180 d (one reprocess cycle); metrics → forever. Nightly
  `prune:raw`.

## 4. Processing pipeline
**Laravel queued jobs (Redis) call FastAPI over HTTP** (keep the boundary an HTTP contract — each side
independently testable, Python runs anywhere).
1. Controller: persist raw → MinIO, insert ledger (`received`), dispatch `ProcessWindowJob` → `biosignal` queue,
   `status=queued`, return 202.
2. Worker: load window from MinIO → `BiosignalClient` → FastAPI `/process/{kind}` → **idempotent upsert** into
   canonical table → update ledger (`processed`, algo_version, result_refs).
```php
// BiosignalClient (bare Http, mirrors TerraClient)
Http::baseUrl(config('services.biosignal.url'))->withToken(config('services.biosignal.token'))
    ->timeout(60)->retry(2,500)->post('/process/hrv',$window)->throw()->json();
```
- **Batch windows:** HRV over a sleep-stable window → one `recovery_logs` row/date. **Sleep:** don't process
  incrementally — `SealNightJob` fires when no new sleep windows >45 min after a long quiescent block →
  `ProcessSleepJob` hands the whole night → one `sleep_logs` row. **Activity:** accel-count windows >threshold
  for ≥10 min → a `workouts` row (unless user already logged one).
- **Idempotent writes (natural keys):** `RecoveryLog::updateOrCreate(['profile_id'=>$p,'logged_at'=>$d],[…])`;
  same for sleep. Add a provenance column (`updated_via`) so wearable data never clobbers subjective ratings
  (wearable fills hrv_ms/resting_hr/stages; stress/mood/energy stay user-owned).
- **Reprocess on algorithm upgrade:** bump `BIOSIGNAL_ALGO_VERSION`; `php artisan biosignal:reprocess --from=…
  --kind=sleep` re-dispatches where `algo_version < current`; `updateOrCreate` corrects rows in place (why we
  keep raw 180 d).

## 5. The Python biosignal service (FastAPI)
```
biosignal/  Dockerfile · pyproject (fastapi,uvicorn,neurokit2,numpy,scipy,pandas)
  app/main.py (bearer auth, /health)
  routers/ hrv.py (NeuroKit2) · sleep.py (Walch actigraphy; YASA if EEG) · activity.py
  core/ hrv.py · staging.py · activity.py    models/ (joblib weights)
```
Stateless pure functions (no DB access from Python):
```jsonc
// POST /process/hrv → {algo_version, metrics:{hrv_ms,resting_hr,rmssd,sdnn,pnn50,lf_hf,artifact_pct,valid}}
// POST /process/sleep → {algo_version, metrics:{duration_min,deep/rem/light/awake_min,bedtime,wake_time,quality,hypnogram_30s}}
```
HRV uses NeuroKit2 PPG→IBI cleaning + time/freq HRV. Sleep = **Walch-style activity-count + HR classifier**
(YASA reserved for a future EEG headband). **Deploy as a docker-compose service** `biosignal` (internal-only,
`expose: 8000`, on the `sail` network; Laravel reaches `http://biosignal:8000`). Config in `services.biosignal`.

## 6. Realtime + scheduled
- **`MetricsProcessed(profile,kind,date)` event** on write → broadcast over Reverb (live UI refresh) + notify
  `CoachService`.
- **Scheduler** (`routes/console.php`): `biosignal:seal-nights` hourly; `recovery:compute-daily` 05:30;
  `coach:morning-briefing` 06:30 (per-profile tz); `prune:raw` 03:00. Morning briefing asks AiService/CoachService
  to generate a proactive message from last night's metrics.

## 7. Security & privacy (sensitive health data)
- TLS external (Caddy auto-HTTPS); internal Laravel↔FastAPI on the private network + bearer-gated.
- MinIO SSE; encrypted MySQL volume; optional app-side per-profile encryption of raw blobs before upload.
- Per-profile namespacing on every raw object + query scope; device tokens 1:1 to a profile; HMAC + 5-min replay
  window + constant-time compare. Instant revocation (null the hash).
- **Privacy by self-hosting:** biosignals never leave the user's own server (no third-party cloud unless they
  opt into Terra). `export` command (all raw + processed) and `forget` (cascade hard-delete).

## 8. Self-hostable deployment (one docker-compose)
Extend existing `compose.yaml` with `minio` + `biosignal` + a `queue` worker (`php artisan queue:work redis
--queue=biosignal,default`); mysql/redis/laravel already there. Stranger's path: `git clone` → set MINIO/
BIOSIGNAL env → `sail up -d` → `migrate` → create bucket → POST a sample batch (seeder fixtures).

## 9. Phased pipeline roadmap
| Phase | Deliverables | Diff |
|---|---|---|
| **P0** | Migration extending `wearable_connections` + `device_ingestions`; `POST /devices/ingest` (A/B/C) + HMAC + idempotency + tz; MinIO `raw` disk; seeder fixtures; **Shape-C summaries write straight to tables** (Apple Health/Polar work day one, no Python) | **4** |
| **P1** | FastAPI `biosignal` + compose service; `/process/hrv` (NeuroKit2 RMSSD/SDNN); `BiosignalClient` + `ProcessWindowJob`; idempotent `recovery_logs` write; `queue` container. E2E: simulated IBI → `recovery_logs.hrv_ms` | **6** |
| **P2** | `SealNightJob` + `ProcessSleepJob`; `/process/sleep` (Walch); raw retention + `biosignal:reprocess` w/ algo_version; provenance columns | **8** |
| **P3** | `MetricsProcessed` event + Reverb live UI; scheduled recovery/briefing/prune (per-tz); coach consumes metrics; `export`/`forget`; one-command self-host | **7** |

**Order rationale:** P0 = working device-agnostic intake (handles Apple Health/Polar summaries immediately),
zero Python risk. P1 proves the Laravel↔FastAPI boundary on the simplest algorithm. P2 is hardest (sleep + night
boundaries + reprocess). P3 = realtime/coach value once data flows.
