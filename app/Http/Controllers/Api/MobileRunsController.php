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

        // Consistency header for the Workouts screen: the consecutive-day streak (the number that
        // matters), plus this-week / this-month counts and the days lit on the calendar strip.
        $tz = (string) $request->query('tz', config('app.timezone', 'UTC'));
        $streak = \App\Support\WorkoutStreak::forProfile($profile, $tz);

        return response()->json([
            'runs' => $runs->map(fn (ActivitySession $s) => $this->summary($s))->values(),
            'streak' => [
                'current' => $streak['current'],
                'longest' => $streak['longest'],
                'this_week' => $streak['this_week'],
                'this_month' => $streak['this_month'],
                'worked_out_today' => $streak['worked_out_today'],
            ],
            'active_days' => $streak['active_days'],
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
     * Exercises + sets for a lifting session. Sets are written only when the user explicitly logs them
     * via the coach (log_set / log_workout) — the seal no longer auto-detects them — keyed on the same
     * (profile, started_at) as the ActivitySession, so we look it up by that. Returns null when nothing
     * was logged (or for a cardio run) so the client can branch on its presence.
     *
     * @return array<string,mixed>|null
     */
    private function strengthDetail(\App\Models\Profile $profile, ActivitySession $session): ?array
    {
        if ($session->activity_type !== 'strength' || $session->started_at === null) {
            return null;
        }

        // Sets attach DETERMINISTICALLY by FK now: SealActivityJob links each logged Workout to the
        // ActivitySession it was performed inside (workouts.activity_session_id), and the coach's log_workout
        // does the same at log time. So a direct lookup — no more read-time ±20-min proximity guessing, which
        // mis-attached (or doubled/dropped) sets when two lifts sat close together. Most recent first covers a
        // session with more than one logged block.
        $workout = $profile->workouts()
            ->where('activity_session_id', $session->id)
            ->with(['exercises.exercise', 'exercises.sets'])
            ->orderByDesc('performed_at')
            ->first();

        // Legacy fallback: workouts logged BEFORE the FK existed (and any the seal hasn't linked yet) carry a
        // NULL activity_session_id — a bare FK lookup would show them as strength:null (e.g. the App Store
        // demo account's seeded lifts). Match those by containment to THIS session's span, scoped to
        // whereNull so an already-FK-linked workout is never re-stolen (no cross-session mis-attribution).
        // started_at/ended_at are UTC wall-clock (the seal's convention); the Eloquent cast would mislabel
        // them as app-tz, so read raw as UTC and convert to app tz before comparing to performed_at (written
        // app-tz by the coach) — identical to SealActivityJob::linkWorkoutsToSession.
        if (! $workout) {
            $tz = config('app.timezone');
            $startLocal = \Illuminate\Support\Carbon::parse($session->getRawOriginal('started_at'), 'UTC')->setTimezone($tz);
            $endRaw = $session->getRawOriginal('ended_at');
            $endLocal = $endRaw
                ? \Illuminate\Support\Carbon::parse($endRaw, 'UTC')->setTimezone($tz)
                : $startLocal->copy()->addHours(4);
            $workout = $profile->workouts()
                ->whereNull('activity_session_id')
                ->whereBetween('performed_at', [$startLocal->copy()->subMinutes(20), $endLocal->copy()->addMinutes(20)])
                ->with(['exercises.exercise', 'exercises.sets'])
                ->orderByDesc('performed_at')
                ->first();
        }
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
