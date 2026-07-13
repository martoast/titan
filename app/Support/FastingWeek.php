<?php

namespace App\Support;

use App\Models\Fast;
use App\Models\Profile;
use Illuminate\Support\Carbon;

/**
 * The fasting HISTORY + week view (FASTING_EVIDENCE Thrust 5) — past fasts, the longest, a 7-day strip,
 * and the eating-window adherence streak. Mirrors the sleep/training week surfaces so it feels native.
 * Consistency (window adherence) is the behaviour that actually helps, so it's celebrated alongside the
 * raw fast hours. TZ: fasts (like meals) are app-tz wall-clocks — group by that frame, never re-convert
 * (see the meal-timezone fault; review af83f57).
 */
class FastingWeek
{
    private const RECENT = 14;

    /** Length of a completed fast in hours. Pure. */
    public static function fastHours(Carbon $start, Carbon $end): float
    {
        return round($start->diffInMinutes($end) / 60, 1);
    }

    /** Did a fast hit its goal? (a small tolerance for rounding). Pure. */
    public static function hitGoal(float $hours, ?float $goalHours): bool
    {
        return $hours >= max(1.0, (float) ($goalHours ?: 16)) - 0.1;
    }

    /** @return array<string,mixed> the `fastingweek` card payload. */
    public static function forProfile(Profile $profile): array
    {
        $appTz = config('app.timezone', 'UTC');
        $completed = $profile->fasts()->whereNotNull('ended_at')
            ->orderByDesc('started_at')->orderByDesc('id')->limit(self::RECENT)->get();

        $longest = 0.0;
        $byDay = [];   // app-tz 'Y-m-d' => [hours…] (a fast counts on the day it STARTED)
        $sum = 0.0;
        foreach ($completed as $f) {
            $h = self::fastHours($f->started_at, $f->ended_at);
            $longest = max($longest, $h);
            $sum += $h;
            $byDay[$f->started_at->toDateString()][] = $h;
        }

        $recent = $completed->map(fn (Fast $f) => [
            'started_at' => $f->started_at->toIso8601String(),
            'hours' => self::fastHours($f->started_at, $f->ended_at),
            'goal_h' => $f->goal_hours,
            'hit' => self::hitGoal(self::fastHours($f->started_at, $f->ended_at), $f->goal_hours),
        ])->values()->all();

        // 7-day strip (oldest → newest), a day lit by its longest fast that started that day.
        $now = Carbon::now($appTz);
        $days = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = $now->copy()->subDays($i);
            $key = $d->toDateString();
            $h = isset($byDay[$key]) ? max($byDay[$key]) : null;
            $days[] = ['date' => $key, 'weekday' => $d->format('D'), 'fasted' => $h !== null, 'hours' => $h];
        }

        return array_filter([
            'type' => 'fastingweek',
            'longest_h' => round($longest, 1),
            'avg_h' => $completed->count() ? round($sum / $completed->count(), 1) : 0.0,
            'count' => $profile->fasts()->whereNotNull('ended_at')->count(),
            'recent' => $recent,
            'days' => $days,
            // The eating-window adherence streak (the consistency that actually helps) — same source as the card.
            'window' => class_exists(EatingWindow::class) ? EatingWindow::forProfile($profile) : null,
        ], fn ($v) => $v !== null);
    }
}
