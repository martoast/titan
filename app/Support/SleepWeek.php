<?php

namespace App\Support;

use App\Models\Profile;
use Carbon\CarbonImmutable;

/**
 * Sleep's "week at a glance" — the recovery-side counterpart to WorkoutStreak: every night's score for
 * the last 7 local days, one cumulative week score, a consistency streak, a 5-week heat strip, and ONE
 * data-driven tip about the week's pattern. Reuses SleepCoach's performance read (hours vs need) as the
 * per-night score; never invents a new one. Honest like the seal: a low_confidence night is shown but
 * EXCLUDED from the week score + streak (silent inclusion is the dishonesty the trust fix just killed).
 *
 * `slept_at` is a DATE (the seal writes the wake-local date), so it already buckets on the local day — no
 * UTC-wall-clock dance (that pitfall is for ActivitySession.started_at datetimes; see WorkoutStreak).
 */
class SleepWeek
{
    /** A night "hit need" (counts for the streak + strip) at ≥ this % of need. One place, tunable. */
    public const NEED_THRESHOLD = 85;

    private const STRIP_DAYS = 35;   // 5-week heat strip

    public static function forProfile(Profile $profile, string $tz = 'UTC'): array
    {
        $tz = self::safeZone($tz);
        $baseline = SleepCoach::baselineFor($profile);
        $today = CarbonImmutable::now($tz)->startOfDay();

        // Nights over the strip window, keyed by local date. Score = hours vs need (SleepCoach's read).
        $logs = $profile->sleepLogs()->nights()->final()
            ->where('slept_at', '>=', $today->subDays(self::STRIP_DAYS - 1)->toDateString())
            ->where('slept_at', '<=', $today->toDateString())
            ->orderBy('slept_at')
            ->get(['slept_at', 'duration_min', 'low_confidence', 'bedtime', 'deep_min', 'rem_min']);

        $byDate = [];
        foreach ($logs as $l) {
            $date = $l->slept_at?->toDateString();
            if ($date === null) {
                continue;
            }
            $low = (bool) $l->low_confidence;
            $score = ($low || ! $l->duration_min)
                ? null
                : (int) round(min(100, ($l->duration_min / 60.0) / max(0.1, $baseline) * 100));
            $byDate[$date] = [
                'score' => $score, 'duration_min' => $l->duration_min, 'low' => $low,
                'bedtime' => $l->bedtime, 'deep' => $l->deep_min, 'rem' => $l->rem_min,
            ];
        }

        // The 7-night row (oldest→newest), missing days included as hollow slots.
        $days = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = $today->subDays($i);
            $ds = $date->toDateString();
            $e = $byDate[$ds] ?? null;
            $score = $e['score'] ?? null;
            $days[] = [
                'date' => $ds,
                'weekday' => $date->format('D'),
                'score' => $score,
                'duration_min' => $e['duration_min'] ?? null,
                'hit_need' => $score !== null && $score >= self::NEED_THRESHOLD,
                'low_confidence' => $e['low'] ?? false,
                'logged' => $e !== null,
            ];
        }

        // Week score = mean of the last 7 CONFIDENT nights' scores (low-confidence excluded).
        $weekScores = array_values(array_filter(array_column($days, 'score'), fn ($s) => $s !== null));
        $weekScore = $weekScores === [] ? null : (int) round(array_sum($weekScores) / count($weekScores));
        [$weekBand, $weekLabel] = self::band($weekScore);

        // Trend vs the prior 7-night window.
        $priorScores = [];
        for ($i = 13; $i >= 7; $i--) {
            $s = $byDate[$today->subDays($i)->toDateString()]['score'] ?? null;
            if ($s !== null) {
                $priorScores[] = $s;
            }
        }
        $trend = null;
        if ($weekScore !== null && $priorScores !== []) {
            $prior = array_sum($priorScores) / count($priorScores);
            $trend = $weekScore > $prior + 3 ? 'up' : ($weekScore < $prior - 3 ? 'down' : 'flat');
        }

