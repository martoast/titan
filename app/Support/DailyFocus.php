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
            // NOTHING TO BALANCE YET. Readiness is null until an HRV/RHR reading or a recent sealed
            // night exists, SleepCoach returns null with no nights in its window, and strain's load is
            // 0 with no workout, no activity and no HR stream. With a pillar missing there is nothing
            // to synthesise -- and `default` below used to answer "recovery, strain and sleep are in
            // balance", a claim about things nobody has measured. A brand-new App Store user met
            // exactly that on their first open, under a recovery ring reading "No data".
            //
            // This arm sits LAST before the default on purpose: a measured low recovery, a real sleep
            // debt, a green light to push and an over-target strain day are all honest reads of the
            // data that IS present, so they keep priority over "we are still missing something".
            $readiness === null || $sleep === null => self::unmeasured($profile, $readiness, $sleep),
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

    /**
     * The focus when a pillar has not arrived: name what is missing and what starts the loop,
     * rather than describing a balance nobody has measured.
     *
     * A LAPSED user is not a NEW user -- "your first recovery score" reads as amnesia to somebody
     * with months of history, and "pick the thread back up" is nonsense on day one -- so the two are
     * told apart by whether any reading has ever landed. Those two `exists()` queries are only
     * reached on this arm, so the ordinary path still pays nothing for them.
     *
     * @param  ?array<string,mixed>  $sleep  SleepCoach assessment, or null when no night is in its window
     * @return array{0:string,1:string,2:string}
     */
    private static function unmeasured(Profile $profile, int|float|null $readiness, ?array $sleep): array
    {
        if ($readiness === null && $sleep === null) {
            $everMeasured = $profile->recoveryLogs()->exists()
                || $profile->sleepLogs()->nights()->final()->exists();

            return $everMeasured
                ? [
                    'measure', 'Pick the thread back up',
                    'No recent night and no recovery reading, so there is nothing to read today. '
                    .'Wear your band tonight and the daily read comes straight back.',
                ]
                : [
                    'measure', 'Start with one night',
                    'Nothing is measured yet, so there is nothing to judge today. Wear your band '
                    .'tonight -- or connect Apple Health -- and your first recovery score lands in the morning.',
                ];
        }

        if ($readiness === null) {
            return [
                'measure', 'Recovery needs a reading',
                'Your sleep is coming through, but there is no recovery reading yet -- that comes from '
                .'HRV and resting heart rate, which the band records while you sleep.',
            ];
        }

        return [
            'measure', 'Log a full night',
            'Recovery is reading, but no complete night has landed recently -- without one, sleep debt '
            .'and tonight\'s target cannot be worked out.',
        ];
    }
}
