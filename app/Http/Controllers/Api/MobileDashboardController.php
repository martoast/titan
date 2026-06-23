<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DailyActivity;
use App\Models\RecoveryLog;
use App\Models\SleepLog;
use App\Support\Readiness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only dashboard aggregate for the native app — readiness + latest recovery, sleep and
 * activity in one call, so the app's Dashboard/Recovery/Sleep screens don't scrape Blade pages.
 * Reuses the same support classes the coach's tools use. auth.any (bearer or session).
 */
class MobileDashboardController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        $profile = $request->user()->profile ?? $request->user()->ensureProfile();

        $readiness = $this->safe(fn () => Readiness::compute($profile));

        $rec = RecoveryLog::where('profile_id', $profile->id)
            ->orderByDesc('logged_at')->orderByDesc('id')->first();

        $sleep = SleepLog::where('profile_id', $profile->id)
            ->orderByDesc('slept_at')->first();

        $activity = DailyActivity::where('profile_id', $profile->id)
            ->orderByDesc('date')->first();

        $confidence = ($rec && class_exists(\App\Support\RecoveryConfidence::class))
            ? $this->safe(fn () => \App\Support\RecoveryConfidence::assess($profile, $rec))
            : null;

        return response()->json([
            'readiness' => $readiness,
            'recovery' => $rec ? [
                'logged_at' => $rec->logged_at,
                'hrv_ms' => $rec->hrv_ms,
                'resting_hr' => $rec->resting_hr,
                'resp_rate' => $rec->resp_rate,
                'stress' => $rec->stress,
                'soreness' => $rec->soreness,
                'mood' => $rec->mood,
                'energy' => $rec->energy,
                'updated_via' => $rec->updated_via,
                'confidence' => $confidence,
            ] : null,
            'sleep' => $sleep ? [
                'slept_at' => $sleep->slept_at,
                'duration_min' => $sleep->duration_min,
                'quality' => $sleep->quality,
                'deep_min' => $sleep->deep_min,
                'rem_min' => $sleep->rem_min,
                'light_min' => $sleep->light_min,
                'awake_min' => $sleep->awake_min,
            ] : null,
            'activity' => $activity ? [
                'date' => $activity->date,
                'steps' => $activity->steps,
                'active_kcal' => $activity->active_kcal,
                'floors' => $activity->floors,
                'distance_km' => $activity->distance_km,
            ] : null,
        ]);
    }

    /** Run a closure, returning null on any failure (missing support class / no baseline). */
    private function safe(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (\Throwable) {
            return null;
        }
    }
}
