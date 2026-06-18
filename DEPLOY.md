# Self-hosting Titan

Titan runs as a single Docker Compose stack on any box you control (a DigitalOcean
Droplet, a VPS, a home server). One `docker compose up` brings up the whole thing:
the Laravel app, a queue worker, the scheduler, the Python biosignal service, MySQL,
Redis, MinIO (raw-waveform storage), and Caddy (automatic HTTPS).

> Local development is separate — that still uses Laravel Sail (`compose.yaml`).
> Production self-hosting uses **`docker-compose.prod.yml`** + **`Dockerfile`**.

## 1. Prerequisites

- A server with **Docker** + the **Docker Compose plugin** (Ubuntu 22.04+ is fine).
- ~2 GB RAM minimum (4 GB comfortable).
- For HTTPS: a **domain** pointed at the server's IP (an `A` record). You can start on a
  bare IP over HTTP and add the domain later.

```bash
# On a fresh Ubuntu Droplet:
curl -fsSL https://get.docker.com | sh
```

## 2. Get the code + configure

```bash
git clone git@github.com:martoast/titan.git
cd titan
cp .env.production.example .env
```

Fill in the **«REQUIRED»** values in `.env`. Generate the secrets:

```bash
openssl rand -hex 24    # DB_PASSWORD
openssl rand -hex 32    # BIOSIGNAL_TOKEN
openssl rand -hex 12    # MINIO_ACCESS_KEY
openssl rand -hex 24    # MINIO_SECRET_KEY
```

Then the app key (uses the built image, so build first or run after step 3's build):

```bash
docker compose -f docker-compose.prod.yml build app
docker compose -f docker-compose.prod.yml run --rm app php artisan key:generate --show
# → paste the base64:... value into APP_KEY in .env
```

Set **`APP_URL`** and **`APP_DOMAIN`** to your domain (e.g. `https://titan.example.com`
and `titan.example.com`). No domain yet? Use `APP_URL=http://YOUR_IP` and `APP_DOMAIN=:80`.

Finally, set **`OPENAI_API_KEY`** (the coach's brain) and, for push notifications,
**`VAPID_PUBLIC_KEY` / `VAPID_PRIVATE_KEY`**.

## 3. Launch

```bash
docker compose -f docker-compose.prod.yml up -d --build
```

That builds both images, starts everything, **auto-runs migrations** (on the `app`
container) and creates the `titan-raw` MinIO bucket. Watch it come up:

```bash
docker compose -f docker-compose.prod.yml ps
docker compose -f docker-compose.prod.yml logs -f app
```

Open `https://your-domain` (or `http://YOUR_IP`). Done.

## 4. Connect the wearable

The band/bridge POSTs HMAC-signed batches to **`https://your-domain/api/devices/ingest`**.
In the app: open the coach chat → *"I got my band, help me connect it"* → tap the bridge
link → connect over Bluetooth. The first data trips the "your band is live" moment.

> The bridge uses Web Bluetooth, which **requires HTTPS** (or `localhost`). Set a real
> domain so `APP_DOMAIN` gives you a Let's Encrypt cert automatically.

## What runs

| Service | Role |
|---|---|
| `app` | Laravel web app + coach + ingestion API (php-fpm + nginx, behind Caddy) |
| `queue` | Async jobs: window processing, night seals, coach reactions (`biosignal` + `default` queues) |
| `scheduler` | Nightly seals, briefings, nudges (`routes/console.php`) |
| `biosignal` | Python FastAPI: HRV / sleep / activity / VO₂max / gym / elevation |
| `mysql` · `redis` | Data + queue/cache/sessions |
| `minio` | S3-compatible store for raw waveform blobs (the `raw` disk) |
| `caddy` | Reverse proxy + automatic HTTPS |

## Operating it

```bash
# Update to the latest code
git pull && docker compose -f docker-compose.prod.yml up -d --build

# Logs / shell / tinker
docker compose -f docker-compose.prod.yml logs -f queue
docker compose -f docker-compose.prod.yml exec app php artisan tinker

# Back up the database
docker compose -f docker-compose.prod.yml exec mysql \
  mysqldump -u titan -p"$DB_PASSWORD" titan > titan-backup.sql
```

Data lives in named volumes (`titan-mysql`, `titan-minio`, `titan-redis`) and survives
`down`. Only `down -v` deletes it.

## Notes

- **Secrets**: `.env` is gitignored — keep it on the server only, never commit it.
- **`BIOSIGNAL_TOKEN`** is shared: the same value authenticates Laravel → biosignal.
- **Scaling raw storage**: swap MinIO for any S3 (DigitalOcean Spaces, AWS) by pointing
  the `MINIO_*` vars at it and dropping the `minio`/`minio-init` services.
- **Email** is `log` by default; set `MAIL_*` for real password-reset delivery.
