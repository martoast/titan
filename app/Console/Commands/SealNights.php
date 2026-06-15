<?php

namespace App\Console\Commands;

use App\Jobs\SealNightJob;
use App\Models\DeviceIngestion;
use App\Models\Profile;
use Illuminate\Console\Command;

/**
 * Seal completed nights into authoritative whole-night metrics.
 *
 *   php artisan biosignal:seal-nights              # every profile with unsealed windows
 *   php artisan biosignal:seal-nights --profile=1  # one profile
 *   php artisan biosignal:seal-nights --night=2026-06-14 --profile=1
 *   php artisan biosignal:seal-nights --sync       # run inline (don't queue) — handy in dev/tests
 *
 * Per-window Shape-A ingestion writes recovery_logs once per IBI window (last write
 * wins), so daily hrv_ms reflects only the final window. This command finds each profile
 * with unsealed IBI/sleep windows from a completed night (quiescent >45 min, or a past
 * night) and dispatches a SealNightJob that recomputes HRV/RHR over the WHOLE night and
 * writes the authoritative row.
 *
 * Intended to run hourly from the scheduler (see the schedule line returned to the
 * orchestrator); idempotent, so running it more often is harmless.
 */
class SealNights extends Command
{
    protected $signature = 'biosignal:seal-nights
        {--profile= : Only seal this profile id}
        {--night= : Only seal this calendar night (Y-m-d, device-local)}
        {--sync : Run the seal jobs inline instead of dispatching to the queue}';

    protected $description = 'Aggregate completed nights of raw biosignal windows into authoritative whole-night recovery/sleep metrics.';

    public function handle(): int
    {
        $night = $this->option('night');
        $sync = (bool) $this->option('sync');

        $profileIds = $this->resolveProfiles();

        if (empty($profileIds)) {
            $this->info('No profiles with unsealed biosignal windows.');

            return self::SUCCESS;
        }

        $dispatched = 0;
        foreach ($profileIds as $profileId) {
            $job = new SealNightJob($profileId, $night);

            if ($sync) {
                dispatch_sync($job);
            } else {
                dispatch($job);
            }
            $dispatched++;
            $this->line("  <info>→</info> profile #{$profileId} ".($sync ? 'sealed' : 'queued for sealing').($night ? " (night {$night})" : ''));
        }

        $this->info("Seal-nights: {$dispatched} profile(s) ".($sync ? 'processed.' : 'queued.'));

        return self::SUCCESS;
    }

    /**
     * Profiles that actually have unsealed IBI/sleep windows — so we never queue empty work.
     *
     * @return array<int,int>
     */
    private function resolveProfiles(): array
    {
        if ($id = $this->option('profile')) {
            return Profile::whereKey($id)->exists() ? [(int) $id] : [];
        }

        return DeviceIngestion::query()
            ->whereIn('kind', ['ibi', 'ppg_raw', 'sleep'])
            ->where('status', '!=', DeviceIngestion::STATUS_SEALED)
            ->distinct()
            ->pluck('profile_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }
}
