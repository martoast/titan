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
    /**
     * Merge a day's activity from a source. **Band-primary, Apple-Health fallback — per day; never max
     * across the two, never sum.** The band (`updated_via='device:summary'`) is authoritative for the
     * cumulative fields; `apple_health` only fills a day the band hasn't claimed. This supersedes the old
     * "per-day max can't double-count" rationale — `max()` let the phone override the band whenever its
     * count was higher, so a walk logged by BOTH surfaced the (usually larger) phone number over the
     * trusted device. See tasks/reviews/2026-07-13-steps-band-primary-not-max-over-apple-health.md.
     */
    public static function mergeDaily(int $profileId, string $date, array $fields, array $meta = []): self
    {
        $row = static::firstOrNew(['profile_id' => $profileId, 'date' => $date]);

        $incomingBand = ($meta['updated_via'] ?? null) === 'device:summary';
        $rowBandOwned = ($row->updated_via ?? null) === 'device:summary';   // read BEFORE overwriting meta

        // A NON-ZERO band value claims the day. A band write of 0 (early morning, no steps yet) must NOT
        // lock out the health fallback, so it doesn't take ownership until it actually reports something.
        $bandContributed = false;
        foreach (['steps', 'mvpa_min', 'active_kcal', 'floors', 'distance_km'] as $k) {
            $val = $fields[$k] ?? null;
            if ($val === null) {
                continue;
            }
            $row->{$k} = self::mergeCumulativeValue($row->{$k} ?? 0, $val, $incomingBand, $rowBandOwned);
            if ($incomingBand && $val > 0) {
                $bandContributed = true;
            }
        }

        // Hourly steps: same ownership rule — a health write must not clobber a band-owned day's hourly.
        if (($fields['hourly'] ?? null) !== null && ($incomingBand || ! $rowBandOwned)) {
            $row->hourly = $fields['hourly'];
        }

        // Meta: apply, but a health write never downgrades a band-owned row's source/updated_via, and a
        // band-0 first write doesn't get to claim ownership yet (so health can still fill the day).
        $bandOwnsAfter = $rowBandOwned || $bandContributed;
        foreach ($meta as $k => $v) {
            $isOwnerLabel = $k === 'source' || $k === 'updated_via';
            if ($isOwnerLabel && ! $incomingBand && $rowBandOwned) {
                continue;   // keep the band's ownership labels
            }
            if ($isOwnerLabel && $incomingBand && ! $bandOwnsAfter) {
                continue;   // band-0 hasn't earned ownership of the day
            }
            $row->{$k} = $v;
        }

        $row->save();

        return $row;
    }

    /**
     * The band-primary merge decision for ONE cumulative field (pure — unit-tested). Given the current
     * stored value and an incoming one, returns the value to keep:
     *  - incoming band, non-zero → take over a health value / grow monotonically across band re-syncs;
     *  - incoming band, zero     → keep what's there (don't overwrite with 0, don't lock out health);
     *  - incoming health         → fill only when the band doesn't already own the day.
     *
     * @param  int|float  $cur
     * @param  int|float  $val
     * @return int|float
     */
    public static function mergeCumulativeValue($cur, $val, bool $incomingBand, bool $rowBandOwned)
    {
        $cur = $cur ?? 0;
        if ($incomingBand) {
            if ($val > 0) {
                return $rowBandOwned ? max($cur, $val) : $val;   // grow if owned; else take over from health
            }

            return $rowBandOwned ? max($cur, $val) : $cur;       // band-0: keep current, don't claim
        }

        return $rowBandOwned ? $cur : max($cur, $val);           // health fills only an unclaimed day
    }
}
