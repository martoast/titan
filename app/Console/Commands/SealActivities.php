<?php

namespace App\Console\Commands;

use App\Jobs\SealActivityJob;
use App\Models\DeviceIngestion;
use Illuminate\Console\Command;

/**
 * Seal completed workout windows into authoritative activity_sessions (classification + TRIMP +
 * VO2max + HRR). Companion to biosignal:seal-nights, for the workout path.
 *
 *   php artisan biosignal:seal-activities              # every profile with unsealed workout windows
 *   php artisan biosignal:seal-activities --profile=1
 *   php artisan biosignal:seal-activities --sync       # run inline (dev/tests)
 *
 * Intended to run every ~15 min from the scheduler; idempotent.
 */
class SealActivities extends Command
{
    protected $signature = 'biosignal:seal-activities
        {--profile= : Only seal this profile id}
        {--sync : Run the seal jobs inline instead of dispatching to the queue}';

    protected $description = 'Aggregate completed workout windows into authoritative activity_sessions (classification, TRIMP, VO2max, HRR).';

    public function handle(): int
    {
        $sync = (bool) $this->option('sync');
        $profileIds = $this->resolveProfiles();

        if (empty($profileIds)) {
            $this->info('No profiles with unsealed workout windows.');

            return self::SUCCESS;
        }

        foreach ($profileIds as $profileId) {
            $job = new SealActivityJob($profileId);
            $sync ? dispatch_sync($job) : dispatch($job);
            $this->line("  <info>→</info> profile #{$profileId} ".($sync ? 'sealed' : 'queued for sealing'));
        }

        $this->info('Seal-activities: '.count($profileIds).' profile(s) '.($sync ? 'processed.' : 'queued.'));

        return self::SUCCESS;
    }

    /** @return array<int,int> */
    private function resolveProfiles(): array
    {
        if ($id = $this->option('profile')) {
            return [(int) $id];
        }

        return DeviceIngestion::query()
            ->where('kind', 'workout')
            ->where('status', '!=', DeviceIngestion::STATUS_SEALED)
            ->distinct()
            ->pluck('profile_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }
}
