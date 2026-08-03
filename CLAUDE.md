# CLAUDE.md — Titan deployment runbook (for the server agent)

You are running on the **production server** (a Droplet / VPS / home box). Your job is to
**deploy and operate Titan** — a self-hosted, single-Docker-Compose stack. This file is
your runbook. The longer human version is [DEPLOY.md](DEPLOY.md); this is the actionable
version for you. Read both before a first deploy.

## What Titan is

An open-source, self-hosted AI health/fitness/longevity OS: a Laravel app + an AI coach
(chat-driven), a Python biosignal service (HRV / sleep / activity / VO₂max), and a DIY
recovery wearable that streams HMAC-signed biosignal batches to the ingestion API.

## Golden rules (do not violate)

1. **Never commit `.env`.** It holds live secrets and is gitignored. Keep it on the server only.
2. **Never print secret values** to the user or into logs (API keys, DB/MinIO passwords, `BIOSIGNAL_TOKEN`, `APP_KEY`). Generate them, write them into `.env`, move on.
3. **Local dev vs prod are different stacks.** Production = `docker-compose.prod.yml` + `Dockerfile`. Do **not** use `compose.yaml` (that's Laravel Sail for local dev) on the server.
4. **Migrations auto-run** on the `app` container at boot — don't run them by hand unless a deploy log shows they failed.
5. Re-running `docker compose -f docker-compose.prod.yml up -d --build` is the safe, idempotent way to deploy and to ship updates. Data lives in named volumes and survives `down` (only `down -v` destroys it).

## First deploy

```bash
# 0) Prereqs (Docker + compose plugin). On fresh Ubuntu:
#    curl -fsSL https://get.docker.com | sh

# 1) Config
cp .env.production.example .env
# Fill every «REQUIRED» value. Generate the secrets and write them into .env
# (do NOT echo them back to the user):
#   DB_PASSWORD      = openssl rand -hex 24
#   BIOSIGNAL_TOKEN  = openssl rand -hex 32
#   MINIO_ACCESS_KEY = openssl rand -hex 12
#   MINIO_SECRET_KEY = openssl rand -hex 24

# 2) Build the app image, then generate APP_KEY into .env
docker compose -f docker-compose.prod.yml build app
docker compose -f docker-compose.prod.yml run --rm app php artisan key:generate --show
#   → write the base64:... value into APP_KEY=

# 3) Ask the user for: the DOMAIN (or confirm bare-IP), and the OPENAI_API_KEY.
#    Set APP_URL + APP_DOMAIN accordingly:
#      - domain:  APP_URL=https://titan.example.com   APP_DOMAIN=titan.example.com   (auto-HTTPS)
#      - bare IP: APP_URL=http://SERVER_IP            APP_DOMAIN=:80                  (HTTP only)
#    Set OPENAI_API_KEY (coach's brain) and, for push, VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY.

# 4) Launch the whole stack
docker compose -f docker-compose.prod.yml up -d --build
```

## Verify before declaring success (all must pass)

```bash
# every service Up/healthy
docker compose -f docker-compose.prod.yml ps

# migrations ran on first boot
docker compose -f docker-compose.prod.yml logs app | grep -iE "migrat|DONE|error"

# app serves through Caddy  → expect 200 (or 302)
curl -s -o /dev/null -w "%{http_code}\n" http://localhost/

# ingestion endpoint live + secured → expect 401 (rejects unsigned)
curl -s -o /dev/null -w "%{http_code}\n" -X POST http://localhost/api/devices/ingest -d '{}'

# app can reach the biosignal service internally → {"status":"ok",...}
docker compose -f docker-compose.prod.yml exec app curl -fsS http://biosignal:8000/health
```

If any check fails, read that service's logs (`logs <service>`) and fix before continuing.
Do not report "deployed" until all five pass.

## Connecting the wearable

The band/bridge POSTs HMAC-signed batches to `https://<domain>/api/devices/ingest`. In the
app, the user opens the coach chat → "I got my band, help me connect it" → taps the bridge
link → connects over Bluetooth. **The bridge needs HTTPS** (Web Bluetooth requirement), so a
real domain + `APP_DOMAIN` set to it is required for a real device — bare-IP/HTTP is for
testing only.

## Operating

```bash
# Ship an update
git pull && docker compose -f docker-compose.prod.yml up -d --build

# Logs / shell / tinker
docker compose -f docker-compose.prod.yml logs -f <service>     # app | queue | scheduler | biosignal
docker compose -f docker-compose.prod.yml exec app php artisan tinker

# DB backup (don't print the password; read it from .env)
docker compose -f docker-compose.prod.yml exec mysql sh -c 'mysqldump -u titan -p"$MYSQL_PASSWORD" titan' > titan-backup-$(date +%F).sql
```

## The stack (so you can reason about failures)

| Service | Role | Common failure |
|---|---|---|
| `app` | Laravel web + coach + ingestion API (behind Caddy) | missing `APP_KEY`/`OPENAI_API_KEY`; migration failed (DB not ready) |
| `queue` | Async jobs — window processing, night seals, coach reactions | can't reach `redis`; processes `biosignal` + `default` queues |
| `scheduler` | Nightly seals, briefings, nudges (`routes/console.php`) | — |
| `biosignal` | Python FastAPI — HRV / sleep / activity / VO₂max / gym / elevation | image not rebuilt after source change (rebuild: `up -d --build biosignal`) |
| `mysql` / `redis` | Data + queue/cache/sessions | volume perms; wrong password in `.env` |
| `minio` (+ `minio-init`) | Raw-waveform store (the `raw` disk); init creates the `titan-raw` bucket | bucket missing → re-run `minio-init` |
| `caddy` | Reverse proxy + automatic HTTPS | domain DNS not pointed at server; ports 80/443 taken |

## Gotchas

- **`BIOSIGNAL_TOKEN` is shared** — the same value authenticates Laravel → biosignal. One value in `.env`; the compose passes it to both.
- **Caddy needs ports 80 + 443 free** and (for HTTPS) the domain's `A` record pointing here.
- **Raw storage** can move to any S3 (DigitalOcean Spaces, etc.): point the `MINIO_*` vars at it and remove the `minio`/`minio-init` services.
- The biosignal `app/models/*.joblib` files are committed and baked into its image at build — no separate model download needed.

## Before touching the coach chat — read the day-chats doc

Coach chats are **one conversation per local day** (`conversations.day`), and the coach's proactive
briefings/reactions land inline in that day rather than in a hidden thread.
**[docs/COACH_DAY_CHATS.md](docs/COACH_DAY_CHATS.md)** is required reading before changing
`CoachController`, `CoachService::history()`, `Conversation`, or any `ReactTo*` job — it covers the
cross-day memory window and the two date/timezone traps that bite every time.

## Before touching the seal — read the architecture doc

The band **duty-cycles** (samples in short bursts to save battery), so a night/workout arrives as dozens of
sparse windows that a **seal** job stitches together. Almost every serious data bug has been a seal bug, not
a math bug. **[docs/SEAL_ARCHITECTURE.md](docs/SEAL_ARCHITECTURE.md)** is required reading before changing
`SealNightJob`, `SealActivityJob`, `ProcessWindowJob`, the biosignal `staging.py`/`activity.py`, or any
"last night"/streak/strain reader — it has the invariants and a pre-change checklist that prevent the
"17-minute night" class of bug.
