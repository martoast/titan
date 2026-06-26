"""POST /process/fitness — VO2max estimate (+ optional HRR) from profile + measured HR.

Wellness scope only: a cardiorespiratory-fitness ESTIMATE with an honest ± band, never a
diagnosis. See app/core/fitness.py for the validated methods.
"""

from __future__ import annotations

import math
from typing import List, Optional

from fastapi import APIRouter, HTTPException
from pydantic import BaseModel, Field, model_validator

from .. import ALGO_VERSION
from ..core import fitness as fitness_core

router = APIRouter(prefix="/process", tags=["fitness"])


class RunCapture(BaseModel):
    """A paced run the wrist captures with GPS (pace) + barometer (grade) — the calibration
    signal for VO2max. GPS is powered only once the accel classifier confirms a run, so this
    only arrives for genuine outdoor locomotion (see firmware GPS gating)."""
    hr: List[float] = Field(..., description="Per-sample HR (bpm) over the run.")
    speed_kmh: List[float] = Field(..., description="GPS speed (km/h), same length/cadence as hr.")
    grade: Optional[List[float]] = Field(default=None, description="Baro-derived grade (rise/run fraction); normalizes hilly pace.")
    hrr60: Optional[float] = Field(default=None, allow_inf_nan=False, description="Heart-rate recovery (bpm) if measured from the run's tail.")

    @model_validator(mode="after")
    def _equal_lengths(self) -> "RunCapture":
        # hr and speed_kmh are combined element-wise downstream — mismatched lengths raise an opaque
        # NumPy broadcast error (500). Reject them up front as a 422 instead.
        if len(self.hr) != len(self.speed_kmh):
            raise ValueError("hr and speed_kmh must be the same length")
        if self.grade is not None and len(self.grade) != len(self.hr):
            raise ValueError("grade must be the same length as hr")
        return self


class FitnessRequest(BaseModel):
    age: float = Field(..., description="Age in years.")
    sex: str = Field(..., description="Sex ('M'/'F' or 0/1) — VO2max norms differ by sex.")
    weight_kg: float
    height_cm: float
    resting_hr: Optional[float] = Field(default=None, description="Overnight resting HR (bpm); blends in the Uth-Sørensen estimate.")
    hr_max: Optional[float] = Field(default=None, description="Measured HRmax from a workout; falls back to Tanaka (208-0.7·age).")
    run: Optional[RunCapture] = Field(default=None, description="A GPS-paced run → the run-calibrated VO2max (tracks training).")
    workout_hr_bpm: Optional[List[float]] = Field(default=None, description="HR series from a workout's tail, for heart-rate recovery.")
    hr_fs: float = Field(default=1.0, gt=0, le=1000, description="Sample rate (Hz) of workout_hr_bpm.")


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
    # A NaN/Inf scalar would propagate to a non-finite vo2max and crash at response serialization as an
    # opaque 500. Reject it up front with a clean message (raising on the pydantic model instead would
    # echo the NaN back into the 422 body, which itself fails to encode).
    if not all(v is None or math.isfinite(v)
               for v in (req.age, req.weight_kg, req.height_cm, req.resting_hr, req.hr_max, req.hr_fs)):
        raise HTTPException(status_code=422, detail="numeric inputs must be finite")
    try:
        est = fitness_core.estimate_vo2max(
            age=req.age, sex=req.sex, weight_kg=req.weight_kg, height_cm=req.height_cm,
            resting_hr=req.resting_hr, hr_max=req.hr_max,
            run=req.run.model_dump() if req.run else None,
        )
        hrr = fitness_core.heart_rate_recovery(req.workout_hr_bpm, fs=req.hr_fs) if req.workout_hr_bpm else None
    except Exception as exc:  # pragma: no cover
        raise HTTPException(status_code=500, detail=f"Fitness estimation failed: {exc}")

    return FitnessResponse(algo_version=ALGO_VERSION, hrr=hrr, **est)
