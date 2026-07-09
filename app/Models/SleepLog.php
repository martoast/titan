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
    ];

    protected function casts(): array
    {
        return ['slept_at' => 'date', 'session_start' => 'datetime', 'is_nap' => 'boolean', 'hypnogram' => 'array'];
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
