<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One point on the 24/7 heart-rate trend (≈1/minute, from the band's continuous + duty-cycled HR).
 * Stored by {@see \App\Services\Wearables\DeviceIngestionService} from `hr_trend` summaries; read by
 * the native HR graph (MobileHrController).
 */
class HrSample extends Model
{
    protected $fillable = ['profile_id', 'recorded_at', 'bpm', 'confidence', 'source'];

    protected $casts = [
        'recorded_at' => 'datetime',
        'bpm' => 'integer',
        'confidence' => 'integer',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }
}
