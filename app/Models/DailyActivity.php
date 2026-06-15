<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A day of ambient movement for a profile (steps, MVPA minutes, active energy, floors, distance).
 * See {@see \App\Support\StepGoal} for the evidence-based daily target this drives.
 */
class DailyActivity extends Model
{
    protected $table = 'daily_activity';

    protected $fillable = [
        'profile_id', 'date', 'steps', 'mvpa_min', 'active_kcal', 'floors', 'distance_km',
        'hourly', 'source', 'updated_via',
    ];

    protected function casts(): array
    {
        return ['date' => 'date', 'distance_km' => 'float', 'hourly' => 'array'];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }
}
