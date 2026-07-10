<?php

namespace App\Console\Commands;

use App\Jobs\SealActivityJob;
use App\Models\DeviceIngestion;
use Illuminate\Console\Command;

/**
 * Reopen QUARANTINED workout windows — the ones a deterministic seal failure parked at the attempt cap
 * (rather than sealing them away and destroying the run). Once the cause is resolved (a bad biosignal
 * deploy rolled back, a payload fix shipped), reopen them: this dispatches a reopen-scoped
 * {@see SealActivityJob} per affected profile, which re-includes quarantined windows (the routine seal
 * skips them) and re-seals. On success the workout seals; a still-failing session re-quarantines rather
 * than livelocking. The sleep-side sibling is `sleep:reopen-quarantine`.
 *
 *   php artisan activity:reopen-quarantine                 # DRY RUN: list quarantined workouts
 *   php artisan activity:reopen-quarantine --apply         # reseal them (inline)
 *   php artisan activity:reopen-quarantine --profile=1 --apply
 */
class ReopenWorkoutQuarantine extends Command
{
    protected $signature = 'activity:reopen-quarantine
        {--profile= : Only this profile id}
        {--apply : Actually reseal (otherwise dry-run)}';

    protected $description = 'Reopen quarantined workouts (parked at the seal-attempt cap) and reseal them.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $q = DeviceIngestion::query()
            ->where('kind', 'workout')
            ->where('status', DeviceIngestion::STATUS_QUARANTINE);
        if ($id = $this->option('profile')) {
            $q->where('profile_id', (int) $id);
        }
        $windows = $q->get();

        if ($windows->isEmpty()) {
            $this->info('No quarantined workout windows.');

            return self::SUCCESS;
        }

        // One reopen-scoped seal per profile re-clusters and re-seals all its quarantined workout windows.
        $byProfile = $windows->groupBy('profile_id');

        $this->info(($apply ? 'Reopening' : 'DRY RUN — would reopen').' quarantined workouts for '.$byProfile->count().' profile(s):');
        foreach ($byProfile as $profileId => $group) {
            $this->line("  <info>→</info> profile #{$profileId}  ({$group->count()} windows)");
            if ($apply) {
                dispatch_sync(new SealActivityJob((int) $profileId, reopenQuarantine: true));
                $this->line('     <info>✓</info> resealed');
            }
        }

        if (! $apply) {
            $this->comment('Re-run with --apply to reseal.');
        }

        return self::SUCCESS;
    }
}
