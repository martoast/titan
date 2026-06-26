<?php

namespace App\Services\Community;

use App\Models\Achievement;
use App\Models\ActivitySession;
use App\Models\Profile;
use Illuminate\Support\Carbon;

/**
 * Awards milestone badges. Evaluated after each seal (single-activity + current-window milestones)
 * and on a nightly pass (so time-window badges still land when nothing sealed that day).
 *
 * Each badge is earned once (unique profile_id+key). The catalog here is the single source of truth
 * for display copy — the API hands the catalog to the client so the badge wall renders without
 * hard-coding strings on every platform.
 */
class AchievementEngine
{
    /** key => [title, blurb, icon (SF Symbol), emoji] */
    public const CATALOG = [
        'first_run' => ['First run', 'Your first activity on Titan', 'figure.run', '🏃'],
        'first_5k' => ['5K', 'Ran 5 km in a single activity', 'flag.checkered', '🟢'],
        'first_10k' => ['10K', 'Ran 10 km in a single activity', 'flag.checkered.2.crossed', '🔵'],
        'first_half' => ['Half marathon', '21.1 km in one go', 'medal', '🥈'],
        'first_marathon' => ['Marathon', '42.2 km in one go', 'medal.fill', '🥇'],
        'effort_week_500' => ['Big week', '500+ effort in one week', 'bolt.fill', '⚡️'],
        'distance_100k_month' => ['Centurion', '100 km in a calendar month', 'figure.run.circle.fill', '💯'],
        'climb_1000m_month' => ['Mountain goat', '1,000 m climbed in a month', 'mountain.2.fill', '⛰️'],
        'streak_3w' => ['3-week streak', 'Active 3 weeks running', 'flame.fill', '🔥'],
        'streak_5w' => ['5-week streak', 'Active 5 weeks running', 'flame.fill', '🔥'],
        'early_bird' => ['Early bird', 'Started a run before 6am', 'sunrise.fill', '🌅'],
        'night_owl' => ['Night owl', 'Started a run after 9pm', 'moon.stars.fill', '🌙'],
    ];

    /**
     * Evaluate every badge for a profile and award any newly-earned. `$trigger` is the just-sealed
     * activity (lets single-activity badges attribute themselves); null on the nightly pass.
     *
     * @return array<int,string> newly-awarded keys
     */
    public function evaluate(Profile $profile, ?ActivitySession $trigger = null): array
    {
        $earned = Achievement::where('profile_id', $profile->id)->pluck('key')->flip();
        $awards = [];

        $award = function (string $key, array $meta = [], ?int $activityId = null) use ($profile, $earned, &$awards) {
            if ($earned->has($key)) {
                return;
            }
            Achievement::firstOrCreate(
                ['profile_id' => $profile->id, 'key' => $key],
                ['awarded_at' => now(), 'meta' => $meta, 'activity_session_id' => $activityId],
            );
            $awards[] = $key;
        };

        $sessions = $profile->activitySessions();

        // --- single-activity milestones (cheapest off the trigger, else the best run ever) --------
        $longest = (clone $sessions)->orderByDesc('distance_km')->first();
        if ($longest) {
            $award('first_run', [], $longest->id);
            $km = (float) ($longest->distance_km ?? 0);
            if ($km >= 5) {
                $award('first_5k', ['km' => $km], $longest->id);
            }
            if ($km >= 10) {
                $award('first_10k', ['km' => $km], $longest->id);
            }
            if ($km >= 21.0975) {
                $award('first_half', ['km' => $km], $longest->id);
            }
            if ($km >= 42.195) {
                $award('first_marathon', ['km' => $km], $longest->id);
            }
        }

        // --- time-of-day (from the trigger, or any qualifying session) -----------------------------
        $earlyHr = function (ActivitySession $s) { return (int) $s->started_at?->copy()->hour; };
        $candidates = $trigger ? collect([$trigger]) : (clone $sessions)->orderByDesc('started_at')->limit(40)->get();
        foreach ($candidates as $s) {
            $h = $earlyHr($s);
            if ($h < 6) {
                $award('early_bird', [], $s->id);
            }
            if ($h >= 21) {
                $award('night_owl', [], $s->id);
            }
        }

        // --- window aggregates --------------------------------------------------------------------
        $weekEffort = (clone $sessions)->where('started_at', '>=', now()->startOfWeek())->sum('relative_effort');
        if ($weekEffort >= 500) {
            $award('effort_week_500', ['effort' => (int) $weekEffort]);
        }

        $monthKm = (clone $sessions)->where('started_at', '>=', now()->startOfMonth())->sum('distance_km');
        if ($monthKm >= 100) {
            $award('distance_100k_month', ['km' => round((float) $monthKm, 1)]);
        }

        $monthClimb = (clone $sessions)->where('started_at', '>=', now()->startOfMonth())->sum('elevation_gain_m');
        if ($monthClimb >= 1000) {
            $award('climb_1000m_month', ['m' => (int) $monthClimb]);
        }

        // --- consecutive-week streak --------------------------------------------------------------
        $streak = $this->currentWeekStreak($profile);
        if ($streak >= 3) {
            $award('streak_3w', ['weeks' => $streak]);
        }
        if ($streak >= 5) {
            $award('streak_5w', ['weeks' => $streak]);
        }

        return $awards;
    }

    /** How many consecutive ISO weeks (ending this week) the profile has had ≥1 activity. */
    private function currentWeekStreak(Profile $profile): int
    {
        $weeks = $profile->activitySessions()
            ->where('started_at', '>=', now()->subWeeks(12)->startOfWeek())
            ->orderBy('started_at')
            ->pluck('started_at')
            ->map(fn (Carbon $d) => $d->copy()->startOfWeek()->toDateString())
            ->unique()->values();

        if ($weeks->isEmpty()) {
            return 0;
        }

        $set = $weeks->flip();
        $streak = 0;
        $cursor = now()->startOfWeek();
        // Allow the streak to be "alive" even if you haven't run yet this week (count from last week).
        if (! $set->has($cursor->toDateString())) {
            $cursor = $cursor->subWeek();
        }
        while ($set->has($cursor->toDateString())) {
            $streak++;
            $cursor = $cursor->subWeek();
        }

        return $streak;
    }
}
