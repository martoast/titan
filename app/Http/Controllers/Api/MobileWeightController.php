<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\WeightTrend;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The native app's weight screen — the smoothed "true weight" trend, weekly rate, goal projection,
 * and the daily series for the chart. Also logs a weigh-in.
 */
class MobileWeightController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $profile = $request->user()->profile ?? $request->user()->ensureProfile();

        return response()->json(
            WeightTrend::card($profile) + ['series' => WeightTrend::series($profile, 120)]
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'weight_kg' => ['required', 'numeric', 'min:20', 'max:400'],
            'body_fat_pct' => ['sometimes', 'numeric', 'min:2', 'max:70'],
            'taken_at' => ['sometimes', 'date'],
        ]);
        $profile = $request->user()->profile ?? $request->user()->ensureProfile();

        // ONE weigh-in per day, latest wins. `taken_at` is a DATE column, so a second log on the same day
        // used to insert a second row for that date — and the trend then read the FIRST of them, so
        // correcting a typo silently did nothing and left the duplicate behind. A daily EMA wants one
        // canonical reading per day anyway.
        $takenAt = Carbon::parse($data['taken_at'] ?? now())->toDateString();

        $values = ['weight_kg' => round($data['weight_kg'], 2)];
        // Only overwrite body fat when this weigh-in actually carried one — otherwise correcting the
        // weight would erase a body-fat reading the earlier entry recorded.
        if (array_key_exists('body_fat_pct', $data)) {
            $values['body_fat_pct'] = round($data['body_fat_pct'], 1);
        }

        // Matched with whereDate, NOT updateOrCreate's `=`. `taken_at` is cast to `date`, which serialises
        // as "2026-09-09 00:00:00" on SQLite (it has no DATE type), so an equality match against the bare
        // date silently misses and inserts a duplicate — the very bug this is fixing. whereDate compares
        // the date part on both engines.
        $existing = $profile->bodyMetrics()->whereDate('taken_at', $takenAt)->latest('id')->first();

        if ($existing) {
            $existing->fill($values)->save();
        } else {
            $profile->bodyMetrics()->create($values + ['taken_at' => $takenAt]);
        }

        return response()->json(WeightTrend::card($profile));
    }
}
