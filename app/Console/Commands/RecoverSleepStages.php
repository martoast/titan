<?php

namespace App\Console\Commands;

use App\Jobs\ProcessWindowJob;
use App\Jobs\SealNightJob;
use App\Models\DeviceIngestion;
use App\Models\Profile;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Recover nights that sealed WITHOUT stages because of the unnormalized-motion bug (epoch_motion was a
 * gravity-dominated accel sum ~tens-of-thousands → every epoch staged as WAKE → duration-only "no data").
 * See tasks / titan-stageless-nights-bug: the forward fix (accel STD) only helps NEW windows; nights sealed
 * before it hold the bad stored epoch_motion. This re-processes the affected raw windows through the FIXED
 * biosignal service (recomputing the epoch features), then re-seals the night so the real stages appear.
 *
 *   php artisan sleep:recover-stages                 # DRY RUN: list affected nights/windows
 *   php artisan sleep:recover-stages --apply         # do it (re-process + reseal, inline)
 *   php artisan sleep:recover-stages --profile=1 --days=5 --apply
 *
 * Safe: dry-run by default; only touches windows whose STORED epoch_motion is implausibly large
 * (--min-motion, default 1000 — the fix keeps it ~0-50); re-processing is idempotent; the reseal writes the
 * newly-staged row over the duration-only one (a stageless row never beats a staged one in upsertSleep).
 */
class RecoverSleepStages extends Command
{
    protected $signature = 'sleep:recover-stages
        {--profile= : Only this profile id}
        {--days=3 : How many days back to scan for affected nights}
        {--min-motion=1000 : Treat a window as affected when its max stored epoch_motion exceeds this}
        {--apply : Actually re-process + reseal (otherwise dry-run)}';

    protected $description = 'Recover stage-less nights caused by the unnormalized-motion bug (re-process raw windows + reseal).';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $minMotion = (float) $this->option('min-motion');
        $since = CarbonImmutable::now()->subDays((int) $this->option('days'))->startOfDay();

        $q = DeviceIngestion::query()
            ->whereIn('kind', ['ibi', 'ppg_raw'])
            ->where('created_at', '>=', $since);
        if ($id = $this->option('profile')) {
            $q->where('profile_id', (int) $id);
        }

        // Affected = a window whose STORED epoch_motion is off the ~0-50 scale (the bug's fingerprint).
        $affected = $q->get()->filter(function (DeviceIngestion $i) use ($minMotion) {
            $em = $i->result_refs['epoch_motion'] ?? null;

            return is_array($em) && $em !== [] && max(array_map('floatval', $em)) > $minMotion;
        });

        if ($affected->isEmpty()) {
            $this->info('No affected windows found (no stored epoch_motion over '.$minMotion.').');

            return self::SUCCESS;
        }

        // Group by (profile, local night date) — the reseal target. tz from profile settings, else UTC.
        $nights = [];
        foreach ($affected as $i) {
            $tz = data_get(Profile::find($i->profile_id)?->settings, 'timezone', config('app.timezone', 'UTC'));
            $end = $i->window_end ?? $i->window_start ?? $i->created_at;
            $date = CarbonImmutable::parse($end)->setTimezone($tz)->toDateString();
            $nights[$i->profile_id.'|'.$date][] = $i;
        }

        $this->info(($apply ? 'Recovering' : 'DRY RUN — would recover').' '.$affected->count().' window(s) across '.count($nights).' night(s):');
        foreach ($nights as $key => $wins) {
            [$profileId, $date] = explode('|', $key);
            $this->line("  <info>→</info> profile #{$profileId}  night {$date}  ({".count($wins)."} windows)");
        }

        if (! $apply) {
            $this->comment('Re-run with --apply to re-process and reseal.');

            return self::SUCCESS;
        }

        foreach ($nights as $key => $wins) {
            [$profileId, $date] = explode('|', $key);

            // 1) Unseal + re-queue each affected window, then re-process it INLINE through the fixed biosignal
            //    so its epoch features are recomputed before we reseal.
            foreach ($wins as $i) {
                $refs = (array) $i->result_refs;
                unset($refs['sealed'], $refs['sleep_log_id'], $refs['seal_attempts'], $refs['seal_error']);
                $i->update(['status' => DeviceIngestion::STATUS_QUEUED, 'result_refs' => $refs]);
                dispatch_sync(new ProcessWindowJob($i->batch_uid));
            }

            // 2) Reseal the night — reads the now-PROCESSED windows with corrected motion and stages them; the
            //    staged row replaces the old duration-only one.
            dispatch_sync(new SealNightJob((int) $profileId, $date));
            $this->line("  <info>✓</info> profile #{$profileId} night {$date} re-processed + resealed");
        }

        $this->info('Done. Re-open the Sleep view for the recovered nights.');

        return self::SUCCESS;
    }
}
