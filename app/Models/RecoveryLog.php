<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A daily recovery / stress snapshot for a profile. Objective signals (HRV, resting
 * HR) come from a wearable; stress / soreness / mood / energy are subjective 1-10
 * self-ratings. The controller blends these with sleep into a readiness score.
 */
class RecoveryLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'profile_id', 'logged_at', 'hrv_ms', 'resting_hr',
        'stress', 'soreness', 'mood', 'energy', 'notes',
    ];

    protected function casts(): array
    {
        return ['logged_at' => 'date'];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }
}
