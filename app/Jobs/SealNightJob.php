<?php

namespace App\Jobs;

use App\Models\DeviceIngestion;
use App\Models\Profile;
use App\Models\RecoveryLog;
use App\Models\SleepLog;
use App\Services\Notifications\NotificationService;
use App\Services\Wearables\BiosignalClient;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Seal a completed night for ONE profile — the authoritative whole-night pass.
 *
 * Per-window Shape-A ingestion (ProcessWindowJob) upserts the daily recovery_logs row
 * once per ~7-min IBI window with last-write-wins, so `hrv_ms` ends up reflecting only
 * the final window rather than the whole night. RMSSD from a 7-min window is noisy
 * (03-algorithms §2: 5-min windows r²≈0.77 vs whole-night r²≈0.98) — recovery HRV must
 * be computed over the WHOLE night.
 *
 * This job gathers every unsealed IBI window from the night, concatenates the IBI series,
 * and calls the biosignal service ONCE on the full series to write the authoritative
 * recovery_logs row (hrv_ms = whole-night RMSSD, resting_hr). It does the same for sleep
 * windows when present. The contributing ingestions are then marked `sealed` so they are
 * never re-aggregated.
 *
 * Idempotent: updateOrCreate on (profile_id, logged_at|slept_at) and re-running on an
 * already-sealed night is a no-op (no unsealed windows remain). Runs on the `biosignal`
 * queue alongside ProcessWindowJob.
 */
class SealNightJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** A night is "complete" once no new windows have arrived for this many minutes. */
    public const QUIET_MINUTES = 45;

    /**
     * Per-window RMSSD ceiling (ms). A 2-minute window above this is almost certainly a
     * peak-detection artifact (a missed/extra beat creates a huge successive difference),
     * not real overnight HRV — excluded from the whole-night aggregate so a handful of
     * bad windows can't inflate the sealed number. (Observed in dogfooding: a few windows
     * came back at 200-380 ms and dragged a true ~66 ms night up to ~99 ms.)
     *
     * Set conservatively at 200 ms — physiologically, even elite resting HRV tops out
     * around there for an ultra-short window, so this only removes clear artifacts without
     * cutting genuine deep-sleep HRV. Exact calibration is a real-data (Polar H10) job, not
     * a threshold to tune against synthetic signals.
     */
    public const ARTIFACT_RMSSD_CEIL_MS = 200;

    public int $tries = 2;

    public int $backoff = 15;

    /**
     * @param  int  $profileId  the profile whose night to seal
     * @param  string|null  $night  optional explicit night date (Y-m-d, local); null = auto-detect the latest completed night
     */
    public function __construct(public int $profileId, public ?string $night = null)
    {
        $this->onQueue('biosignal');
    }

    public function handle(BiosignalClient $biosignal): void
    {
        $profile = Profile::find($this->profileId);
        if (! $profile) {
            return;
        }

        try {
            $tz = $this->timezoneFor($profile);

            // All unsealed raw windows that still need whole-night aggregation. A window is
            // "unsealed" when its status is not yet 'sealed'. We accept processed/queued/
            // received/failed per-window rows — the night seal supersedes them either way.
            $unsealed = DeviceIngestion::query()
                ->where('profile_id', $profile->id)
                ->whereIn('kind', ['ibi', 'ppg_raw', 'sleep'])
                ->where('status', '!=', DeviceIngestion::STATUS_SEALED)
                ->orderBy('window_end')
                ->get();

            if ($unsealed->isEmpty()) {
                return; // nothing pending
            }

            // Group windows by the local calendar night (resolved from window_end).
            $byNight = $unsealed->groupBy(
                fn (DeviceIngestion $i) => CarbonImmutable::parse($i->window_end ?? $i->window_start ?? $i->created_at)
                    ->setTimezone($tz)->toDateString()
            );

            foreach ($byNight as $date => $windows) {
                // If a specific night was requested, only seal that one.
                if ($this->night !== null && $date !== $this->night) {
                    continue;
                }

                if (! $this->nightIsComplete($windows, $date, $tz)) {
                    continue; // still streaming — let it finish
                }

                $this->sealNight($profile, $biosignal, (string) $date, $tz, $windows);
            }
        } catch (\Throwable $e) {
            Log::warning('[Biosignal] night seal failed', [
                'profile_id' => $this->profileId,
                'night' => $this->night,
                'error' => $e->getMessage(),
            ]);
            // Don't rethrow on the auto-scheduled pass: a single bad night must not block
            // the others. The retry/backoff still applies for transient service errors.
            throw $e;
        }
    }

    /**
     * A night is sealable once it is quiescent: the most recent window ended more than
     * QUIET_MINUTES ago (the device finished streaming), OR the night is in the past
     * relative to the device-owner's local "today" (a morning cutoff — yesterday is done).
     *
     * @param  \Illuminate\Support\Collection<int,DeviceIngestion>  $windows
     */
    private function nightIsComplete(\Illuminate\Support\Collection $windows, string $date, string $tz): bool
    {
        if ($date < now($tz)->toDateString()) {
            return true; // a past night — morning cutoff
        }

        $lastEnd = $windows
            ->map(fn (DeviceIngestion $i) => $i->window_end ?? $i->window_start ?? $i->created_at)
            ->filter()
            ->map(fn ($t) => CarbonImmutable::parse($t))
            ->max();

        if (! $lastEnd) {
            return false;
        }

        return $lastEnd->lte(now()->subMinutes(self::QUIET_MINUTES));
    }

    /**
     * Aggregate one night's windows → one authoritative recovery_logs row (+ sleep_logs
     * when sleep windows are present) and mark the contributing ingestions sealed.
     *
     * @param  \Illuminate\Support\Collection<int,DeviceIngestion>  $windows
     */
    private function sealNight(Profile $profile, BiosignalClient $biosignal, string $date, string $tz, \Illuminate\Support\Collection $windows): void
    {
        $ibiWindows = $windows->whereIn('kind', ['ibi', 'ppg_raw']);
        $sleepWindows = $windows->where('kind', 'sleep');

        $sealedIds = [];

        // --- Whole-night HRV / RHR → recovery_logs ---
        if ($ibiWindows->isNotEmpty()) {
            $allIbi = [];
            $accel = [];
            $windowStart = null;
            $windowEnd = null;

            foreach ($ibiWindows as $ingestion) {
                // Prefer the per-window IBI persisted by ProcessWindowJob — this is what makes
                // ppg_raw (the Bangle) sealable, since its raw blob holds samples, not IBI. Fall
                // back to the blob's ibi_ms for Shape-A `ibi` windows (Polar / Apple Health).
                $persistedIbi = $ingestion->result_refs['ibi_ms'] ?? null;
                if (is_array($persistedIbi)) {
                    // Drop artifact windows (implausible per-window RMSSD) from the aggregate;
                    // they're still sealed below so the night isn't reprocessed.
                    $winRmssd = $ingestion->result_refs['rmssd'] ?? null;
                    if (! is_numeric($winRmssd) || $winRmssd <= self::ARTIFACT_RMSSD_CEIL_MS) {
                        foreach ($persistedIbi as $v) {
                            if (is_numeric($v)) {
                                $allIbi[] = (float) $v;
                            }
                        }
                        $windowStart = $windowStart ?? ($ingestion->window_start ?? null);
                        $windowEnd = $ingestion->window_end ?? $windowEnd;
                    }

                    continue;
                }

                $window = $this->loadWindow($ingestion);
                if ($window === null) {
                    continue; // raw blob missing — skip, but still seal so we don't loop forever
                }
                foreach ((array) ($window['ibi_ms'] ?? []) as $v) {
                    if (is_numeric($v)) {
                        $allIbi[] = (float) $v;
                    }
                }
                foreach ((array) ($window['accel_counts'] ?? []) as $v) {
                    $accel[] = $v;
                }
                $windowStart = $windowStart ?? ($ingestion->window_start ?? null);
                $windowEnd = $ingestion->window_end ?? $windowEnd;
            }

            if (count($allIbi) >= 10 && $biosignal->configured()) {
                $wholeNight = [
                    'kind' => 'ibi',
                    'start' => $windowStart ? CarbonImmutable::parse($windowStart)->toIso8601ZuluString() : null,
                    'end' => $windowEnd ? CarbonImmutable::parse($windowEnd)->toIso8601ZuluString() : null,
                    'ibi_ms' => $allIbi,
                    'accel_counts' => $accel,
                    'whole_night' => true,
                ];

                $result = $biosignal->processHrv($wholeNight);
                $metrics = $result['metrics'] ?? [];
                $algoVersion = $result['algo_version'] ?? config('services.biosignal.algo_version', 'v1');

                if ($metrics['valid'] ?? true) {
                    $log = RecoveryLog::updateOrCreate(
                        ['profile_id' => $profile->id, 'logged_at' => $date],
                        array_filter([
                            'hrv_ms' => isset($metrics['hrv_ms']) ? (int) round($metrics['hrv_ms']) : null,
                            'resting_hr' => isset($metrics['resting_hr']) ? (int) round($metrics['resting_hr']) : null,
                            'updated_via' => 'biosignal:sealed',
                        ], fn ($v) => $v !== null),
                    );

                    $ibiWindows->each(function (DeviceIngestion $i) use ($algoVersion, $log) {
                        $i->update([
                            'status' => DeviceIngestion::STATUS_SEALED,
                            'algo_version' => $algoVersion,
                            'result_refs' => array_merge((array) $i->result_refs, ['recovery_log_id' => $log->id, 'sealed' => true]),
                        ]);
                    });

                    // Recovery is in — let the profile know (in-app + Web Push). Best-effort;
                    // notify() never throws.
                    $this->notifyRecovery($profile, $log);
                } else {
                    // Invalid whole-night signal — still seal so we don't reprocess endlessly.
                    $ibiWindows->each(fn (DeviceIngestion $i) => $i->update(['status' => DeviceIngestion::STATUS_SEALED]));
                }
            } else {
                // Too little data or service unconfigured — seal to release the night.
                $ibiWindows->each(fn (DeviceIngestion $i) => $i->update(['status' => DeviceIngestion::STATUS_SEALED]));
            }

            $sealedIds = $ibiWindows->pluck('id')->all();
        }

        // --- Whole-night sleep staging → sleep_logs ---
        if ($sleepWindows->isNotEmpty()) {
            $this->sealSleep($profile, $biosignal, $date, $sleepWindows);
        }

        Log::info('[Biosignal] night sealed', [
            'profile_id' => $profile->id,
            'night' => $date,
            'ibi_windows' => $ibiWindows->count(),
            'sleep_windows' => $sleepWindows->count(),
            'sealed_ids' => $sealedIds,
        ]);
    }

    /**
     * Concatenate the night's sleep windows and hand the whole night to the biosignal
     * service once → one sleep_logs row (idempotent on profile_id + slept_at).
     *
     * @param  \Illuminate\Support\Collection<int,DeviceIngestion>  $sleepWindows
     */
    private function sealSleep(Profile $profile, BiosignalClient $biosignal, string $date, \Illuminate\Support\Collection $sleepWindows): void
    {
        $hr = [];
        $accel = [];
        $start = null;
        $end = null;

        foreach ($sleepWindows as $ingestion) {
            $window = $this->loadWindow($ingestion);
            if ($window === null) {
                continue;
            }
            foreach ((array) ($window['hr'] ?? $window['heart_rate'] ?? []) as $v) {
                $hr[] = $v;
            }
            foreach ((array) ($window['accel_counts'] ?? []) as $v) {
                $accel[] = $v;
            }
            $start = $start ?? ($ingestion->window_start ?? null);
            $end = $ingestion->window_end ?? $end;
        }

        if (! $biosignal->configured() || (empty($hr) && empty($accel))) {
            $sleepWindows->each(fn (DeviceIngestion $i) => $i->update(['status' => DeviceIngestion::STATUS_SEALED]));

            return;
        }

        try {
            $result = $biosignal->processSleep([
                'kind' => 'sleep',
                'start' => $start ? CarbonImmutable::parse($start)->toIso8601ZuluString() : null,
                'end' => $end ? CarbonImmutable::parse($end)->toIso8601ZuluString() : null,
                'hr' => $hr,
                'accel_counts' => $accel,
                'whole_night' => true,
            ]);
            $metrics = $result['metrics'] ?? [];
            $algoVersion = $result['algo_version'] ?? config('services.biosignal.algo_version', 'v1');

            $log = SleepLog::updateOrCreate(
                ['profile_id' => $profile->id, 'slept_at' => $date],
                array_filter([
                    'duration_min' => isset($metrics['duration_min']) ? (int) round($metrics['duration_min']) : null,
                    'deep_min' => isset($metrics['deep_min']) ? (int) round($metrics['deep_min']) : null,
                    'rem_min' => isset($metrics['rem_min']) ? (int) round($metrics['rem_min']) : null,
                    'light_min' => isset($metrics['light_min']) ? (int) round($metrics['light_min']) : null,
                    'awake_min' => isset($metrics['awake_min']) ? (int) round($metrics['awake_min']) : null,
                    'bedtime' => $metrics['bedtime'] ?? null,
                    'wake_time' => $metrics['wake_time'] ?? null,
                    'quality' => isset($metrics['quality']) ? (int) round($metrics['quality']) : null,
                    'updated_via' => 'biosignal:sealed',
                ], fn ($v) => $v !== null),
            );

            $sleepWindows->each(fn (DeviceIngestion $i) => $i->update([
                'status' => DeviceIngestion::STATUS_SEALED,
                'algo_version' => $algoVersion,
                'result_refs' => array_merge((array) $i->result_refs, ['sleep_log_id' => $log->id, 'sealed' => true]),
            ]));
        } catch (\Throwable $e) {
            Log::warning('[Biosignal] sleep seal failed', ['profile_id' => $profile->id, 'night' => $date, 'error' => $e->getMessage()]);
            // Seal anyway so a persistently bad night doesn't wedge the queue.
            $sleepWindows->each(fn (DeviceIngestion $i) => $i->update(['status' => DeviceIngestion::STATUS_SEALED]));
        }
    }

    /**
     * Load + decode a window blob from the `raw` disk. Returns null if the object is
     * missing (already pruned, or never written) so the caller can skip + seal it.
     *
     * @return array<string,mixed>|null
     */
    private function loadWindow(DeviceIngestion $ingestion): ?array
    {
        if (! $ingestion->object_key || ! Storage::disk('raw')->exists($ingestion->object_key)) {
            return null;
        }

        $raw = Storage::disk('raw')->get($ingestion->object_key);
        $decoded = @gzdecode($raw);
        $body = $decoded !== false ? $decoded : $raw;

        foreach (preg_split('/\r?\n/', trim((string) $body)) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            $obj = json_decode($line, true);
            if (is_array($obj)) {
                return $obj;
            }
        }

        return null;
    }

    /**
     * Tell the profile their recovery for the night is ready. Resolved from the
     * container (this is a queued job, no constructor injection). Best-effort — the
     * service swallows push failures, and we guard the in-app create too so a
     * notification problem never wedges the seal.
     */
    private function notifyRecovery(Profile $profile, RecoveryLog $log): void
    {
        try {
            $bits = [];
            if ($log->hrv_ms !== null) {
                $bits[] = "HRV {$log->hrv_ms} ms";
            }
            if ($log->resting_hr !== null) {
                $bits[] = "resting HR {$log->resting_hr} bpm";
            }
            $body = $bits === []
                ? 'Last night has been sealed. Open Recovery to see your readiness.'
                : 'Last night: '.implode(', ', $bits).'. Tap to see your readiness.';

            app(NotificationService::class)->notify(
                $profile,
                'Recovery ready',
                $body,
                '/recovery',
                'recovery',
            );
        } catch (\Throwable $e) {
            Log::warning('[Biosignal] recovery notification failed', [
                'profile_id' => $profile->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function timezoneFor(Profile $profile): string
    {
        return $profile->wearableConnections()->whereNotNull('timezone')->value('timezone')
            ?: config('app.timezone', 'UTC');
    }
}