        return [
            'week_score' => $weekScore,
            'week_label' => $weekLabel,
            'week_band' => $weekBand,
            'trend' => $trend,
            'nights_logged' => count($weekScores),
            'streak' => self::streak($byDate, $today),
            'days' => $days,
            'strip' => self::strip($byDate, $today),
            'tip' => self::tip($profile, $days, $byDate, $baseline),
        ];
    }

    /** Consecutive most-recent nights that HIT need. Alive off yesterday if last night isn't logged yet. */
    private static function streak(array $byDate, CarbonImmutable $today): array
    {
        $hit = fn (string $d) => ($byDate[$d]['score'] ?? null) !== null && $byDate[$d]['score'] >= self::NEED_THRESHOLD;

        $lastNightLogged = isset($byDate[$today->toDateString()]);
        $current = 0;
        if ($hit($today->toDateString()) || (! $lastNightLogged && $hit($today->subDay()->toDateString()))) {
            $cursor = $hit($today->toDateString()) ? $today : $today->subDay();
            while ($hit($cursor->toDateString())) {
                $current++;
                $cursor = $cursor->subDay();
            }
        }

        // Longest ever from the loaded window (a longer history would need its own query; the strip window
        // is a fair recent best).
        $longest = 0;
        $run = 0;
        $prev = null;
        foreach (array_keys($byDate) as $d) {
            if (! $hit($d)) {
                $run = 0;
                $prev = $d;

                continue;
            }
            $run = ($prev !== null && CarbonImmutable::parse($d)->equalTo(CarbonImmutable::parse($prev)->addDay())) ? $run + 1 : 1;
            $longest = max($longest, $run);
            $prev = $d;
        }

        return [
            'current' => $current,
            'longest' => max($longest, $current),
            'slept_well_last_night' => $hit($today->toDateString()),
        ];
    }

    /** The 35-day heat strip: local dates that HIT need. */
    private static function strip(array $byDate, CarbonImmutable $today): array
    {
        $out = [];
        foreach ($byDate as $d => $e) {
            if (($e['score'] ?? null) !== null && $e['score'] >= self::NEED_THRESHOLD) {
                $out[] = $d;
            }
        }
        sort($out);

        return $out;
    }

    /** @return array{0:string,1:string} [band, label] */
    private static function band(?int $score): array
    {
        return match (true) {
            $score === null => ['none', 'No nights yet'],
            $score >= 90 => ['excellent', 'Excellent week'],
            $score >= 80 => ['strong', 'Strong week'],
            $score >= 65 => ['building', 'Building week'],
            default => ['run_down', 'Run down'],
        };
    }

    /**
     * ONE true thing about the week + an action. Priority order (fire the first that triggers): timing
     * variance → debt → duration → restorative → catch-up → a win. Data already on hand.
     */
    private static function tip(Profile $profile, array $days, array $byDate, float $baseline): array
    {
        $logged = array_values(array_filter($days, fn ($d) => $d['logged'] && ! $d['low_confidence']));

        // 1 · TIMING / CONSISTENCY — bedtime spread > ~90 min is the highest-leverage lever.
        $bedMins = [];
        foreach ($logged as $d) {
            $bt = $byDate[$d['date']]['bedtime'] ?? null;
            if ($bt) {
                [$h, $m] = array_pad(array_map('intval', explode(':', $bt)), 2, 0);
                // Fold evening/after-midnight onto one axis around ~22:00 so 23:30 and 00:30 sit adjacent.
                $mins = $h * 60 + $m;
                $bedMins[] = $mins < 12 * 60 ? $mins + 24 * 60 : $mins;
            }
        }
        if (count($bedMins) >= 4) {
            $spread = max($bedMins) - min($bedMins);
            if ($spread > 90) {
                $h = intdiv($spread, 60);
                $m = $spread % 60;
                return [
                    'headline' => "Your bedtime swung {$h}h{$m}m this week",
                    'insight' => 'Your sleep landed across a wide window. That variance is the top reason deep sleep suffers — the body banks its deepest sleep when it can predict lights-out.',
                    'action' => 'Pick one lights-out time and hold it within ~30 min, every night.',
                    'domain' => 'consistency',
                ];
            }
        }

        // 2 · DEBT — read straight from the SleepDebt ledger (one source of truth).
        if (class_exists(SleepDebt::class)) {
            $debt = SleepDebt::forProfile($profile);
            if (($debt['balance_h'] ?? 0) >= 2.0) {
                return [
                    'headline' => 'You\'re carrying '.$debt['balance_h'].'h of sleep debt',
                    'insight' => $debt['explainer'] ?? 'Debt is the sleep you owe from recent short nights.',
                    'action' => $debt['payback']['plan'] ?? 'Aim for an earlier night to chip at it.',
                    'domain' => 'debt',
                ];
            }
        }

        // 3 · DURATION — several sub-need nights.
        $short = array_filter($logged, fn ($d) => ($d['score'] ?? 100) < self::NEED_THRESHOLD);
        if (count($short) >= 3) {
            return [
                'headline' => count($short).' nights under your need this week',
                'insight' => 'Consistently short nights blunt recovery, focus and appetite control — the deficit compounds.',
                'action' => 'Protect a 30-min-earlier bedtime on your next two nights.',
                'domain' => 'duration',
            ];
        }

        // 4 · RESTORATIVE — adequate hours but low deep+REM.
        $restFracs = [];
        foreach ($logged as $d) {
            $e = $byDate[$d['date']];
            if ($e['duration_min'] && ($e['deep'] !== null || $e['rem'] !== null)) {
                $restFracs[] = (($e['deep'] ?? 0) + ($e['rem'] ?? 0)) / $e['duration_min'];
            }
        }
        if ($restFracs !== [] && array_sum($restFracs) / count($restFracs) < 0.35) {
            return [
                'headline' => 'Your deep + REM ran low this week',
                'insight' => 'You spent enough time in bed, but not enough in the restorative stages — often from late meals, alcohol, or an irregular schedule fragmenting sleep.',
                'action' => 'Cut screens + food in the last hour before bed for a few nights and watch deep sleep recover.',
                'domain' => 'restorative',
            ];
        }

        // 5 · WIN — nothing to fix; reinforce the streak.
        $hits = count(array_filter($days, fn ($d) => $d['hit_need']));

        return [
            'headline' => $hits >= 5 ? 'Strong, consistent week of sleep' : 'A steady week of sleep',
            'insight' => 'You hit your need on '.$hits.' of the last 7 nights. Consistency is the single metric that moves recovery most.',
            'action' => 'Keep the rhythm — same bedtime tonight.',
            'domain' => 'win',
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
