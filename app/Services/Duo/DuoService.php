<?php

namespace App\Services\Duo;

use App\Models\Profile;
use App\Models\Streak;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Gathers the brother-vs-brother comparison data for the /duo dashboard.
 *
 * Every cross-domain model is built by a different agent in parallel and may not
 * exist (or its table may be empty/missing) at runtime, so EVERY access is guarded
 * with class_exists + a try/catch that degrades to a safe default. The /duo page
 * must always render.
 */
class DuoService
{
    /** Points weighting for the weekly leaderboard. */
    private const PTS_WORKOUT = 3;
    private const PTS_MEAL = 1;
    private const PTS_SLEEP_NIGHT = 1;

    /**
     * Side-by-side comparison of the two brothers. Returns a list (max 2) of
     * per-profile stat blocks plus derived "race" framing.
     *
     * @return array{profiles: array<int, array<string, mixed>>, hasPhysiqueRace: bool}
     */
    public function comparison(): array
    {
        $profiles = $this->profiles();
        [$weekStart, $weekEnd] = $this->weekBounds();

        $blocks = $profiles->map(function (Profile $profile) use ($weekStart, $weekEnd) {
            return [
                'profile' => $profile,
                'id' => $profile->id,
                'name' => $profile->display_name ?: ($profile->user->name ?? "Profile {$profile->id}"),
                'workouts' => $this->workoutCount($profile->id, $weekStart, $weekEnd),
                'meals' => $this->mealCount($profile->id, $weekStart, $weekEnd),
                'avg_sleep' => $this->avgSleep($profile->id, $weekStart, $weekEnd),
                'sleep_nights' => $this->sleepNights($profile->id, $weekStart, $weekEnd),
                'latest_weight' => $this->latestWeight($profile->id),
                'pct_to_goal' => $this->pctToGoal($profile->id),
                'streaks' => $this->streaks($profile->id),
                'top_streak' => $this->topStreak($profile->id),
            ];
        })->values()->all();

        $hasPhysiqueRace = collect($blocks)->contains(fn ($b) => $b['pct_to_goal'] !== null);

        return [
            'profiles' => $blocks,
            'hasPhysiqueRace' => $hasPhysiqueRace,
        ];
    }

    /**
     * This week's points per profile + the computed winner.
     *
     * @return array{week_label: string, scores: array<int, array<string, mixed>>, winner: ?array<string, mixed>, tie: bool}
     */
    public function weeklyScores(): array
    {
        $profiles = $this->profiles();
        [$weekStart, $weekEnd] = $this->weekBounds();

        $scores = $profiles->map(function (Profile $profile) use ($weekStart, $weekEnd) {
            $workouts = $this->workoutCount($profile->id, $weekStart, $weekEnd);
            $meals = $this->mealCount($profile->id, $weekStart, $weekEnd);
            $sleepNights = $this->sleepNights($profile->id, $weekStart, $weekEnd);

            $points = $this->points($workouts, $meals, $sleepNights);

            return [
                'id' => $profile->id,
                'name' => $profile->display_name ?: ($profile->user->name ?? "Profile {$profile->id}"),
                'workouts' => $workouts,
                'meals' => $meals,
                'sleep_nights' => $sleepNights,
                'points' => $points,
            ];
        })->sortByDesc('points')->values();

        $top = $scores->first();
        $tie = $scores->count() > 1 && $top
            && $scores->where('points', $top['points'])->count() === $scores->count()
            && $top['points'] > 0;

        // A real winner only when someone has points and it isn't a flat tie.
        $winner = ($top && $top['points'] > 0 && ! $tie) ? $top : null;

        return [
            'week_label' => $weekStart->isoFormat('MMM D') . ' - ' . $weekEnd->copy()->subDay()->isoFormat('MMM D'),
            'scores' => $scores->all(),
            'winner' => $winner,
            'tie' => $tie,
        ];
    }

    /** The points formula: workouts*3 + meals + sleep_nights. */
    public function points(int $workouts, int $meals, int $sleepNights): int
    {
        return ($workouts * self::PTS_WORKOUT)
            + ($meals * self::PTS_MEAL)
            + ($sleepNights * self::PTS_SLEEP_NIGHT);
    }

    // ---------------------------------------------------------------------
    // Profiles & time window
    // ---------------------------------------------------------------------

    /** The duo: at most two profiles, ordered by id. */
    private function profiles(): Collection
    {
        try {
            return Profile::with('user')->orderBy('id')->limit(2)->get();
        } catch (Throwable) {
            return collect();
        }
    }

    /** @return array{0: Carbon, 1: Carbon} [start of week (inclusive), end (exclusive)] */
    private function weekBounds(): array
    {
        $start = Carbon::now()->startOfWeek();

        return [$start, $start->copy()->addWeek()];
    }

