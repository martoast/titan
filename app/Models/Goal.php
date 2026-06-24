<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A measurable, time-bound goal (e.g. target bodyweight by a date). Progress + projection come from
 * the relevant trend (App\Support\WeightTrend for weight). One active goal per (profile, metric).
 */
class Goal extends Model
{
    use HasFactory;

    protected $fillable = [
        'profile_id', 'metric', 'direction', 'start_value', 'target_value',
        'target_date', 'unit', 'status', 'achieved_at',
    ];

    protected function casts(): array
    {
        return [
            'start_value' => 'float', 'target_value' => 'float',
            'target_date' => 'date', 'achieved_at' => 'datetime',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }
}
