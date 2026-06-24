<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A cardio / wearable activity session (run, ride, walk…) sealed from the band's workout
 * windows. See SealActivityJob. Strength training lives in {@see Workout} instead.
 */
class ActivitySession extends Model
{
    protected $fillable = [
        'profile_id', 'source', 'started_at', 'ended_at', 'duration_min',
        'activity_type', 'activity_confidence',
        'distance_km', 'avg_hr', 'max_hr', 'hr_source', 'hr_quality', 'hr_zones', 'trimp', 'calories_kcal',
        'vo2max', 'fitness_level', 'hrr_bpm', 'updated_via',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'activity_confidence' => 'float',
            'distance_km' => 'float',
            'hr_quality' => 'float',
            'hr_zones' => 'array',
            'trimp' => 'float',
            'vo2max' => 'float',
            'hrr_bpm' => 'float',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    /**
     * Honest label for how this session's HR was derived — drives the trust badge in the UI.
     * 'ppg_inmotion' = recomputed from raw PPG with motion-artifact suppression (accurate);
     * 'chest_strap'  = a paired BLE strap (reference-grade); 'onchip' = the wrist's bare register.
     */
    public function hrSourceLabel(): ?string
    {
        return match ($this->hr_source) {
            'chest_strap' => 'chest strap',
            'ppg_inmotion' => 'motion-corrected',
            'onchip' => 'wrist',
            default => null,
        };
    }

    /** Minutes in the hard zones (Z4+Z5, ≥80% HRmax) — what a strong lifting day actually shows. */
    public function hardZoneMin(): float
    {
        $z = $this->hr_zones ?? [];

        return round((float) ($z['z4'] ?? 0) + (float) ($z['z5'] ?? 0), 1);
    }

    /** A human label for the activity (e.g. "Run", "Ride"). */
    public function title(): string
    {
        return match ($this->activity_type) {
            'run' => 'Run',
            'walk' => 'Walk',
            'cycle' => 'Ride',
            'stairs' => 'Stairs',
            'strength' => 'Strength',
            'rest' => 'Rest',
            default => 'Workout',
        };
    }
}
