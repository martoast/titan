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

        // Biological age — the "how old is your body" hero stat (PhenoAge + Fitness Age combiner).
        $bio = $this->safe(fn () => \App\Support\BiologicalAge::assess($profile));

        return response()->json([
            'readiness' => $readiness,
            'bio_age' => $bio ? [
                'biological_age' => $bio['biological_age'] ?? null,
                'chronological_age' => $bio['chronological_age'] ?? null,
                'delta' => $bio['delta'] ?? null,
                'label' => $bio['label'] ?? null,
                'confidence' => $bio['confidence'] ?? null,
                'fitness_age' => $bio['fitness_age'] ?? null,
            ] : null,
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

    /**
     * GET /api/me/trends?metric=hrv|rhr&days=30 — a series for the app's trend charts.
     * Returns `{ metric, points: [{date, value}] }` oldest→newest.
     */
    public function trends(Request $request): JsonResponse
    {
        $data = $request->validate([
            'metric' => ['nullable', 'in:hrv,rhr'],
            'days' => ['nullable', 'integer', 'min:2', 'max:180'],
        ]);
        $metric = $data['metric'] ?? 'hrv';
        $days = $data['days'] ?? 30;
        $col = $metric === 'rhr' ? 'resting_hr' : 'hrv_ms';
        $profile = $request->user()->profile ?? $request->user()->ensureProfile();

        $rows = RecoveryLog::where('profile_id', $profile->id)
            ->whereNotNull($col)
            ->where('logged_at', '>=', now()->subDays($days)->toDateString())
            ->orderBy('logged_at')
            ->get(['logged_at', $col]);

        return response()->json([
            'metric' => $metric,
            'points' => $rows->map(fn ($r) => [
                'date' => $r->logged_at,
                'value' => (float) $r->getAttribute($col),
            ])->values(),
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
