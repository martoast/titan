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

        $windowsQueued = 0;
        $summariesWritten = 0;

        // --- Shapes A/B: raw windows → MinIO + ledger + queue ---
        foreach (($payload['windows'] ?? []) as $i => $window) {
            if (! is_array($window)) {
                continue;
            }
            $windowUid = count($payload['windows'] ?? []) > 1
                ? $batchUid.'-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT)
                : $batchUid;

            $this->persistRawWindow($connection, $windowUid, $window, $tz);
            $windowsQueued++;
        }

        // --- Shape C: provider summaries → straight to canonical tables ---
        foreach (($payload['summaries'] ?? []) as $summary) {
            if (! is_array($summary)) {
                continue;
            }
            if ($this->writeSummary($connection, $summary, $tz)) {
                $summariesWritten++;
            }
        }

        $connection->forceFill(['last_sync_at' => now()])->save();

        return [
            'accepted' => true,
            'batch_uid' => $batchUid,
            'windows_queued' => $windowsQueued,
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

        // raw/{profile}/{yyyy-mm-dd}/{batch_uid}.ndjson.gz — one window per line.
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
            'activity' => (bool) DailyActivity::updateOrCreate(
                ['profile_id' => $pid, 'date' => $this->dateOf($summary['date'] ?? null, $tz)],
                array_filter([
                    'steps' => isset($summary['steps']) ? (int) round($summary['steps']) : null,
                    'mvpa_min' => isset($summary['mvpa_min']) ? (int) round($summary['mvpa_min']) : null,
                    'active_kcal' => isset($summary['active_kcal']) ? (int) round($summary['active_kcal']) : null,
                    'floors' => isset($summary['floors']) ? (int) round($summary['floors']) : null,
                    'distance_km' => $summary['distance_km'] ?? null,
                    'hourly' => (isset($summary['hourly']) && is_array($summary['hourly']) && count($summary['hourly']) === 24)
                        ? array_map('intval', $summary['hourly']) : null,
                    'source' => $connection->source,
                    'updated_via' => 'device:summary',
                ], fn ($v) => $v !== null),
            ),
            default => false,
        };
    }

    /** Resolve a wire date/timestamp to the device-owner's local calendar date. */
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
