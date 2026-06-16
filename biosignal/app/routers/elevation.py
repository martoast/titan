"""POST /process/elevation — floors climbed + ascent/descent from a barometric altitude series.

The Bangle streams ambient barometric altitude (~1 Hz, GPS-free); the server turns it into floors
(drift- and noise-robust) so the device stays a dumb sensor. Wellness estimate, never medical.
"""

from __future__ import annotations

from typing import List, Optional

from fastapi import APIRouter, HTTPException
from pydantic import BaseModel, Field

from .. import ALGO_VERSION
from ..core import elevation as elevation_core

router = APIRouter(prefix="/process", tags=["elevation"])


class ElevationWindow(BaseModel):
    altitude_m: List[float] = Field(..., description="Per-sample barometric altitude (m).")
    sample_rate_hz: float = Field(default=1.0, description="Altitude sample rate (Hz). Bangle ambient ≈ 1.")


class ElevationMetrics(BaseModel):
    floors: int
    ascent_m: float
    descent_m: float
    n_climbs: int


class ElevationResponse(BaseModel):
    algo_version: str
    metrics: ElevationMetrics


@router.post("/elevation", response_model=ElevationResponse)
async def process_elevation(window: ElevationWindow) -> ElevationResponse:
    try:
        metrics = elevation_core.floors_from_altitude(
            window.altitude_m, sample_rate_hz=window.sample_rate_hz)
    except Exception as exc:  # pragma: no cover
        raise HTTPException(status_code=500, detail=f"Elevation processing failed: {exc}")
    return ElevationResponse(algo_version=ALGO_VERSION, metrics=ElevationMetrics(**metrics))
