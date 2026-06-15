"""POST /process/fitness — VO2max estimate (+ optional HRR) from profile + measured HR.

Wellness scope only: a cardiorespiratory-fitness ESTIMATE with an honest ± band, never a
diagnosis. See app/core/fitness.py for the validated methods.
"""

from __future__ import annotations

from typing import List, Optional

from fastapi import APIRouter, HTTPException
from pydantic import BaseModel, Field

from .. import ALGO_VERSION
from ..core import fitness as fitness_core

router = APIRouter(prefix="/process", tags=["fitness"])


class FitnessRequest(BaseModel):
    age: float = Field(..., description="Age in years.")
    sex: str = Field(..., description="Sex ('M'/'F' or 0/1) — VO2max norms differ by sex.")
    weight_kg: float
    height_cm: float
    resting_hr: Optional[float] = Field(default=None, description="Overnight resting HR (bpm); blends in the Uth-Sørensen estimate.")
    hr_max: Optional[float] = Field(default=None, description="Measured HRmax from a workout; falls back to Tanaka (208-0.7·age).")
    workout_hr_bpm: Optional[List[float]] = Field(default=None, description="HR series from a workout's tail, for heart-rate recovery.")
    hr_fs: float = Field(default=1.0, description="Sample rate (Hz) of workout_hr_bpm.")


class FitnessResponse(BaseModel):
    algo_version: str
    vo2max: float
    plusminus: float = Field(..., description="Honest ± band (validated MAE, ml/kg/min).")
    methods: List[str]
    fitness_level: str
    fitness_percentile_band: int
    hrr: Optional[dict] = None


@router.post("/fitness", response_model=FitnessResponse)
async def process_fitness(req: FitnessRequest) -> FitnessResponse:
    try:
        est = fitness_core.estimate_vo2max(
            age=req.age, sex=req.sex, weight_kg=req.weight_kg, height_cm=req.height_cm,
            resting_hr=req.resting_hr, hr_max=req.hr_max,
        )
        hrr = fitness_core.heart_rate_recovery(req.workout_hr_bpm, fs=req.hr_fs) if req.workout_hr_bpm else None
    except Exception as exc:  # pragma: no cover
        raise HTTPException(status_code=500, detail=f"Fitness estimation failed: {exc}")

    return FitnessResponse(algo_version=ALGO_VERSION, hrr=hrr, **est)
