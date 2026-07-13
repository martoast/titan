<?php

namespace App\Support;

use App\Models\SleepLog;
use Illuminate\Support\Carbon;

/**
 * "The story of your night" — a short, specific, human read derived from the hypnogram + metrics the
 * seal already computed. One intelligence, three surfaces: the sleep detail screen, the web, and the
 * coach ("how'd I sleep?") all share this. Not a stats dump — a narrative with a single honest takeaway,
 * teaching the WHY briefly (Coach v3 stance). Honesty moat: a low_confidence night leads as an estimate
 * and stays qualitative — it never narrates a hatched NODATA hole as if it were measured.
 *
 * @see SleepDetail (attaches this as `story`) @see docs/SLEEP_ARCHITECTURE.md (the hypnogram)
 */
class SleepStory
{
    private const EPOCH_S = 30;
    private const WAKE_RUN_EPOCHS = 10;   // a "wake" of ≥5 min is a real awakening; shorter is normal noise

    /**
     * @param  ?float  $baselineH  the user's STABLE sleep need (baseline_h, ~8h) — NOT the debt-inflated,
     *                             capped need_h. Sufficiency is "was this night enough vs your baseline",
     *                             and debt is built by falling short of BASELINE, not of a target that's
     *                             high because you're already in debt (review c683a67 — that was circular
     *                             and told the user "you need 10h"). Tonight's elevated target is advice.
     * @return array{
     *   onset_min: ?int, deep_distribution: ?string, awakenings: array<int,array{at:string,min:int}>,
     *   rem_periods: int, asleep_h: float, need_h: ?float, short_by_h: ?float,
     *   takeaway: string, text: string, low_confidence: bool
     * }|null  null when there's no hypnogram to read
     */
    public static function forNight(SleepLog $log, ?float $baselineH = null): ?array
    {
        $hyp = is_array($log->hypnogram) ? array_values($log->hypnogram) : [];
        if (count($hyp) < 4) {
            return null;
        }
        $low = (bool) $log->low_confidence;

        // SUFFICIENCY + stage ADEQUACY (not just where the stages sat). Minutes come straight off the log.
        $deepMin = (float) ($log->deep_min ?? 0);
        $remMin = (float) ($log->rem_min ?? 0);
        $lightMin = (float) ($log->light_min ?? 0);
        $asleepMin = $deepMin + $remMin + $lightMin ?: (float) ($log->duration_min ?? 0);
        $asleepH = round($asleepMin / 60, 1);
        // The stable BASELINE need — falling short of THIS is what builds debt (not the debt-inflated need).
        $needH = $baselineH ?? self::needFor($log);
        $shortBy = $needH !== null ? round($needH - $asleepH, 1) : null;
        // Healthy stage fractions: deep ~13–23%, REM ~20–25% of sleep. Below the floor = deficient.
        $deepLow = $asleepMin > 60 && ($deepMin / $asleepMin) < 0.11;
        $remLow = $asleepMin > 60 && ($remMin / $asleepMin) < 0.15;
        $bed = self::bedCarbon($log);
        $clock = fn (int $i) => $bed ? $bed->copy()->addSeconds($i * self::EPOCH_S)->format('g:i A') : '';

        $isSleep = fn (?string $c) => in_array($c, ['light', 'deep', 'rem'], true);

        // Sleep-onset latency: epochs from the start to the first real sleep epoch.
        $onsetIdx = null;
        foreach ($hyp as $i => $c) {
            if ($isSleep($c)) {
                $onsetIdx = $i;
                break;
            }
        }
        if ($onsetIdx === null) {
            return null;   // never actually asleep in the record — nothing to narrate
        }
        $onsetMin = (int) round($onsetIdx * self::EPOCH_S / 60);

        // Last sleep epoch → the sleep span [onset, lastSleep] we judge distribution + wakes within.
        $lastSleep = $onsetIdx;
        foreach ($hyp as $i => $c) {
            if ($isSleep($c)) {
                $lastSleep = $i;
            }
        }

        // Deep distribution across the sleep span's thirds — front-loaded is the healthy default.
        $deepDist = self::deepDistribution($hyp, $onsetIdx, $lastSleep);

        // Real awakenings: wake runs ≥5 min strictly INSIDE the night (after onset, before final wake).
        $awakenings = self::awakenings($hyp, $onsetIdx, $lastSleep, $clock);

        // REM PERIODS (not "cycles") — real ~90-min NREM→REM completions, capped by the time asleep so a
        // fragmented 4.5h night can't report 6 (micro-REM runs filtered; review finding #3).
        [$remPeriods, $lastRemLong] = self::remPeriods($hyp, $asleepMin);

        $ctx = compact('low', 'onsetMin', 'deepDist', 'awakenings', 'remPeriods', 'lastRemLong',
            'asleepH', 'needH', 'shortBy', 'deepLow', 'remLow', 'deepMin', 'remMin');
        [$takeaway, $text] = self::compose($ctx);

        return [
            'onset_min' => $onsetMin,
            'deep_distribution' => $deepDist,
            'awakenings' => $awakenings,
            'rem_periods' => $remPeriods,
            'asleep_h' => $asleepH,
            'need_h' => $needH,
            'short_by_h' => $shortBy !== null && $shortBy > 0 ? $shortBy : null,
            'takeaway' => $takeaway,
            'text' => $text,
            'low_confidence' => $low,
        ];
    }

