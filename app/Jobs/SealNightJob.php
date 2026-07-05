<?php

namespace App\Jobs;

use App\Models\DeviceIngestion;
use App\Models\Profile;
use App\Models\RecoveryLog;
use App\Models\SleepLog;
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
 * Seal a completed night for ONE profile -- the authoritative whole-night pass.
 *
 * Per-window Shape-A ingestion (ProcessWindowJob) upserts the daily recovery_logs row
 * once per ~7-min IBI window with last-write-wins, so `hrv_ms` ends up reflecting only
 * the final window rather than the whole night. RMSSD from a 7-min window is noisy
 * (03-algorithms §2: 5-min windows r²≈0.77 vs whole-night r²≈0.98) -- recovery HRV must
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
     * Minimum duration (minutes) for a user-confirmed sleep session to seal. A sub-20-min button tap
     * is not a nap — don't create sleep noise. A legit 20-90 min nap sails through. This gate lives on
     * the SEAL (read) side, never on capture: the band always persists + delivers the marker; we decide
     * whether it's worth a row here.
     */
    public const MIN_SLEEP_MIN = 20;

    /** A confirmed session under 4 h is a NAP: its own sleep_logs row (keyed by session_start) so it
     * never clobbers the night on the same date, and excluded from recovery/debt math. */
    public const NAP_MAX_MIN = 240;

    /** Slack (seconds) around a confirmed session's [bedtime, wake] when scoping which windows belong to it. */
    public const SESSION_MARGIN_S = 600;

    /**
     * Per-window RMSSD ceiling (ms). A 2-minute window above this is almost certainly a
     * peak-detection artifact (a missed/extra beat creates a huge successive difference),
     * not real overnight HRV -- excluded from the whole-night aggregate so a handful of
     * bad windows can't inflate the sealed number. (Observed in dogfooding: a few windows
     * came back at 200-380 ms and dragged a true ~66 ms night up to ~99 ms.)
     *
     * Set conservatively at 200 ms -- physiologically, even elite resting HRV tops out
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
     * @param  bool  $confirmed  the user MARKED AWAKE on the band → fire the coach's sleep summary. The
     *                           automatic (cron) seal leaves this false: it computes the data silently,
     *                           so you only get a sleep push when YOU end the session (no wrong-time noise).
     */
    public function __construct(
        public int $profileId,
        public ?string $night = null,
        public bool $confirmed = false,
        public ?int $sessionBedEpoch = null,
        public ?int $sessionWakeEpoch = null,
    ) {
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

            // A user-CONFIRMED session with explicit bounds (the watch's "I'm awake" T9 carries the real
            // bedtime + wake) seals SCOPED to that window — never by vacuuming the whole calendar date.
            // This is what saves a nap correctly and keeps unrelated day bursts out of it.
            if ($this->confirmed && $this->sessionBedEpoch && $this->sessionWakeEpoch
                && $this->sessionWakeEpoch > $this->sessionBedEpoch) {
                $this->sealConfirmedSession($profile, $biosignal, $tz);

                return;
            }

            // All unsealed raw windows that still need whole-night aggregation. A window is
            // "unsealed" when its status is not yet 'sealed'. We accept processed/queued/
            // received/failed per-window rows -- the night seal supersedes them either way.
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
                    continue; // still streaming -- let it finish
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
     * relative to the device-owner's local "today" (a morning cutoff -- yesterday is done).
     *
     * @param  \Illuminate\Support\Collection<int,DeviceIngestion>  $windows
     */
    private function nightIsComplete(\Illuminate\Support\Collection $windows, string $date, string $tz): bool
    {
        if ($this->confirmed) {
            return true; // the user explicitly marked awake on the band — seal now, don't wait for
            // quiescence. This also keeps the hourly cron (confirmed=false, still gated below) from
            // sealing the fresh night out from under the user's confirmed summary.
        }

        if ($date < now($tz)->toDateString()) {
            return true; // a past night -- morning cutoff
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
            $windowsUsed = 0;       // windows whose beats fed the whole-night aggregate
            $windowsDropped = 0;    // windows rejected as artifacts (or with a missing blob)

            foreach ($ibiWindows as $ingestion) {
                // Prefer the per-window IBI persisted by ProcessWindowJob -- this is what makes
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
                        $windowsUsed++;
                    } else {
                        $windowsDropped++;
                    }

                    continue;
                }

                $window = $this->loadWindow($ingestion);
                if ($window === null) {
                    $windowsDropped++;

                    continue; // raw blob missing -- skip, but still seal so we don't loop forever
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
                $windowsUsed++;
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

                // Fail SAFE: only write a recovery read when the service explicitly says the
                // whole-night signal is valid. A missing flag means an unexpected/erroring
                // response, not a clean night -- don't present it as a real reading.
                if (($metrics['valid'] ?? false) === true) {
                    // Whole-night respiratory rate. PREFER the value the whole-night pass computes from
                    // the aggregated IBI (RSA) — the band's 30 s bursts are too short for waveform
                    // respiration, so the per-window PPG estimate is usually absent. Fall back to the
                    // median of any per-window values (a longer-window device like Polar/Apple provides).
                    $respRate = $metrics['resp_rate'] ?? null;
                    if ($respRate === null) {
                        $respRate = $ibiWindows
                            ->map(fn (DeviceIngestion $i) => $i->result_refs['resp_rate'] ?? null)
                            ->filter(fn ($v) => is_numeric($v))
                            ->median();
                    }

                    // Idempotent re-seal guard: if a MORE complete sealed read already exists
                    // (more beats), keep it -- a re-seal triggered by a lone late window must not
                    // replace a good whole-night aggregate with a worse one.
                    $prior = RecoveryLog::query()
                        ->where('profile_id', $profile->id)->whereDate('logged_at', $date)
                        ->where('updated_via', 'like', 'biosignal:sealed%')->first();

                    if ($prior && (int) ($prior->quality['beats'] ?? 0) >= count($allIbi)) {
                        $log = $prior;
                    } else {
                        $log = RecoveryLog::updateOrCreate(
                            ['profile_id' => $profile->id, 'logged_at' => $date],
                            array_filter([
                                'hrv_ms' => isset($metrics['hrv_ms']) ? (int) round($metrics['hrv_ms']) : null,
                                'resting_hr' => isset($metrics['resting_hr']) ? (int) round($metrics['resting_hr']) : null,
                                'resp_rate' => $respRate !== null ? round((float) $respRate, 1) : null,
                                'updated_via' => 'biosignal:sealed',
                                // Provenance for RecoveryConfidence: how clean was this night's aggregate.
                                'quality' => [
                                    'windows_used' => $windowsUsed,
                                    'windows_dropped' => $windowsDropped,
                                    'beats' => count($allIbi),
                                    'valid' => true,
                                ],
                            ], fn ($v) => $v !== null),
                        );
                    }

                    $ibiWindows->each(function (DeviceIngestion $i) use ($algoVersion, $log) {
                        $i->update([
                            'status' => DeviceIngestion::STATUS_SEALED,
                            'algo_version' => $algoVersion,
                            'result_refs' => array_merge((array) $i->result_refs, ['recovery_log_id' => $log->id, 'sealed' => true]),
                        ]);
                    });

                    // Recovery is in -- let the COACH react: morning read + a note in the chat,
                    // deduped to once a day (see ReactToDeviceSync). Best-effort, off the seal path.
                    \App\Jobs\ReactToDeviceSync::dispatch($profile->id);
                } else {
                    // Invalid whole-night signal -- still seal so we don't reprocess endlessly.
                    $ibiWindows->each(fn (DeviceIngestion $i) => $i->update(['status' => DeviceIngestion::STATUS_SEALED]));
                }
            } elseif (! $biosignal->configured()) {
                // Service UNCONFIGURED (missing/typo'd biosignal url after a deploy) is an ops state,
                // not a data verdict — leave the windows unsealed so the hourly seal cron reprocesses
                // them once config returns. Sealing here silently discarded whole nights.
                Log::warning('[Biosignal] night left unsealed: service not configured', ['profile_id' => $profile->id]);
            } else {
                // Genuinely too little data -- seal to release the night.
                $ibiWindows->each(fn (DeviceIngestion $i) => $i->update(['status' => DeviceIngestion::STATUS_SEALED]));
            }

            $sealedIds = $ibiWindows->pluck('id')->all();
        }

        // --- Whole-night sleep staging → sleep_logs ---
        if ($sleepWindows->isNotEmpty()) {
            $this->sealSleep($profile, $biosignal, $date, $sleepWindows);
        } elseif ($ibiWindows->isNotEmpty()) {
            // The wearable sends raw PPG, not sleep windows -- stage sleep from the per-window
            // epoch features (HR + motion proxy) the HRV pass persisted.
            $this->sealSleepFromPpg($profile, $biosignal, $date, $ibiWindows);
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
     * Seal ONE user-confirmed session (nap or night) scoped to the marker's real [bedtime, wake].
     *
     * The fix for the lost-nap bug. Unlike the date-grouped whole-night seal, this:
     *   1. Enforces a minimum duration (a sub-20-min tap isn't a nap).
     *   2. Scopes staging to windows overlapping [bedtime, wake] (± margin) — no whole-date vacuum, so
     *      an unrelated day burst can't turn a nap into an impossible multi-hour "night".
     *   3. GUARANTEES a sleep_logs row from the marker's own duration even when the PPG windows are thin
     *      or never arrived (airplane mode) — the session still surfaces on the Sleep page.
     *   4. Never presents an all-awake block (the phantom guard): if nothing staged asleep, the row is
     *      duration-only (no fake stage bar).
     *   5. Stores a nap keyed by session_start (its own row) so it never clobbers the night on the same
     *      date; a full night keeps the (profile_id, slept_at) key.
     */
    private function sealConfirmedSession(Profile $profile, BiosignalClient $biosignal, string $tz): void
    {
        $bed = (int) $this->sessionBedEpoch;
        $wake = (int) $this->sessionWakeEpoch;
        $durMin = (int) round(($wake - $bed) / 60);

        if ($durMin < self::MIN_SLEEP_MIN) {
            Log::info('[Biosignal] confirmed session below minimum — not sealed', [
                'profile_id' => $profile->id, 'dur_min' => $durMin,
            ]);

            return;
        }

        $isNap = $durMin < self::NAP_MAX_MIN;
        $date = CarbonImmutable::createFromTimestamp($wake, 'UTC')->setTimezone($tz)->toDateString();
        $bedDt = CarbonImmutable::createFromTimestamp($bed, 'UTC');
        $wakeDt = CarbonImmutable::createFromTimestamp($wake, 'UTC');

        // Windows whose span overlaps the session (± margin). Scoping is the anti-vacuum guarantee.
        $loEpoch = $bed - self::SESSION_MARGIN_S;
        $hiEpoch = $wake + self::SESSION_MARGIN_S;
        $scoped = DeviceIngestion::query()
            ->where('profile_id', $profile->id)
            ->whereIn('kind', ['ibi', 'ppg_raw', 'sleep'])
            ->where('status', '!=', DeviceIngestion::STATUS_SEALED)
            ->get()
            ->filter(function (DeviceIngestion $i) use ($loEpoch, $hiEpoch) {
                $ws = $i->window_start ? CarbonImmutable::parse($i->window_start)->timestamp : null;
                $we = $i->window_end ? CarbonImmutable::parse($i->window_end)->timestamp : $ws;
                if ($ws === null && $we === null) {
                    return false;
                }

                return ($we ?? $ws) >= $loEpoch && ($ws ?? $we) <= $hiEpoch;
            });

        // Stage the scoped windows (best-effort). All-awake / no-signal → $metrics stays null and we
        // fall back to a duration-only row (the phantom guard) rather than presenting garbage.
        $metrics = $this->stageScoped($biosignal, $scoped, $bedDt, $wakeDt);

        $key = $isNap
            ? ['profile_id' => $profile->id, 'session_start' => $bedDt->setTimezone($tz)->toDateTimeString(), 'is_nap' => true]
            : ['profile_id' => $profile->id, 'slept_at' => $date, 'is_nap' => false];

        $attrs = $metrics !== null
            ? array_filter([
                'slept_at' => $date,
                'duration_min' => isset($metrics['duration_min']) ? (int) round($metrics['duration_min']) : $durMin,
                'deep_min' => isset($metrics['deep_min']) ? (int) round($metrics['deep_min']) : null,
                'rem_min' => isset($metrics['rem_min']) ? (int) round($metrics['rem_min']) : null,
                'light_min' => isset($metrics['light_min']) ? (int) round($metrics['light_min']) : null,
                'awake_min' => isset($metrics['awake_min']) ? (int) round($metrics['awake_min']) : null,
                'bedtime' => $this->timeOnly($metrics['bedtime'] ?? null) ?? $bedDt->setTimezone($tz)->format('H:i:s'),
                'wake_time' => $this->timeOnly($metrics['wake_time'] ?? null) ?? $wakeDt->setTimezone($tz)->format('H:i:s'),
                'quality' => isset($metrics['quality']) ? (int) round($metrics['quality']) : null,
                'hypnogram' => (isset($metrics['hypnogram_30s']) && is_array($metrics['hypnogram_30s'])) ? $metrics['hypnogram_30s'] : null,
                'updated_via' => 'biosignal:sealed-session',
            ], fn ($v) => $v !== null)
            // Duration-only fallback: honest "you slept ~45 min", no fabricated stages, never 100% awake.
            : [
                'slept_at' => $date,
                'duration_min' => $durMin,
                'bedtime' => $bedDt->setTimezone($tz)->format('H:i:s'),
                'wake_time' => $wakeDt->setTimezone($tz)->format('H:i:s'),
                'updated_via' => 'biosignal:sealed-session-marker',
            ];

        $log = SleepLog::updateOrCreate($key, $attrs);

        // Only the scoped windows are consumed by this session — everything else stays for its own seal.
        $scoped->each(fn (DeviceIngestion $i) => $i->update([
            'status' => DeviceIngestion::STATUS_SEALED,
            'result_refs' => array_merge((array) $i->result_refs, ['sleep_log_id' => $log->id, 'sealed' => true]),
        ]));

        // A confirmed session fires the coach's summary (the user asked for it by ending on the band).
        \App\Jobs\ReactToSleepConfirmed::dispatch($log->id)->afterCommit();

        Log::info('[Biosignal] confirmed session sealed', [
            'profile_id' => $profile->id, 'is_nap' => $isNap, 'dur_min' => $durMin,
            'scoped_windows' => $scoped->count(), 'staged' => $metrics !== null,
        ]);
    }

    /**
     * Stage a scoped set of ppg_raw/ibi windows for a confirmed session. Returns the biosignal metrics,
     * or null when there's not enough signal OR the result is all-awake (the phantom guard) — the caller
     * then writes a duration-only row instead of a fake stage breakdown.
     *
     * @param  \Illuminate\Support\Collection<int,DeviceIngestion>  $scoped
     * @return array<string,mixed>|null
     */
    private function stageScoped(BiosignalClient $biosignal, \Illuminate\Support\Collection $scoped, CarbonImmutable $bedDt, CarbonImmutable $wakeDt): ?array
    {
        if (! $biosignal->configured() || $scoped->isEmpty()) {
            return null;
        }

        $motion = [];
        $hr = [];
        $rmssd = [];
        foreach ($scoped->sortBy('window_end') as $ingestion) {
            $em = $ingestion->result_refs['epoch_motion'] ?? null;
            if (! is_array($em) || $em === []) {
                continue;
            }
            $eh = (array) ($ingestion->result_refs['epoch_hr'] ?? []);
            $er = (array) ($ingestion->result_refs['epoch_rmssd'] ?? []);
            foreach ($em as $k => $v) {
                $motion[] = is_numeric($v) ? (float) $v : 0.0;
                $hr[] = (isset($eh[$k]) && is_numeric($eh[$k])) ? (float) $eh[$k] : 0.0;
                $rmssd[] = (isset($er[$k]) && is_numeric($er[$k])) ? (float) $er[$k] : null;
            }
        }

        if (count($motion) < 10) {
            return null; // too thin to stage → duration-only
        }

        try {
            $result = $biosignal->processSleep([
                'kind' => 'sleep',
                'start' => $bedDt->toIso8601ZuluString(),
                'end' => $wakeDt->toIso8601ZuluString(),
                'accel_counts' => $motion,
                'hr_bpm' => $hr,
                'rmssd_ms' => $rmssd,
                'whole_night' => true,
            ]);
            $metrics = $result['metrics'] ?? [];
            if ($this->stagedAllAwake($metrics)) {
                return null; // phantom guard: nothing scored asleep → don't present an all-awake block
            }

            return $metrics;
        } catch (\Throwable $e) {
            Log::warning('[Biosignal] scoped session staging failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * True when a staging result has no asleep time — a degenerate "night" the stager scored 100% awake.
     * Writing it produces the "Awake 100% / 8h 15m" phantom, so callers skip it.
     *
     * @param  array<string,mixed>  $metrics
     */
    private function stagedAllAwake(array $metrics): bool
    {
        $asleep = (float) ($metrics['deep_min'] ?? 0) + (float) ($metrics['rem_min'] ?? 0) + (float) ($metrics['light_min'] ?? 0);

        return $asleep <= 0.0;
    }

    /**
     * Stage sleep from a raw-PPG (wearable) night: concatenate the per-window 30-s epoch
     * features (HR + motion proxy) persisted by ProcessWindowJob -- across both valid and
     * motion-rejected windows, in time order -- and run the whole night through the stager
     * once → one sleep_logs row. This is what gives the Bangle deep/REM/light without an
     * accelerometer (motion is inferred from PPG signal quality).
     *
     * @param  \Illuminate\Support\Collection<int,DeviceIngestion>  $ibiWindows
     */
    private function sealSleepFromPpg(Profile $profile, BiosignalClient $biosignal, string $date, \Illuminate\Support\Collection $ibiWindows): void
    {
        if (! $biosignal->configured()) {
            return;
        }

        $motion = [];
        $hr = [];
        $rmssd = [];
        $start = null;
        $end = null;

        // Windows arrive ordered by window_end → epochs are already chronological.
        foreach ($ibiWindows->sortBy('window_end') as $ingestion) {
            $em = $ingestion->result_refs['epoch_motion'] ?? null;
            if (! is_array($em) || $em === []) {
                continue;
            }
            $eh = (array) ($ingestion->result_refs['epoch_hr'] ?? []);
            $er = (array) ($ingestion->result_refs['epoch_rmssd'] ?? []);
            foreach ($em as $k => $v) {
                $motion[] = is_numeric($v) ? (float) $v : 0.0;
                $hr[] = (isset($eh[$k]) && is_numeric($eh[$k])) ? (float) $eh[$k] : 0.0;
                $rmssd[] = (isset($er[$k]) && is_numeric($er[$k])) ? (float) $er[$k] : null;
            }
            $start = $start ?? ($ingestion->window_start ?? null);
            $end = $ingestion->window_end ?? $end;
        }

        if (count($motion) < 10) {
            return; // not enough of a night to stage
        }

        try {
            $result = $biosignal->processSleep([
                'kind' => 'sleep',
                'start' => $start ? CarbonImmutable::parse($start)->toIso8601ZuluString() : null,
                'end' => $end ? CarbonImmutable::parse($end)->toIso8601ZuluString() : null,
                'accel_counts' => $motion,
                'hr_bpm' => $hr,
                'rmssd_ms' => $rmssd,
                'whole_night' => true,
            ]);
            $metrics = $result['metrics'] ?? [];

            // Phantom guard: the automatic (cron) pass must never write an all-awake block — scattered
            // daytime bursts that stage 100% awake are the "Awake 100% / 8h 15m" phantom. No sleep, no row.
            if ($this->stagedAllAwake($metrics)) {
                return;
            }

            $log = SleepLog::updateOrCreate(
                ['profile_id' => $profile->id, 'slept_at' => $date],
                array_filter([
                    'duration_min' => isset($metrics['duration_min']) ? (int) round($metrics['duration_min']) : null,
                    'deep_min' => isset($metrics['deep_min']) ? (int) round($metrics['deep_min']) : null,
                    'rem_min' => isset($metrics['rem_min']) ? (int) round($metrics['rem_min']) : null,
                    'light_min' => isset($metrics['light_min']) ? (int) round($metrics['light_min']) : null,
                    'awake_min' => isset($metrics['awake_min']) ? (int) round($metrics['awake_min']) : null,
                    'bedtime' => $this->timeOnly($metrics['bedtime'] ?? null),
                    'wake_time' => $this->timeOnly($metrics['wake_time'] ?? null),
                    'quality' => isset($metrics['quality']) ? (int) round($metrics['quality']) : null,
                    // The per-30s hypnogram (the stager already computes it) → the Whoop stage timeline.
                    'hypnogram' => (isset($metrics['hypnogram_30s']) && is_array($metrics['hypnogram_30s'])) ? $metrics['hypnogram_30s'] : null,
                    'updated_via' => 'biosignal:sealed-ppg',
                ], fn ($v) => $v !== null),
            );

            // The night's data is computed either way; the coach SUMMARY fires only when the user
            // marked awake on the band (confirmed) — so the morning push is user-controlled, never automatic.
            if ($this->confirmed) {
                \App\Jobs\ReactToSleepConfirmed::dispatch($log->id)->afterCommit();
            }
        } catch (\Throwable $e) {
            Log::warning('[Biosignal] ppg sleep staging failed', [
                'profile_id' => $profile->id, 'night' => $date, 'error' => $e->getMessage(),
            ]);
        }
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

            // Phantom guard (see sealSleepFromPpg): don't seal a night that scored 100% awake.
            if ($this->stagedAllAwake($metrics)) {
                $sleepWindows->each(fn (DeviceIngestion $i) => $i->update(['status' => DeviceIngestion::STATUS_SEALED]));

                return;
            }

            $log = SleepLog::updateOrCreate(
                ['profile_id' => $profile->id, 'slept_at' => $date],
                array_filter([
                    'duration_min' => isset($metrics['duration_min']) ? (int) round($metrics['duration_min']) : null,
                    'deep_min' => isset($metrics['deep_min']) ? (int) round($metrics['deep_min']) : null,
                    'rem_min' => isset($metrics['rem_min']) ? (int) round($metrics['rem_min']) : null,
                    'light_min' => isset($metrics['light_min']) ? (int) round($metrics['light_min']) : null,
                    'awake_min' => isset($metrics['awake_min']) ? (int) round($metrics['awake_min']) : null,
                    'bedtime' => $this->timeOnly($metrics['bedtime'] ?? null),
                    'wake_time' => $this->timeOnly($metrics['wake_time'] ?? null),
                    'quality' => isset($metrics['quality']) ? (int) round($metrics['quality']) : null,
                    'hypnogram' => (isset($metrics['hypnogram_30s']) && is_array($metrics['hypnogram_30s'])) ? $metrics['hypnogram_30s'] : null,
                    'updated_via' => 'biosignal:sealed',
                ], fn ($v) => $v !== null),
            );

            $sleepWindows->each(fn (DeviceIngestion $i) => $i->update([
                'status' => DeviceIngestion::STATUS_SEALED,
                'algo_version' => $algoVersion,
                'result_refs' => array_merge((array) $i->result_refs, ['sleep_log_id' => $log->id, 'sealed' => true]),
            ]));

            // The night's data is computed either way; the coach SUMMARY fires only when the user
            // marked awake on the band (confirmed) — so the morning push is user-controlled, never automatic.
            if ($this->confirmed) {
                \App\Jobs\ReactToSleepConfirmed::dispatch($log->id)->afterCommit();
            }
        } catch (\Throwable $e) {
            Log::warning('[Biosignal] sleep seal failed', ['profile_id' => $profile->id, 'night' => $date, 'attempt' => $this->attempts(), 'error' => $e->getMessage()]);
            // Transient failure (service restarting) → rethrow so the queue retries; the HRV pass is
            // idempotent on re-run. Only the FINAL attempt seals-anyway (unwedges a truly bad night).
            if ($this->attempts() < $this->tries) {
                throw $e;
            }
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


    /** The stager returns bedtime/wake_time as ISO-8601 datetimes, but the columns are TIME. */
    private function timeOnly(?string $iso): ?string
    {
        if (! $iso) {
            return null;
        }
        try {
            return CarbonImmutable::parse($iso)->format('H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    private function timezoneFor(Profile $profile): string
    {
        return $profile->wearableConnections()->whereNotNull('timezone')->value('timezone')
            ?: config('app.timezone', 'UTC');
    }
}
