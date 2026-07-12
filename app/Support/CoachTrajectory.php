<?php

namespace App\Support;

use App\Models\ActivitySession;
use App\Models\Profile;
use Illuminate\Support\Carbon;

/**
 * The coach's always-on situational awareness — a compact, confidence-tagged "trajectory" block injected
 * into every system prompt so the coach opens each chat already knowing the DIRECTION of the user's life
 * (the way a real coach reviews your week before you sit down), instead of flying blind until it decides
 * to fetch. It SURFACES math that already exists (TrendsOverview / WeightTrend / Macros) rather than
 * inventing any — the deep drill-downs stay as tools (show_trend, sleep_recovery_summary, weekly_review);
 * this is the zoomed-out picture, they're the zoom-in.
 *
 * Design rules:
 *  - 7d current vs the 28d baseline, with a direction arrow and a confidence tag per domain.
 *  - NEVER emit a domain with no data (an empty line reads as "flat", which is a lie). Thin data →
 *    "building" so the coach hedges instead of stating a soft number flatly.
 *  - Tight (~≤ $budget chars, budgeted like the memory digest). Fully defensive: any domain that throws
 *    is dropped, and a total failure returns '' so the prompt is never broken by a trajectory error.
 */
class CoachTrajectory
{
    private const WINDOW_DAYS = 28;
    private const DEADBAND = 0.04;   // within ±4% of the 28d baseline reads as "flat" (no meaningful move)

    public static function digest(Profile $profile, int $budget = 900): string
    {
        return rescue(fn () => self::build($profile, $budget), '', false);
    }

    private static function build(Profile $profile, int $budget): string
    {
        $lines = [];

        $ov = rescue(fn () => TrendsOverview::forProfile($profile, self::WINDOW_DAYS), null, false);
        $points = is_array($ov) ? ($ov['points'] ?? []) : [];
        $avg28 = is_array($ov) ? ($ov['averages'] ?? []) : [];
        $last7 = array_slice($points, -7);

        // --- Sleep: duration + performance, trending vs the 28d baseline.
        $sleep7 = self::avg($last7, 'sleep_h', 1);
        if ($sleep7 !== null) {
            $perf7 = self::avg($last7, 'sleep_performance');
            $dir = self::arrow($sleep7, $avg28['sleep_h'] ?? null);
            $base = ($avg28['sleep_h'] ?? null) !== null ? ", 28d {$avg28['sleep_h']}h" : '';
            $perf = $perf7 !== null ? ', perf '.(int) $perf7.'%' : '';
            $lines[] = "Sleep: {$sleep7}h/night{$perf} {$dir}{$base}. conf: ".self::conf(self::count($last7, 'sleep_h'));
        }

        // --- Recovery: HRV (higher better) + RHR (lower better) vs 28d, plus readiness.
        $hrv7 = self::avg($last7, 'hrv');
        $rhr7 = self::avg($last7, 'rhr');
        $ready7 = self::avg($last7, 'recovery');
        if ($hrv7 !== null || $rhr7 !== null) {
            $parts = [];
            if ($hrv7 !== null) {
                $parts[] = 'HRV '.(int) $hrv7.'ms '.self::arrow($hrv7, $avg28['hrv'] ?? null);
            }
            if ($rhr7 !== null) {
                // Raw direction (↓ = RHR fell); the coach knows lower RHR is better, so no good/bad flip here.
                $parts[] = 'RHR '.(int) $rhr7.' '.self::arrow($rhr7, $avg28['rhr'] ?? null);
            }
            if ($ready7 !== null) {
                $parts[] = 'readiness ~'.(int) $ready7;
            }
            $lines[] = 'Recovery: '.implode(', ', $parts).'. conf: '.self::conf(max(self::count($last7, 'hrv'), self::count($last7, 'rhr')));
        }

        // --- Training: typical strain per active day + sessions/wk + load direction.
        $strain7 = self::avg($last7, 'strain', 1);
        $sessions7 = rescue(fn () => ActivitySession::where('profile_id', $profile->id)->training()
            ->where('started_at', '>=', Carbon::now()->subDays(7))->count(), 0, false);
        if ($strain7 !== null || $sessions7 > 0) {
            $parts = [];
            if ($strain7 !== null) {
                $parts[] = '~'.$strain7.' strain/active day '.self::arrow($strain7, $avg28['strain'] ?? null);
            }
            $parts[] = $sessions7.' session'.($sessions7 === 1 ? '' : 's').'/wk';
            $lines[] = 'Training: '.implode(', ', $parts).'. conf: '.self::conf($sessions7 > 0 ? max(3, self::count($last7, 'strain')) : self::count($last7, 'strain'));
        }

        // --- Stress: typical DAILY PEAK stress (0–3) + direction. Higher = worse; arrow shows raw
        // direction (↑ = rising), the coach knows up is bad. Silent until the strip has coverage.
        if (class_exists(\App\Support\StressMonitor::class)) {
            $stress7 = rescue(fn () => \App\Support\StressMonitor::weeklyPeak($profile, 7), null, false);
            if ($stress7 !== null) {
                $stress28 = rescue(fn () => \App\Support\StressMonitor::weeklyPeak($profile, 28), null, false);
                $lines[] = 'Stress: ~'.$stress7.'/3 typical daily peak '.self::arrow($stress7, $stress28).'. conf: '.self::conf($stress7 > 0 ? 3 : 1);
            }
        }

        // --- Nutrition: avg daily calories + protein vs target, protein gap, adherence.
        $nut = rescue(fn () => self::nutrition($profile), null, false);
        if (is_array($nut)) {
            $lines[] = $nut['line'];
        }

        // --- Body: weight trend + weekly rate vs the goal direction.
        $body = rescue(fn () => self::body($profile), null, false);
        if (is_string($body) && $body !== '') {
            $lines[] = $body;
        }

        if ($lines === []) {
            return '';
        }

        $block = '- '.implode("\n- ", $lines);
        if (strlen($block) > $budget) {
            // mb_strcut (not substr) so a byte-length cap never splits a multibyte arrow (↑↓→) into
            // a mojibake tail; it trims on a char boundary at/under the byte budget.
            $block = rtrim(mb_strcut($block, 0, $budget - 3)).'…';
        }

        return $block;
    }

