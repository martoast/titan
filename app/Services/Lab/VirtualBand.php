<?php

namespace App\Services\Lab;

use App\Models\WearableConnection;
use App\Services\Simulator\BiosignalSimulator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * SLEEP LAB · VirtualBand — the firmware's method actor (spec §2.2).
 *
 * This is the ONE wire renderer. It WRAPS {@see BiosignalSimulator} (the pure signal engine — no second
 * simulator) and owns the two things the simulator deliberately does not: {@see renderWindow()} (a stage +
 * a span → the exact wire JSON the real band emits) and {@see postSigned()} (the HMAC-signed POST to the
 * REAL /api/devices/ingest). {@see streamNight()} turns a whole {@see NightScript} into the dozens of sparse
 * duty-cycle windows a real night arrives as, and {@see emitMarker()} fires the T9 "I'm awake" marker.
 *
 * SimulateNight and routes/titan/simulator.php were each an inline copy of the render-and-sign loop; both
 * now delegate here, so there is one wire contract, not three. If the firmware and the VirtualBand ever
 * disagree about the wire format, that is a P0 in one of them.
 */
class VirtualBand
{
    public function __construct(
        private BiosignalSimulator $sim,
        private WearableConnection $device,
        private string $secret,
        private ?SleepCalibration $calibration = null,
        private ?string $ingestUrl = null,
    ) {
        $this->ingestUrl ??= rtrim((string) config('app.url', 'http://localhost'), '/').'/api/devices/ingest';
    }

    public function simulator(): BiosignalSimulator
    {
        return $this->sim;
    }

    public function device(): WearableConnection
    {
        return $this->device;
    }

    public function profileId(): int
    {
        return (int) $this->device->profile_id;
    }

    /**
     * Render ONE duty-cycle window for a stage across [$start, +$seconds] into the exact wire shape.
     * The physiology is generated FROM the stage: a calibrated per-stage config (deep = low motion + low HR
     * + high HRV; REM = still + variable HR; wake = movement) drives the shared signal engine. `ibi` carries
     * IBI + accel + confidence; `ppg_raw` carries the raw PPG the server peak-detects (accel omitted, exactly
     * like the offline T2 log).
     *
     * @return array<string,mixed>
     */
    public function renderWindow(string $stage, CarbonImmutable $start, int $seconds, string $wireKind = 'ibi', int $ppgHz = 25): array
    {
        // Sample from the calibrated per-stage distribution for a known LAB token; otherwise (or with no
        // calibration) fall back to the STATES preset — so SimulateNight's behaviour is unchanged.
        $cfg = ($this->calibration && isset(NightScript::STAGE_STATE[$stage]))
            ? $this->calibration->stageCfg($stage)
            : $this->cfgFromStates($stage);

        return $this->wireWindow($this->sim->generateWindowFromCfg($cfg, $seconds), $start, $seconds, $wireKind, $stage, $ppgHz);
    }

    /**
     * Format an already-generated signal ({ibi_ms, accel_counts}) into the exact wire window. THIS is the
     * one wire renderer both the LAB and {@see \App\Console\Commands\SimulateNight} go through — SimulateNight
     * generates the signal (it needs the raw IBI for its whole-night HRV summary) and hands it here for the
     * wire shape, instead of its old inline copy.
     *
     * @param  array{ibi_ms:array<int,int>,accel_counts:array<int,int>}  $signal
     * @return array<string,mixed>
     */
    public function wireWindow(array $signal, CarbonImmutable $start, int $seconds, string $wireKind, string $stage, int $ppgHz = 25): array
    {
        $end = $start->addSeconds($seconds);

        if ($wireKind === 'ppg_raw') {
            // The real overnight shape: raw PPG (server peak-detects), accel OMITTED like the offline T2 log.
            return [
                'kind' => 'ppg_raw',
                'start' => $start->toIso8601ZuluString(),
                'end' => $end->toIso8601ZuluString(),
                'sample_rate_hz' => $ppgHz,
                'ppg' => BiosignalSimulator::ppgFromIbi($signal['ibi_ms'], $ppgHz),
                'src' => 'banglejs2',
            ];
        }

        return [
            'kind' => 'ibi',
            'start' => $start->toIso8601ZuluString(),
            'end' => $end->toIso8601ZuluString(),
            'ibi_ms' => $signal['ibi_ms'],
            'accel_counts' => $signal['accel_counts'],
            'confidence' => $this->isAsleepStage($stage) ? 0.95 : 0.7,
        ];
    }

    /** A LAB token (deep/light/rem/wake) or raw STATES key (deep/sleep/rem/rest/…) → is it asleep? */
    private function isAsleepStage(string $stage): bool
    {
        $statesKey = array_key_exists($stage, BiosignalSimulator::STATES)
            ? $stage
            : (NightScript::STAGE_STATE[$stage] ?? 'rest');

        return in_array($statesKey, ['deep', 'sleep', 'rem'], true);
    }

