<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\WeightTrend;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        $profile->bodyMetrics()->create([
            'weight_kg' => round($data['weight_kg'], 2),
            'body_fat_pct' => isset($data['body_fat_pct']) ? round($data['body_fat_pct'], 1) : null,
            'taken_at' => $data['taken_at'] ?? now(),
        ]);

        return response()->json(WeightTrend::card($profile));
    }
}
