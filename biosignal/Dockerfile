# Titan biosignal microservice.
# Internal-only FastAPI service reached by Laravel at http://biosignal:8000.
FROM python:3.11-slim

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    PIP_NO_CACHE_DIR=1

# scipy/neurokit2 build/runtime deps.
RUN apt-get update \
    && apt-get install -y --no-install-recommends build-essential curl \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /srv

# Install dependencies directly (cached layer) — we run uvicorn against ./app on
# the path, so the local package itself never needs to be pip-installed.
RUN pip install --upgrade pip && pip install \
    "fastapi>=0.110" "uvicorn[standard]>=0.29" "neurokit2>=0.2.7" \
    "numpy>=1.24" "scipy>=1.10" "pandas>=2.0" "pydantic>=2.6"

# App code.
COPY pyproject.toml README.md ./
COPY app ./app

EXPOSE 8000

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD curl -fsS http://localhost:8000/health || exit 1

# Shell-form so ${UVICORN_WORKERS} expands at runtime. Overnight sealing fans out ~30
# per-window DSP calls; a single worker serialises them (~80s for a full night). Set
# UVICORN_WORKERS to (cores-1) in prod so the windows process concurrently and the sleep
# summary's stages land in seconds, not a minute-plus. Defaults to 1 (dev / tiny boxes).
CMD uvicorn app.main:app --host 0.0.0.0 --port 8000 --workers ${UVICORN_WORKERS:-1}
