"""POST /process/sleep — Walch-style actigraphy + HR sleep staging (v1 baseline)."""

from __future__ import annotations

from typing import List, Optional

from fastapi import APIRouter, HTTPException
from pydantic import BaseModel, Field

from .. import ALGO_VERSION
from ..core import staging as staging_core

router = APIRouter(prefix="/process", tags=["sleep"])


class SleepWindow(BaseModel):
    """Whole-night accel counts (+ optional per-epoch HR) for one sleep period.

    The Laravel SealNightJob assembles the whole night and posts it here (one call per
    night → one sleep_logs row). accel_counts/hr_bpm are per-30s-epoch.
    """

    accel_counts: List[float] = Field(..., description="Per-30s-epoch activity counts.")
    hr_bpm: Optional[List[float]] = Field(default=None, description="Per-30s-epoch heart rate.")
    start: Optional[str] = Field(default=None, description="Bedtime / window start (ISO-8601 UTC).")
    end: Optional[str] = Field(default=None, description="Window end (ISO-8601 UTC).")


class SleepMetrics(BaseModel):
    duration_min: float
    deep_min: float
    rem_min: float
    light_min: float
    awake_min: float
    bedtime: str
    wake_time: str
    quality: int
    hypnogram_30s: List[str]


class SleepResponse(BaseModel):
    algo_version: str
    metrics: SleepMetrics


@router.post("/sleep", response_model=SleepResponse)
async def process_sleep(window: SleepWindow) -> SleepResponse:
    try:
        metrics = staging_core.stage_night(
            accel_counts=window.accel_counts,
            hr_bpm=window.hr_bpm,
            start=window.start,
            end=window.end,
        )
    except Exception as exc:  # pragma: no cover
        raise HTTPException(status_code=500, detail=f"Sleep staging failed: {exc}")

    return SleepResponse(algo_version=ALGO_VERSION, metrics=SleepMetrics(**metrics))
