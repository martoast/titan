"""POST /process/function — gait cadence + the guided sit-to-stand (30CST) frailty screen.

From a wrist-accel bout: walking cadence (steps/min) and, for a guided chair-stand test, the rep
count scored against age/sex norms. Wellness-scope functional fitness — a screen, never a diagnosis.
"""

from __future__ import annotations

from typing import List, Optional

from fastapi import APIRouter, HTTPException
from pydantic import BaseModel, Field, model_validator

from .. import ALGO_VERSION
from ..core import gait as gait_core
from ..core.errors import DataFaultError

router = APIRouter(prefix="/process", tags=["function"])


class FunctionRequest(BaseModel):
    ax: List[float] = Field(..., description="Wrist accel X.")
    ay: List[float]
    az: List[float]
    fs: int = Field(default=25, gt=0, le=1000, description="Accel sample rate (Hz).")
    unit: str = Field(default="ms2", description="'ms2', 'g', or 'mg'.")
    test: str = Field(default="cadence", description="'cadence' (walking) or 'sit_to_stand' (guided 30CST).")
    duration_s: Optional[float] = Field(default=None, description="Protocol length for sit_to_stand (e.g. 30).")
    age: Optional[float] = None
    sex: Optional[str] = None

    @model_validator(mode="after")
    def _len(self):
        if not (len(self.ax) == len(self.ay) == len(self.az)) or len(self.ax) < 10:
            raise ValueError("ax/ay/az must be equal-length and non-trivial.")
        return self


class FunctionResponse(BaseModel):
    algo_version: str
    cadence: Optional[dict] = None
    sit_to_stand: Optional[dict] = None


@router.post("/function", response_model=FunctionResponse)
async def process_function(req: FunctionRequest) -> FunctionResponse:
    try:
        cadence = sts = None
        if req.test == "sit_to_stand":
            sts = gait_core.sit_to_stand(req.ax, req.ay, req.az, fs=req.fs, unit=req.unit, duration_s=req.duration_s)
            if sts and req.age is not None:
                female = str(req.sex).strip().lower() in {"f", "female", "woman", "w"}
                sts["score"] = gait_core.chair_stand_score(sts["reps"], req.age, female)
        else:
            cadence = gait_core.cadence_spm(req.ax, req.ay, req.az, fs=req.fs, unit=req.unit)
    except DataFaultError as exc:
        raise HTTPException(status_code=422, detail=f"Function processing rejected the payload: {exc}")
    except Exception as exc:  # pragma: no cover
        raise HTTPException(status_code=500, detail=f"Function processing failed: {exc}")
    return FunctionResponse(algo_version=ALGO_VERSION, cadence=cadence, sit_to_stand=sts)
