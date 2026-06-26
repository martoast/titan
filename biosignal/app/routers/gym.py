"""POST /process/gym — gym exercise recognition + rep counting from a wrist-accel workout.

Turns a logged lifting/treadmill session's 3-axis accel into detected sets ("Squats 3×10").
Validated on MM-Fit (real smartwatch wrist accel): 95% exercise classification, rep MAE 0.14.
"""

from __future__ import annotations

from typing import List, Optional

from fastapi import APIRouter, HTTPException
from pydantic import BaseModel, Field

from .. import ALGO_VERSION
from ..core import gym as gym_core

router = APIRouter(prefix="/process", tags=["gym"])


class GymAccel(BaseModel):
    x: List[float]
    y: List[float]
    z: List[float]


class GymRequest(BaseModel):
    accel_xyz: GymAccel = Field(..., description="Whole-workout 3-axis wrist accel.")
    accel_fs: int = Field(default=25, gt=0, le=1000, description="Sample rate (Hz). Bangle workout ≈ 25.")
    accel_unit: str = Field(default="ms2", description="'ms2' | 'g' | 'mg'. Bangle sends 'mg'.")


class GymResponse(BaseModel):
    algo_version: str
    sets: list
    summary: dict


@router.post("/gym", response_model=GymResponse)
async def process_gym(req: GymRequest) -> GymResponse:
    try:
        result = gym_core.analyze_workout(
            req.accel_xyz.x, req.accel_xyz.y, req.accel_xyz.z,
            fs=req.accel_fs, unit=req.accel_unit,
        )
    except Exception as exc:  # pragma: no cover
        raise HTTPException(status_code=500, detail=f"Gym analysis failed: {exc}")

    return GymResponse(algo_version=ALGO_VERSION, sets=result["sets"], summary=result["summary"])
