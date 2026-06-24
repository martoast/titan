<?php

namespace App\Services\Wearables;

use App\Jobs\ProcessWindowJob;
use App\Models\BodyMetric;
use App\Models\DailyActivity;
use App\Models\DeviceIngestion;
use App\Models\Profile;
use App\Models\RecoveryLog;
use App\Models\SleepLog;
use App\Models\WearableConnection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The heart of the device-agnostic ingestion pipeline. Given a validated, decoded
 * batch payload + the connection it arrived on, it:
 *
 *   Shape A (ibi+accel) / B (ppg_raw)  → persist each window to the `raw` disk, insert a
 *                                        device_ingestions ledger row, dispatch a
 *                                        ProcessWindowJob to the `biosignal` queue.
 *   Shape C (summaries)                → write straight into recovery_logs / sleep_logs /
 *                                        body_metrics (Apple Health / Polar work day one,
 *                                        no Python). Marked updated_via=device:summary.
 *
 * Calendar dates are resolved from UTC wire timestamps + the connection's IANA timezone
 * so a 2am workout or a night that crosses midnight lands on the right day.
 *
 * The controller AND the devices:simulate command both go through here, so the wire
 * contract has exactly one implementation.
 */
class DeviceIngestionService
{
    /**
     * @param  array<string,mixed>  $payload  decoded batch body (already JSON-decoded)
     * @return array{accepted:bool,batch_uid:string,windows_queued:int,summaries_written:int,duplicate:bool}
     */
    public function ingest(WearableConnection $connection, array $payload): array
    {
        $batchUid = (string) ($payload['batch_uid'] ?? Str::ulid());
        $tz = $connection->effectiveTimezone();

        // First data EVER on this connection? last_sync_at is null until the first ingest,
        // so this naturally flags the band's very first stream after pairing.
        $isFirstData = $connection->last_sync_at === null;

        $windowsQueued = 0;
        $windowsRejected = 0;
        $summariesWritten = 0;

        // --- Shapes A/B: raw windows → MinIO + ledger + queue ---
        foreach (($payload['windows'] ?? []) as $i => $window) {
            if (! is_array($window)) {
                continue;
            }

            // Sanity-gate the raw signal BEFORE it touches storage or the queue. The HMAC
            // proved who sent it, not that the signal is real -- drop clearly-corrupt windows
            // (flatline, NaN, impossible rate/clock) so garbage can't masquerade as data.
            $sanity = WindowSanity::check($window);
            if (! $sanity['ok']) {
                $windowsRejected++;
                Log::warning('[Ingest] dropped a corrupt window', [
                    'reason' => $sanity['reason'],
                    'kind' => $window['kind'] ?? null,
                    'profile_id' => $connection->profile_id,
                    'source' => $connection->source,
                ]);

                continue;
            }

            $windowUid = count($payload['windows'] ?? []) > 1
                ? $batchUid.'-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT)
                : $batchUid;

            $this->persistRawWindow($connection, $windowUid, $window, $tz);
            $windowsQueued++;
        }

        // --- Shape C: provider summaries → straight to canonical tables ---
        $recoveryLanded = false;
        foreach (($payload['summaries'] ?? []) as $summary) {
            if (! is_array($summary)) {
                continue;
            }
            if ($this->writeSummary($connection, $summary, $tz)) {
                $summariesWritten++;
                if (($summary['kind'] ?? '') === 'recovery') {
                    $recoveryLanded = true;
                }
            }
        }

        // Fresh overnight recovery just landed → let the coach react (deduped to once a day in the job).
        if ($recoveryLanded && class_exists(\App\Jobs\ReactToDeviceSync::class)) {
            \App\Jobs\ReactToDeviceSync::dispatch($connection->profile_id);
        }

        // Capture device telemetry (battery, firmware) if the band reported it this sync.
        $telemetry = ['last_sync_at' => now()];
        if (is_array($dev = $payload['device'] ?? null)) {
            if (isset($dev['battery']) && is_numeric($dev['battery'])) {
                $telemetry['battery_pct'] = (int) max(0, min(100, round((float) $dev['battery'])));
            }
            if (! empty($dev['firmware'])) {
                $telemetry['firmware'] = \Illuminate\Support\Str::limit((string) $dev['firmware'], 40, '');
            }
        }
        $connection->forceFill($telemetry)->save();

        // The band just came alive for the first time → fire the "you're live!" moment once
        // (only when real data actually arrived, not an empty heartbeat batch).
        if ($isFirstData && ($windowsQueued + $summariesWritten) > 0 && class_exists(\App\Jobs\ReactToFirstConnection::class)) {
            \App\Jobs\ReactToFirstConnection::dispatch($connection->id);
        }

        return [
            'accepted' => true,
            'batch_uid' => $batchUid,
            'windows_queued' => $windowsQueued,
            'windows_rejected' => $windowsRejected,
            'summaries_written' => $summariesWritten,
            'duplicate' => false,
        ];
    }

