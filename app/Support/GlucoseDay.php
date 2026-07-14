<?php

namespace App\Support;

use App\Models\GlucoseReading;
use App\Models\Meal;
use App\Models\Profile;
use Illuminate\Support\Carbon;

/**
 * A day's glucose curve + headline metrics + the meals overlaid on it (CGM_INTEGRATION P1/P2).
 *
 * TZ: glucose `taken_at` (and meals `eaten_at`) are stored in APP-TZ wall-clock — NOT UTC (see the
 * meal-timezone fault). So we window AND present in the app-tz frame: the query bounds are the user's
 * local day expressed in app-tz (mirror Macros::today), and points/markers keep their app-tz wall-clock so
 * the meal markers line up with the glucose spikes. (A prior `->utc()` here shifted the whole curve by the
 * app-vs-profile offset — it only "worked" because a full-day window still overlaps most readings.) Honest
 * gaps: no reading = no point (the UI draws a gap, never interpolates). Wellness, not medical.
 */
class GlucoseDay
{
    public static function forProfile(Profile $profile, ?string $date = null): array
    {
        $appTz = config('app.timezone', 'UTC');
        $tz = $profile->settings['timezone'] ?? $appTz;
        $day = $date ? Carbon::parse($date, $tz) : Carbon::now($tz);
        // The user's local day, expressed in the app-tz frame the readings are stored/displayed in.
        $start = $day->copy()->startOfDay()->setTimezone($appTz);
        $end = $start->copy()->addDay();

        $readings = $profile->glucoseReadings()
            ->whereBetween('taken_at', [$start, $end])
            ->orderBy('taken_at')
            ->get(['taken_at', 'mg_dl', 'trend']);

        $values = $readings->pluck('mg_dl')->map(fn ($v) => (int) $v)->all();
        $points = $readings->map(fn ($r) => [
            't' => $r->taken_at->toIso8601String(),   // app-tz wall-clock (matches the meal markers)
            'mg' => (int) $r->mg_dl,
        ])->values()->all();

        // Fasting/overnight glucose: mean of the 00:00–06:00 readings (a clean baseline signal).
        $overnight = $readings->filter(fn ($r) => $r->taken_at->hour < 6)->pluck('mg_dl')->all();

        // Meals overlaid on the curve (P2): each at its app-tz time, with its glucose response computed from
        // the SAME readings — so the marker can show "+55" where the spike is.
        $meals = Meal::where('profile_id', $profile->id)
            ->whereBetween('eaten_at', [$start, $end])->orderBy('eaten_at')->get();
        $mealMarkers = $meals->map(function (Meal $m) use ($profile, $readings) {
            $resp = GlucoseResponse::forMeal($profile, $m, $readings);

            return array_filter([
                't' => $m->eaten_at->toIso8601String(),
                'name' => $m->name,
                'peak_delta' => $resp['peak_delta'] ?? null,
                'spike' => $resp['spike'] ?? null,
            ], fn ($v) => $v !== null);
        })->values()->all();

        $cfg = GlucoseConnection::config($profile);
        $lastAt = $profile->glucoseReadings()->max('taken_at');

        return array_filter([
            'date' => $start->toDateString(),
            'has_data' => $points !== [],
            'range_low' => GlucoseMetrics::RANGE_LOW,
            'range_high' => GlucoseMetrics::RANGE_HIGH,
            'meals' => $mealMarkers ?: null,
            'points' => $points,
            'summary' => GlucoseMetrics::summary($values),
            'overnight_mg_dl' => $overnight !== [] ? (int) round(array_sum($overnight) / count($overnight)) : null,
            // Spikiest/steadiest foods over the last 2 weeks (P2) — turns the curve into an action.
            'ranking' => GlucoseMealRanking::forProfile($profile),
            'disclaimer' => GlucoseMetrics::DISCLAIMER,
            'status' => [
                'connected' => $cfg !== null && $cfg['enabled'],
                'provider' => $cfg['provider'] ?? null,
                'last_reading_at' => $lastAt ? Carbon::parse($lastAt)->toIso8601String() : null,   // app-tz
                'fresh' => $lastAt ? Carbon::parse($lastAt)->gt(Carbon::now()->subMinutes(15)) : false,
            ],
        ], fn ($v) => $v !== null);
    }
}
