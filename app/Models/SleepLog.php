<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One night's sleep for a profile. Duration is stored in minutes; helpers expose it
 * as hours/min for display. The optional stage breakdown (deep/rem/light/awake)
 * powers the stacked bar -- present only when a wearable supplied it.
 */
class SleepLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'profile_id', 'slept_at', 'is_nap', 'session_start', 'duration_min', 'quality',
        'deep_min', 'rem_min', 'light_min', 'awake_min',
        'bedtime', 'wake_time', 'notes', 'updated_via', 'hypnogram',
        'stage_status', 'coverage', 'finalized_at',
    ];

    protected function casts(): array
    {
        return [
            'slept_at' => 'date', 'session_start' => 'datetime', 'is_nap' => 'boolean',
            'hypnogram' => 'array', 'finalized_at' => 'datetime', 'coverage' => 'float',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    /** Only full nights — excludes naps, which must never surface as "last night", pollute a night
     *  baseline, or plot on the nightly trend. */
    public function scopeNights($query)
    {
        return $query->where('is_nap', false);
    }

    /** Only FINALIZED nights — excludes the in-flight `computing` placeholder (progressive summary), whose
     *  stages/quality are still NULL. Any reader that renders a completed-night card (stages, quality,
     *  performance) must use this so it never shows a half-computed night as if it were done. The one place
     *  that WANTS the computing row is the loading-card feed (MobileSleepController's nights list). Rows
     *  predating the feature default to 'final', so this is a no-op for them. */
    public function scopeFinal($query)
    {
        return $query->where('stage_status', 'final');
    }

    /** Whole hours of sleep (floor of duration). */
    public function getHoursAttribute(): int
    {
        return intdiv((int) $this->duration_min, 60);
    }

    /** Remaining minutes after the whole hours. */
    public function getMinutesAttribute(): int
    {
        return ((int) $this->duration_min) % 60;
    }

    /** "7h 24m" style label. */
    public function durationLabel(): string
    {
        return $this->hours.'h '.str_pad((string) $this->minutes, 2, '0', STR_PAD_LEFT).'m';
    }

    /** True when a wearable supplied a sleep-stage breakdown. */
    public function hasStages(): bool
    {
        return ($this->deep_min ?? 0) + ($this->rem_min ?? 0) + ($this->light_min ?? 0) > 0;
    }
}
