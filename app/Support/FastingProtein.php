<?php

namespace App\Support;

use App\Models\Profile;
use Illuminate\Support\Carbon;

/**
 * The fasting PROTEIN GUARDRAIL (FASTING_EVIDENCE Thrust 4). A hard rule straight from the research
 * (docs/research/DAVID_SINCLAIR_LONGEVITY.md): the protein–longevity relationship FLIPS with age — older
 * adults and lifters need MORE protein (~1.2–1.5 g/kg/day), not less. So fasting must never undercut it:
 * when a user's eating window is too short to realistically fit the protein they still need today, we flag
 * it (gently, informatively) — "fast harder" should not cost muscle.
 */
class FastingProtein
{
    /** A sustainable protein intake rate across an eating window (~a palm of protein every ~1.5h). */
    public const SUSTAINABLE_G_PER_HOUR = 20;

    /** Grams of protein realistically eatable in the remaining eating-window hours. Pure. */
    public static function achievableG(float $windowHoursRemaining): float
    {
        return max(0.0, $windowHoursRemaining) * self::SUSTAINABLE_G_PER_HOUR;
    }

    /** Is the window squeezing protein — i.e. more remaining than can be eaten in the time left? Pure. */
    public static function isSqueezed(float $remainingProteinG, float $windowHoursRemaining): bool
    {
        return $remainingProteinG > 0 && $remainingProteinG > self::achievableG($windowHoursRemaining);
    }

    /**
     * The guardrail for a profile: null when there's no window, protein's already hit, or the window can
     * still fit it; otherwise a flag payload the fasting card + coach surface.
     *
     * @return array{remaining_g:int,target_g:int,window_hours_left:float,message:string}|null
     */
    public static function check(Profile $profile): ?array
    {
        $win = EatingWindow::config($profile);
        if ($win === null) {
            return null;   // no eating window → nothing to squeeze
        }
        // Reuse the ONE macro resolver (unified target + today's consumed, same as every macro surface).
        $macros = Macros::today($profile);
        $target = (int) ($macros['protein']['target'] ?? 0);
        $consumed = (int) ($macros['protein']['value'] ?? 0);
        $remaining = $target - $consumed;
        if ($remaining <= 0) {
            return null;   // already hit protein today — no squeeze
        }

        $appTz = config('app.timezone', 'UTC');
        $now = Carbon::now($appTz);   // app-tz frame, consistent with EatingWindow (review af83f57)
        $hoursLeft = EatingWindow::windowHoursRemaining(
            $now->hour * 60 + $now->minute,
            EatingWindow::startMinute($win['start']),
            EatingWindow::windowLengthMin($win['plan']),
        );

        if (! self::isSqueezed($remaining, $hoursLeft)) {
            return null;
        }

        $hLabel = $hoursLeft <= 0 ? 'no' : ('~'.rtrim(rtrim(number_format($hoursLeft, 1), '0'), '.').'h');

        return [
            'remaining_g' => $remaining,
            'target_g' => $target,
            'window_hours_left' => $hoursLeft,
            'message' => "You still need ~{$remaining}g protein but have {$hLabel} of eating window left — that's a lot to fit. Protein protects muscle (older adults & lifters need MORE, ~1.2–1.5 g/kg), so don't let the fast squeeze it: front-load protein now or widen today's window.",
        ];
    }
}
