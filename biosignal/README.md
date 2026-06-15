# Titan Biosignal Service

Stateless **FastAPI** microservice that turns raw wearable signals (PPG / IBI / accel)
into the metrics Titan stores in `recovery_logs`, `sleep_logs`, and `workouts`. It is the
heavy-DSP half of the pipeline in
[`tasks/titan-wearable/04-platform-pipeline.md`](../tasks/titan-wearable/04-platform-pipeline.md) §5;
algorithms follow [`03-algorithms.md`](../tasks/titan-wearable/03-algorithms.md).

It does **no DB access** — Laravel's `BiosignalClient` calls it over the private docker
network (`http://biosignal:8000`) with a bearer token, and writes the results itself.

## Endpoints

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/health` | Liveness (no auth). `{status, algo_version}` |
| `POST` | `/process/hrv` | Overnight HRV from an IBI window **or** raw PPG → NeuroKit2 |
| `POST` | `/process/sleep` | Walch-style actigraphy+HR sleep staging (v1 baseline) |
| `POST` | `/process/activity` | Accel-count session detection + TRIMP/calorie estimate |

All `/process/*` routes require `Authorization: Bearer $BIOSIGNAL_TOKEN`
(mirrors `Http::withToken(...)`). If `BIOSIGNAL_TOKEN` is unset, auth is disabled (dev/test).

### `POST /process/hrv`

Request (shape A — IBI, band default):
```json
{ "ibi_ms": [812,798,805,1190,...], "accel_counts": [3,1,0,...],
  "start": "2026-06-14T05:00:00Z", "end": "2026-06-14T13:00:00Z" }
```
Request (shape B — raw PPG snippet, audit/reprocess):
```json
{ "ppg": [512,540,600,...], "sample_rate_hz": 64,
  "start": "...Z", "end": "...Z" }
```
Response:
```json
{ "algo_version": "hrv-1.0.0+sleep-0.1.0+activity-0.1.0",
  "metrics": { "hrv_ms": 42.5, "resting_hr": 53.0, "rmssd": 42.5, "sdnn": 58.1,
    "pnn50": 18.3, "lf_hf": 1.42, "artifact_pct": 0.61, "valid": true,
    "n_beats_raw": 28800, "n_beats_clean": 28600 } }
```
- `hrv_ms` == whole-night **RMSSD** (the recovery north-star; map to `recovery_logs.hrv_ms`).
- `resting_hr` = min of windowed (30-beat) medians (`recovery_logs.resting_hr`).
- `valid=false` when the signal is too poor to trust (few beats, high artifact %, low PPG
  SQI) — **the caller should skip the upsert when `valid` is false.**

### `POST /process/sleep`

Request: whole-night per-30s-epoch `accel_counts` (+ optional `hr_bpm`) + `start`/`end`.
Response `metrics`: `duration_min, deep_min, rem_min, light_min, awake_min, bedtime,
wake_time, quality (0-100), hypnogram_30s[]`.

### `POST /process/activity`

Request: per-30s-epoch `accel_counts` (+ optional `hr_bpm`, `hr_max`, `hr_rest`,
`weight_kg`). Response `metrics`: `sessions[]` (each with `start/end/duration_min/
intensity/trimp/calories_kcal`) + totals.

Full canonical request/response pairs live in [`sample_payloads.json`](./sample_payloads.json).

## Run locally

```bash
python -m venv .venv && . .venv/bin/activate
pip install ".[dev]"
BIOSIGNAL_TOKEN=dev-token uvicorn app.main:app --reload --port 8000
pytest -q
```

## Docker

```bash
docker build -t titan-biosignal .
docker run -e BIOSIGNAL_TOKEN=secret -p 8000:8000 titan-biosignal
```

Intended deployment: a `biosignal` service on the existing compose `sail` network,
`expose: 8000` (internal only). Laravel config under `services.biosignal`.

## Accuracy & honesty

- **Overnight RMSSD / resting HR: reliable.** PPG at rest matches ECG closely
  (overnight RMSSD r²≈0.98 vs ECG, RHR bias <1 bpm — Kinnunen 2020). We reject IBIs
  <300/>2000 ms, apply Kubios artifact correction (`signal_fixpeaks`), and gate on
  artifact %/beat count/PPG SQI. We aggregate the **whole night** (5-min windows are
  much noisier).
- **HRV in motion / daytime: not trusted** — out of scope for v1 (recovery is the
  overnight regime). The IBI path assumes the edge already motion-gated.
- **Sleep staging: approximate.** This is a transparent heuristic, *not* the trained
  Walch model. Honest expectations without EEG: sleep/wake ~80-90%, wake specificity
  ~50-70%, 4-stage κ ~0.4-0.6, deep(N3) weakest. Lead with sleep/wake + duration.
  See the `>>> TO PLUG IN THE REAL MODEL <<<` note in `app/core/staging.py` for swapping
  in a joblib `ojwalch/sleep_classifiers` model trained on the PhysioNet dataset.
- **Activity TRIMP/calories: estimate.** Accel-proxy intensity unless HR is supplied;
  accurate in-motion HR (and HR-zone TRIMP) is a later roadmap item.
- `lf_hf` is reported for completeness but **should not** drive recovery (Billman 2013);
  use RMSSD.

## Layout

```
biosignal/
  Dockerfile  pyproject.toml  README.md  sample_payloads.json
  app/
    main.py                 # FastAPI app, bearer auth, /health
    routers/ hrv.py sleep.py activity.py
    core/    hrv.py staging.py activity.py   # pure, DB-free functions
  tests/                    # pytest + synthetic overnight fixtures
```
