<?php

namespace App\Support;

use App\Models\ActivitySession;
use App\Models\Profile;
use Carbon\CarbonImmutable;

/**
 * Consecutive-day workout streak, computed straight from sealed ActivitySession dates.
 *
 * This is the deliberate source of truth for "how many days in a row have I trained" — the
 * `Streak` Eloquent model belongs to the (currently dormant) brother-vs-brother duo dashboard and
 * nothing writes to it, so we do NOT depend on it here. A day "counts" if it has at least one
 * sealed activity (run OR lift); rest days break the chain.
 *
 * All bucketing is done in the caller's local timezone (`$tz`) so a 9pm workout lands on the right
 * calendar day — the app passes `TimeZone.current.identifier`; server storage is UTC.
 */
class WorkoutStreak
{
    /** How far back the calendar heat-strip on the Workouts screen looks (5 weeks, today inclusive). */
    private const STRIP_DAYS = 35;

    /**
     * @return array{
     *   current:int, longest:int, this_week:int, this_month:int,
     *   worked_out_today:bool, active_days:array<int,string>
     * }
     */
    public static function forProfile(Profile $profile, string $tz = 'UTC'): array
    {
        $tz = self::safeZone($tz);

        // Distinct local workout-days ('Y-m-d'), oldest→newest. A year of history is plenty to find
        // any real streak while staying a single cheap query.
        $days = ActivitySession::query()->training()
            ->where('profile_id', $profile->id)
            ->whereNotNull('started_at')
            ->where('started_at', '>=', CarbonImmutable::now($tz)->subYear()->utc())
            ->orderBy('started_at')
            ->get(['started_at'])
            // started_at is stored as UTC wall-clock (the seal convention — see
            // MobileRunsController/SealActivityJob). The Eloquent cast mislabels it as app-tz, so read the
            // RAW value and parse it AS UTC before converting to the caller's tz — otherwise an EVENING
            // workout (past 00:00 UTC) buckets on tomorrow and "worked out today" reads false right after
            // Alex finishes an evening session. This one map feeds every bucket below (they compare against
            // these local dates), so fixing it here corrects streak / week / month / heat-strip together.
            ->map(fn (ActivitySession $s) => CarbonImmutable::parse($s->getRawOriginal('started_at'), 'UTC')->setTimezone($tz)->toDateString())
            ->unique()
            ->values();

        $daySet = $days->flip(); // 'Y-m-d' => position, O(1) membership

        $today = CarbonImmutable::now($tz)->startOfDay();
        $workedOutToday = $daySet->has($today->toDateString());

        // Current streak: walk back from today. If nothing yet today the chain is still alive off
        // yesterday (it only breaks after a full missed day) — so we don't punish "haven't trained
        // yet today".
        $current = 0;
        if ($workedOutToday || $daySet->has($today->subDay()->toDateString())) {
            $cursor = $workedOutToday ? $today : $today->subDay();
            while ($daySet->has($cursor->toDateString())) {
                $current++;
                $cursor = $cursor->subDay();
            }
        }

        // Longest streak ever: single pass over the sorted day list.
        $longest = 0;
        $run = 0;
        $prev = null;
        foreach ($days as $d) {
            $dc = CarbonImmutable::parse($d);
            $run = ($prev !== null && $dc->equalTo($prev->addDay())) ? $run + 1 : 1;
            $longest = max($longest, $run);
            $prev = $dc;
        }

        $weekStart = CarbonImmutable::now($tz)->startOfWeek();
        $monthStart = CarbonImmutable::now($tz)->startOfMonth();
        $stripStart = $today->subDays(self::STRIP_DAYS - 1);

        return [
            'current' => $current,
            'longest' => max($longest, $current),
            'this_week' => $days->filter(fn ($d) => CarbonImmutable::parse($d)->gte($weekStart))->count(),
            'this_month' => $days->filter(fn ($d) => CarbonImmutable::parse($d)->gte($monthStart))->count(),
            'worked_out_today' => $workedOutToday,
            'active_days' => $days->filter(fn ($d) => CarbonImmutable::parse($d)->gte($stripStart))->values()->all(),
        ];
    }

    private static function safeZone(string $tz): string
    {
        try {
            new \DateTimeZone($tz);

            return $tz;
        } catch (\Throwable) {
            return (string) config('app.timezone', 'UTC');
        }
    }
}
