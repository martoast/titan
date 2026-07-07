<?php

namespace App\Support;

use App\Models\Profile;
use App\Models\SleepLog;
use Illuminate\Support\Carbon;

/**
 * Sleep Coach -- the "how much sleep do you actually need" half of the daily loop.
 *
 * Sleep Need tonight = a personalised baseline + the sleep debt you've built up + a bump for how hard
 * today was (harder days need more sleep). Sleep Performance scores last night against a full baseline
 * night. Together: "you got 84% of a full night, and you're carrying 1.3 h of debt -- aim for 8.7 h
 * tonight."
 *
 * Grounded in the consensus that adults need ~7-9 h (baseline ~8 h, nudged by age), that deficits
 * accumulate as debt, and that exercise modestly raises sleep need. Honest scope: a wellness coaching
 * heuristic (Whoop's exact formula is proprietary; ours is transparent), not a clinical sleep measure.
 */
class SleepCoach
{
    private const BASELINE_H = 8.0;
    private const DEBT_NIGHTS = 5;
    private const DEBT_CAP_H = 5.0;
    private const NEED_CAP_H = 10.0;

    /**
     * The morning sleep SUMMARY — fired when the user marks awake on the band (a CONFIRMED session).
     * Unlike assess()'s selective win/debt nudge, this speaks to EVERY confirmed night: the breakdown
     * (duration, deep, REM, quality), the band's read, and a forward line. Mirrors {@see WorkoutCoach}.
     *
     * @return array{title:string, push:string, body:string}
     */
    public static function summary(Profile $profile, SleepLog $log): array
    {
        $name = $profile->display_name ? ' '.$profile->display_name : '';
        $h = $log->duration_min ? $log->duration_min / 60.0 : null;
        $hh = $h !== null ? rtrim(rtrim(number_format($h, 1), '0'), '.').'h' : null;

        $bits = [];
        if ($hh) {
            $bits[] = $hh.' asleep';
        }
        if ($log->deep_min) {
            $bits[] = $log->deep_min.' min deep';
        }
        if ($log->rem_min) {
            $bits[] = $log->rem_min.' min REM';
        }
        if ($log->quality) {
            $bits[] = $log->quality.'% quality';
        }
        $summary = implode(' · ', $bits);

        $assess = rescue(fn () => self::assess($profile), null, false);
        $band = $assess['band'] ?? null;
        $advice = $assess['advice'] ?? '';

        $emoji = $band === 'optimal' ? '☀️' : (($band === 'debt' || $band === 'low') ? '😴' : '🌅');
        $lead = $band === 'optimal' ? 'You banked a full night'
            : (($band === 'debt' || $band === 'low') ? 'A short one' : 'Solid night');

        return [
            'title' => "{$emoji} Good morning",
            'push' => ($summary !== '' ? $summary : 'Sleep logged').'. Tap — your coach has your sleep breakdown.',
            'body' => "{$emoji} **Good morning{$name}.** {$lead}".($summary !== '' ? " — {$summary}." : '.')
                .($advice !== '' ? " {$advice}" : '')
                ." How do you feel — rested, or still tired? I'll factor it into today's plan.",
        ];
    }

    /**
     * @return array{need_h:float,baseline_h:float,debt_h:float,strain_bump_h:float,
     *   last_h:?float,performance_pct:?int,band:string,label:string,advice:string}|null
     */
    public static function assess(Profile $profile, ?Carbon $day = null): ?array
    {
        $day = $day ?? Carbon::today();

        $nights = $profile->sleepLogs()
            ->where('is_nap', false)   // naps are bounded sessions, not "last night" — exclude from debt/need
            ->where('slept_at', '>=', $day->copy()->subDays(self::DEBT_NIGHTS))
            ->orderByDesc('slept_at')->orderByDesc('id')->get();
        if ($nights->isEmpty()) {
            return null;
        }

        $baseline = self::baselineFor($profile);

        // Sleep debt: recent deficits vs baseline, weighted toward recent nights, capped.
        $debt = 0.0;
        $w = 1.0;
        foreach ($nights as $n) {
            $h = $n->duration_min / 60.0;
            $debt += max(0.0, $baseline - $h) * $w;
            $w *= 0.7;                                       // older nights matter less
        }
        $debt = min($debt, self::DEBT_CAP_H);

        // Today's strain raises tonight's need a little (hard day → more sleep).
        $strain = Strain::assess($profile, $day)['strain'];
        $strainBump = round(($strain / 21.0) * 0.75, 2);

        $need = min(self::NEED_CAP_H, $baseline + min($debt * 0.5, 1.5) + $strainBump);

        // Last night's raw duration…
        $last = $nights->first();
        $lastH = $last ? round($last->duration_min / 60.0, 1) : null;

        // …plus any naps today: a nap is real recovery, so it adds to the effective sleep that
        // drives the performance ring (and thus the dashboard Sleep pillar). Nights still own debt/need.
        $napMin = (int) $profile->sleepLogs()->where('is_nap', true)
            ->whereDate('slept_at', $day)->sum('duration_min');
        $effectiveH = $lastH !== null ? round($lastH + $napMin / 60.0, 1) : ($napMin > 0 ? round($napMin / 60.0, 1) : null);

        $performance = $effectiveH !== null ? (int) round(min(100, $effectiveH / $baseline * 100)) : null;

        [$band, $label, $advice] = self::coach($performance, $debt, $need, $effectiveH);

        return [
            'need_h' => round($need, 1),
            'baseline_h' => round($baseline, 1),
            'debt_h' => round($debt, 1),
            'strain_bump_h' => $strainBump,
            'last_h' => $lastH,
            'nap_min' => $napMin,
            'effective_h' => $effectiveH,
            'performance_pct' => $performance,
            'band' => $band,
            'label' => $label,
            'advice' => $advice,
        ];
    }

    /** The nightly sleep target (hours) — a user override wins, else an age-based default. */
    public static function targetHours(Profile $profile): float
    {
        return self::baselineFor($profile);
    }

    public static function baselineFor(Profile $profile): float
    {
        // User-set target wins (app Targets sheet / coach set_targets).
        $override = $profile->settings['sleep_target_h'] ?? null;
        if (is_numeric($override) && $override > 0) {
            return (float) $override;
        }

        // Age nudge: teens/young adults need a touch more; older adults a touch less.
        $age = $profile->birthdate ? Carbon::parse($profile->birthdate)->diffInYears(now()) : 35;

        return match (true) {
            $age < 25 => 8.5,
            $age >= 65 => 7.5,
            default => self::BASELINE_H,
        };
    }

    /** @return array{0:string,1:string,2:string} [band, label, advice] */
    private static function coach(?int $perf, float $debt, float $need, ?float $lastH): array
    {
        $aim = sprintf('Aim for about %s h tonight.', rtrim(rtrim(number_format($need, 1), '0'), '.'));

        if ($perf === null) {
            return ['unknown', 'Log a night to start', $aim];
        }
        if ($debt >= 2.0) {
            return ['debt', 'Carrying sleep debt', sprintf("You're down ~%s h. %s An earlier night chips into it.", rtrim(rtrim(number_format($debt, 1), '0'), '.'), $aim)];
        }
        if ($perf >= 90) {
            return ['optimal', 'Well rested', "Last night covered your need -- keep this rhythm. {$aim}"];
        }
        if ($perf >= 80) {
            return ['good', 'Solid', "A good night, a little short of a full one. {$aim}"];
        }

        return ['low', 'Short night', "Last night fell short. {$aim} Protect your wind-down."];
    }
}
