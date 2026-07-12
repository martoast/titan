<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One point on the stress-over-day strip — `stress` is a 0–100 PERCENT of the 0–3 stress max (calm ≈ 13,
 * high ≈ 80) at a sampled minute, so the stored value is one unambiguous scale the sparkline reads
 * directly. Written periodically by {@see \App\Console\Commands\SampleStressCommand} from
 * {@see \App\Support\StressMonitor} (HR-vs-baseline + motion gate). Mirrors {@see MotionSample}.
 */
class StressSample extends Model
{
    protected $fillable = ['profile_id', 'recorded_at', 'stress', 'source'];

    protected $casts = [
        'recorded_at' => 'datetime',
        'stress' => 'integer',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }
}