    // ---------------------------------------------------------------------
    // Cross-domain getters -- every one guarded
    // ---------------------------------------------------------------------

    private function workoutCount(int $profileId, Carbon $start, Carbon $end): int
    {
        return $this->guardCount(
            \App\Models\Workout::class,
            fn ($q) => $q->where('profile_id', $profileId)
                ->whereBetween('performed_at', [$start, $end]),
        );
    }

    private function mealCount(int $profileId, Carbon $start, Carbon $end): int
    {
        return $this->guardCount(
            \App\Models\Meal::class,
            fn ($q) => $q->where('profile_id', $profileId)
                ->whereBetween('eaten_at', [$start, $end]),
        );
    }

    private function avgSleep(int $profileId, Carbon $start, Carbon $end): ?float
    {
        if (! class_exists(\App\Models\SleepLog::class)) {
            return null;
        }

        try {
            $rows = \App\Models\SleepLog::where('profile_id', $profileId)->get();
            $rows = $rows->filter(fn ($r) => $this->dateInWindow($this->sleepDate($r), $start, $end));

            if ($rows->isEmpty()) {
                return null;
            }

            $hours = $rows->map(fn ($r) => $this->sleepHours($r))->filter(fn ($h) => $h !== null);

            return $hours->isEmpty() ? null : round($hours->avg(), 1);
        } catch (Throwable) {
            return null;
        }
    }

    private function sleepNights(int $profileId, Carbon $start, Carbon $end): int
    {
        if (! class_exists(\App\Models\SleepLog::class)) {
            return 0;
        }

        try {
            return \App\Models\SleepLog::where('profile_id', $profileId)->get()
                ->filter(fn ($r) => $this->dateInWindow($this->sleepDate($r), $start, $end))
                ->count();
        } catch (Throwable) {
            return 0;
        }
    }

    private function latestWeight(int $profileId): ?float
    {
        if (! class_exists(\App\Models\BodyMetric::class)) {
            return null;
        }

        try {
            $row = \App\Models\BodyMetric::where('profile_id', $profileId)
                ->whereNotNull('weight_kg')
                ->orderByDesc('taken_at')
                ->first();

            return $row && $row->weight_kg !== null ? (float) $row->weight_kg : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Latest "% to goal physique" for a profile (0-100), or null if unavailable. */
    private function pctToGoal(int $profileId): ?float
    {
        if (! class_exists(\App\Models\PhysiqueAnalysis::class)) {
            return null;
        }

        try {
            $row = \App\Models\PhysiqueAnalysis::where('profile_id', $profileId)
                ->whereNotNull('pct_to_goal')
                ->orderByDesc('created_at')
                ->first();

            if (! $row || $row->pct_to_goal === null) {
                return null;
            }

            return max(0.0, min(100.0, (float) $row->pct_to_goal));
        } catch (Throwable) {
            return null;
        }
    }

    /** All streak rows for a profile (your own model -- safe to query directly). */
    private function streaks(int $profileId): Collection
    {
        try {
            return Streak::where('profile_id', $profileId)
                ->orderByDesc('current_count')
                ->get();
        } catch (Throwable) {
            return collect();
        }
    }

    /** The headline streak (highest current run) for a profile. */
    private function topStreak(int $profileId): ?Streak
    {
        return $this->streaks($profileId)->first();
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Count rows for a model class if it exists, applying $scope to the query.
     * Returns 0 on any failure (missing table, schema mismatch, etc.).
     */
    private function guardCount(string $modelClass, callable $scope): int
    {
        if (! class_exists($modelClass)) {
            return 0;
        }

        try {
            return (int) $scope($modelClass::query())->count();
        } catch (Throwable) {
            return 0;
        }
    }

    /** Best-effort date for a sleep log row across plausible column names. */
    private function sleepDate($row): ?Carbon
    {
        foreach (['logged_on', 'date', 'slept_on', 'recorded_at', 'created_at'] as $col) {
            $val = $row->{$col} ?? null;
            if ($val) {
                try {
                    return Carbon::parse($val);
                } catch (Throwable) {
                    // try next column
                }
            }
        }

        return null;
    }

    /** Best-effort sleep duration in hours across plausible column names. */
    private function sleepHours($row): ?float
    {
        if (isset($row->duration_hours) && $row->duration_hours !== null) {
            return (float) $row->duration_hours;
        }
        foreach (['duration_min', 'minutes_asleep', 'total_minutes'] as $col) {
            if (isset($row->{$col}) && $row->{$col} !== null) {
                return round(((float) $row->{$col}) / 60, 2);
            }
        }
        if (isset($row->hours) && $row->hours !== null) {
            return (float) $row->hours;
        }

        return null;
    }

    private function dateInWindow(?Carbon $date, Carbon $start, Carbon $end): bool
    {
        return $date !== null && $date->gte($start) && $date->lt($end);
    }
}