    /** Tonight's need if the caller didn't pass one — via SleepCoach's baseline (age/target aware). */
    private static function needFor(SleepLog $log): ?float
    {
        $profile = $log->profile;
        if (! $profile || ! class_exists(SleepCoach::class)) {
            return null;
        }

        return round(SleepCoach::baselineFor($profile), 1);
    }

    /** front | even | back | null (too little deep to judge). */
    private static function deepDistribution(array $hyp, int $onset, int $lastSleep): ?string
    {
        $span = $lastSleep - $onset;
        if ($span < 30) {
            return null;
        }
        $third = intdiv($span, 3);
        $first = $last = 0;
        for ($i = $onset; $i <= $lastSleep; $i++) {
            if (($hyp[$i] ?? null) !== 'deep') {
                continue;
            }
            if ($i < $onset + $third) {
                $first++;
            } elseif ($i >= $onset + 2 * $third) {
                $last++;
            }
        }
        if ($first + $last < 6) {
            return null;   // < 3 min of deep in the outer thirds — not enough to call
        }

        return $first > $last * 1.5 ? 'front' : ($last > $first * 1.5 ? 'back' : 'even');
    }

    /** @return array<int,array{at:string,min:int}> */
    private static function awakenings(array $hyp, int $onset, int $lastSleep, callable $clock): array
    {
        $out = [];
        $runStart = null;
        for ($i = $onset; $i <= $lastSleep; $i++) {
            $isWake = ($hyp[$i] ?? null) === 'wake';
            if ($isWake && $runStart === null) {
                $runStart = $i;
            } elseif (! $isWake && $runStart !== null) {
                $len = $i - $runStart;
                if ($len >= self::WAKE_RUN_EPOCHS) {
                    $out[] = ['at' => $clock($runStart), 'min' => (int) round($len * self::EPOCH_S / 60)];
                }
                $runStart = null;
            }
        }

        return array_slice($out, 0, 4);
    }

    /** REM periods = ~90-min NREM→REM completions. Count only REM runs ≥3 min (micro-REM filtered) and cap
     *  at the physiological max for the time asleep (~one per 80 min), so a fragmented short night can't
     *  report an implausible 6. @return array{0:int,1:bool} [periods, last-run-was-long] */
    private static function remPeriods(array $hyp, float $asleepMin): array
    {
        $periods = 0;
        $run = 0;
        $lastRun = 0;
        foreach ($hyp as $c) {
            if ($c === 'rem') {
                $run++;
                $lastRun = $run;
            } else {
                if ($run >= 6) {   // ≥3 min → a real REM period, not micro-REM
                    $periods++;
                }
                $run = 0;
            }
        }
        if ($run >= 6) {
            $periods++;
        }
        $cap = max(1, (int) floor($asleepMin / 80));

        return [min($periods, $cap), $lastRun >= 20];
    }