    /** A LAB token or resolved STATES key → its generator config (the un-calibrated / SimulateNight path). */
    private function cfgFromStates(string $stage): array
    {
        $statesKey = array_key_exists($stage, BiosignalSimulator::STATES)
            ? $stage
            : (NightScript::STAGE_STATE[$stage] ?? 'rest');
        $s = BiosignalSimulator::STATES[$statesKey] ?? BiosignalSimulator::STATES['rest'];

        return ['hr' => $s['hr'], 'hr_sd' => $s['hr_sd'], 'rmssd' => $s['rmssd'], 'motion' => $s['motion']];
    }

    /**
     * Render a whole NightScript into the sparse duty-cycle windows a real night arrives as, and stream them
     * to the ingest API in signed batches. Each stage block is sampled as ~30 s bursts every `period_sec`
     * (the band duty-cycles to save battery), placed at their REAL epochs across bed→wake — NEVER
     * concatenated. Charge gaps and BLE-drop windows are honoured (a charge gap emits nothing; a BLE drop's
     * windows are withheld here and replayed by {@see replayBuffered()} with original sample timestamps but
     * recent ingest time — store-and-forward). Clock drift shifts every sample timestamp.
     *
     * @param  array{now?:CarbonImmutable,batch_size?:int}  $opts
     * @return array{windows:int,batches:int,buffered:array<int,array<string,mixed>>,delivered:int,summary:array<string,mixed>}
     */
    public function streamNight(NightScript $script, array $opts = []): array
    {
        $batchSize = $opts['batch_size'] ?? 60;
        $rendered = $this->renderNight($script);
        $live = $rendered['live'];

        $batches = 0;
        $delivered = 0;
        foreach (array_chunk($live, $batchSize) as $chunk) {
            $batches++;
            $this->postSigned(['windows' => array_values($chunk)], throwOnFailure: true);
            $delivered++;
        }

        return [
            'windows' => count($live),
            'batches' => $batches,
            'buffered' => $rendered['buffered'],
            'delivered' => $delivered,
            'summary' => $rendered['summary'],
        ];
    }

    /**
     * PURE render of a NightScript into its wire windows (no HTTP) — the deterministic core of
     * {@see streamNight()}, split out so it can be exercised in a unit test. Returns the windows that stream
     * live, the windows a BLE drop buffered for later replay, and the whole-night HRV summary.
     *
     * @return array{live:array<int,array<string,mixed>>,buffered:array<int,array{window:array<string,mixed>,skew:int}>,summary:array<string,mixed>}
     */
    public function renderNight(NightScript $script): array
    {
        $burst = (int) $script->dutyCycle['burst_sec'];
        $period = (int) $script->dutyCycle['period_sec'];
        $drift = $script->clockDriftSec;

        // Absolute [bed, wake] instants of each charge gap / BLE drop (minutes from bed → UTC epochs).
        $chargeGaps = array_map(fn ($g) => [
            'from' => $script->bedAt->addMinutes($g['start_min'])->timestamp,
            'to' => $script->bedAt->addMinutes($g['start_min'] + $g['dur_min'])->timestamp,
        ], $script->chargeGaps);
        $bleDrops = array_map(fn ($d) => [
            'from' => $script->bedAt->addMinutes($d['start_min'])->timestamp,
            'to' => $script->bedAt->addMinutes($d['start_min'] + $d['dur_min'])->timestamp,
            'skew' => $d['replay_skew_sec'] ?? 0,
        ], $script->bleDrops);

        $live = [];       // windows that stream in real time
        $buffered = [];   // windows withheld by a BLE drop, replayed later
        $allIbi = [];
        $rhrMedians = [];

        $cursorMin = 0;
        foreach ($script->blocks as $block) {
            $blockStart = $cursorMin;
            $blockEnd = $cursorMin + $block['minutes'];
            // Walk the block in duty-cycle steps, emitting a burst at each period.
            for ($m = $blockStart; $m < $blockEnd; $m += $period / 60.0) {
                $burstSec = (int) min($burst, ($blockEnd - $m) * 60);
                if ($burstSec < 5) {
                    break;
                }
                $start = $script->bedAt->addSeconds((int) round($m * 60) + $drift);
                $sampleTs = $start->timestamp;

                if ($this->inAnyGap($sampleTs, $chargeGaps)) {
                    continue; // band was off charging — a real NODATA hole, emit nothing
                }

                $window = $this->renderWindow($block['stage'], $start, $burstSec, $script->wireKind);
                $this->collectHrv($window, $block['stage'], $allIbi, $rhrMedians);

                $drop = $this->gapFor($sampleTs, $bleDrops);
                if ($drop !== null) {
                    $buffered[] = ['window' => $window, 'skew' => $drop['skew']];
                } else {
                    $live[] = $window;
                }
            }
            $cursorMin = $blockEnd;
        }

        return [
            'live' => $live,
            'buffered' => $buffered,
            'summary' => $this->summariseHrv($allIbi, $rhrMedians),
        ];
    }

