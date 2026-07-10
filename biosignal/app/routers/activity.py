"""POST /process/activity — accel-count session detection + TRIMP/calorie estimate."""

from __future__ import annotations

from typing import List, Optional

from fastapi import APIRouter, HTTPException
from pydantic import BaseModel, Field

from .. import ALGO_VERSION
from ..core import activity as activity_core
from ..core import gait as gait_core
from ..core.errors import DataFaultError

router = APIRouter(prefix="/process", tags=["activity"])


class AccelXYZ(BaseModel):
    x: List[float] = Field(..., description="Raw accel X axis (the wrist's live 3-axis stream).")
    y: List[float]
    z: List[float]


class ActivityWindow(BaseModel):
    accel_counts: List[float] = Field(..., description="Per-30s-epoch activity counts.")
    hr_bpm: Optional[List[float]] = Field(default=None, description="Per-30s-epoch heart rate (refines TRIMP).")
    start: Optional[str] = Field(default=None, description="Window start (ISO-8601 UTC).")
    hr_max: int = Field(default=190, description="User HRmax (avoid 220-age; field-test if possible).")
    hr_rest: int = Field(default=55, description="User resting HR.")
    weight_kg: float = Field(default=75.0, description="User weight (kg) for calorie estimate.")
    # Optional raw 3-axis accel stream (the Bangle's live/T1 frames) → workout classification.
    accel_xyz: Optional[AccelXYZ] = Field(default=None, description="Raw 3-axis accel for activity classification (rest/walk/run/cycle/stairs/other).")
    accel_fs: int = Field(default=25, gt=0, le=1000, description="Sample rate of accel_xyz (Hz). Bangle live ≈ 25.")
    accel_unit: str = Field(default="ms2", description="Unit of accel_xyz: 'ms2', 'g', or 'mg'. Bangle sends 'mg' (milli-g).")
    accel_start: Optional[str] = Field(default=None, description="accel_xyz start time (ISO-8601 UTC); defaults to `start`.")
    # Optional per-epoch GPS pace (+ baro grade) → grade-aware cost-of-transport calories for runs/walks.
    speed_kmh: Optional[List[float]] = Field(default=None, description="Per-epoch GPS speed (km/h), aligned to accel_counts. Enables grade-aware EE.")
    grade: Optional[List[float]] = Field(default=None, description="Per-epoch baro grade (rise/run fraction), aligned to accel_counts.")


class ActivitySession(BaseModel):
    start: str
    end: str
    duration_min: float
    mean_accel_counts: float
    mean_hr: Optional[float]
    intensity: float
    trimp: float
    calories_kcal: float
    distance_km: Optional[float] = Field(default=None, description="GPS distance (km), when pace is supplied.")
    energy_method: str = Field(default="met_proxy", description="How calories were computed: met_proxy / pace_cost / grade_cost.")
    activity_type: Optional[str] = Field(default=None, description="Classified workout type (when a 3-axis accel stream is supplied).")
    activity_confidence: Optional[float] = None
    activity_mix: Optional[dict] = Field(default=None, description="Share of windows per activity within the session.")


class ActivityMetrics(BaseModel):
    sessions: List[ActivitySession]
    session_count: int
    total_active_min: float
    total_trimp: float
    total_calories_kcal: float


class ActivityResponse(BaseModel):
    algo_version: str
    metrics: ActivityMetrics


class StepDistanceRequest(BaseModel):
    accel_xyz: AccelXYZ = Field(..., description="Raw 3-axis accel for the whole activity (no GPS).")
    accel_fs: int = Field(default=25, gt=0, le=1000)
    accel_unit: str = Field(default="ms2", description="'ms2' | 'g' | 'mg' (Bangle sends 'mg').")
    duration_s: float = Field(..., gt=0, description="Activity duration in seconds.")
    height_cm: float = Field(..., gt=0, description="User height (cm) → step-length scale.")
    activity_type: Optional[str] = Field(default=None, description="Classified type (run/walk/…) to tune stride.")


class StepDistanceResponse(BaseModel):
    algo_version: str
    estimated: bool
    cadence_spm: Optional[float] = None
    steps: Optional[int] = None
    stride_m: Optional[float] = None
    distance_km: Optional[float] = None


@router.post("/step-distance", response_model=StepDistanceResponse)
async def process_step_distance(req: StepDistanceRequest) -> StepDistanceResponse:
    """Estimate distance from accel cadence + height when there's no GPS (indoor/treadmill runs)."""
    try:
        est = gait_core.estimate_distance(
            req.accel_xyz.x, req.accel_xyz.y, req.accel_xyz.z,
            fs=req.accel_fs, duration_s=req.duration_s, height_cm=req.height_cm,
            activity_type=req.activity_type, unit=req.accel_unit,
        )
    except DataFaultError as exc:
        raise HTTPException(status_code=422, detail=f"Step-distance rejected the payload: {exc}")
    except Exception as exc:  # pragma: no cover
        raise HTTPException(status_code=500, detail=f"Step-distance failed: {exc}")

    if not est:
        return StepDistanceResponse(algo_version=ALGO_VERSION, estimated=False)
    return StepDistanceResponse(algo_version=ALGO_VERSION, estimated=True, **est)


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
            accel_xyz=window.accel_xyz.model_dump() if window.accel_xyz else None,
            accel_fs=window.accel_fs,
            accel_unit=window.accel_unit,
            accel_start=window.accel_start,
            speed_kmh=window.speed_kmh,
            grade=window.grade,
        )
    except DataFaultError as exc:
        # Deterministic data fault (e.g. an unknown accel unit) → 422, not a retry-forever 5xx.
        raise HTTPException(status_code=422, detail=f"Activity processing rejected the payload: {exc}")
    except Exception as exc:  # pragma: no cover
        raise HTTPException(status_code=500, detail=f"Activity processing failed: {exc}")

    return ActivityResponse(algo_version=ALGO_VERSION, metrics=ActivityMetrics(**metrics))