    /**
     * Persist one raw window to the `raw` disk and ledger it, then queue processing.
     * Idempotent on the window's batch_uid: a re-sent window resolves to the same row.
     *
     * @param  array<string,mixed>  $window
     */
    private function persistRawWindow(WearableConnection $connection, string $windowUid, array $window, string $tz): DeviceIngestion
    {
        // Already ledgered? Don't rewrite the blob or re-queue (idempotency).
        if ($existing = DeviceIngestion::where('batch_uid', $windowUid)->first()) {
            return $existing;
        }

        $kind = (string) ($window['kind'] ?? 'ibi');
        $start = isset($window['start']) ? CarbonImmutable::parse($window['start']) : now()->toImmutable();
        $end = isset($window['end']) ? CarbonImmutable::parse($window['end']) : $start;
        $date = $end->setTimezone($tz)->toDateString();

        // raw/{profile}/{yyyy-mm-dd}/{batch_uid}.ndjson.gz -- one window per line.
        $ext = $kind === 'ppg_raw' ? 'ppg.gz' : 'ndjson.gz';
        $objectKey = "raw/{$connection->profile_id}/{$date}/{$windowUid}.{$ext}";

        Storage::disk('raw')->put($objectKey, gzencode(json_encode($window).PHP_EOL));

        $ingestion = DeviceIngestion::create([
            'batch_uid' => $windowUid,
            'profile_id' => $connection->profile_id,
            'source' => $connection->source,
            'kind' => $kind,
            'object_key' => $objectKey,
            'window_start' => $start,
            'window_end' => $end,
            'status' => DeviceIngestion::STATUS_QUEUED,
        ]);

        ProcessWindowJob::dispatch($windowUid);

        return $ingestion;
    }

