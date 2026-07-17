<?php

namespace App\Console\Commands;

use App\Jobs\SealNightJob;
use App\Models\SleepLog;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Recover a night stranded in `computing` — its stage pass never finished, so the hypnogram is empty
 * and the app shows an eternal loading card with NO timeline. The usual cause is the biosignal staging
 * service being down/unhealthy at seal time (it was hung for ~2 days once, and that night got stuck):
 * the routine `--night` reseal does NOT re-stage an already-placed COMPUTING row. This command re-runs
 * the CONFIRMED seal — scoped to the row's real bed→wake window, the path that actually finalizes it —
 * so the stages compute and the row flips to `final`.
 *
 * Safe by construction:
 *   - Only overnights (never a nap) `computing` for longer than MIN_AGE_MIN — a genuinely in-flight
 *     seal (its stages are just seconds away) is left alone.
 *   - Only when biosignal is actually reachable — else re-staging would just fail again; it defers and
 *     the autoheal watchdog (~/deploy/autoheal.sh) restarts biosignal first.
 *   - Idempotent: once a night seals `final` it's no longer a candidate.
 * See docs/SEAL_ARCHITECTURE.md. Companion to sleep:recover-late (the "phone died mid-night" case).
 */
class RecoverStuckNights extends Command
{
    protected $signature = 'sleep:recover-stuck
        {--profile= : Only this profile id}
        {--days=3 : How many days back to consider}
        {--dry : Report without re-staging}';

    protected $description = 'Re-stage overnight sleep logs stranded in "computing" (e.g. biosignal was down at seal time).';

    /** Don't touch a computing row younger than this — its stage pass may still be in flight. */
    private const MIN_AGE_MIN = 20;

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $dry = (bool) $this->option('dry');
        $tz = (string) config('app.timezone', 'UTC');

        $stuck = SleepLog::query()
            ->where('stage_status', SleepLog::STATUS_COMPUTING)
            ->where('is_nap', false)
            ->where('slept_at', '>=', Carbon::now($tz)->subDays($days)->toDateString())
            ->where('created_at', '<=', Carbon::now()->subMinutes(self::MIN_AGE_MIN))
            ->when($this->option('profile'), fn ($q) => $q->where('profile_id', $this->option('profile')))
            ->orderBy('slept_at')
            ->get();

        if ($stuck->isEmpty()) {
            $this->info('recover-stuck: no stranded computing nights.');

            return self::SUCCESS;
        }

        // Only worth re-staging if biosignal can actually run the model — else we'd just re-fail.
        if (! $this->biosignalHealthy()) {
            $this->warn('recover-stuck: '.$stuck->count().' stranded night(s), but biosignal is NOT healthy — deferring (autoheal will restart it, then a later run recovers them).');

            return self::SUCCESS;
        }

        $recovered = 0;
        foreach ($stuck as $log) {
            $pid = (int) $log->profile_id;
            $night = $log->slept_at?->toDateString();
            [$bed, $wake] = $this->bedWakeEpochs($log, $tz);
            if (! $night || $bed === null || $wake === null || $wake <= $bed) {
                $this->line("  profile #{$pid} {$night}: can't reconstruct bed/wake — skip.");

                continue;
            }

            $recovered++;
            $this->line("  <info>↻</info> profile #{$pid} {$night}: re-staging (confirmed seal, ".round(($wake - $bed) / 60)."min)");
            if (! $dry) {
                // The CONFIRMED path scopes staging to bed→wake and finalizes the COMPUTING row.
                dispatch_sync(new SealNightJob($pid, $night, true, $bed, $wake));
            }
        }

        $this->info("recover-stuck: {$recovered} night(s) re-staged".($dry ? ' [dry run]' : '').'.');

        return self::SUCCESS;
    }

    /** The row's own confirmed bed→wake as UTC epochs. Handles a bedtime that falls on the previous day. */
    private function bedWakeEpochs(SleepLog $log, string $tz): array
    {
        $date = $log->slept_at?->toDateString();
        if (! $date || ! $log->bedtime || ! $log->wake_time) {
            return [null, null];
        }
        try {
            $wake = Carbon::parse($date.' '.$log->wake_time, $tz);
            $bed = Carbon::parse($date.' '.$log->bedtime, $tz);
            if ($log->bedtime > $log->wake_time) {   // bed 23:38 with wake 06:02 ⇒ bed is the night before
                $bed->subDay();
            }

            return [$bed->timestamp, $wake->timestamp];
        } catch (\Throwable) {
            return [null, null];
        }
    }

    private function biosignalHealthy(): bool
    {
        $url = rtrim((string) config('services.biosignal.url'), '/').'/health';

        return rescue(fn () => Http::timeout(8)->get($url)->successful(), false, false);
    }
}
