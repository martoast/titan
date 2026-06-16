<?php

namespace App\Support;

use App\Models\Profile;
use Illuminate\Support\Carbon;

/**
 * Daily Strain (0–21) + the Strain Coach — the "how hard should I go today" half of the daily loop.
 *
 * Strain is the day's accumulated cardiovascular/metabolic load mapped onto a 0–21 scale (Whoop-style,
 * Borg-flavoured), so a rest day sits low and a double session sits near the top. We build the load
 * from what we actually measure: each logged workout's Banister TRIMP plus ambient activity (MVPA
 * minutes / steps), then compress it logarithmically (load saturates — going from hard to brutal moves
 * the number less than easy to moderate).
 *
 * The COACH closes the loop with the morning Readiness score: well-recovered → a higher target ("push"),
 * run-down → a low target ("restrain", protect recovery). It's the guidance the readiness score is
 * begging for. Honest scope: a relative training-guidance heuristic (like TRIMP/ACWR), not a validated
 * physiological unit — the value is the daily push/rest decision, not the absolute number.
 */
class Strain
{
    private const K = 90.0;            // load → strain compression constant (tuned for sane day values)
    private const MAX = 21.0;

    /**
     * @return array{strain:float,load:float,band:string,label:string,
     *   target:array{low:float,high:float,mode:string,label:string},
     *   status:string,advice:string,readiness:?int}
     */
    public static function assess(Profile $profile, ?Carbon $day = null): array
    {
        $day = $day ?? Carbon::today();

        // Workout load: sum of the day's session TRIMP.
        $workout = (float) $profile->activitySessions()
            ->whereDate('started_at', $day)->sum('trimp');

        // Ambient load: a day of living. MVPA minutes are the strongest signal; fall back to steps.
        $act = $profile->dailyActivity()->whereDate('date', $day)->first();
        $mvpa = (float) ($act->mvpa_min ?? 0);
        $steps = (int) ($act->steps ?? 0);
        $ambient = max($mvpa * 0.8, $steps * 0.004);

        $load = $workout + $ambient;
        $strain = round(self::MAX * (1 - exp(-$load / self::K)), 1);
        [$band, $label] = self::band($strain);

        // Coach: target band from this morning's readiness.
        $readiness = Readiness::compute($profile, $day)['score'] ?? null;
        $target = self::targetFor($readiness);
        [$status, $advice] = self::coach($strain, $target, $readiness);

        return [
            'strain' => $strain,
            'load' => round($load, 1),
            'band' => $band,
            'label' => $label,
            'target' => $target,
            'status' => $status,
            'advice' => $advice,
            'readiness' => $readiness !== null ? (int) round($readiness) : null,
        ];
    }

    /** Recovery-driven target strain band. */
    public static function targetFor(?float $readiness): array
    {
        return match (true) {
            $readiness === null => ['low' => 9.0, 'high' => 14.0, 'mode' => 'maintain', 'label' => 'Maintain'],
            $readiness >= 67 => ['low' => 14.0, 'high' => 18.0, 'mode' => 'push', 'label' => 'Primed to push'],
            $readiness >= 34 => ['low' => 9.0, 'high' => 14.0, 'mode' => 'maintain', 'label' => 'Maintain'],
            default => ['low' => 4.0, 'high' => 9.0, 'mode' => 'restrain', 'label' => 'Hold back'],
        };
    }

    /** @return array{0:string,1:string} [status, advice] */
    private static function coach(float $strain, array $target, ?float $readiness): array
    {
        if ($strain < $target['low']) {
            return match ($target['mode']) {
                'restrain' => ['under', "You're under your easy ceiling — good. Keep it light and let recovery come back."],
                'push' => ['under', sprintf('Room to push — about %.0f more strain to hit your target. Your body is primed for it today.', $target['low'] - $strain)],
                default => ['under', sprintf('A bit more would hit your target (~%.0f to go). A solid session fits well today.', $target['low'] - $strain)],
            };
        }
        if ($strain <= $target['high']) {
            return ['on_target', 'On target for how recovered you are today. Nicely balanced.'];
        }
        // Above the target band.
        return $target['mode'] === 'restrain'
            ? ['over', "Above your target on a low-recovery day — ease off and prioritise sleep tonight."]
            : ['over', "You've cleared your target — strong day. Anything more is a bonus; guard recovery."];
    }

    /** @return array{0:string,1:string} */
    private static function band(float $strain): array
    {
        return match (true) {
            $strain >= 18 => ['all_out', 'All-out'],
            $strain >= 14 => ['high', 'High'],
            $strain >= 8 => ['moderate', 'Moderate'],
            default => ['light', 'Light'],
        };
    }
}
