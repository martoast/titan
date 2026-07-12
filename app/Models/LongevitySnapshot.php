<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A dated Titan Age reading — the history that turns the Longevity Index into a real PACE OF AGING trend.
 * Written weekly (and on fresh bloodwork / a new fitness test) by {@see \App\Support\LongevityIndex}.
 */
class LongevitySnapshot extends Model
{
    protected $fillable = ['profile_id', 'captured_on', 'titan_age', 'chronological_age', 'delta', 'confidence', 'metrics'];

    protected $casts = [
        'captured_on' => 'date',
        'titan_age' => 'float',
        'chronological_age' => 'float',
        'delta' => 'float',
        'metrics' => 'array',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }
}
