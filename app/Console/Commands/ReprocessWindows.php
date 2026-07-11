<?php

namespace App\Console\Commands;

use App\Jobs\ProcessWindowJob;
use App\Jobs\SealNightJob;
use App\Models\DeviceIngestion;
use App\Models\Profile;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Reprocess stored raw windows through the CURRENT biosignal service, then reseal the affected nights.
 *
 * When a biosignal derivation is fixed (e.g. the per-epoch RMSSD saturation fix, or the earlier
 * gravity-dominated motion fix), the forward fix only helps NEW windows — nights already sealed hold the OLD
 * stored epoch features (epoch_rmssd / epoch_motion / epoch_hr) in result_refs, and everything that reads
 * those (the SLEEP LAB calibration, recovery/HRV, the staged hypnogram) stays wrong until the ORIGINAL
 * waveforms are re-run. The raw PPG is retained on the raw disk, so this recomputes the features from source
 * and reseals — the general form of {@see RecoverSleepStages} (which is scoped to the motion bug's fingerprint).
 *
 *   php artisan sleep:reprocess --profile=1 --days=7              # DRY RUN: list the nights/windows
 *   php artisan sleep:reprocess --profile=1 --days=7 --apply      # re-process + reseal, inline
 *   php artisan sleep:reprocess --profile=1 --from=2026-07-07 --to=2026-07-10 --apply
 *
 * SAFE: dry-run by default; idempotent (unseal → re-process → reseal, the same invariants as
 * sleep:recover-stages and docs/SEAL_ARCHITECTURE.md — a reseal never double-counts or shifts the night's
 * span, and a staged row is never replaced by a thinner one). WARNING: --apply MUTATES that profile's real
 * sealed history — stages, quality and recovery all recompute from the corrected features. It requires an
 * explicit --profile so a reprocess is always scoped to one person you've decided to re-run.
 */
class ReprocessWindows extends Command
{
    protected $signature = 'sleep:reprocess
        {--profile= : Profile id to reprocess (REQUIRED for --apply; omit in a dry-run to scan every profile)}
        {--days=7 : Days back to scan (ignored when --from is given)}
        {--from= : Start date YYYY-MM-DD, inclusive (by window date)}
        {--to= : End date YYYY-MM-DD, inclusive (defaults to today)}
        {--apply : Actually re-process + reseal (otherwise dry-run)}';

    protected $description = 'Reprocess stored raw windows through the current biosignal + reseal (use after a biosignal fix).';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $profileId = $this->option('profile') !== null ? (int) $this->option('profile') : null;

        // Reprocessing rewrites real sealed history, so an --apply must be scoped to one deliberately-chosen
        // profile (never a fleet-wide mutation from a single command).
        if ($apply && $profileId === null) {
            $this->error('--apply requires --profile=<id>: reprocessing mutates real sealed history, so scope it to one profile.');

            return self::FAILURE;
        }

        [$from, $to] = $this->range();

        $q = DeviceIngestion::query()
            ->whereIn('kind', ['ibi', 'ppg_raw'])
            ->whereBetween('window_start', [$from, $to]);
        if ($profileId !== null) {
            $q->where('profile_id', $profileId);
        }
        $windows = $q->orderBy('window_start')->get();

        if ($windows->isEmpty()) {
            $this->info('No stored raw windows in range.');

            return self::SUCCESS;
        }

        // Group by (profile, local night date) — the reseal target. tz from profile settings, else app tz.
        $nights = [];
        foreach ($windows as $i) {
            $tz = $this->tzFor((int) $i->profile_id);
            $end = $i->window_end ?? $i->window_start ?? $i->created_at;
            $date = CarbonImmutable::parse($end)->setTimezone($tz)->toDateString();
            $nights[$i->profile_id.'|'.$date][] = $i;
        }
        ksort($nights);

        $this->info(($apply ? 'Reprocessing' : 'DRY RUN — would reprocess').' '.$windows->count().' window(s) across '.count($nights).' night(s):');
        foreach ($nights as $key => $wins) {
            [$pid, $date] = explode('|', $key);
            $this->line("  <info>→</info> profile #{$pid}  night {$date}  (".count($wins).' windows)');
        }

        if (! $apply) {
            $this->comment('Re-run with --apply --profile=<id> to re-process + reseal. This MUTATES that profile\'s sealed history.');

            return self::SUCCESS;
        }

        foreach ($nights as $key => $wins) {
            [$pid, $date] = explode('|', $key);

            // 1) Unseal + re-queue each window (clearing only the sleep-seal markers, exactly like
            //    sleep:recover-stages), then re-process it INLINE so ProcessWindowJob re-reads the raw waveform
            //    and recomputes the epoch features through the fixed biosignal before we reseal.
            foreach ($wins as $i) {
                $refs = (array) $i->result_refs;
                unset($refs['sealed'], $refs['sleep_log_id'], $refs['seal_attempts'], $refs['seal_error']);
                $i->update(['status' => DeviceIngestion::STATUS_QUEUED, 'result_refs' => $refs]);
                dispatch_sync(new ProcessWindowJob($i->batch_uid));
            }

            // 2) Reseal the night from the now-reprocessed windows (idempotent — a staged row never loses to a
            //    thinner one; the seal re-derives stages + recovery from the corrected features).
            dispatch_sync(new SealNightJob((int) $pid, $date));
            $this->line("  <info>✓</info> profile #{$pid} night {$date} reprocessed + resealed");
        }

        $this->info('Done. Re-run `sleep:lab --calibrate` to rebuild the calibration from the corrected windows.');

        return self::SUCCESS;
    }

    /** @return array{0:CarbonImmutable,1:CarbonImmutable} */
    private function range(): array
    {
        $to = $this->option('to')
            ? CarbonImmutable::parse((string) $this->option('to'))->endOfDay()
            : CarbonImmutable::now()->endOfDay();
        $from = $this->option('from')
            ? CarbonImmutable::parse((string) $this->option('from'))->startOfDay()
            : CarbonImmutable::now()->subDays((int) $this->option('days'))->startOfDay();

        return [$from, $to];
    }

    private function tzFor(int $profileId): string
    {
        return data_get(Profile::find($profileId)?->settings, 'timezone', config('app.timezone', 'UTC'));
    }
}
