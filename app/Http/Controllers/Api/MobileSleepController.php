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

        $nights = $profile->sleepLogs()->nights()->orderByDesc('slept_at')->orderByDesc('id')->limit(14)->get()
            ->map(fn ($s) => [
                'date' => $s->slept_at?->toDateString(),
                'duration_min' => $s->duration_min,
                'quality' => $s->quality,
                'deep_min' => $s->deep_min,
                'rem_min' => $s->rem_min,
                'light_min' => $s->light_min,
                'awake_min' => $s->awake_min,
                // Progressive summary: 'computing' ⇒ render the loading card (duration/bed/wake are already
                // real; stages fill in when it flips to 'final'). coverage = how complete the sampling was.
                'stage_status' => $s->stage_status,
                'coverage' => $s->coverage,
                'bedtime' => $s->bedtime,
                'wake_time' => $s->wake_time,
            ])->values();

        return response()->json([
            'assess' => SleepCoach::assess($profile),   // need_h, debt_h, last_h, performance_pct, band, label, advice
            // The Whoop-style breakdown: performance %, hours vs need, stages (min + %), efficiency,
            // restorative (deep+REM), debt, respiratory rate, consistency.
            'detail' => \App\Support\SleepDetail::forProfile($profile),
            // Sleep-as-sessions: TODAY's every sleep session (overnight + each nap), each with its own full
            // detail + v2 timeline, above a daily aggregate (total asleep + combined stages). Grouped on the
            // user's local day so a nap isn't dropped or mis-dated. This is what the sessions list + today
            // card render; `detail`/`nights` above stay for the existing single-night surfaces.
            'today' => \App\Support\SleepDetail::sessionsForDay($profile),
            // The most recent night's compute state — the app shows a loading card while this is 'computing'
            // and swaps to the full stats in place when it becomes 'final'.
            'last_status' => $nights->first()['stage_status'] ?? null,
            'nights' => $nights,
        ]);
    }
}
