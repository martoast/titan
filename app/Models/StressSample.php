<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One point on the stress-over-day strip — the 0–3 stress score ×100 (0..300) at a sampled minute.
 * Written periodically by {@see \App\Console\Commands\SampleStressCommand} from {@see \App\Support\StressMonitor}
 * (HR-vs-baseline + motion gate), read back into the day strip. Mirrors {@see MotionSample}.
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
