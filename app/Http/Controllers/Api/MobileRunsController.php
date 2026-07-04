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
            // NULL (not []) when empty: PHP's empty array JSON-encodes as `[]`, but this field is a
            // MAP — the iOS client decodes [String: BestEffort]?, and `[]` fails the whole RunDetail
            // decode (every lift + every routeless run showed a "failed" summary). `?:` also catches
            // a stored-empty array, which round-trips from biosignal's {} through the array cast.
            'best_efforts' => $session->best_efforts ?: null,
            'relative_effort' => $session->relative_effort,
            'avg_hr' => $session->avg_hr,
            'max_hr' => $session->max_hr,
            // Time-in-HR-zone + training load — the stats that headline a LIFT summary (the average is
            // dragged down by inter-set rest, so the peak + minutes in the red tell the real story).
            'hr_zones' => $session->hr_zones,
            'trimp' => $session->trimp !== null ? (float) $session->trimp : null,
            'hr_quality' => $session->hr_quality !== null ? (float) $session->hr_quality : null,
            'workout_hrv_ms' => $session->workout_hrv_ms !== null ? (float) $session->workout_hrv_ms : null,
            'calories_kcal' => $session->calories_kcal,
            'vo2max' => $session->vo2max !== null ? (float) $session->vo2max : null,
            'fitness_level' => $session->fitness_level,
            'hrr_bpm' => $session->hrr_bpm !== null ? (float) $session->hrr_bpm : null,
            'bounds' => $session->route_bounds,
            'polyline' => $session->route_polyline,
            'map_url_large' => $session->hasRoute() ? $session->staticMapUrl(900, 520) : null,
            // Strength detail (exercises + sets/reps) for a lifting session — null for a run.
            'strength' => $this->strengthDetail($profile, $session),
            'units' => $imperial ? 'imperial' : 'metric',
        ]);
    }

    /**
     * Detected exercises + sets for a lifting session. The strength seal writes a Workout keyed on the
     * same (profile, started_at) as the ActivitySession (see SealActivityJob::sealStrength), so we look
     * it up by that. Returns null for a cardio run (no Workout) so the client can branch on its presence.
     *
     * @return array<string,mixed>|null
     */
    private function strengthDetail(\App\Models\Profile $profile, ActivitySession $session): ?array
    {
        if ($session->activity_type !== 'strength' || $session->started_at === null) {
            return null;
        }

        $workout = $profile->workouts()
            ->where('performed_at', $session->started_at)
            ->with(['exercises.exercise', 'exercises.sets'])
            ->first();
        if (! $workout) {
            return null;
        }

        $exercises = $workout->exercises->map(fn ($we) => [
            'name' => $we->exercise?->name ?? 'Exercise',
            'muscle_group' => $we->exercise?->muscle_group,
            'sets' => $we->sets->map(fn ($s) => [
                'set_number' => $s->set_number,
                'reps' => $s->reps,
                'weight_kg' => $s->weight_kg !== null ? (float) $s->weight_kg : null,
            ])->values(),
        ])->values();

        return [
            'total_sets' => $workout->exercises->sum(fn ($we) => $we->sets->count()),
            'total_reps' => $workout->exercises->sum(fn ($we) => $we->sets->sum('reps')),
            'exercises' => $exercises,
        ];
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