    /**
     * Shape-C summary → canonical table. Objective fields only; subjective ratings stay
     * user-owned. updateOrCreate keyed on the natural key (profile + date) so re-exports
     * (Apple Health re-syncs the same day repeatedly) correct in place.
     *
     * @param  array<string,mixed>  $summary
     */
    private function writeSummary(WearableConnection $connection, array $summary, string $tz): bool
    {
        $kind = (string) ($summary['kind'] ?? '');
        $pid = $connection->profile_id;

        return match ($kind) {
            'recovery' => (bool) RecoveryLog::updateOrCreate(
                ['profile_id' => $pid, 'logged_at' => $this->dateOf($summary['date'] ?? null, $tz)],
                array_filter([
                    'hrv_ms' => isset($summary['hrv_ms']) ? (int) round($summary['hrv_ms']) : null,
                    'resting_hr' => isset($summary['resting_hr']) ? (int) round($summary['resting_hr']) : null,
                    'updated_via' => 'device:summary',
                ], fn ($v) => $v !== null),
            ),
            'sleep' => (bool) SleepLog::updateOrCreate(
                ['profile_id' => $pid, 'slept_at' => $this->dateOf($summary['date'] ?? null, $tz)],
                array_filter([
                    'duration_min' => isset($summary['duration_min']) ? (int) round($summary['duration_min']) : null,
                    'quality' => isset($summary['quality']) ? (int) round($summary['quality']) : null,
                    'deep_min' => isset($summary['deep_min']) ? (int) round($summary['deep_min']) : null,
                    'rem_min' => isset($summary['rem_min']) ? (int) round($summary['rem_min']) : null,
                    'light_min' => isset($summary['light_min']) ? (int) round($summary['light_min']) : null,
                    'awake_min' => isset($summary['awake_min']) ? (int) round($summary['awake_min']) : null,
                    'bedtime' => $summary['bedtime'] ?? null,
                    'wake_time' => $summary['wake_time'] ?? null,
                    'updated_via' => 'device:summary',
                ], fn ($v) => $v !== null),
            ),
            'body' => (bool) BodyMetric::create(array_filter([
                'profile_id' => $pid,
                'taken_at' => $this->dateOf($summary['taken_at'] ?? null, $tz),
                'weight_kg' => $summary['weight_kg'] ?? null,
                'body_fat_pct' => $summary['body_fat_pct'] ?? null,
            ], fn ($v) => $v !== null)),
            // Merge (per-day MAX) rather than overwrite, so the band's steps and the phone's never
            // double-count or clobber each other — see DailyActivity::mergeDaily.
            'activity' => (bool) DailyActivity::mergeDaily(
                $pid,
                $this->dateOf($summary['date'] ?? null, $tz),
                array_filter([
                    'steps' => isset($summary['steps']) ? (int) round($summary['steps']) : null,
                    'mvpa_min' => isset($summary['mvpa_min']) ? (int) round($summary['mvpa_min']) : null,
                    'active_kcal' => isset($summary['active_kcal']) ? (int) round($summary['active_kcal']) : null,
                    'floors' => $this->floorsFor($summary),
                    'distance_km' => $summary['distance_km'] ?? null,
                    'hourly' => (isset($summary['hourly']) && is_array($summary['hourly']) && count($summary['hourly']) === 24)
                        ? array_map('intval', $summary['hourly']) : null,
                ], fn ($v) => $v !== null),
                ['source' => $connection->source, 'updated_via' => 'device:summary'],
            ),
            default => false,
        };
    }

    /** Resolve a wire date/timestamp to the device-owner's local calendar date. */
    /**
     * Floors for the day. Prefer a device-provided count (Apple Health / Gadgetbridge already
     * compute floors); otherwise, if our DIY band streamed a raw barometric altitude series, count
     * floors SERVER-side via the biosignal service (dumb-sensor philosophy). Best-effort: a biosignal
     * outage just leaves floors null rather than failing the whole summary.
     *
     * @param  array<string,mixed>  $summary
     */
    private function floorsFor(array $summary): ?int
    {
        if (isset($summary['floors'])) {
            return (int) round($summary['floors']);
        }
        $alt = $summary['altitude_m'] ?? null;
        if (! is_array($alt) || count($alt) < 3) {
            return null;
        }
        try {
            $res = app(BiosignalClient::class)->processElevation([
                'altitude_m' => array_values(array_map('floatval', $alt)),
                'sample_rate_hz' => (float) ($summary['altitude_fs'] ?? 1.0),
            ]);

            return isset($res['metrics']['floors']) ? (int) $res['metrics']['floors'] : null;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[Biosignal] floors-from-altitude failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function dateOf(?string $value, string $tz): string
    {
        if ($value === null || $value === '') {
            return now($tz)->toDateString();
        }

        // A bare "2026-06-14" is already a local date; a Z-timestamp must be localized.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }

        return CarbonImmutable::parse($value)->setTimezone($tz)->toDateString();
    }
}
