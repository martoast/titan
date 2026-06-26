"""POST /process/route — turn a run's GPS track into the Strava-style summary.

Given the coordinate track a run logged (lat/lon/alt fixes) + a 1 Hz HR series, returns
distance, moving vs elapsed time, average + grade-adjusted pace, per-km/mile splits, elevation
gain + profile, best efforts, Relative Effort, and an encoded+simplified polyline for the map.
Pure geometry/stats — see app/core/route.py. Wellness scope (GPS-grade estimates).
"""

from __future__ import annotations

from typing import List, Optional

from fastapi import APIRouter, HTTPException
from pydantic import BaseModel, Field

from .. import ALGO_VERSION
from ..core import route as route_core

router = APIRouter(prefix="/process", tags=["route"])


class TrackPoint(BaseModel):
    t: float = Field(..., description="Epoch milliseconds of the fix.")
    lat: float = Field(..., ge=-90, le=90)
    lon: float = Field(..., ge=-180, le=180)
    alt: Optional[float] = Field(default=None, description="Altitude (m); enables elevation + GAP.")


class RouteRequest(BaseModel):
    track: List[TrackPoint] = Field(..., description="Coord-bearing GPS fixes, ascending time.")
    hr_bpm: Optional[List[float]] = Field(default=None, description="1 Hz HR aligned to the run start.")
    hr_max: Optional[float] = Field(default=None, description="HRmax — needed for Relative Effort.")
    units: str = Field(default="metric", description="'metric' | 'imperial' — picks the headline split unit.")


@router.post("/route")
async def process_route(req: RouteRequest) -> dict:
    try:
        result = route_core.analyze(
            [p.model_dump() for p in req.track],
            hr1=req.hr_bpm, hr_max=req.hr_max, units=req.units,
        )
    except Exception as exc:  # pragma: no cover
        raise HTTPException(status_code=500, detail=f"Route analysis failed: {exc}")
    return {"algo_version": ALGO_VERSION, **result}