    /** Weave the bits into ONE narrative with a single takeaway. @return array{0:string,1:string} */
    private static function compose(array $c): array
    {
        [$low, $onsetMin, $deepDist, $awakenings, $remPeriods, $lastRemLong, $asleepH, $shortBy] =
            [$c['low'], $c['onsetMin'], $c['deepDist'], $c['awakenings'], $c['remPeriods'], $c['lastRemLong'], $c['asleepH'], $c['shortBy']];
        $s = [];

        // Onset + how much sleep it actually was (sufficiency, not just architecture).
        if ($low) {
            $s[] = 'Signal was thin overnight, so this is an estimate — read it as the shape of your night, not exact numbers.';
        } else {
            $onset = $onsetMin <= 12 ? "You fell asleep quickly — within about {$onsetMin} minutes"
                : ($onsetMin >= 30 ? "It took you a while to drop off — around {$onsetMin} minutes"
                    : "You were asleep within about {$onsetMin} minutes");
            $s[] = $onset." for {$asleepH}h total";
        }

        // Deep distribution (skip precise framing on a thin night).
        if (! $low && $deepDist !== null) {
            $s[] = match ($deepDist) {
                'front' => 'Most of your deep sleep came in the first hours — when the body does its repair work.',
                'back' => 'Your deep sleep came later than ideal — the body banks its deepest sleep best early on.',
                default => 'Your deep sleep was spread through the night.',
            };
        }

        // Awakenings.
        if ($awakenings === []) {
            $s[] = $low ? 'You looked settled through the night.' : 'You slept essentially straight through.';
        } elseif (count($awakenings) === 1) {
            $s[] = "You had one wake around {$awakenings[0]['at']}.";
        } else {
            $s[] = 'You woke '.count($awakenings).' times through the night.';
        }

        // REM periods.
        if (! $low && $remPeriods >= 1) {
            $rem = "You went through {$remPeriods} REM ".($remPeriods === 1 ? 'period' : 'periods');
            $s[] = $lastRemLong ? $rem.', the last a long one right before waking — that\'s normal.' : $rem.'.';
        }

        $takeaway = self::takeaway($c);
        $text = self::join($s).' '.$takeaway;

        return [$takeaway, trim($text)];
    }

    /**
     * The ONE takeaway — a function of SUFFICIENCY (duration vs need) AND stage ADEQUACY, then architecture.
     * A well-built but short night must read as short (so the story agrees with the debt ledger), and a
     * front-loaded-but-deep-deficient night must not read "textbook" (review findings #1–#2).
     */
    private static function takeaway(array $c): string
    {
        if ($c['low']) {
            return 'Check your band fit tonight so we can read the full picture.';
        }
        // 1 · SUFFICIENCY — a short night is the headline, however well-built.
        if (($c['shortBy'] ?? 0) >= 1.5) {
            $need = $c['needH'] !== null ? ' short of your ~'.rtrim(rtrim(number_format($c['needH'], 1), '0'), '.').'h need' : ' short of your need';
            $built = $c['deepDist'] === 'front' && ! $c['deepLow'] && ! $c['remLow'] ? 'Good structure, but ' : '';
            return $built."only {$c['asleepH']}h asleep — well{$need}; that's what's building your sleep debt. The fix isn't the shape of the night, it's more of it: an earlier bedtime.";
        }
        // 2 · STAGE ADEQUACY — enough hours, but a stage came up light.
        if ($c['deepLow']) {
            return "Deep sleep came up light ({$c['deepMin']} min) — that's your physical-repair stage. Alcohol, a late workout, or a warm room all cut it; steadier, cooler nights bring it back.";
        }
        if ($c['remLow']) {
            return "REM was scarce ({$c['remMin']} min) — the stage for memory and mood. It's the last to build, so it's the first lost to a short or late night.";
        }
        // 3 · ARCHITECTURE.
        if ($c['deepDist'] === 'back') {
            return 'The one soft spot: your deep sleep skewed late — an earlier, steadier bedtime pulls it forward.';
        }
        if ($c['onsetMin'] >= 30) {
            return 'The one thing to work on: a slow wind-down — dim screens and light in the last hour to fall asleep faster.';
        }
        if (count($c['awakenings']) >= 3) {
            return 'The one soft spot: a few awakenings broke up the night — a cooler, darker room often smooths those out.';
        }
        // 4 · A genuine win — only when it was BOTH enough AND well-built.
        return "A full, well-built night — {$c['asleepH']}h with solid deep and REM. This is the rhythm to keep.";
    }

    private static function join(array $parts): string
    {
        // Glue the onset + deep clause (they read as one sentence), then the rest as sentences.
        $parts = array_values(array_filter($parts, fn ($p) => $p !== ''));
        if ($parts === []) {
            return '';
        }
        $head = $parts[0];
        if (isset($parts[1]) && str_starts_with($parts[1], 'and ')) {
            $head .= ' '.array_splice($parts, 1, 1)[0];
        }
        $head = rtrim($head, '.').'.';

        return trim($head.' '.implode(' ', array_slice($parts, 1)));
    }

    private static function bedCarbon(SleepLog $log): ?Carbon
    {
        if (! $log->bedtime) {
            return null;
        }
        try {
            return Carbon::createFromFormat('H:i:s', str_pad($log->bedtime, 8, ':00'));
        } catch (\Throwable) {
            return null;
        }
    }
}
