"""POST /process/activity — accel-count session detection + TRIMP/calorie estimate."""

from __future__ import annotations

from typing import List, Optional

from fastapi import APIRouter, HTTPException
from pydantic import BaseModel, Field

from .. import ALGO_VERSION
from ..core import activity as activity_core

router = APIRouter(prefix="/process", tags=["activity"])


class ActivityWindow(BaseModel):
    accel_counts: List[float] = Field(..., description="Per-30s-epoch activity counts.")
    hr_bpm: Optional[List[float]] = Field(default=None, description="Per-30s-epoch heart rate (refines TRIMP).")
    start: Optional[str] = Field(default=None, description="Window start (ISO-8601 UTC).")
    hr_max: int = Field(default=190, description="User HRmax (avoid 220-age; field-test if possible).")
    hr_rest: int = Field(default=55, description="User resting HR.")
    weight_kg: float = Field(default=75.0, description="User weight (kg) for calorie estimate.")


class ActivitySession(BaseModel):
    start: str
    end: str
    duration_min: float
    mean_accel_counts: float
    mean_hr: Optional[float]
    intensity: float
    trimp: float
    calories_kcal: float


class ActivityMetrics(BaseModel):
    sessions: List[ActivitySession]
    session_count: int
    total_active_min: float
    total_trimp: float
    total_calories_kcal: float


class ActivityResponse(BaseModel):
    algo_version: str
    metrics: ActivityMetrics


@router.post("/activity", response_model=ActivityResponse)
async def process_activity(window: ActivityWindow) -> ActivityResponse:
    try:
        metrics = activity_core.detect_sessions(
            accel_counts=window.accel_counts,
            start=window.start,
            hr_bpm=window.hr_bpm,
            hr_max=window.hr_max,
            hr_rest=window.hr_rest,
            weight_kg=window.weight_kg,
        )
    except Exception as exc:  # pragma: no cover
        raise HTTPException(status_code=500, detail=f"Activity processing failed: {exc}")

    return ActivityResponse(algo_version=ALGO_VERSION, metrics=ActivityMetrics(**metrics))
