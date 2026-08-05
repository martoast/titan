<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

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
        'bedtime', 'wake_time', 'notes', 'updated_via', 'hypnogram', 'hr_series', 'motion_series',
        'stage_status', 'coverage', 'low_confidence', 'stages_low_confidence', 'finalized_at',
    ];

    protected function casts(): array
    {
        return [
            'slept_at' => 'date', 'session_start' => 'datetime', 'is_nap' => 'boolean',
            'hypnogram' => 'array', 'hr_series' => 'array', 'motion_series' => 'array',
            'finalized_at' => 'datetime', 'coverage' => 'float', 'low_confidence' => 'boolean',
            // Distinct from low_confidence: the night's DURATION is trustworthy, its stage SPLIT isn't.
            'stages_low_confidence' => 'boolean',
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

    /** Progressive-summary compute states (docs/PROGRESSIVE_SUMMARY.md). A confirmed night is written first as
     *  a lightweight COMPUTING placeholder (real duration/bed/wake, stages/quality still NULL) then refined in
     *  place to FINAL. */
    public const STATUS_COMPUTING = 'computing';

    public const STATUS_FINAL = 'final';

    /** Only FINALIZED nights — excludes the in-flight `computing` placeholder, whose stages/quality are still
     *  NULL (and whose duration is the raw bed→wake ENVELOPE, not measured asleep time). Any reader that
     *  surfaces a night as a completed metric (stages, quality, performance, debt) must use this so it never
     *  treats a half-computed night as done. The one place that WANTS the computing row is the loading-card
     *  feed (MobileSleepController's nights list). Rows predating the feature default to FINAL — a no-op. */
    public function scopeFinal($query)
    {
        return $query->where('stage_status', self::STATUS_FINAL);
    }

    /** How stale the most-recent recorded night may be and still count as "last night". A night is only
     *  last night's sleep if its wake date is today or yesterday; if the band wasn't worn for longer, the
     *  freshest row is history, NOT current sleep. */
    public const RECENT_NIGHT_GRACE_DAYS = 1;

    /** The earliest `slept_at` (wake date) that still qualifies as "last night", as a Y-m-d string.
     *  One source of truth for the freshness rule so every current-day reader agrees. */
    public static function recentNightFloor(?string $tz = null, ?Carbon $asOf = null): string
    {
        $base = $asOf ? $asOf->copy() : Carbon::now($tz ?: config('app.timezone', 'UTC'));

        return $base->subDays(self::RECENT_NIGHT_GRACE_DAYS)->toDateString();
    }

    /** Constrain to nights recent enough to be "last night" (today or yesterday). Use this on any reader
     *  that presents a night as CURRENT — the home sleep card, the Sleep-performance ring, the recovery
     *  sleep term — so a stale night (band not worn for a day+) can't masquerade as last night's sleep.
     *  Deliberately NOT used by debt/streak/history readers: a MISSED night still owes sleep debt. */
    public function scopeRecentNight($query, ?string $tz = null, ?Carbon $asOf = null)
    {
        return $query->where('slept_at', '>=', self::recentNightFloor($tz, $asOf));
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
