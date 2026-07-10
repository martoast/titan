<?php

namespace App\Console\Commands;

use App\Jobs\SealNightJob;
use App\Models\DeviceIngestion;
use App\Models\Profile;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Reopen QUARANTINED nights — windows a deterministic seal failure parked at the attempt cap (rather than
 * destroying them). Once the cause is resolved (a bad biosignal deploy rolled back, an epoch-feature fix
 * shipped), reopen them: this dispatches a `--night` reseal per affected night, which re-includes quarantined
 * windows (the routine cron skips them) and re-stages. On success the night seals; on a still-failing night
 * the `--night` path retries via the queue rather than re-quarantining.
 *
 *   php artisan sleep:reopen-quarantine                 # DRY RUN: list quarantined nights
 *   php artisan sleep:reopen-quarantine --apply         # reseal them (inline)
 *   php artisan sleep:reopen-quarantine --profile=1 --apply
 *
 * Note: reopen re-STAGES the stored epoch features. If the features themselves are corrupt (e.g. the
 * unnormalized-motion bug), use `sleep:recover-stages` instead — it re-PROCESSES the raw blobs first.
 */
class ReopenQuarantine extends Command
{
    protected $signature = 'sleep:reopen-quarantine
        {--profile= : Only this profile id}
        {--apply : Actually reseal (otherwise dry-run)}';

    protected $description = 'Reopen quarantined nights (parked at the seal-attempt cap) and reseal them.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $q = DeviceIngestion::query()
            ->whereIn('kind', ['ibi', 'ppg_raw', 'sleep'])
            ->where('status', DeviceIngestion::STATUS_QUARANTINE);
        if ($id = $this->option('profile')) {
            $q->where('profile_id', (int) $id);
        }
        $windows = $q->get();

        if ($windows->isEmpty()) {
            $this->info('No quarantined windows.');

            return self::SUCCESS;
        }

        // Group by (profile, local night date) — the reseal target.
        $nights = [];
        foreach ($windows as $i) {
            $tz = data_get(Profile::find($i->profile_id)?->settings, 'timezone', config('app.timezone', 'UTC'));
            $end = $i->window_end ?? $i->window_start ?? $i->created_at;
            $date = CarbonImmutable::parse($end)->setTimezone($tz)->toDateString();
            $nights[$i->profile_id.'|'.$date] = ($nights[$i->profile_id.'|'.$date] ?? 0) + 1;
        }

        $this->info(($apply ? 'Reopening' : 'DRY RUN — would reopen').' '.count($nights).' quarantined night(s):');
        foreach ($nights as $key => $count) {
            [$profileId, $date] = explode('|', $key);
            $this->line("  <info>→</info> profile #{$profileId}  night {$date}  ({$count} windows)");
            if ($apply) {
                dispatch_sync(new SealNightJob((int) $profileId, $date));
                $this->line("     <info>✓</info> resealed");
            }
        }

        if (! $apply) {
            $this->comment('Re-run with --apply to reseal. (If the epoch FEATURES are corrupt, use sleep:recover-stages instead.)');
        }

        return self::SUCCESS;
    }
}
