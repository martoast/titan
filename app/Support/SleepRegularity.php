<?php

namespace App\Support;

use App\Models\SleepLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Sleep Regularity Index (SRI) -- Phillips et al., Scientific Reports 2017.
 *
 * The probability, scaled to [-100, 100], that a person is in the SAME sleep/wake state at any two
 * times exactly 24 h apart. 100 = identical schedule every day; 0 = no better than chance; negative
 * = anti-phase. It measures the CONSISTENCY of sleep timing, independent of how much you sleep.
 *
 * Why it's worth surfacing: in UK Biobank (Windred et al., Sleep 2024, n≈60k) SRI predicted
 * all-cause mortality MORE strongly than sleep duration -- the most-irregular sleepers had a hazard
 * ratio ~1.5 vs the median, and the most-regular quintiles 20-48% lower mortality. It's also
 * directly behaviourally actionable ("keep a consistent schedule") and free from the data we
 * already store.
 *
 * Implementation: lay every night's sleep window onto one minute-resolution timeline, then compare
 * each minute to the same minute 24 h (1440 min) later. SRI = 200 × agreement − 100. We use the
 * stored bed/wake window as the sleep period (a standard approximation; within-night awakenings
 * aren't persisted yet, so this is "sleep-window regularity").
 */
class SleepRegularity
{
    /** Need at least this many usable nights before an SRI is meaningful (literature uses ~7+). */
    public const MIN_NIGHTS = 5;

    private const MINUTES_PER_DAY = 1440;

    /**
     * @param  Collection<int,SleepLog>  $logs  recent nights, any order
     * @return array{sri:int,nights:int,band:string,label:string}|null  null if too few usable nights
     */
    public static function compute(Collection $logs): ?array
    {
        $intervals = [];
        foreach ($logs as $log) {
            if ($iv = self::intervalFor($log)) {
                $intervals[] = $iv;
            }
        }
        if (count($intervals) < self::MIN_NIGHTS) {
            return null;
        }

        usort($intervals, fn ($a, $b) => $a[0] <=> $b[0]);
        // Anchor the timeline to the FIRST sleep onset (not the start of its calendar day): a partial
        // leading day would add a phantom "awake morning" before any recorded sleep and depress SRI.
        // The 24 h-lag comparison is alignment-invariant, so any continuous anchor is valid.
        $windowStart = $intervals[0][0]->timestamp;
        $windowEnd = max(array_map(fn ($iv) => $iv[1]->timestamp, $intervals));
        $totalMin = intdiv($windowEnd - $windowStart, 60) + 1;
        if ($totalMin < 2 * self::MINUTES_PER_DAY) {
            return null; // need ≥2 days to compare anything 24 h apart
        }

        // Minute-resolution sleep/wake timeline (1 = asleep). Timestamps avoid Carbon diff-sign quirks.
        $asleep = array_fill(0, $totalMin, 0);
        foreach ($intervals as [$start, $end]) {
            $s = max(0, intdiv($start->timestamp - $windowStart, 60));
            $e = min($totalMin, intdiv($end->timestamp - $windowStart, 60));
            for ($i = $s; $i < $e; $i++) {
                $asleep[$i] = 1;
            }
        }

        // Agreement between each minute and the same minute one day later.
        $pairs = $totalMin - self::MINUTES_PER_DAY;
        $matches = 0;
        for ($t = 0; $t < $pairs; $t++) {
            if ($asleep[$t] === $asleep[$t + self::MINUTES_PER_DAY]) {
                $matches++;
            }
        }

        $sri = (int) round(200 * ($matches / $pairs) - 100);
        [$band, $label] = self::band($sri);

        return ['sri' => $sri, 'nights' => count($intervals), 'band' => $band, 'label' => $label];
    }

    /** Reconstruct a night's [start, end] datetime from slept_at + bedtime + wake_time. */
    private static function intervalFor(SleepLog $log): ?array
    {
        if (! $log->bedtime || ! $log->wake_time) {
            return null; // SRI is about timing -- need both ends of the sleep window
        }
        $date = CarbonImmutable::parse($log->slept_at);
        [$bh, $bm] = array_pad(array_map('intval', explode(':', (string) $log->bedtime)), 2, 0);
        [$wh, $wm] = array_pad(array_map('intval', explode(':', (string) $log->wake_time)), 2, 0);

        $end = $date->setTime($wh, $wm);              // woke on slept_at
        $bedSameDay = $date->setTime($bh, $bm);
        // A bedtime later on the clock than wake-time (e.g. 23:30 vs 07:00) was the previous evening.
        $start = $bedSameDay >= $end ? $bedSameDay->subDay() : $bedSameDay;

        $minutes = intdiv($end->timestamp - $start->timestamp, 60);
        if ($minutes < 30 || $minutes > 16 * 60) {
            return null; // implausible window
        }

        return [$start, $end];
    }

    /** @return array{0:string,1:string} */
    private static function band(int $sri): array
    {
        return match (true) {
            $sri >= 85 => ['excellent', 'Very regular'],
            $sri >= 70 => ['good', 'Regular'],
            $sri >= 55 => ['fair', 'Somewhat irregular'],
            default => ['low', 'Irregular'],
        };
    }
}
