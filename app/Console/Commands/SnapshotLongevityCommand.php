<?php

namespace App\Console\Commands;

use App\Models\Profile;
use App\Support\LongevityIndex;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Weekly Titan Age snapshot → the pace-of-aging trend. Stores one reading per profile that can produce an
 * honest estimate; over months the snapshots become a real "aging at 0.9×" curve. Idempotent per day.
 *
 *   php artisan longevity:snapshot
 *   php artisan longevity:snapshot --profile=1
 */
class SnapshotLongevityCommand extends Command
{
    protected $signature = 'longevity:snapshot {--profile= : Only this profile id}';

    protected $description = 'Snapshot each profile\'s Titan Age so pace-of-aging is a real trend.';

    public function handle(): int
    {
        $stored = 0;
        $profiles = Profile::query()
            ->when($this->option('profile'), fn ($q, $id) => $q->whereKey($id))
            ->orderBy('id')->get();

        foreach ($profiles as $profile) {
            try {
                if (LongevityIndex::snapshot($profile) !== null) {
                    $stored++;
                }
            } catch (\Throwable $e) {
                Log::warning('[longevity] snapshot failed', ['profile' => $profile->id, 'error' => $e->getMessage()]);
            }
        }

        $this->info("Longevity snapshots stored: {$stored}");

        return self::SUCCESS;
    }
}
