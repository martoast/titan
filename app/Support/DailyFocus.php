<?php

namespace App\Support;

use App\Models\Profile;
use Illuminate\Support\Carbon;

/**
 * Daily Focus -- the one thing to work on today, synthesised from the Recovery → Strain → Sleep loop.
 *
 * The dashboard's job is to answer "what should I do today?" in a glance. This reads the three pillars
 * and picks the single highest-priority message: recover, sleep, push, or maintain -- so the user never
 * has to interpret three scores themselves.
 */
class DailyFocus
{
    /**
     * @return array{focus:string,headline:string,detail:string,
     *   readiness:?int,strain:float,sleep_performance:?int}
     */
    public static function compute(Profile $profile, ?Carbon $day = null): array
    {
        $day = $day ?? Carbon::today();
        $readiness = Readiness::compute($profile, $day)['score'] ?? null;
        $strain = Strain::assess($profile, $day);
        $sleep = SleepCoach::assess($profile, $day);

        $perf = $sleep['performance_pct'] ?? null;
        $debt = $sleep['debt_h'] ?? 0.0;

        // Priority order: protect a run-down body first, then sleep, then green-light a push.
        [$focus, $headline, $detail] = match (true) {
            $readiness !== null && $readiness < 34 => [
                'recover', 'Recover today',
                'Your recovery is low -- keep strain easy and bank an early night. Pushing now digs the hole deeper.',
            ],
            $sleep && ($debt >= 2.0 || ($perf !== null && $perf < 75)) => [
                'sleep', 'Prioritise sleep',
                $sleep['advice'],
            ],
            $readiness !== null && $readiness >= 67 && $strain['status'] === 'under' => [
                'push', 'Primed to push',
                'You\'re well recovered and under your strain target -- today\'s the day to go hard.',
            ],
            $strain['status'] === 'over' => [
                'maintain', 'Solid work in',
                $strain['advice'],
            ],
            default => [
                'maintain', 'Stay the course',
                'Recovery, strain and sleep are in balance -- keep the rhythm and stay consistent.',
            ],
        };

        return [
            'focus' => $focus,
            'headline' => $headline,
            'detail' => $detail,
            'readiness' => $readiness !== null ? (int) round($readiness) : null,
            'strain' => $strain['strain'],
            'sleep_performance' => $perf,
        ];
    }
}
