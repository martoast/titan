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

    /**
     * Daily metrics that accumulate over the day (steps, MVPA, energy, floors, distance). The band
     * and the phone both measure the SAME day from different vantage points, so when they land on the
     * same row we take the per-field MAX, never the sum — that's the only merge that can't double-count
     * (within a day each source's running total only grows, so max keeps whichever device saw more,
     * e.g. the band during a phone-free walk). `hourly`/meta are latest-wins. Used by the wearable
     * (band) and Apple Health ingests; explicit MANUAL/coach sets bypass this and overwrite.
     *
     * @param  array<string,mixed>  $fields  cumulative metrics (+ optional 'hourly')
     * @param  array<string,mixed>  $meta    latest-wins fields (source, updated_via)
     */
    public static function mergeDaily(int $profileId, string $date, array $fields, array $meta = []): self
    {
        $row = static::firstOrNew(['profile_id' => $profileId, 'date' => $date]);

        foreach (['steps', 'mvpa_min', 'active_kcal', 'floors', 'distance_km'] as $k) {
            if (($fields[$k] ?? null) !== null) {
                $row->{$k} = max($row->{$k} ?? 0, $fields[$k]);
            }
        }
        if (($fields['hourly'] ?? null) !== null) {
            $row->hourly = $fields['hourly'];
        }
        foreach ($meta as $k => $v) {
            $row->{$k} = $v;
        }
        $row->save();

        return $row;
    }
}
