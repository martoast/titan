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
 * in P2 — here we no-op for them so the pipeline degrades gracefully.
 */
class ProcessWindowJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

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
                // Sleep/activity sealing is P2 — leave queued for the seal jobs.
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

        $result = $biosignal->processHrv($window);
        $metrics = $result['metrics'] ?? [];
        $algoVersion = $result['algo_version'] ?? config('services.biosignal.algo_version', 'v1');

        if (! ($metrics['valid'] ?? true)) {
            // Invalid for HRV (no IBI persisted → excluded from the RMSSD aggregate), but its
            // epoch features still matter for SLEEP: a motion-rejected window is usually a
            // wake/arousal period, so its high motion must reach the staging pass.
            $ingestion->update([
                'status' => DeviceIngestion::STATUS_PROCESSED,
                'algo_version' => $algoVersion,
                'result_refs' => array_filter([
                    'skipped' => 'invalid_signal',
                    'artifact_pct' => $metrics['artifact_pct'] ?? null,
                    'epoch_hr' => $result['epoch_hr'] ?? null,
                    'epoch_motion' => $result['epoch_motion'] ?? null,
                ], fn ($v) => $v !== null),
            ]);

            return;
        }

        $tz = $ingestion->profile->wearableConnections()->where('source', $ingestion->source)->value('timezone')
            ?: config('app.timezone', 'UTC');

        $date = CarbonImmutable::parse($ingestion->window_end ?? $ingestion->window_start ?? now())
            ->setTimezone($tz)->toDateString();

        $log = RecoveryLog::updateOrCreate(
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
                'artifact_pct' => $metrics['artifact_pct'] ?? null,
                // Persist the clean per-window IBI so SealNightJob can compute a true
                // whole-night RMSSD (ppg_raw blobs hold samples, not IBI).
                'ibi_ms' => $result['ibi_ms'] ?? null,
                // Per-30s-epoch sleep features → concatenated whole-night to stage sleep.
                'epoch_hr' => $result['epoch_hr'] ?? null,
                'epoch_motion' => $result['epoch_motion'] ?? null,
            ], fn ($v) => $v !== null),
        ]);
    }

    private function fail(DeviceIngestion $ingestion, string $message): void
    {
        Log::warning('[Biosignal] window processing failed', ['batch_uid' => $ingestion->batch_uid, 'error' => $message]);
        $ingestion->update(['status' => DeviceIngestion::STATUS_FAILED, 'error' => mb_substr($message, 0, 1000)]);
    }
}
