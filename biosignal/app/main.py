"""Titan biosignal FastAPI service.

Internal-only microservice (see 04-platform-pipeline.md §5). Laravel's BiosignalClient
calls it over the private docker network with a bearer token. Stateless: no DB access.
"""

from __future__ import annotations

import os

from fastapi import Depends, FastAPI, Header, HTTPException, status

from . import ALGO_VERSION
from .routers import activity, elevation, fitness, function, gym, hrv, inmotion_hr, sleep

BIOSIGNAL_TOKEN = os.environ.get("BIOSIGNAL_TOKEN", "")

app = FastAPI(
    title="Titan Biosignal Service",
    version=ALGO_VERSION,
    description="PPG/IBI/accel → HRV, sleep staging, activity metrics (NeuroKit2 + Walch).",
)


async def require_bearer(authorization: str = Header(default="")) -> None:
    """Bearer-token auth gate. Matches `Http::withToken(...)` on the Laravel side.

    If BIOSIGNAL_TOKEN is unset (local dev / tests), auth is disabled.
    """
    if not BIOSIGNAL_TOKEN:
        return
    scheme, _, token = authorization.partition(" ")
    if scheme.lower() != "bearer" or not _constant_time_eq(token, BIOSIGNAL_TOKEN):
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Invalid or missing bearer token.",
            headers={"WWW-Authenticate": "Bearer"},
        )


def _constant_time_eq(a: str, b: str) -> bool:
    import hmac

    return hmac.compare_digest(a.encode(), b.encode())


@app.get("/health", tags=["meta"])
async def health() -> dict:
    """Liveness probe. No auth (used by docker healthcheck / Laravel readiness)."""
    return {"status": "ok", "algo_version": ALGO_VERSION}


# Routers — all gated by bearer auth.
app.include_router(hrv.router, dependencies=[Depends(require_bearer)])
app.include_router(sleep.router, dependencies=[Depends(require_bearer)])
app.include_router(activity.router, dependencies=[Depends(require_bearer)])
app.include_router(fitness.router, dependencies=[Depends(require_bearer)])
app.include_router(gym.router, dependencies=[Depends(require_bearer)])
app.include_router(elevation.router, dependencies=[Depends(require_bearer)])
app.include_router(function.router, dependencies=[Depends(require_bearer)])
app.include_router(inmotion_hr.router, dependencies=[Depends(require_bearer)])
