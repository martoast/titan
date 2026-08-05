<?php

namespace App\Console\Commands;

use App\Models\SleepLog;
use App\Support\SleepPlausibility;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Re-judge already-sealed nights against the physiological-impossibility ceilings in SleepPlausibility.
 *
 *   php artisan sleep:recheck-confidence                        # DRY RUN across every staged night
 *   php artisan sleep:recheck-confidence --profile=6 --apply
 *   php artisan sleep:recheck-confidence --night=2026-08-04 --profile=6 --apply
 *
 * WHY THIS AND NOT A WINDOW-LEVEL RESEAL. Re-running the seal would feed the same raw windows to the same
 * unchanged model and get the same stage minutes back — the guard fix changes the VERDICT on a night, not
 * its staging. A real reseal would, however, have to rebuild the epoch grid from the row's stored
 * bedtime/wake_time, and those are the first/last ASLEEP epochs rather than the original bed/wake markers,
 * so the grid would shift by a few minutes and the stage numbers would move for no reason. Re-judging the
 * stored metrics is exactly what a reseal would conclude, without perturbing the data it concluded it from.
 *
 * STRICTLY MONOTONIC: this only ever raises `low_confidence` 0 → 1. It never clears the flag, because a
 * stored row doesn't carry the other inputs the seal weighed (the stager's own doubt, the valid-window
 * fraction), and re-deriving the full verdict from a partial picture could silently un-flag a night that
 * was correctly caveated for a reason invisible here.
 */
class RecheckSleepConfidence extends Command
{
    protected $signature = 'sleep:recheck-confidence
        {--profile= : Only this profile id}
        {--night= : Only this night (Y-m-d, the row\'s slept_at)}
        {--days= : Only nights within this many days back}
        {--apply : Actually write (otherwise dry-run)}';

    protected $description = 'Re-judge sealed nights against the physiological stage-layout ceilings and flag the impossible ones low_confidence.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $logs = SleepLog::query()
            ->whereNotNull('hypnogram')
            ->when($this->option('profile'), fn ($q, $v) => $q->where('profile_id', (int) $v))
            ->when($this->option('night'), fn ($q, $v) => $q->whereDate('slept_at', $v))
            ->when($this->option('days'), fn ($q, $v) => $q->where('slept_at', '>=', CarbonImmutable::now()->subDays((int) $v)->toDateString()))
            ->orderBy('slept_at')
            ->get();

        if ($logs->isEmpty()) {
            $this->info('recheck-confidence: no staged nights matched.');

            return self::SUCCESS;
        }

        $flagged = 0;
        foreach ($logs as $log) {
            $metrics = [
                'deep_min' => $log->deep_min,
                'rem_min' => $log->rem_min,
                'light_min' => $log->light_min,
                'hypnogram' => $log->hypnogram,
            ];

            if (! SleepPlausibility::impossible($metrics)) {
                continue;
            }

            $sleep = (float) $log->deep_min + (float) $log->rem_min + (float) $log->light_min;
            $reason = sprintf(
                'deep %d min (%.1f%% of sleep), longest bout %.1f min',
                (int) $log->deep_min,
                $sleep > 0 ? 100 * $log->deep_min / $sleep : 0,
                SleepPlausibility::longestDeepBoutMin($metrics),
            );

            if ($log->low_confidence) {
                $this->line("  profile #{$log->profile_id} {$log->slept_at?->toDateString()}: already low_confidence — {$reason}");

                continue;
            }

            $flagged++;
            $this->line("  <comment>⚑</comment> profile #{$log->profile_id} {$log->slept_at?->toDateString()} (log #{$log->id}): {$reason}");

            if ($apply) {
                // Only the flag. The stage minutes stay exactly as sealed — this command judges a night, it
                // does not restage one.
                $log->forceFill(['low_confidence' => true])->save();
            }
        }

        $this->info("recheck-confidence: {$flagged} night(s) ".($apply ? 'flagged low_confidence' : 'would be flagged [dry run]').'.');

        return self::SUCCESS;
    }
}