    /**
     * Replay windows a BLE drop buffered on-device: they arrive late (now) with their ORIGINAL sample
     * timestamps, so created_at (ingest) skews hours past window_end (sample) — the store-and-forward signal
     * the seal's backlog-drain guard keys on. (Phase-2 scars use this; wired now so the vocabulary is real.)
     *
     * @param  array<int,array{window:array<string,mixed>,skew:int}>  $buffered
     */
    public function replayBuffered(array $buffered, int $batchSize = 60): int
    {
        $windows = array_map(fn ($b) => $b['window'], $buffered);
        $delivered = 0;
        foreach (array_chunk($windows, $batchSize) as $chunk) {
            $this->postSigned(['windows' => array_values($chunk)], throwOnFailure: true);
            $delivered++;
        }

        return $delivered;
    }

    /**
     * Emit the T9 "I'm awake" confirmed sleep marker (Shape-C) per the script's marker timing. `live` fires
     * at true wake; `delayed`/`replayed` fire late; `absent` emits nothing (the cron auto-seals). The bedtime
     * / wake epochs carry the script's clock drift so they stay consistent with the streamed windows.
     */
    public function emitMarker(NightScript $script): bool
    {
        if ($script->markerTiming === NightScript::MARKER_ABSENT) {
            return false;
        }
        $bed = $script->bedAt->timestamp + $script->clockDriftSec;
        $wake = $script->wakeAt()->timestamp + $script->clockDriftSec;

        return $this->postSigned(['summaries' => [[
            'kind' => 'sleep_session',
            'confirmed' => true,
            'bedtime' => $bed,
            'wake' => $wake,
        ]]], throwOnFailure: true);
    }

    /**
     * HMAC-sign a batch and POST it to the REAL ingest API. Signature scheme is byte-identical to
     * TerraClient::verifyDeviceSignature / the firmware bridge: the HMAC key is sha256(secret) (== the stored
     * device_token_hash), over "<ts>.<rawBody>". Adds batch_uid + device_id + timezone.
     *
     * Returns whether the batch was accepted (2xx). Callers that tolerate an unreachable API (SimulateNight's
     * "not delivered" line, the /simulator/night route's delivery bools) get the plain bool.
     *
     * With $throwOnFailure=true (the LAB night-streaming path) a transport error or non-2xx THROWS immediately
     * WITH THE STATUS, so a LAB that can't reach the ingest API dies in seconds instead of the runner spending
     * minutes "waiting for seal" on data that never landed.
     *
     * @param  array<string,mixed>  $payload  {windows?, summaries?}
     *
     * @throws \RuntimeException when $throwOnFailure and the POST fails to connect or returns non-2xx
     */
    public function postSigned(array $payload, bool $throwOnFailure = false): bool
    {
        $payload = array_merge([
            'batch_uid' => (string) Str::ulid(),
            'device_id' => $this->device->device_id,
            'timezone' => $this->device->effectiveTimezone(),
        ], $payload);

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $ts = (string) time();
        $sig = 't='.$ts.',v1='.hash_hmac('sha256', $ts.'.'.$body, hash('sha256', $this->secret));

        try {
            $res = Http::withHeaders([
                'X-Device-Id' => $this->device->device_id,
                'X-Titan-Signature' => $sig,
                'Content-Type' => 'application/json',
            ])->timeout(30)->withBody($body, 'application/json')->post($this->ingestUrl);
        } catch (\Throwable $e) {
            if ($throwOnFailure) {
                throw new \RuntimeException("ingest POST to {$this->ingestUrl} failed to connect: {$e->getMessage()}", 0, $e);
            }

            return false;
        }

        if (! $res->successful()) {
            if ($throwOnFailure) {
                throw new \RuntimeException(sprintf('ingest POST to %s rejected: HTTP %d %s',
                    $this->ingestUrl, $res->status(), Str::limit($res->body(), 160)));
            }

            return false;
        }

        return true;
    }

    private function inAnyGap(int $ts, array $gaps): bool
    {
        foreach ($gaps as $g) {
            if ($ts >= $g['from'] && $ts < $g['to']) {
                return true;
            }
        }

        return false;
    }

    private function gapFor(int $ts, array $gaps): ?array
    {
        foreach ($gaps as $g) {
            if ($ts >= $g['from'] && $ts < $g['to']) {
                return $g;
            }
        }

        return null;
    }

    /** Accumulate whole-night IBI + per-window RHR medians for the recovery summary. */
    private function collectHrv(array $window, string $stage, array &$allIbi, array &$rhrMedians): void
    {
        $ibi = $window['ibi_ms'] ?? null;
        if (! is_array($ibi)) {
            return; // ppg_raw carries no pre-detected IBI
        }
        foreach ($ibi as $v) {
            $allIbi[] = $v;
        }
        if (in_array($stage, NightScript::ASLEEP, true) && count($ibi) > 5) {
            $rhrMedians[] = BiosignalSimulator::meanHr($ibi);
        }
    }

    /** @return array<string,mixed> */
    private function summariseHrv(array $allIbi, array $rhrMedians): array
    {
        return [
            'rmssd_ms' => BiosignalSimulator::rmssd($allIbi),
            'resting_hr' => $rhrMedians ? min($rhrMedians) : BiosignalSimulator::meanHr($allIbi),
            'beats' => count($allIbi),
        ];
    }
}
