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
     * @return array{
     *   onset_min: ?int, deep_distribution: ?string, awakenings: array<int,array{at:string,min:int}>,
     *   rem_cycles: int, takeaway: string, text: string, low_confidence: bool
     * }|null  null when there's no hypnogram to read
     */
    public static function forNight(SleepLog $log): ?array
    {
        $hyp = is_array($log->hypnogram) ? array_values($log->hypnogram) : [];
        if (count($hyp) < 4) {
            return null;
        }
        $low = (bool) $log->low_confidence;
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

        // REM cycles + whether the last was a long one (normal right before waking).
        [$remCycles, $lastRemLong] = self::remCycles($hyp);

        [$takeaway, $text] = self::compose($low, $onsetMin, $deepDist, $awakenings, $remCycles, $lastRemLong);

        return [
            'onset_min' => $onsetMin,
            'deep_distribution' => $deepDist,
            'awakenings' => $awakenings,
            'rem_cycles' => $remCycles,
            'takeaway' => $takeaway,
            'text' => $text,
            'low_confidence' => $low,
        ];
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

    /** @return array{0:int,1:bool} [rem cycle count, last-rem-run-was-long] */
    private static function remCycles(array $hyp): array
    {
        $cycles = 0;
        $inRem = false;
        $runLen = 0;
        $lastRunLen = 0;
        foreach ($hyp as $c) {
            if ($c === 'rem') {
                if (! $inRem) {
                    $cycles++;
                    $inRem = true;
                    $runLen = 0;
                }
                $runLen++;
                $lastRunLen = $runLen;
            } else {
                $inRem = false;
            }
        }

        return [$cycles, $lastRunLen >= 20];   // ≥10 min final REM run
    }

    /** Weave the bits into ONE narrative with a single takeaway. @return array{0:string,1:string} */
    private static function compose(bool $low, int $onsetMin, ?string $deepDist, array $awakenings, int $remCycles, bool $lastRemLong): array
    {
        $s = [];

        // Onset.
        $s[] = $low
            ? 'Signal was thin overnight, so this is an estimate — read it as the shape of your night, not exact numbers.'
            : ($onsetMin <= 12 ? "You fell asleep quickly — within about {$onsetMin} minutes"
                : ($onsetMin >= 30 ? "It took you a while to drop off — around {$onsetMin} minutes"
                    : "You were asleep within about {$onsetMin} minutes"));

        // Deep distribution (skip precise framing on a low-confidence night).
        if (! $low && $deepDist !== null) {
            $s[] = match ($deepDist) {
                'front' => 'and most of your deep sleep came in the first hours — exactly when the body does its repair work',
                'back' => 'and your deep sleep came later than ideal — the body banks its deepest sleep best early on',
                default => 'and your deep sleep was spread through the night',
            };
        }

        // Awakenings.
        if ($awakenings === []) {
            $s[] = $low ? 'You looked settled through the night.' : 'You slept essentially straight through.';
        } elseif (count($awakenings) === 1) {
            $a = $awakenings[0];
            $s[] = "You had one wake around {$a['at']}.";
        } else {
            $s[] = 'You woke '.count($awakenings).' times through the night.';
        }

        // REM cycles.
        if (! $low && $remCycles >= 1) {
            $rem = "You cycled through REM {$remCycles} ".($remCycles === 1 ? 'time' : 'times');
            $s[] = $lastRemLong ? $rem.', the last a long one right before waking — that\'s normal.' : $rem.'.';
        }

        // The ONE takeaway — a single soft spot or win.
        $takeaway = self::takeaway($low, $onsetMin, $deepDist, $awakenings, $remCycles);
        $text = self::join($s).' '.$takeaway;

        return [$takeaway, trim($text)];
    }

    private static function takeaway(bool $low, int $onsetMin, ?string $deepDist, array $awakenings, int $remCycles): string
    {
        if ($low) {
            return 'Check your band fit tonight so we can read the full picture.';
        }
        if ($deepDist === 'back') {
            return 'The one soft spot: your deep sleep skewed late — an earlier, steadier bedtime pulls it forward.';
        }
        if ($onsetMin >= 30) {
            return 'The one thing to work on: a slow wind-down — dim screens and light in the last hour to fall asleep faster.';
        }
        if (count($awakenings) >= 3) {
            return 'The one soft spot: a few awakenings broke up the night — a cooler, darker room often smooths those out.';
        }
        if ($deepDist === 'front' && $onsetMin <= 15 && $remCycles >= 3) {
            return 'The win: fast onset, front-loaded deep, and full REM cycles — a textbook night. Keep the rhythm.';
        }

        return 'Solid, well-structured night — keep the routine that got you here.';
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
