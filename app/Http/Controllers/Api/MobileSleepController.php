<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\SleepCoach;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The native Sleep view: last night's stages + the need/debt/performance read (SleepCoach) and a
 * short history of recent nights for the trend.
 */
class MobileSleepController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $profile = $request->user()->profile ?? $request->user()->ensureProfile();

        $nights = $profile->sleepLogs()->orderByDesc('slept_at')->limit(14)->get()
            ->map(fn ($s) => [
                'date' => $s->slept_at?->toDateString(),
                'duration_min' => $s->duration_min,
                'quality' => $s->quality,
                'deep_min' => $s->deep_min,
                'rem_min' => $s->rem_min,
                'light_min' => $s->light_min,
                'awake_min' => $s->awake_min,
            ])->values();

        return response()->json([
            'assess' => SleepCoach::assess($profile),   // need_h, debt_h, last_h, performance_pct, band, label, advice
            'nights' => $nights,
        ]);
    }
}
