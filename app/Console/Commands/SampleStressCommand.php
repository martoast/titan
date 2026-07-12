<?php

namespace App\Console\Commands;

use App\Models\Profile;
use App\Support\StressMonitor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Periodic stress sampler → the stress-over-day strip. Runs on the schedule (every 15 min); for each
 * profile with a recent HR read it computes {@see StressMonitor::sample()} and persists one strip point.
 * Only profiles with fresh HR are touched (a still, no-data, or moving minute writes nothing), so this
 * is cheap and paints an honest curve.
 *
 *   php artisan stress:sample
 *   php artisan stress:sample --profile=1
 */
class SampleStressCommand extends Command
{
    protected $signature = 'stress:sample {--profile= : Only sample this profile id}';

    protected $description = 'Sample real-time stress for profiles with recent HR → the day strip.';

    public function handle(): int
    {
        $written = 0;
        foreach ($this->resolveProfiles() as $profile) {
            try {
                if (StressMonitor::sample($profile) !== null) {
                    $written++;
                }
            } catch (\Throwable $e) {
                Log::warning('[stress] sample failed', ['profile' => $profile->id, 'error' => $e->getMessage()]);
            }
        }

        $this->info("Stress samples written: {$written}");

        return self::SUCCESS;
    }

    /** Profiles with an HR sample in the last 20 min — the only ones we can read stress for right now. */
    private function resolveProfiles()
    {
        $query = Profile::query()
            ->when($this->option('profile'), fn ($q, $id) => $q->whereKey($id))
            ->whereHas('hrSamples', fn ($q) => $q->where('recorded_at', '>=', now()->subMinutes(20)));

        return $query->orderBy('id')->get();
    }
}
