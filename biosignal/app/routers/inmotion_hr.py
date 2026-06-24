"""POST /process/inmotion-hr — workout heart rate from raw wrist PPG + accelerometer.

The wrist on-chip bpm cadence-locks under load (reports rep/stride rhythm as HR). This recomputes
HR from the raw PPG we stream during a workout (T1) plus the 3-axis accel, suppressing the motion
frequencies — see app/core/inmotion_hr.py. Returns a per-window HR series with an honest reliability
flag so downstream metrics (zones, TRIMP, VO2, strain) only trust HR that survived the motion.
"""

from __future__ import annotations

from typing import List, Optional

from fastapi import APIRouter, HTTPException
from pydantic import BaseModel, Field

from .. import ALGO_VERSION
from ..core import inmotion_hr as core

router = APIRouter(prefix="/process", tags=["inmotion-hr"])


class InMotionHRRequest(BaseModel):
    ppg: List[float] = Field(..., description="Raw PPG samples over the workout window.")
    fs_ppg: float = Field(..., gt=0, description="PPG sample rate (Hz), e.g. 25 or 50.")
    accel_x: List[float] = Field(default_factory=list, description="Accel X (g), motion-suppression source.")
    accel_y: List[float] = Field(default_factory=list)
    accel_z: List[float] = Field(default_factory=list)
    fs_acc: float = Field(default=25.0, gt=0, description="Accel sample rate (Hz). May differ from fs_ppg.")
    seed_bpm: Optional[float] = Field(default=None, description="Prior HR (e.g. resting HR) to seed the tracker.")
    min_confidence: float = Field(default=core.MIN_CONFIDENCE, ge=0, le=100,
                                  description="Window confidence below which HR is held (unreliable).")


class InMotionHRResponse(BaseModel):
    algo_version: str
    t: List[float] = Field(..., description="Window-center time (s) for each estimate.")
    bpm: List[Optional[float]] = Field(..., description="HR (bpm) per window; holds last-good when unreliable, null before the first trusted reading.")
    confidence: List[float] = Field(..., description="Per-window confidence 0-100.")
    reliable: List[bool] = Field(..., description="Did this window clear the confidence bar on its own?")
    cadence_collision: List[bool] = Field(..., description="HR≈cadence in this window (irreducibly uncertain).")
    summary: dict = Field(..., description="hr_mean/max/min over reliable windows, coverage, n_windows.")


@router.post("/inmotion-hr", response_model=InMotionHRResponse)
async def process_inmotion_hr(req: InMotionHRRequest) -> InMotionHRResponse:
    try:
        out = core.estimate_series(
            ppg=req.ppg, fs_ppg=req.fs_ppg,
            accel_x=req.accel_x, accel_y=req.accel_y, accel_z=req.accel_z, fs_acc=req.fs_acc,
            seed_bpm=req.seed_bpm, min_confidence=req.min_confidence,
        )
    except Exception as exc:  # pragma: no cover
        raise HTTPException(status_code=500, detail=f"In-motion HR estimation failed: {exc}")
    return InMotionHRResponse(algo_version=ALGO_VERSION, **out)
