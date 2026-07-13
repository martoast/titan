<?php

namespace App\Console\Commands;

use App\Services\Glucose\GlucoseSync;
use App\Support\GlucoseConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Pull fresh CGM readings for every glucose-enabled profile and upsert them (CGM_INTEGRATION P1). Runs on
 * the schedule (~every 5 min); incremental + dedup so re-runs are cheap and safe. Logs counts only —
 * never a token.
 *
 *   php artisan glucose:sync
 *   php artisan glucose:sync --profile=1
 */
class GlucoseSyncCommand extends Command
{
    protected $signature = 'glucose:sync {--profile= : Only this profile id}';

    protected $description = 'Sync continuous-glucose readings from each enabled profile\'s CGM source.';

    public function handle(GlucoseSync $sync): int
    {
        $profiles = GlucoseConnection::enabledProfiles();
        if ($id = $this->option('profile')) {
            $profiles = $profiles->where('id', (int) $id)->values();
        }

        $total = 0;
        foreach ($profiles as $profile) {
            try {
                $n = $sync->syncProfile($profile);
                $total += $n;
                if ($n > 0) {
                    $this->info("profile {$profile->id}: +{$n} glucose readings");
                }
            } catch (\Throwable $e) {
                Log::warning('[glucose] sync failed', ['profile' => $profile->id, 'error' => $e->getMessage()]);
            }
        }

        $this->info("glucose:sync done — {$profiles->count()} profile(s), {$total} readings.");

        return self::SUCCESS;
    }
}
