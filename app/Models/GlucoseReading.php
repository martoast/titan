<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One continuous-glucose reading (CGM_INTEGRATION P1). Source-agnostic — from Nightscout / HealthKit /
 * Dexcom, all upserted by (profile_id, taken_at). mg/dL canonical; `mmol()` converts for display. This is
 * the user's own device data, surfaced for wellness only (never insulin dosing or diagnosis).
 */
class GlucoseReading extends Model
{
    use HasFactory;

    public const SOURCES = ['nightscout', 'healthkit', 'dexcom'];

    /** mg/dL ÷ this = mmol/L (the molar-mass conversion for glucose). */
    public const MMOL_PER_MGDL = 18.0182;

    protected $fillable = ['profile_id', 'taken_at', 'mg_dl', 'trend', 'source', 'device', 'raw'];

    protected function casts(): array
    {
        return ['taken_at' => 'datetime', 'mg_dl' => 'integer', 'raw' => 'array'];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    /** The physical range a CGM measures — it reports LOW/HIGH outside this. Clamp to it so a calibration
     *  glitch (e.g. sgv=600) can't skew TIR/average/variability (review fa31f1a). */
    public const RANGE_MIN = 40;
    public const RANGE_MAX = 400;

    /** Clamp a raw mg/dL to the real CGM range. Shared by every ingest source. */
    public static function clampMgDl(int $mg): int
    {
        return max(self::RANGE_MIN, min(self::RANGE_MAX, $mg));
    }

    /** The reading in mmol/L (non-US display). */
    public function mmol(): float
    {
        return round($this->mg_dl / self::MMOL_PER_MGDL, 1);
    }
}
