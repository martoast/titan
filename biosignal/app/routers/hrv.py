"""POST /process/hrv — overnight HRV from an IBI window or raw PPG snippet."""

from __future__ import annotations

from typing import List, Optional

from fastapi import APIRouter, HTTPException
from pydantic import BaseModel, Field, model_validator

from .. import ALGO_VERSION
from ..core import hrv as hrv_core

router = APIRouter(prefix="/process", tags=["hrv"])


class HrvWindow(BaseModel):
    """One of two shapes: IBI (band default) or raw PPG (audit/reprocess).

    Mirrors the ingestion window in 04-platform-pipeline.md §2 (shapes A and B).
    """

    ibi_ms: Optional[List[float]] = Field(default=None, description="Inter-beat intervals (ms).")
    accel_counts: Optional[List[float]] = Field(default=None, description="Per-epoch accel counts.")
    ppg: Optional[List[float]] = Field(default=None, description="Raw PPG samples (int16-ish).")
    sample_rate_hz: Optional[int] = Field(default=None, description="PPG sample rate (Hz).")
    start: Optional[str] = Field(default=None, description="Window start (ISO-8601 UTC).")
    end: Optional[str] = Field(default=None, description="Window end (ISO-8601 UTC).")

    @model_validator(mode="after")
    def _need_a_signal(self):
        if not self.ibi_ms and not (self.ppg and self.sample_rate_hz):
            raise ValueError("Provide ibi_ms, or ppg + sample_rate_hz.")
        return self


class HrvMetrics(BaseModel):
    hrv_ms: Optional[float]
    resting_hr: Optional[float]
    rmssd: Optional[float]
    sdnn: Optional[float]
    pnn50: Optional[float]
    lf_hf: Optional[float]
    artifact_pct: float
    valid: bool
    n_beats_raw: int
    n_beats_clean: int


class HrvResponse(BaseModel):
    algo_version: str
    metrics: HrvMetrics
    # Per-window clean IBI (PPG path only) — persisted by the platform for whole-night sealing.
    ibi_ms: Optional[List[float]] = None
    # Per-30s-epoch sleep features (PPG path only) — concatenated whole-night to stage sleep.
    epoch_hr: Optional[List[float]] = None
    epoch_motion: Optional[List[float]] = None
    epoch_rmssd: Optional[List[Optional[float]]] = None


@router.post("/hrv", response_model=HrvResponse)
async def process_hrv(window: HrvWindow) -> HrvResponse:
    try:
        metrics = hrv_core.process_hrv(
            ibi_ms=window.ibi_ms,
            ppg=window.ppg,
            sample_rate_hz=window.sample_rate_hz,
            accel=window.accel_counts,
        )
    except ValueError as exc:
        raise HTTPException(status_code=422, detail=str(exc))
    except Exception as exc:  # pragma: no cover
        raise HTTPException(status_code=500, detail=f"HRV processing failed: {exc}")

    ibi = metrics.pop("ibi_ms", None)  # not part of HrvMetrics; returned at top level
    epoch_hr = metrics.pop("epoch_hr", None)
    epoch_motion = metrics.pop("epoch_motion", None)
    epoch_rmssd = metrics.pop("epoch_rmssd", None)
    return HrvResponse(
        algo_version=ALGO_VERSION,
        metrics=HrvMetrics(**metrics),
        ibi_ms=ibi,
        epoch_hr=epoch_hr,
        epoch_motion=epoch_motion,
        epoch_rmssd=epoch_rmssd,
    )
