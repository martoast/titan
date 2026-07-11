<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One point on the continuous overnight-motion trend — a per-epoch movement magnitude (motionEMA×1000,
 * milli-g). Stored by {@see \App\Services\Wearables\DeviceIngestionService} from `motion_trend`
 * summaries (the band's T10 frames); read by {@see \App\Jobs\SealNightJob}, which prefers this dense
 * channel over the sparse HRV-burst accel proxy when building the sleep-timeline movement strip.
 */
class MotionSample extends Model
{
    protected $fillable = ['profile_id', 'recorded_at', 'motion', 'source'];

    protected $casts = [
        'recorded_at' => 'datetime',
        'motion' => 'integer',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }
}
