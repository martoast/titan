<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivitySession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Native run list + Strava-style detail. Cardio sessions sealed from the band (see SealActivityJob);
 * the route analytics (polyline, splits, elevation, best efforts, Relative Effort) come from the
 * biosignal /process/route pass. The map is a Mapbox Static Images URL the app loads as an image.
 */
class MobileRunsController extends Controller
{
    /** Recent cardio sessions (newest first) — the list rows. */
    public function index(Request $request): JsonResponse
    {
        $profile = $request->user()->profile ?? $request->user()->ensureProfile();

        $runs = $profile->activitySessions()
            ->orderByDesc('started_at')
            ->limit(50)
            ->get();

        return response()->json([
            'runs' => $runs->map(fn (ActivitySession $s) => $this->summary($s))->values(),
        ]);
    }

    /** Full run detail — the end-of-run summary. */
    public function show(Request $request, ActivitySession $session): JsonResponse
    {
        $profile = $request->user()->profile ?? $request->user()->ensureProfile();
        abort_unless($session->profile_id === $profile->id, 404);

        $imperial = ($profile->units ?? 'metric') === 'imperial';

        return response()->json($this->summary($session) + [
            'distance_source' => $session->distance_source,
            'moving_time_s' => $session->moving_time_s,
            'avg_pace_s_per_km' => $session->avg_pace_s_per_km,
            'gap_s_per_km' => $session->gap_s_per_km,
            'elevation_gain_m' => $session->elevation_gain_m,
            'elevation_loss_m' => $session->elevation_loss_m,
            'elevation_profile' => $session->elevation_profile ?? [],
            'splits' => $session->splits ?? ['km' => [], 'mi' => []],
            'best_efforts' => $session->best_efforts ?? [],
            'relative_effort' => $session->relative_effort,
            'avg_hr' => $session->avg_hr,
            'max_hr' => $session->max_hr,
            'workout_hrv_ms' => $session->workout_hrv_ms !== null ? (float) $session->workout_hrv_ms : null,
            'calories_kcal' => $session->calories_kcal,
            'vo2max' => $session->vo2max !== null ? (float) $session->vo2max : null,
            'hrr_bpm' => $session->hrr_bpm !== null ? (float) $session->hrr_bpm : null,
            'bounds' => $session->route_bounds,
            'polyline' => $session->route_polyline,
            'map_url_large' => $session->staticMapUrl(900, 520),
            'units' => $imperial ? 'imperial' : 'metric',
        ]);
    }

    /** The list-row shape — shared by index + the header of show. */
    private function summary(ActivitySession $s): array
    {
        return [
            'id' => $s->id,
            'title' => $s->title(),
            'activity_type' => $s->activity_type,
            'started_at' => $s->started_at?->toIso8601String(),
            'duration_min' => $s->duration_min,
            'distance_km' => $s->distance_km !== null ? (float) $s->distance_km : null,
            'avg_pace_s_per_km' => $s->avg_pace_s_per_km,
            'has_route' => $s->hasRoute(),
            'map_thumb_url' => $s->staticMapUrl(400, 220),
        ];
    }
}