    /**
     * Nutrition rollup over the last 7 logged-food days vs target: avg calories + protein, the recurring
     * protein gap, and adherence (share of days that hit ≥ 90% of the protein target).
     *
     * @return array{line:string}|null
     */
    private static function nutrition(Profile $profile): ?array
    {
        $appTz = config('app.timezone', 'UTC');
        $tz = $profile->settings['timezone'] ?? $appTz;
        $from = Carbon::now($tz)->startOfDay()->subDays(6)->setTimezone($appTz);

        $meals = $profile->meals()->where('eaten_at', '>=', $from)->get(['eaten_at', 'calories', 'protein_g']);
        if ($meals->isEmpty()) {
            return null;
        }

        $byDay = $meals->groupBy(fn ($m) => Carbon::parse($m->eaten_at)->setTimezone($tz)->toDateString());
        $days = $byDay->count();
        $calDay = (int) round($meals->sum('calories') / $days);
        $proDay = (int) round((float) $meals->sum('protein_g') / $days);

        $t = rescue(fn () => TargetSettings::resolve($profile), null, false) ?? Macros::DEFAULT_TARGETS;
        $calT = (int) ($t['calories'] ?? 2800);
        $proT = (int) ($t['protein_g'] ?? 200);

        $hit = $byDay->filter(fn ($m) => (float) $m->sum('protein_g') >= 0.9 * $proT)->count();
        $adherence = (int) round($hit / $days * 100);
        $gap = $proT - $proDay;
        $gapTxt = $gap >= 15 ? " — protein short ~{$gap}g" : ($gap <= -15 ? ' — protein over target' : ' — protein on target');

        $conf = self::conf($days);

        return ['line' => "Nutrition: ~{$calDay} kcal / {$proDay}g protein/day vs {$calT}/{$proT}{$gapTxt}, adherence {$adherence}% ({$days}d logged). conf: {$conf}"];
    }

    /** Weight trend + weekly rate, framed by the active goal's direction. */
    private static function body(Profile $profile): string
    {
        if (! class_exists(WeightTrend::class)) {
            return '';
        }
        $cur = WeightTrend::current($profile);
        $latest = $cur['trend'] ?? ($cur['weight'] ?? null);
        if ($latest === null) {
            return '';
        }
        $rate = WeightTrend::weeklyRateKg($profile);
        $rateTxt = '';
        if ($rate !== null && abs($rate) >= 0.05) {
            $arrow = $rate < 0 ? '↓' : '↑';
            $rateTxt = ', '.$arrow.abs(round($rate, 2)).'kg/wk';
        } elseif ($rate !== null) {
            $rateTxt = ', holding';
        }
        $goal = rescue(fn () => WeightTrend::activeGoal($profile), null, false);
        $goalTxt = '';
        if ($goal && ($goal->direction ?? null)) {
            $goalTxt = ' (goal: '.$goal->direction.')';
        }

        return 'Body: '.round((float) $latest, 1).'kg'.$rateTxt.$goalTxt.'. conf: '.self::conf($rate !== null ? 5 : 2);
    }

    /** Direction arrow of a 7d value vs its 28d baseline, honoring a deadband. */
    private static function arrow(?float $now, ?float $base): string
    {
        if ($now === null || $base === null || $base == 0.0) {
            return '';
        }
        $rel = ($now - $base) / abs($base);
        if (abs($rel) < self::DEADBAND) {
            return '→';
        }

        return $rel > 0 ? '↑' : '↓';
    }

    /** Confidence tag from how many days of data back the number. */
    private static function conf(int $n): string
    {
        return $n >= 5 ? 'solid' : ($n >= 2 ? 'building' : 'thin');
    }

    /** @param array<int,array<string,mixed>> $points */
    private static function avg(array $points, string $key, int $decimals = 0): ?float
    {
        $vals = array_values(array_filter(array_column($points, $key), fn ($v) => $v !== null));
        if ($vals === []) {
            return null;
        }
        $mean = array_sum($vals) / count($vals);

        return $decimals ? round($mean, $decimals) : (float) round($mean);
    }

    /** @param array<int,array<string,mixed>> $points */
    private static function count(array $points, string $key): int
    {
        return count(array_filter(array_column($points, $key), fn ($v) => $v !== null));
    }
}
