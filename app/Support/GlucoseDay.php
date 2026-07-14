<?php

namespace App\Support;

use App\Models\GlucoseReading;
use App\Models\Profile;
use Illuminate\Support\Carbon;

/**
 * A day's glucose curve + headline metrics for the app surface (CGM_INTEGRATION P1). Readings are stored
 * UTC; we window by the user's LOCAL day and return points in local time so the curve lines up with the
 * day. Honest gaps: no reading = no point (the UI draws a gap, never interpolates). Wellness, not medical.
 */
class GlucoseDay
{
    public static function forProfile(Profile $profile, ?string $date = null): array
    {
        $tz = $profile->settings['timezone'] ?? config('app.timezone', 'UTC');
        $day = $date ? Carbon::parse($date, $tz) : Carbon::now($tz);
        $start = $day->copy()->startOfDay();
        $end = $start->copy()->addDay();

        // Query in UTC (the stored frame), then present local.
        $readings = $profile->glucoseReadings()
            ->whereBetween('taken_at', [$start->copy()->utc(), $end->copy()->utc()])
            ->orderBy('taken_at')
            ->get(['taken_at', 'mg_dl', 'trend']);

        $values = $readings->pluck('mg_dl')->map(fn ($v) => (int) $v)->all();
        $points = $readings->map(fn ($r) => [
            't' => $r->taken_at->copy()->setTimezone($tz)->toIso8601String(),
            'mg' => (int) $r->mg_dl,
        ])->values()->all();

        // Fasting/overnight glucose: mean of the local 00:00–06:00 readings (a clean baseline signal).
        $overnight = $readings->filter(fn ($r) => $r->taken_at->copy()->setTimezone($tz)->hour < 6)
            ->pluck('mg_dl')->all();

        $cfg = GlucoseConnection::config($profile);
        $lastAt = $profile->glucoseReadings()->max('taken_at');

        return array_filter([
            'date' => $start->toDateString(),
            'has_data' => $points !== [],
            'range_low' => GlucoseMetrics::RANGE_LOW,
            'range_high' => GlucoseMetrics::RANGE_HIGH,
            'points' => $points,
            'summary' => GlucoseMetrics::summary($values),
            'overnight_mg_dl' => $overnight !== [] ? (int) round(array_sum($overnight) / count($overnight)) : null,
            'disclaimer' => GlucoseMetrics::DISCLAIMER,
            'status' => [
                'connected' => $cfg !== null && $cfg['enabled'],
                'provider' => $cfg['provider'] ?? null,
                'last_reading_at' => $lastAt ? Carbon::parse($lastAt)->setTimezone($tz)->toIso8601String() : null,
                'fresh' => $lastAt ? Carbon::parse($lastAt)->gt(Carbon::now()->subMinutes(15)) : false,
            ],
        ], fn ($v) => $v !== null);
    }
}
