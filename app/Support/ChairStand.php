<?php

namespace App\Support;

use App\Models\Profile;
use Illuminate\Support\Carbon;

/**
 * The 30-second chair-stand test (30CST) -- a validated lower-body-strength & frailty screen, and the
 * honest way a wearable can assess function (gait SPEED from the wrist isn't reliable; a GUIDED test is).
 * Protocol: arms crossed, stand fully and sit back down as many times as you can in 30 seconds.
 *
 * Norms: Rikli & Jones 1999 (Senior Fitness Test). We score reps against the age/sex below-average cut
 * (under it → flag for lower-body work). A wellness screen, never a clinical frailty diagnosis -- gait
 * speed itself is the "sixth vital sign" (Studenski 2011, mortality HR 0.88 per +0.1 m/s).
 *
 * Mirrors biosignal/app/core/gait.py::chair_stand_score so the manual-entry UI and the wearable
 * auto-count path band identically.
 */
class ChairStand
{
    /** Below-average rep cut (reps in 30 s) by age threshold, per sex. */
    private const BELOW = [
        'm' => [[60, 14], [65, 12], [70, 12], [75, 11], [80, 10], [85, 8], [90, 7]],
        'f' => [[60, 12], [65, 11], [70, 10], [75, 10], [80, 9], [85, 8], [90, 4]],
    ];

    /**
     * @return array{reps:int,band:string,label:string,age_below_cut:int,good_at:int}
     */
    public static function score(int $reps, float $age, bool $female): array
    {
        $table = self::BELOW[$female ? 'f' : 'm'];
        $below = $table[0][1];
        foreach ($table as [$a, $cut]) {
            if ($age >= $a) {
                $below = $cut;
            }
        }
        $good = $age >= 60 ? (int) round($below * 1.5) : max((int) round($below * 1.6), 18);

        [$band, $label] = match (true) {
            $reps < $below => ['below', 'Below average for your age -- worth building lower-body strength'],
            $reps >= $good => ['good', 'Strong lower-body function for your age'],
            default => ['average', 'Average lower-body function for your age'],
        };

        return ['reps' => $reps, 'band' => $band, 'label' => $label, 'age_below_cut' => $below, 'good_at' => $good];
    }

    /** Score for a profile (uses its age + sex). null if no birthdate. */
    public static function scoreFor(Profile $profile, int $reps): ?array
    {
        if (! $profile->birthdate) {
            return null;
        }
        $age = Carbon::parse($profile->birthdate)->diffInYears(now());
        $female = in_array(strtolower((string) ($profile->sex ?? '')), ['f', 'female', 'woman', 'w'], true);

        return self::score($reps, $age, $female);
    }
}
