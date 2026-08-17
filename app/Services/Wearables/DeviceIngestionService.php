<?php

namespace App\Services\Wearables;

use App\Jobs\ProcessWindowJob;
use App\Models\BodyMetric;
use App\Models\DailyActivity;
use App\Models\DeviceIngestion;
use App\Models\HrSample;
use App\Models\MotionSample;
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
        // One clock correction for the whole batch, measured before any window is stored, so the night's
        // internal spacing survives it (see batchClockOffsetSec).
        $clockOffsetSec = $this->batchClockOffsetSec($payload);
        if ($clockOffsetSec !== 0) {
            Log::warning('[Ingest] band clock is wrong — re-anchoring the batch', [
                'profile_id' => $connection->profile_id,
                'source' => $connection->source,
                'batch_uid' => $batchUid,
                'offset_sec' => $clockOffsetSec,
                'windows' => count($payload['windows'] ?? []),
            ]);

            // An un-synced clock means the band REBOOTED, and a band only reboots on power loss or a
            // reflash. Record it: it is the only evidence that tells a night which ended in a dead
            // battery from one which ended in a wake (App\Support\NightTruncation). Recorded once per
            // reconnect — a band that is still streaming on the bad clock keeps the first timestamp,
            // because that is when the power loss became visible, not each subsequent batch.
            $rebootWasKnown = $connection->last_reboot_at !== null
                && $connection->last_sync_at !== null
                && $connection->last_reboot_at->greaterThanOrEqualTo($connection->last_sync_at->subMinutes(self::REBOOT_SAME_EPISODE_MIN));

            if (! $rebootWasKnown) {
                $connection->forceFill(['last_reboot_at' => now()])->save();
                \App\Jobs\FlagTruncatedNightJob::dispatch($connection->profile_id)->afterCommit();
            }
        }

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

            $this->persistRawWindow($connection, $windowUid, $window, $tz, $clockOffsetSec);
            $windowsQueued++;
        }

        // --- Shape C: provider summaries → straight to canonical tables ---
        $recoveryLanded = false;
        foreach (($payload['summaries'] ?? []) as $summary) {
            if (! is_array($summary)) {
                continue;
            }
            if ($this->writeSummary($connection, $summary, $tz, $clockOffsetSec)) {
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
    private function persistRawWindow(WearableConnection $connection, string $windowUid, array $window, string $tz, int $clockOffsetSec = 0): DeviceIngestion
    {
        // Already ledgered? Don't rewrite the blob or re-queue (idempotency).
        if ($existing = DeviceIngestion::where('batch_uid', $windowUid)->first()) {
            return $existing;
        }

        $kind = (string) ($window['kind'] ?? 'ibi');
        $start = isset($window['start']) ? CarbonImmutable::parse($window['start']) : now()->toImmutable();
        $end = isset($window['end']) ? CarbonImmutable::parse($window['end']) : $start;
        // Batch-wide clock correction first (keeps the spacing between windows); the per-window re-anchor
        // stays as the backstop for a single-window batch and is a no-op once the offset has landed.
        if ($clockOffsetSec !== 0) {
            $start = $start->addSeconds($clockOffsetSec);
            $end = $end->addSeconds($clockOffsetSec);
        }
        [$start, $end] = $this->reanchorIfClockBad($start, $end);
        $date = $end->setTimezone($tz)->toDateString();

        // Persist window bounds in the APP timezone. Eloquent's `datetime` cast reads columns back in
        // config('app.timezone'), so a UTC Carbon stored as-is round-trips to the WRONG instant on any
        // non-UTC server (e.g. a window at 08:00Z reads back as 08:00 local). That silently shifts the
        // window out of the confirmed-seal's UTC [bed,wake] scope → the night sealed duration-only with
        // no stages. Converting here makes the read-back instant correct on every server; on a UTC
        // server this is a no-op. (Verified: 08:00Z → stored "02:00" in MX → reads back 08:00Z.)
        $appTz = config('app.timezone', 'UTC');
        $start = $start->setTimezone($appTz);
        $end = $end->setTimezone($appTz);

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

    /** The lower bound of a plausible Titan timestamp — anything older is an un-synced band clock. */
    private const CLOCK_FLOOR = '2020-01-01T00:00:00Z';
    /** Grace for a band clock running slightly ahead of the phone before we call it broken. */
    private const CLOCK_AHEAD_TOLERANCE_SEC = 7200; // 2h

    /**
     * How stale a sample may be before we call the band's clock wrong rather than its data buffered.
     *
     * The absolute CLOCK_FLOOR above is NOT enough on its own, and the reason is a guard collision that
     * cost Tester B a whole night (2026-08-17). The firmware floors its RTC on a dead-battery boot to keep
     * frames "at least plausible" — `CFG.CLOCK_FLOOR` in firmware/banglejs/titan.app.js, currently
     * 2026-01-01 — and its comment promises "the server re-anchors the offset". But that floored value
     * sits ABOVE this server's 2020 floor, so the re-anchor never fired: the firmware moved the bad
     * timestamp from a value we caught (1970) to one we ignored, and 144 windows of a real night were
     * filed as 2026-01-01 with a rock-steady 227.7-day offset. Bumping the firmware floor "to keep it
     * recent" makes that worse, not better.
     *
     * So the invariant is RELATIVE, not a date: a band cannot hold data it does not have room for. The
     * log ring is 6 × 700 KB ≈ 4.1 MB and evicts the oldest segment (~16 h of overnight PPG), so no
     * genuine store-and-forward replay is anywhere near this old. 30 days leaves that legitimate case
     * two orders of magnitude of headroom while still catching a floored/free-running clock by ~7×.
     */
    private const CLOCK_STALE_MAX_SEC = 30 * 86400;

    /**
     * A band that reboots keeps streaming on its bad clock for as long as it stays un-synced — Tester B's ran
     * seven hours. Every one of those batches trips the clock correction, but they are ONE power loss, and
     * `last_reboot_at` must keep pointing at when it became visible rather than sliding forward with each
     * batch (a sliding value would eventually overtake the very night it is evidence about). A gap larger
     * than this between syncs means the band went away and came back — a genuinely new episode.
     */
    private const REBOOT_SAME_EPISODE_MIN = 180;
    /** Cap for a re-anchored window's span so a corrupt duration can't invent a huge session. */
    private const REANCHOR_MAX_SPAN_SEC = 21600; // 6h
    /** Span cap when re-anchoring a SLEEP marker — a real night outruns 6h; mirrors SealNightJob::MAX_SESSION_MIN. */
    private const REANCHOR_MAX_SLEEP_SPAN_SEC = 57600; // 16h

    /**
     * Guard against a broken band clock. After a dead-battery reboot or a fresh reflash the band's RTC
     * is un-synced until the phone's `C2:` time-sync lands, so any frame recorded before that carries a
     * ~1970 epoch (or, if the clock ran ahead, a future time). Left as-is those timestamps MISFILE the
     * session — buried in 1970 (so an older correctly-clocked run shows as "newest"), parked at the top
     * of the list, or worse, SPLIT one real run into two rows (a 1970 cluster + a correctly-clocked
     * cluster > SESSION_GAP_MINUTES "apart"). A real Titan window is always a recent, non-future time;
     * anything outside that band is a bad clock, so we re-anchor it to the upload time (the batch arrives
     * right after the frames), preserving the window's OWN duration so the run keeps its true length +
     * route. Correctly-clocked live runs and genuinely-old offline runs (a real 2024+ time, not in the
     * future) fall inside the band and are never touched.
     *
     * @return array{0:CarbonImmutable,1:CarbonImmutable}
     */
    private function reanchorIfClockBad(CarbonImmutable $start, CarbonImmutable $end, int $maxSpanSec = self::REANCHOR_MAX_SPAN_SEC): array
    {
        $now = now()->toImmutable();

        if (! $this->clockIsBad($start, $end, $now)) {
            return [$start, $end];
        }

        // Keep the real span (clamped), and hang it off the upload time.
        $span = $start->lessThanOrEqualTo($end) ? abs($start->diffInSeconds($end)) : 0;
        $span = min($span, $maxSpanSec);

        return [$now->subSeconds($span), $now];
    }

    /**
     * Is this [start,end] off the band's own clock untrustworthy? Three ways: before the absolute floor
     * (a ~1970 un-synced RTC), in the future beyond the ahead-tolerance, or staler than any ring buffer
     * could hold (a floored / free-running clock — see CLOCK_STALE_MAX_SEC).
     */
    private function clockIsBad(CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $now): bool
    {
        $floor = CarbonImmutable::parse(self::CLOCK_FLOOR);
        $ahead = $now->addSeconds(self::CLOCK_AHEAD_TOLERANCE_SEC);
        $stale = $now->subSeconds(self::CLOCK_STALE_MAX_SEC);

        return $start->lessThan($floor) || $start->greaterThan($ahead)
            || $end->lessThan($floor) || $end->greaterThan($ahead)
            || $end->lessThan($stale);
    }

    /**
     * The seconds to ADD to every timestamp in this batch to put it back on real time, or 0 if the band's
     * clock is trustworthy.
     *
     * Why a batch-wide OFFSET rather than re-anchoring each window on its own: a per-window re-anchor
     * hangs each one off `now()`, which is right for a single marker but collapses a multi-window batch
     * onto one instant — it preserves each window's length and destroys the spacing BETWEEN them, which
     * is the only thing a night's hypnogram is built from. A single offset preserves relative timing
     * exactly, for a live stream and a buffered dump alike, and is precisely what the firmware's own
     * CLOCK_FLOOR comment already assumes the server does.
     *
     * The offset is taken from the NEWEST timestamp in the batch, which was recorded moments before the
     * upload — so `now - newest` is the clock error. On Tester B's night that estimator was stable to ±1 s
     * across 128 consecutive windows.
     *
     * It reads the T5/T10 TREND points as well as the raw windows, and both are corrected by the result.
     * Those points carry the band's epoch straight through with no clock guard of their own, and they are
     * the FIRST thing a rebooted band dumps (it banks them to the ring while offline) — so on Tester B's night
     * they landed 1,240 samples on 1970/Jan-1 dates even for the hours no PPG window covered.
     *
     * @param  array<string,mixed>  $payload
     */
    private function batchClockOffsetSec(array $payload): int
    {
        $now = now()->toImmutable();
        $newest = null;
        $consider = function (?CarbonImmutable $t) use (&$newest): void {
            if ($t !== null && ($newest === null || $t->greaterThan($newest))) {
                $newest = $t;
            }
        };

        foreach (($payload['windows'] ?? []) as $window) {
            if (! is_array($window)) {
                continue;
            }
            $raw = $window['end'] ?? $window['start'] ?? null;
            if ($raw === null) {
                continue;
            }
            try {
                $consider(CarbonImmutable::parse($raw));
            } catch (\Throwable) {
                // unparseable — WindowSanity rejects it downstream; it must not anchor the batch
            }
        }

        foreach (($payload['summaries'] ?? []) as $summary) {
            if (! is_array($summary) || ! is_array($summary['samples'] ?? null)) {
                continue;
            }
            foreach ($summary['samples'] as $s) {
                $t = is_array($s) ? (int) ($s['t'] ?? 0) : 0;
                if ($t > 0) {
                    $consider(CarbonImmutable::createFromTimestamp($t, 'UTC'));
                }
            }
        }

        if ($newest === null || ! $this->clockIsBad($newest, $newest, $now)) {
            return 0;
        }

        return (int) $newest->diffInSeconds($now, false);
    }

    /**
     * Shape-C summary → canonical table. Objective fields only; subjective ratings stay
     * user-owned. updateOrCreate keyed on the natural key (profile + date) so re-exports
     * (Apple Health re-syncs the same day repeatedly) correct in place.
     *
     * @param  array<string,mixed>  $summary
     */
    /**
     * A user-confirmed sleep marker from the band (the "I'm awake" double-click). Seal that night with
     * the user's real boundaries and — because they explicitly ended it — fire the morning sleep summary.
     */
    private function triggerSleepSummary(WearableConnection $connection, array $summary, string $tz): bool
    {
        if (empty($summary['confirmed'])) {
            return false;
        }
        // The marker's [bedtime, wake] epochs come off the band's OWN clock, and — like raw windows —
        // a dead-battery reboot leaves that clock un-synced (~1970) until a C2 time-sync lands. Raw
        // windows are re-anchored on ingest, but these epochs used to pass through RAW: the confirmed
        // seal then scoped itself to a 1970 window, found no windows there, and wrote a phantom
        // 1970-dated marker-only row while the real night waited hours for the slow auto pass (bit
        // Tester B four mornings running, 2026-07-23..26). Re-anchor the pair the same way: keep the
        // user's declared span (a night can be long — cap 16h, mirroring SealNightJob's session max)
        // and hang it off the upload time, since the marker uploads moments after the double-click.
        $bed = (! empty($summary['bedtime']) && is_numeric($summary['bedtime'])) ? (int) $summary['bedtime'] : null;
        $wake = (! empty($summary['wake']) && is_numeric($summary['wake'])) ? (int) $summary['wake'] : null;
        if ($wake !== null) {
            [$bedDt, $wakeDt] = $this->reanchorIfClockBad(
                CarbonImmutable::createFromTimestamp($bed ?? $wake, 'UTC'),
                CarbonImmutable::createFromTimestamp($wake, 'UTC'),
                self::REANCHOR_MAX_SLEEP_SPAN_SEC,
            );
            $bed = $bed !== null ? $bedDt->getTimestamp() : null;
            $wake = $wakeDt->getTimestamp();
        }
        // Key the night the SAME way SealNightJob groups windows — by the local date the overnight
        // windows END (the wake date). Deriving it from bedtime breaks for every sleep that crosses
        // midnight (bed 23:00 → wake 07:00): the bedtime date never matches the window_end grouping,
        // so the user's confirmed summary never fires. Use the latest unsealed window's end date.
        $latestEnd = DeviceIngestion::query()
            ->where('profile_id', $connection->profile_id)
            ->whereIn('kind', ['ibi', 'ppg_raw', 'sleep'])
            ->where('status', '!=', DeviceIngestion::STATUS_SEALED)
            ->whereNotNull('window_end')
            ->max('window_end');
        if ($latestEnd !== null) {
            $night = CarbonImmutable::parse($latestEnd)->setTimezone($tz)->toDateString();
        } elseif ($wake !== null) {
            // No unsealed windows (already sealed by the cron, or the night's raw upload failed) —
            // the user still explicitly marked awake, so key the night off the marker's own wake
            // timestamp instead of silently dropping their confirmation.
            $night = CarbonImmutable::createFromTimestamp($wake, 'UTC')->setTimezone($tz)->toDateString();
        } else {
            return false;
        }
        // Pass the user's REAL session bounds through so the seal scopes to [bedtime, wake] — not the
        // whole calendar date — and can guarantee a bounded nap row even when the PPG windows are thin
        // or never arrived. Ignoring these is what buried a nap under a whole-day "Awake 100%" phantom.
        \App\Jobs\SealNightJob::dispatch($connection->profile_id, $night, true, $bed, $wake)->afterCommit();

        return true;
    }

    /**
     * A watch-confirmed WORKOUT end marker (T W): the user finished a run/lift on the band, which carries
     * the real [start, end, kind, manual]. Dispatch a seal SCOPED to those bounds so it lands even when
     * the accel windows are thin, arrived late, or never arrived (out of BLE range / airplane) — mirrors
     * {@see triggerSleepSummary}. The watch's chosen kind stays authoritative (run vs lift).
     */
    private function triggerWorkoutSummary(WearableConnection $connection, array $summary): bool
    {
        if (empty($summary['confirmed'])) {
            return false;
        }
        $start = (isset($summary['start']) && is_numeric($summary['start'])) ? (int) $summary['start'] : null;
        $end = (isset($summary['end']) && is_numeric($summary['end'])) ? (int) $summary['end'] : null;
        if ($start === null || $end === null || $end <= $start) {
            return false;
        }
        // Same un-synced-RTC guard as the sleep marker: a 1970-stamped envelope scopes the workout
        // seal to nowhere and writes a phantom 1970-dated session. Keep the real span, anchor at upload.
        [$startDt, $endDt] = $this->reanchorIfClockBad(
            CarbonImmutable::createFromTimestamp($start, 'UTC'),
            CarbonImmutable::createFromTimestamp($end, 'UTC'),
        );
        [$start, $end] = [$startDt->getTimestamp(), $endDt->getTimestamp()];
        // The workout's ACTIVITY kind ('run'/'strength'/…) travels in its own field — `kind` on the
        // summary is the summary TYPE ('workout_session') and is consumed by writeSummary's router.
        $kind = (! empty($summary['activity_kind']) && is_string($summary['activity_kind'])) ? $summary['activity_kind'] : null;
        $manual = ! empty($summary['manual']);
        \App\Jobs\SealActivityJob::dispatch($connection->profile_id, true, $start, $end, $kind, $manual)->afterCommit();

        return true;
    }

    private function writeSummary(WearableConnection $connection, array $summary, string $tz, int $clockOffsetSec = 0): bool
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
            // The band's "I'm awake" marker (T9): seal that night and fire the coach's sleep summary,
            // BECAUSE the user confirmed it. (No marker → the cron still computes the data, silently.)
            'sleep_session' => $this->triggerSleepSummary($connection, $summary, $tz),
            // The band's workout END marker (TW): the user's explicit [start,end,kind]. Seal that workout
            // SCOPED to the envelope — guaranteed even when the accel windows are thin/late/offline.
            'workout_session' => $this->triggerWorkoutSummary($connection, $summary),
            // The 24/7 HR trend (≈1 point/minute) → time-series rows for the all-day HR graph.
            'hr_trend' => $this->writeHrTrend($connection, $summary, $tz, $clockOffsetSec),
            // The continuous overnight motion channel (≈1 point/30s, T10) → per-epoch rows the night
            // seal prefers over the sparse HRV-burst proxy for the sleep-timeline movement strip.
            'motion_trend' => $this->writeMotionTrend($connection, $summary, $tz, $clockOffsetSec),
            'body' => (bool) BodyMetric::create(array_filter([
                'profile_id' => $pid,
                'taken_at' => $this->dateOf($summary['taken_at'] ?? null, $tz),
                'weight_kg' => $summary['weight_kg'] ?? null,
                'body_fat_pct' => $summary['body_fat_pct'] ?? null,
            ], fn ($v) => $v !== null)),
            // The band is the PRIMARY source: mergeDaily takes these as authoritative for the day and
            // won't let an Apple-Health write override them (band-primary — supersedes the old per-day
            // MAX merge that let the phone win). See DailyActivity::mergeDaily.
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

    /**
     * Persist a batch of HR trend points (the 24/7 graph). Each point is {t: epoch-seconds, bpm,
     * conf?}; we store recorded_at as the owner's local wall-clock so day-grouping matches the rest
     * of the app. insertOrIgnore dedups on the (profile_id, recorded_at) unique key, so re-sending
     * the same window (a retry, or overlap from the offline ring) never double-counts.
     *
     * @param  array<string,mixed>  $summary
     */
    private function writeHrTrend(WearableConnection $connection, array $summary, string $tz, int $clockOffsetSec = 0): bool
    {
        $samples = $summary['samples'] ?? null;
        if (! is_array($samples) || count($samples) === 0) {
            return false;
        }

        $now = now();
        $rows = [];
        foreach ($samples as $s) {
            if (! is_array($s)) {
                continue;
            }
            $t = (int) ($s['t'] ?? 0);
            $bpm = (int) ($s['bpm'] ?? 0);
            if ($t <= 0 || $bpm <= 0 || $bpm > 255) {
                continue;   // garbage point — skip, don't poison the series
            }
            $t += $clockOffsetSec;   // batch-wide band-clock correction (see batchClockOffsetSec)
            $conf = isset($s['conf']) ? max(0, min(100, (int) $s['conf'])) : null;
            $rows[] = [
                'profile_id' => $connection->profile_id,
                'recorded_at' => CarbonImmutable::createFromTimestamp($t, 'UTC')->setTimezone($tz)->toDateTimeString(),
                'bpm' => $bpm,
                'confidence' => $conf,
                'source' => $connection->source,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        if (count($rows) === 0) {
            return false;
        }

        HrSample::insertOrIgnore($rows);

        return true;
    }

    /**
     * Persist a batch of continuous-motion points (the overnight movement strip). Each point is
     * {t: epoch-seconds, motion: milli-g EMA}.
     *
     * recorded_at is stored in the APP timezone — NOT the connection tz — deliberately: the night seal
     * cross-references these rows against `device_ingestions.window_start`, which is ALSO stored in app
     * tz (see the window-bounds block above), by mapping both onto one `$t0`-anchored epoch grid. If we
     * stored recorded_at in the phone's tz (as hr_samples does — it's read back through the app-tz
     * `datetime` cast) then on any server where app tz ≠ the user's tz, the seal's app-tz query bounds
     * and epoch math would land on the wrong instants and the whole night's movement strip would be
     * mis-timed or dropped. Same-tz round-trip, opposite-side of the exact trap the window block fixes.
     * insertOrIgnore dedups on (profile_id, recorded_at) so a re-sent window never double-inserts.
     *
     * @param  array<string,mixed>  $summary
     */
    private function writeMotionTrend(WearableConnection $connection, array $summary, string $tz, int $clockOffsetSec = 0): bool
    {
        $samples = $summary['samples'] ?? null;
        if (! is_array($samples) || count($samples) === 0) {
            return false;
        }

        $appTz = config('app.timezone', 'UTC');
        $now = now();
        $rows = [];
        foreach ($samples as $s) {
            if (! is_array($s)) {
                continue;
            }
            $t = (int) ($s['t'] ?? 0);
            if ($t <= 0) {
                continue;   // unstamped point — skip, don't poison the series
            }
            $t += $clockOffsetSec;   // batch-wide band-clock correction (see batchClockOffsetSec)
            $motion = (int) ($s['motion'] ?? 0);
            $motion = max(0, min(65535, $motion));   // clamp to the wire's uint16 range (column is smallint)
            $rows[] = [
                'profile_id' => $connection->profile_id,
                'recorded_at' => CarbonImmutable::createFromTimestamp($t, 'UTC')->setTimezone($appTz)->toDateTimeString(),
                'motion' => $motion,
                'source' => $connection->source,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        if (count($rows) === 0) {
            return false;
        }

        MotionSample::insertOrIgnore($rows);

        return true;
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
