<?php

namespace App\Support;

use App\Models\Meal;
use App\Models\Profile;
use Illuminate\Support\Carbon;

/**
 * Rank a user's recent meals by their glucose RESPONSE (CGM_INTEGRATION P2) — the "spikiest vs steadiest"
 * loop that turns a curve into an action ("swap the white rice — it spiked you most this week"). Dedupes
 * by meal name (a food's TYPICAL response, averaged over how often they eat it), so it's about the food,
 * not one lucky day. Wellness framing: a spike is normal physiology; this is optimization, not diagnosis.
 */
class GlucoseMealRanking
{
    private const LOOKBACK_DAYS = 14;
    private const MIN_SAMPLES = 1;   // a food needs at least this many measured responses to rank

    /**
     * @return array{spikiest:array<int,array<string,mixed>>,steadiest:array<int,array<string,mixed>>,measured:int}|null
     *   null when there's no CGM data to rank against.
     */
    public static function forProfile(Profile $profile, int $limit = 3): ?array
    {
        $appTz = config('app.timezone', 'UTC');
        $since = Carbon::now($appTz)->subDays(self::LOOKBACK_DAYS)->startOfDay();

        // One readings pull for the whole window; each meal's response is computed against it (O(1) per meal).
        $readings = $profile->glucoseReadings()->where('taken_at', '>=', $since->copy()->subMinutes(20))
            ->orderBy('taken_at')->get(['taken_at', 'mg_dl']);
        if ($readings->isEmpty()) {
            return null;
        }

        $meals = Meal::where('profile_id', $profile->id)->where('eaten_at', '>=', $since)->get();

        // Group each measured response by food name → the food's average peak Δ.
        $byFood = [];
        foreach ($meals as $meal) {
            $resp = GlucoseResponse::forMeal($profile, $meal, $readings);
            if ($resp === null) {
                continue;
            }
            $name = trim((string) $meal->name);
            $byFood[$name] ??= ['name' => $name, 'deltas' => []];
            $byFood[$name]['deltas'][] = $resp['peak_delta'];
        }

        $ranked = [];
        foreach ($byFood as $food) {
            if (count($food['deltas']) < self::MIN_SAMPLES) {
                continue;
            }
            $avg = array_sum($food['deltas']) / count($food['deltas']);
            $ranked[] = [
                'name' => $food['name'],
                'avg_peak_delta' => (int) round($avg),
                'times' => count($food['deltas']),
                'spike' => GlucoseResponse::spikeBand((int) round($avg)),
            ];
        }
        if ($ranked === []) {
            return null;
        }

        usort($ranked, fn ($a, $b) => $b['avg_peak_delta'] <=> $a['avg_peak_delta']);

        return [
            'measured' => count($ranked),
            'spikiest' => array_slice($ranked, 0, $limit),
            'steadiest' => array_slice(array_reverse($ranked), 0, $limit),
        ];
    }
}
