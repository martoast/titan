<?php

namespace App\Jobs;

use App\Models\DeviceIngestion;
use App\Models\RecoveryLog;
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
 * Processes one raw biosignal batch: pull the window NDJSON.gz from the `raw` disk,
 * hand it to the Python biosignal service, then idempotently upsert the resulting
 * metrics into the canonical Titan tables.
 *
 * Runs on the `biosignal` Redis queue (see the dedicated worker in compose.yaml).
 *
 * Phase 0/1 scope: HRV (kind=ibi or ppg_raw) → recovery_logs. Sleep + activity batches
 * are persisted to the ledger by the controller; their sealing/processing jobs arrive
 * in P2 -- here we no-op for them so the pipeline degrades gracefully.
 */
class ProcessWindowJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Spread retries out (30s, then 5min) so a biosignal restart/deploy window — which easily
     *  outlives three 10-second retries — doesn't mark the whole night's windows FAILED. */
    public array $backoff = [30, 300];

    public function __construct(public string $batchUid)
    {
        $this->onQueue('biosignal');
    }

    public function handle(BiosignalClient $biosignal): void
    {
        /** @var DeviceIngestion|null $ingestion */
        $ingestion = DeviceIngestion::where('batch_uid', $this->batchUid)->first();

        if (! $ingestion || $ingestion->status === DeviceIngestion::STATUS_PROCESSED) {
            return; // nothing to do / already done (idempotent)
        }

        if (! $ingestion->object_key || ! Storage::disk('raw')->exists($ingestion->object_key)) {
            $this->fail($ingestion, 'raw object missing: '.$ingestion->object_key);

            return;
        }

        $ingestion->update(['status' => DeviceIngestion::STATUS_PROCESSING]);

        try {
            $window = $this->loadWindow($ingestion);

            match ($ingestion->kind) {
                'ibi', 'ppg_raw' => $this->processHrv($ingestion, $biosignal, $window),
                'workout' => $this->processWorkout($ingestion, $window),
                // Sleep sealing is driven by SealNightJob -- leave queued for it.
                default => $ingestion->update(['status' => DeviceIngestion::STATUS_QUEUED]),
            };
        } catch (\Throwable $e) {
            $this->fail($ingestion, $e->getMessage());

            throw $e; // let the queue retry/backoff
        }
    }

    /**
     * Decode the stored window. Persisted as gzipped NDJSON (one JSON window per line);
     * for Phase 0 each raw batch holds a single window, so we return the first object.
     *
     * @return array<string,mixed>
     */
    private function loadWindow(DeviceIngestion $ingestion): array
    {
        $raw = Storage::disk('raw')->get($ingestion->object_key);
        $decoded = @gzdecode($raw);
        $body = $decoded !== false ? $decoded : $raw; // tolerate uncompressed in dev

        foreach (preg_split('/\r?\n/', trim($body)) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            $obj = json_decode($line, true);
            if (is_array($obj)) {
                return $obj;
            }
        }

        return [];
    }

    /**
     * HRV path: NeuroKit2 over the IBI window → one recovery_logs row for the window's
     * calendar date. updateOrCreate keyed on (profile_id, logged_at) so reprocessing or
     * a re-sent batch corrects in place; we only touch objective fields + provenance,
     * never the user's subjective stress/mood/energy/soreness/notes.
     *
     * @param  array<string,mixed>  $window
     */
    private function processHrv(DeviceIngestion $ingestion, BiosignalClient $biosignal, array $window): void
    {
        // The device stores per-sample activity under accel_mag_cg; expose it to the
        // biosignal service as accel_counts so it can derive a real per-epoch motion
        // signal (actigraphy) for sleep staging instead of the PPG-quality proxy.
        if (! isset($window['accel_counts']) && isset($window['accel_mag_cg'])) {
            $window['accel_counts'] = $window['accel_mag_cg'];
        }

        // On-demand spot reading (CMD:capture): a momentary snapshot the coach interprets
        // live. It must NOT touch the daily recovery row -- a daytime HRV is far lower than
        // overnight rest and would clobber the morning's score.
        $isSpot = ($window['purpose'] ?? null) === 'spot';

        // Skip the per-window waveform respiratory-rate estimate on the overnight path — it's the
        // costliest DSP step (~1s/window) and the sealer recomputes RR once, whole-night, from the
        // aggregated IBI (which it prefers anyway). Dropping it here is what lets a full night's
        // windows stage in seconds instead of a minute-plus. Spot reads still want it.
        $window['want_resp'] = $isSpot;

        $result = $biosignal->processHrv($window);
        $metrics = $result['metrics'] ?? [];
        $algoVersion = $result['algo_version'] ?? config('services.biosignal.algo_version', 'v1');

        if ($isSpot) {
            $valid = (bool) ($metrics['valid'] ?? true);
            $ingestion->update([
                'status' => DeviceIngestion::STATUS_PROCESSED,
                'algo_version' => $algoVersion,
                'result_refs' => array_filter([
                    'spot' => true,
                    'hrv_ms' => isset($metrics['hrv_ms']) ? (int) round($metrics['hrv_ms']) : null,
                    'resting_hr' => isset($metrics['resting_hr']) ? (int) round($metrics['resting_hr']) : null,
                    'artifact_pct' => $metrics['artifact_pct'] ?? null,
                ], fn ($v) => $v !== null),
            ]);
            if (class_exists(\App\Jobs\ReactToSpotReading::class)) {
                \App\Jobs\ReactToSpotReading::dispatch(
                    $ingestion->profile_id,
                    $valid && isset($metrics['hrv_ms']) ? (int) round($metrics['hrv_ms']) : null,
                    $valid && isset($metrics['resting_hr']) ? (int) round($metrics['resting_hr']) : null,
                    $valid,
                );
            }

            return;
        }

        if (! ($metrics['valid'] ?? true)) {
            // A window can be perfectly CLEAN yet fail the standalone-valid gate purely because it's
            // too SHORT: the band's overnight sleep captures ~30 s HRV bursts (~28 beats), well under
            // the 60-beat validity floor, but the IBI is good. Persist that IBI (tagged aggregate-only,
            // so a single burst never becomes a standalone recovery row) so SealNightJob can concatenate
            // the WHOLE night — hundreds of beats across bursts — into one valid recovery read. Without
            // this, every burst was dropped and a night of sleep produced NO recovery score at all.
            // A genuinely dirty (motion/artifact) window contributes only its epoch features for sleep
            // staging, as before.
            $artifactPct = $metrics['artifact_pct'] ?? null;
            $cleanIbi = $result['ibi_ms'] ?? null;
            $cleanShort = is_array($cleanIbi) && count($cleanIbi) >= 3
                && is_numeric($artifactPct) && (float) $artifactPct <= 5.0; // == hrv.py MAX_ARTIFACT_PCT

            $ingestion->update([
                'status' => DeviceIngestion::STATUS_PROCESSED,
                'algo_version' => $algoVersion,
                'result_refs' => array_filter([
                    'skipped' => $cleanShort ? 'short_window_aggregate_only' : 'invalid_signal',
                    'ibi_ms' => $cleanShort ? $result['ibi_ms'] : null,
                    'rmssd' => $cleanShort ? ($metrics['rmssd'] ?? null) : null,
                    'resp_rate' => $cleanShort ? ($metrics['resp_rate'] ?? null) : null,
                    'artifact_pct' => $artifactPct,
                    'epoch_hr' => $result['epoch_hr'] ?? null,
                    'epoch_motion' => $result['epoch_motion'] ?? null,
                    'epoch_rmssd' => $result['epoch_rmssd'] ?? null,
                ], fn ($v) => $v !== null),
            ]);

            return;
        }

        $tz = $ingestion->profile->wearableConnections()->where('source', $ingestion->source)->value('timezone')
            ?: config('app.timezone', 'UTC');

        $date = CarbonImmutable::parse($ingestion->window_end ?? $ingestion->window_start ?? now())
            ->setTimezone($tz)->toDateString();

        // The whole-night seal (SealNightJob) is authoritative. A single late-arriving window
        // must NOT clobber a sealed recovery row back to a noisier per-window value (which would
        // also drop its confidence from "sealed" to "window"). If the night is already sealed,
        // reference that row instead of overwriting it; otherwise upsert the provisional value.
        $sealed = RecoveryLog::query()
            ->where('profile_id', $ingestion->profile_id)
            ->whereDate('logged_at', $date)
            ->where('updated_via', 'like', 'biosignal:sealed%')
            ->first();

        $log = $sealed ?: RecoveryLog::updateOrCreate(
            ['profile_id' => $ingestion->profile_id, 'logged_at' => $date],
            array_filter([
                'hrv_ms' => isset($metrics['hrv_ms']) ? (int) round($metrics['hrv_ms']) : null,
                'resting_hr' => isset($metrics['resting_hr']) ? (int) round($metrics['resting_hr']) : null,
                'updated_via' => 'biosignal:'.$algoVersion,
            ], fn ($v) => $v !== null),
        );

        $ingestion->update([
            'status' => DeviceIngestion::STATUS_PROCESSED,
            'algo_version' => $algoVersion,
            'result_refs' => array_filter([
                'recovery_log_id' => $log->id,
                'rmssd' => $metrics['rmssd'] ?? null,
                'sdnn' => $metrics['sdnn'] ?? null,
                // Per-window respiratory rate (breaths/min) → sealed to a whole-night median.
                'resp_rate' => $metrics['resp_rate'] ?? null,
                'artifact_pct' => $metrics['artifact_pct'] ?? null,
                // Persist the clean per-window IBI so SealNightJob can compute a true
                // whole-night RMSSD (ppg_raw blobs hold samples, not IBI).
                'ibi_ms' => $result['ibi_ms'] ?? null,
                // Per-30s-epoch sleep features → concatenated whole-night to stage sleep.
                'epoch_hr' => $result['epoch_hr'] ?? null,
                'epoch_motion' => $result['epoch_motion'] ?? null,
                'epoch_rmssd' => $result['epoch_rmssd'] ?? null,
            ], fn ($v) => $v !== null),
        ]);
    }

    /**
     * Workout path: a `kind=workout` window (the band/phone streams one every ~3 min during a run,
     * plus a final one when it ends). SealActivityJob is the authority that groups a session's windows
     * into one activity_sessions row — so we leave this window QUEUED for it, but trigger a seal pass
     * NOW instead of waiting for the every-15-min scheduler.
     *
     * Whoop-style: the phone tags the final window with `ended` (user/watch tapped End). That is an
     * explicit "this session is over", so we seal it immediately rather than waiting QUIET_MINUTES for
     * the stream to fall quiet. Mid-run windows (no flag) still dispatch a seal — which promptly catches
     * any PRIOR finished session — but the live one keeps streaming until its quiet/ended signal.
     *
     * @param  array<string,mixed>  $window
     */
    private function processWorkout(DeviceIngestion $ingestion, array $window): void
    {
        $ingestion->update(['status' => DeviceIngestion::STATUS_QUEUED]);

        $ended = ($window['ended'] ?? false) === true;
        SealActivityJob::dispatch($ingestion->profile_id, $ended)->afterCommit();
    }

    private function fail(DeviceIngestion $ingestion, string $message): void
    {
        Log::warning('[Biosignal] window processing failed', ['batch_uid' => $ingestion->batch_uid, 'error' => $message]);
        $ingestion->update(['status' => DeviceIngestion::STATUS_FAILED, 'error' => mb_substr($message, 0, 1000)]);
    }
}
