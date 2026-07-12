<?php

namespace App\Support;

use App\Models\Profile;
use Illuminate\Support\Carbon;

/**
 * The Sleep Planner — "be in bed by 10:40 tonight." Whoop's explicit bedtime recommendation, on data we
 * already compute: bedtime = target wake − tonight's sleep NEED (SleepCoach, which already folds today's
 * strain + accumulated debt) − a fall-asleep buffer, nudged toward the user's CONSISTENT rhythm so we
 * protect the clock instead of prescribing a wildly different time each night. Returns null when there
 * isn't enough to compute a need (SleepCoach null) — no fabricated bedtime.
 *
 * @see SleepCoach (need/debt) @see SleepRegularity (rhythm) @see CircadianRhythm (phase)
 */
class SleepPlanner
{
    /** Fall-asleep buffer (hours) — time in bed before actually asleep. */
    private const FALL_ASLEEP_H = 0.3;   // ~18 min

    /** Debt (h) past which we nudge bedtime a little EARLIER to chip into it. */
    private const DEBT_NUDGE_H = 1.0;

    /**
     * @return array{
     *   bedtime: string, window: array{0:string,1:string}, target_wake: string,
     *   need_h: float, debt_h: float, earlier_for_debt: bool, reason: string
     * }|null
     */
    public static function plan(Profile $profile, ?string $targetWake = null): ?array
    {
        $sleep = SleepCoach::assess($profile);
        if ($sleep === null || ($sleep['need_h'] ?? 0) <= 0) {
            return null;
        }
        $need = (float) $sleep['need_h'];
        $debt = (float) ($sleep['debt_h'] ?? 0);

        // Recent nights → the user's typical wake + bedtime (their rhythm).
        $nights = $profile->sleepLogs()->nights()->whereNotNull('wake_time')
            ->orderByDesc('slept_at')->limit(14)->get(['bedtime', 'wake_time']);

        $wake = $targetWake
            ?? ($profile->settings['wake_target'] ?? null)
            ?? self::medianClock($nights->pluck('wake_time')->all())
            ?? '07:00';

        // bedtime = wake − need − buffer, wrapped into the prior evening.
        $earlier = $debt >= self::DEBT_NUDGE_H;
        $offsetMin = (int) round(($need + self::FALL_ASLEEP_H) * 60) + ($earlier ? 20 : 0);
        $bedMin = self::wrap(self::toMinutes($wake) - $offsetMin);

        $bedtime = self::fromMinutes($bedMin);
        $window = [self::fromMinutes(self::wrap($bedMin - 10)), self::fromMinutes(self::wrap($bedMin + 10))];

        $needLabel = rtrim(rtrim(number_format($need, 1), '0'), '.');
        $reason = "You need ~{$needLabel}h".((($sleep['strain_bump_h'] ?? 0) > 0) ? ' after today' : '')
            .', up around '.self::pretty($wake).'.'
            .($earlier ? " You're carrying sleep debt, so aim a touch earlier tonight." : ' Keeping this steady protects your rhythm.');

        return [
            'bedtime' => $bedtime,
            'window' => $window,
            'target_wake' => $wake,
            'need_h' => $need,
            'debt_h' => round($debt, 1),
            'earlier_for_debt' => $earlier,
            'reason' => $reason,
        ];
    }

    /** Median of a set of "HH:MM(:SS)" clock strings, circular-safe for wake times (all near morning). */
    private static function medianClock(array $times): ?string
    {
        $mins = array_values(array_filter(array_map(
            fn ($t) => $t ? self::toMinutes($t) : null,
            $times
        ), fn ($v) => $v !== null));
        if ($mins === []) {
            return null;
        }
        sort($mins);
        $mid = $mins[intdiv(count($mins), 2)];

        return self::fromMinutes($mid);
    }

    private static function toMinutes(string $clock): int
    {
        [$h, $m] = array_pad(array_map('intval', explode(':', $clock)), 2, 0);

        return self::wrap($h * 60 + $m);
    }

    private static function fromMinutes(int $min): string
    {
        $min = self::wrap($min);

        return sprintf('%02d:%02d', intdiv($min, 60), $min % 60);
    }

    private static function pretty(string $clock): string
    {
        return Carbon::createFromFormat('H:i', substr($clock, 0, 5))->format('g:i A');
    }

    private static function wrap(int $min): int
    {
        return (($min % 1440) + 1440) % 1440;
    }
}
