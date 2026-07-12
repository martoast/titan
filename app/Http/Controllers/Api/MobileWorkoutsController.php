<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivitySession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Manual workout logging for the native app. Not every workout comes off the band — a gym session or
 * a run you did without the watch still deserves to count. This creates a lightweight ActivitySession
 * (source='manual') from just a type + duration (+ optional when/intensity): no HR, no route, no
 * calories. It shows in the Workouts list, lights the consistency streak, and — via an sRPE-style
 * TRIMP estimate from duration × intensity — registers on the strain ring so the day still adds up.
 *
 * @see MobileRunsController (the list/detail reader — a manual session flows through it unchanged)
 */
class MobileWorkoutsController extends Controller
{
    /** Perceived intensity → per-minute TRIMP factor. Calibrated so a 45-min session reads sanely on
     *  the 0–21 strain scale (easy ≈ 5, moderate ≈ 8, hard ≈ 14). Mirrors Banister TRIMP at HRR ≈
     *  0.4 / 0.6 / 0.8 — the honest range for a self-reported effort with no heart-rate data. */
    private const TRIMP_PER_MIN = ['easy' => 0.6, 'moderate' => 1.2, 'hard' => 2.4];

    public function store(Request $request): JsonResponse
    {
        $profile = $request->user()->profile ?? $request->user()->ensureProfile();

        $data = $request->validate([
            'activity_type' => ['required', Rule::in(['run', 'cycle', 'walk', 'strength', 'other'])],
            'duration_min' => ['required', 'integer', 'min:1', 'max:1440'],
            'started_at' => ['nullable', 'date'],
            'perceived_intensity' => ['nullable', Rule::in(['easy', 'moderate', 'hard'])],
        ]);

        // When it happened: the user's picked time (ISO8601 with offset) as a true UTC instant, matching
        // the activity_sessions convention (see SealActivityJob / WorkoutStreak). Default = now; never let
        // a clock-skewed client log the future.
        $start = isset($data['started_at']) ? Carbon::parse($data['started_at']) : Carbon::now();
        if ($start->isFuture()) {
            $start = Carbon::now();
        }
        $start = $start->setTimezone('UTC');

        $intensity = $data['perceived_intensity'] ?? 'moderate';
        $trimp = round($data['duration_min'] * self::TRIMP_PER_MIN[$intensity], 1);

        $session = $profile->activitySessions()->create([
            'source' => 'manual',
            'activity_type' => $data['activity_type'],
            'started_at' => $start,
            'ended_at' => $start->copy()->addMinutes($data['duration_min']),
            'duration_min' => $data['duration_min'],
            // User-logged: it IS training (counts for the streak) and fully confident in what it is.
            'is_training' => true,
            'activity_confidence' => 1.0,
            'perceived_intensity' => $intensity,
            'trimp' => $trimp,
            'updated_via' => 'manual',
        ]);

        return response()->json([
            'id' => $session->id,
            'title' => $session->title(),
            'activity_type' => $session->activity_type,
            'started_at' => $session->started_at?->toIso8601String(),
            'duration_min' => $session->duration_min,
            'perceived_intensity' => $session->perceived_intensity,
        ], 201);
    }
}
