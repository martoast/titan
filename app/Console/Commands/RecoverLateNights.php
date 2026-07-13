<?php

namespace App\Console\Commands;

use App\Jobs\ReactToSleepConfirmed;
use App\Jobs\SealNightJob;
use App\Models\DeviceIngestion;
use App\Models\SleepLog;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Recover a night whose data arrived LATE -- the "phone died mid-night" case. The band buffers offline;
 * the nightly seal runs BEFORE the buffer uploads, so the night seals thin (or as a short nap) and the
 * morning summary/email never fires (that only fires on the marked-awake / confirmed path, which needs a
 * live phone at wake). When the buffered windows land AFTER the seal, this command:
 *   1. re-seals the affected night -- the explicit per-night seal consumes the now-present unsealed windows
 *      (a routine hourly seal doesn't reliably re-open an already-sealed night), and
 *   2. fires the DEFERRED sleep summary + email for any recent REAL overnight whose summary was never
 *      delivered.
 *
 * Safe by construction: the re-seal only runs when genuinely-late unsealed data exists (idempotent
 * otherwise); the notification only touches finalized real overnights (a completed, past sleep -- never
 * a nap, never mid-sleep) and self-dedupes via settings.sleep_summary_reacted, so it never double-pings.
 * See docs/SEAL_ARCHITECTURE.md (§2 late/out-of-order data; the "phone died mid-night" open item).
 */
class RecoverLateNights extends Command
{
    protected $signature = 'sleep:recover-late
        {--profile= : Only this profile id}
        {--days=1 : How many days back to consider (device-local)}
        {--dry : Report what would happen without re-sealing or notifying}';

    protected $description = 'Re-seal nights whose buffered data arrived after the seal, and fire the missed sleep summary/email.';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $dry = (bool) $this->option('dry');
        $profileFilter = $this->option('profile');
        $tz = (string) config('app.timezone', 'UTC');
        $since = Carbon::now($tz)->startOfDay()->subDays($days - 1)->toDateString();

        // --- 1. Re-seal nights that got late buffered data after they were already sealed. ---
        $sealedByProfile = [];   // profileId => [night => true]  (don't re-seal the same night twice)
        $recovered = 0;

        $logs = SleepLog::query()
            ->where('slept_at', '>=', $since)
            ->when($profileFilter, fn ($q) => $q->where('profile_id', $profileFilter))
            ->get();

        foreach ($logs as $log) {
            $night = $log->slept_at?->toDateString();
            $pid = (int) $log->profile_id;
            if (! $night || isset($sealedByProfile[$pid][$night])) {
                continue;
            }

            // Any unsealed sleep-relevant window that arrived AFTER this night was sealed = late buffer.
            // (Timezone-robust: we don't try to date-match window_end -- SealNightJob is scoped to $night
            //  and only consumes that night's windows, so an over-broad trigger is a harmless no-op.)
            $hasLate = DeviceIngestion::query()
                ->where('profile_id', $pid)
                ->whereIn('kind', ['ibi', 'ppg_raw', 'sleep'])
                ->where('status', '!=', DeviceIngestion::STATUS_SEALED)
                ->where('created_at', '>', $log->created_at)
                ->exists();

            if (! $hasLate) {
                continue;
            }

            $sealedByProfile[$pid][$night] = true;
            $recovered++;
            $this->line("  <info>↻</info> profile #{$pid} night {$night}: late buffered data -> re-seal");
            if (! $dry) {
                dispatch_sync(new SealNightJob($pid, $night));
            }
        }

        // --- 2. Fire the deferred summary for the LATEST recovered overnight that never got one. ---
        // Re-query AFTER the re-seal so a freshly-recovered overnight is included. We consider only the
        // single most-recent overnight per profile: `sleep_summary_reacted` is one value (the last night
        // notified), so an OLDER night always looks "un-notified" once a newer one overwrites it -- firing
        // for those would re-send stale summaries. The recovery case that matters is only ever last night.
        $notified = 0;
        $latestByProfile = [];   // profileId => most-recent overnight SleepLog
        $overnights = SleepLog::query()
            ->where('is_nap', false)
            ->where('stage_status', 'final')
            ->where('slept_at', '>=', $since)
            ->when($profileFilter, fn ($q) => $q->where('profile_id', $profileFilter))
            ->with('profile')
            ->orderBy('slept_at')
            ->get();
        foreach ($overnights as $log) {
            $latestByProfile[(int) $log->profile_id] = $log;   // asc order -> last write wins = latest night
        }

        foreach ($latestByProfile as $log) {
            $profile = $log->profile;
            $night = $log->slept_at?->toDateString();
            if (! $profile || ! $night) {
                continue;
            }
            // Already delivered (confirmed-wake path or a prior run)? ReactToSleepConfirmed also self-dedupes,
            // but check here too so --dry reports honestly and we don't log noise.
            if (data_get($profile->settings, 'sleep_summary_reacted') === $night) {
                continue;
            }

            $notified++;
            $this->line("  <info>✉</info> profile #{$profile->id} night {$night}: firing deferred sleep summary + email");
            if (! $dry) {
                ReactToSleepConfirmed::dispatch($log->id);
            }
        }

        $this->info("recover-late: {$recovered} night(s) re-sealed, {$notified} deferred summary(ies) fired".($dry ? ' [dry run]' : '').'.');

        return self::SUCCESS;
    }
}
