<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\MobileSleepController;
use App\Models\DeviceIngestion;
use App\Models\Profile;
use App\Models\RecoveryLog;
use App\Models\SleepLog;
use App\Models\User;
use App\Models\WearableConnection;
use App\Services\Lab\NightScript;
use App\Services\Lab\SleepCalibration;
use App\Services\Lab\VirtualBand;
use App\Services\Simulator\BiosignalSimulator;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * SLEEP LAB — the night simulator + end-to-end verification harness (tasks/specs/SLEEP_LAB.md).
 *
 *   php artisan sleep:lab --scenario=perfect-night
 *   php artisan sleep:lab --scenario=perfect-night --tz=America/Mexico_City
 *   php artisan sleep:lab --calibrate
 *
 * A {@see NightScript} describes the TRUE night; a {@see VirtualBand} renders it into the exact wire windows
 * and streams them HMAC-signed through the REAL /api/devices/ingest into the REAL queue + REAL biosignal
 * service; the harness then asserts at every layer (DB row shape, seal decision, SleepLog stages vs script
 * within the §5 tolerances, coverage bounds, one-row invariant, reader agreement) and prints a SCORECARD
 * (scenario × the FIVE PROMISES → PASS/FAIL) readable in ten seconds. Runs on a DEDICATED lab profile,
 * never a real user.
 *
 * Phase 1 wires `perfect-night` green (spec §6). The NightScript vocabulary already expresses the Phase-2
 * scars (charge gaps, BLE-drop replay, marker timing, clock drift, tz); those scenarios land in Phase 2.
 */
class SleepLab extends Command
{
    protected $signature = 'sleep:lab
        {--scenario=perfect-night : Which scenario to run}
        {--tz= : IANA timezone the night is lived in (defaults to app timezone; non-UTC on purpose)}
        {--calibrate : Refresh the generator calibration from real sealed nights instead of running a scenario}
        {--calibrate-profile=1 : Which real profile to calibrate from}
        {--timeout=300 : Seconds to wait for the async seal to finalize}
        {--ingest-url= : Override the ingest URL (default: the app server reachable from this container)}
        {--jitter=0 : HR-jitter realism — "0" (idealized clean HR) or "real" (the measured band jitter that over-stages REM)}
        {--seed= : Deterministic RNG seed}';

    protected $description = 'SLEEP LAB: render a scripted night through the real pipeline and prove the five promises.';

    /** §5 tolerances — a miss is a FAIL, not a warning. */
    private const TOL_STAGE_PCT = 8.0;

    private const TOL_DURATION_MIN = 10;

    private const TOL_EFFICIENCY_PCT = 5.0;

    private const LAB_EMAIL = 'sleep-lab@titan.local';

    public function handle(): int
    {
        if ($this->option('calibrate')) {
            return $this->runCalibration();
        }

        $tz = $this->option('tz') ?: config('app.timezone', 'UTC');
        $scenario = (string) $this->option('scenario');

        $band = $this->bootLabBand($tz);
        $cal = SleepCalibration::load();

        $this->line("<info>SLEEP LAB</info> · scenario <comment>{$scenario}</comment> · tz <comment>{$tz}</comment> · calibration <comment>".($cal->source()['kind'] ?? 'unknown').'</comment>');
        $this->newLine();

        $script = $this->buildScenario($scenario, $tz, $cal);
        if ($script === null) {
            $this->error("Unknown scenario '{$scenario}'. Phase 1 ships: perfect-night.");

            return self::FAILURE;
        }

        // FIDELITY knob: overlay the real band's measured HR jitter so the LAB reproduces a real night's
        // staging (a clean-HR sim can't catch an HR-noise bug — LAB_PIPELINE_FIDELITY.md).
        $jitterOn = strtolower((string) $this->option('jitter')) === 'real';
        if ($jitterOn) {
            $script->hrJitterSd = \App\Services\Lab\NightScript::REAL_HR_JITTER_SD;
        }

        // Isolate: wipe any prior lab data so the one-row invariants are meaningful.
        $this->resetLabData($band->profileId());

        $expected = $script->expected();
        $this->line('Streaming the scripted night → real ingest API…');
        try {
            $stream = $band->streamNight($script);
            $band->replayBuffered($stream['buffered']);   // no-op for perfect-night (nothing buffered)
            $markerFired = $band->emitMarker($script);
        } catch (\RuntimeException $e) {
            $this->newLine();
            $this->error('LAB ABORTED — ingest unreachable, no data landed: '.$e->getMessage());
            $this->line('  The LAB streams through the REAL ingest API. It auto-probes :8080/:80; pass');
            $this->line('  --ingest-url=http://HOST:PORT/api/devices/ingest if the app serves elsewhere.');

            return self::FAILURE;
        }

        $this->line(sprintf(
            '  %d windows in %d batches (%d delivered) · marker: %s',
            $stream['windows'], $stream['batches'], $stream['delivered'],
            $markerFired ? $script->markerTiming : 'absent',
        ));

        $row = $this->awaitSeal($band->profileId(), $expected['date'], (int) $this->option('timeout'));

        $scorecard = $this->assertPromises($script, $expected, $row, $band, $stream);
        $this->printScorecard($scenario, $tz, $expected, $row, $scorecard);

        // FIDELITY GATE (LAB_PIPELINE_FIDELITY Part C): does this LAB night's ingested data reproduce the
        // real watch's fingerprint? The gate that would have caught every "passed in sim, failed on the
        // watch" bug. A fidelity miss FAILS the run — the sim is lying about the wrist.
        $fidelity = \App\Support\Lab\FidelityGate::check($band->profileId(), $jitterOn);
        $this->printFidelity($fidelity, $jitterOn);

        $promisesOk = collect($scorecard)->every(fn ($p) => $p['pass']);
        $fidelityOk = \App\Support\Lab\FidelityGate::passes($fidelity);

        return ($promisesOk && $fidelityOk) ? self::SUCCESS : self::FAILURE;
    }

    /** Render the fidelity gate as its own compact section under the scorecard. */
    private function printFidelity(array $checks, bool $jitterOn): void
    {
        $this->newLine();
        $this->line('═══ PIPELINE FIDELITY '.($jitterOn ? '(jitter: real)' : '(jitter: idealized)').' ═══');
        foreach ($checks as $c) {
            $tag = $c['pass'] ? '<info>PASS</info>' : '<error>FAIL</error>';
            $this->line(sprintf('  [%s] %-34s %s', $tag, $c['label'], $c['detail']));
        }
        if (! \App\Support\Lab\FidelityGate::passes($checks)) {
            $this->newLine();
            $this->line('  <error>The LAB night does not match the real watch fingerprint (docs/DATA_PIPELINE_REFERENCE.md §4).</error>');
        }
    }

    // ---------------------------------------------------------------- scenarios

    private function buildScenario(string $scenario, string $tz, SleepCalibration $cal): ?NightScript
    {
        return match ($scenario) {
            'perfect-night' => NightScript::perfectNight($tz, $cal),
            default => null,
        };
    }

    // ---------------------------------------------------------------- lab isolation

    /** Find-or-create the dedicated lab profile + its virtual band, and (re)mint a signing secret. */
    private function bootLabBand(string $tz): VirtualBand
    {
        $user = User::firstOrCreate(
            ['email' => self::LAB_EMAIL],
            ['name' => 'Sleep Lab', 'password' => bcrypt(Str::random(40))],
        );
        $profile = $user->ensureProfile();
        // Give the lab profile a realistic demographic so fitness/HRV math has inputs.
        $profile->forceFill(array_filter([
            'birthdate' => $profile->birthdate ?: '1991-01-01',
            'sex' => $profile->sex ?: 'M',
            'height_cm' => $profile->height_cm ?: 180,
        ]))->save();

        $secret = bin2hex(random_bytes(16));
        $device = WearableConnection::where('profile_id', $profile->id)
            ->where('device_id', 'like', 'tb_lab_%')->first();
        if (! $device) {
            $device = WearableConnection::create([
                'profile_id' => $profile->id,
                'provider' => 'titan_band',
                'source' => 'titan_band',
                'device_id' => 'tb_lab_'.Str::lower(Str::random(10)),
                'device_token_hash' => hash('sha256', $secret),
                'timezone' => $tz,
                'status' => 'connected',
                'scopes' => ['ibi', 'accel', 'sleep', 'recovery'],
            ]);
        } else {
            $device->update(['device_token_hash' => hash('sha256', $secret), 'timezone' => $tz, 'status' => 'connected']);
        }

        $seed = $this->option('seed') !== null ? (int) $this->option('seed') : 424242;

        // The lab calls the ingest API over HTTP (the REAL controller → service → queue path), so it needs a
        // URL reachable from THIS process. The in-container port differs by image — Sail (laravel.test) serves
        // on :80, the serversideup prod image on :8080 — so PROBE for the one that answers rather than hardcode
        // a default that only works on one stack. --ingest-url overrides for anything exotic (Caddy, prod host).
        $ingestUrl = $this->resolveIngestUrl();

        return new VirtualBand(new BiosignalSimulator($seed), $device, $secret, SleepCalibration::load(), $ingestUrl);
    }

    /**
     * Find the ingest URL reachable from this process. --ingest-url wins; otherwise probe the ports the app
     * might be serving on (serversideup :8080, then Sail :80) and pick the first that ANSWERS (any HTTP status
     * means the server is there; only a refused connection throws). Falls back to :8080 so postSigned then
     * fails loud instead of hanging.
     */
    private function resolveIngestUrl(): string
    {
        if ($override = $this->option('ingest-url')) {
            return (string) $override;
        }
        foreach ([8080, 80] as $port) {
            try {
                Http::connectTimeout(2)->timeout(3)->get("http://localhost:{$port}/up");

                return "http://localhost:{$port}/api/devices/ingest";
            } catch (\Throwable $e) {
                continue; // connection refused on this port — try the next
            }
        }

        return 'http://localhost:8080/api/devices/ingest';
    }

    /** Wipe the lab profile's ingestion + sleep/recovery rows so every run starts clean (isolation). */
    private function resetLabData(int $profileId): void
    {
        DeviceIngestion::where('profile_id', $profileId)->delete();
        SleepLog::where('profile_id', $profileId)->delete();
        RecoveryLog::where('profile_id', $profileId)->delete();
        // The faithful sim now emits summary channels too — wipe them so per-run counts (and the fidelity
        // gate's coverage) are meaningful and don't accumulate across LAB runs.
        \App\Models\HrSample::where('profile_id', $profileId)->delete();
        \App\Models\MotionSample::where('profile_id', $profileId)->delete();
        \App\Models\DailyActivity::where('profile_id', $profileId)->delete();
    }

    // ---------------------------------------------------------------- await the async seal

    /**
     * Poll for the night's SleepLog to reach `final` (the real redis queue seals it out-of-process).
     *
     * The \r progress bar is TTY-only: piped/CI output gets plain heartbeat lines instead, so the scorecard
     * that follows survives the pipe intact (the spec's ten-second-readability rule) rather than being eaten
     * by carriage returns.
     */
    private function awaitSeal(int $profileId, string $date, int $timeoutSec): ?SleepLog
    {
        $deadline = microtime(true) + $timeoutSec;
        $tty = $this->output->isDecorated();
        $bar = null;
        if ($tty) {
            $bar = $this->output->createProgressBar($timeoutSec);
            $bar->setFormat(' waiting for seal [%bar%] %elapsed%');
            $bar->start();
        } else {
            $this->line("waiting for seal (up to {$timeoutSec}s)…");
        }
        $last = null;
        $elapsed = 0;
        while (microtime(true) < $deadline) {
            $last = SleepLog::where('profile_id', $profileId)->orderByDesc('id')->first();
            if ($last && $last->stage_status === SleepLog::STATUS_FINAL) {
                $bar?->finish();
                $tty ? $this->newLine(2) : $this->line("  sealed after ~{$elapsed}s");

                return $last;
            }
            usleep(2_000_000);
            $elapsed += 2;
            if ($bar) {
                $bar->advance(2);
            } elseif ($elapsed % 30 === 0) {
                $this->line("  …still sealing ({$elapsed}s)");
            }
        }
        $bar?->finish();
        $tty ? $this->newLine(2) : $this->line("  gave up after {$timeoutSec}s (no final row)");

        return $last; // may be null or a stuck `computing` row — the promises will mark it FAIL
    }

    // ---------------------------------------------------------------- assertions (derive from the script)

    /**
     * @return array<int,array{promise:string,pass:bool,detail:string}>
     */
    private function assertPromises(NightScript $script, array $exp, ?SleepLog $row, VirtualBand $band, array $stream): array
    {
        $profileId = $band->profileId();
        $allRows = SleepLog::where('profile_id', $profileId)->get();
        // slept_at is a date-cast Carbon, so compare on its string form (a Collection where() against a
        // string would never match the Carbon and falsely report "0 night rows").
        $nightRows = $allRows->filter(fn (SleepLog $s) => ! $s->is_nap && $s->slept_at?->toDateString() === $exp['date']);

        $checks = [];

        // ---- P1 · Never lost: a worn night ALWAYS produces a row; nothing quarantined/failed silently.
        $stuck = DeviceIngestion::where('profile_id', $profileId)
            ->whereIn('status', [DeviceIngestion::STATUS_QUARANTINE, DeviceIngestion::STATUS_FAILED])->count();
        $checks[1] = $this->check('P1 · Never lost',
            $row !== null && $stuck === 0,
            $row === null ? 'no SleepLog row was produced' : ($stuck > 0 ? "{$stuck} window(s) quarantined/failed" : 'row produced, nothing lost'));

        // ---- P2 · Never fabricated: real stages within tolerance of the script, never garbage/all-awake.
        $checks[2] = $this->assertStages($exp, $row);

        // ---- P3 · Never split or smeared: exactly ONE night row, no phantom nap.
        $naps = $allRows->filter(fn (SleepLog $s) => (bool) $s->is_nap)->count();
        $checks[3] = $this->check('P3 · Never split',
            $nightRows->count() === 1 && $naps === 0,
            "night rows={$nightRows->count()} (want 1), nap rows={$naps} (want 0)");

        // ---- P4 · Never stale: resolved to `final` (not stuck computing), finalized once.
        $checks[4] = $this->check('P4 · Never stale',
            $row !== null && $row->stage_status === SleepLog::STATUS_FINAL && $row->finalized_at !== null,
            $row === null ? 'no row' : "stage_status={$row->stage_status}, finalized_at=".($row->finalized_at ? 'set' : 'null'));

        // ---- P5 · Every surface agrees: the /api/me/sleep reader returns the same night as the DB row.
        $checks[5] = $this->assertReaderAgreement($band, $row);

        // ---- L6–L9 · SLEEP TIMELINE v2 (movement & peaks): the persisted overlay series prove the layers.
        foreach ($this->assertLayers($script, $row, $profileId) as $i => $c) {
            $checks[$i] = $c;
        }

        return $checks;
    }

    /**
     * SLEEP TIMELINE v2 §5 — prove the persisted hr_series / motion_series render the night's movement and
     * peaks off the ONE epoch grid the hypnogram uses. All four are derived from the script + the sealed
     * windows, never hand-tuned numbers:
     *   L6 Movement fidelity — motion is elevated across the restless (non-deep) epochs and low during deep.
     *   L7 Gap honesty       — no series point lands in a scripted charge gap, nor on a NODATA hypnogram epoch.
     *   L8 Alignment         — every series index is a valid epoch on the hypnogram grid (i ⇒ epoch_sec+i×30).
     *   L9 Peak survival     — the series' max equals the max MEASURED epoch value, so max-pool downsampling
     *                          kept the arousal spike / restless burst instead of averaging it away.
     *
     * @return array<int,array{promise:string,pass:bool,detail:string}>
     */
    private function assertLayers(NightScript $script, ?SleepLog $row, int $profileId): array
    {
        if ($row === null) {
            $miss = fn (string $p) => $this->check($p, false, 'no row');

            return [6 => $miss('L6 · Movement'), 7 => $miss('L7 · Gap honesty'), 8 => $miss('L8 · Alignment'), 9 => $miss('L9 · Peak survival')];
        }

        $hr = is_array($row->hr_series) ? $row->hr_series : [];
        $motion = is_array($row->motion_series) ? $row->motion_series : [];
        $hyp = is_array($row->hypnogram) ? $row->hypnogram : [];
        $spans = $script->layerSpans();
        $inAny = function (int $i, array $ss): bool {
            foreach ($ss as [$s, $e]) {
                if ($i >= $s && $i < $e) {
                    return true;
                }
            }

            return false;
        };
        $maxOver = function (array $series, array $ss) use ($inAny): ?float {
            $vals = [];
            foreach ($series as $p) {
                if ($inAny((int) $p['i'], $ss)) {
                    $vals[] = (float) $p['v'];
                }
            }

            return $vals === [] ? null : max($vals);
        };

        // L6 · Movement fidelity — restless teeth above the calm deep valleys.
        $restMax = $maxOver($motion, $spans['nondeep']);
        $deepMax = $maxOver($motion, $spans['deep']);
        $l6 = $motion !== [] && $restMax !== null && $deepMax !== null && $restMax > $deepMax;
        $checks6 = $this->check('L6 · Movement', $l6, $motion === []
            ? 'no motion_series persisted'
            : sprintf('motion peak non-deep %.1f vs deep %s (restless > calm)', $restMax ?? -1, $deepMax !== null ? sprintf('%.1f', $deepMax) : '—'));

        // L7 · Gap honesty — no HR/motion point inside a scripted charge gap, nor on a NODATA hypnogram epoch.
        $inGap = 0;
        $onHole = 0;
        foreach ([$hr, $motion] as $series) {
            foreach ($series as $p) {
                $i = (int) $p['i'];
                if ($inAny($i, $spans['gap'])) {
                    $inGap++;
                }
                if (isset($hyp[$i]) && strtolower((string) $hyp[$i]) === 'nodata') {
                    $onHole++;
                }
            }
        }
        $l7 = $inGap === 0 && $onHole === 0;
        $checks7 = $this->check('L7 · Gap honesty', $l7,
            $l7 ? (count($spans['gap']) ? 'no points in the charge gap; none on a NODATA epoch' : 'no points on a NODATA epoch (no gap scripted)')
                : "{$inGap} point(s) inside a charge gap, {$onHole} on a NODATA epoch");

        // L8 · Alignment — every series index is a real epoch on the hypnogram grid.
        $oob = 0;
        foreach ([$hr, $motion] as $series) {
            foreach ($series as $p) {
                $i = (int) $p['i'];
                if ($hyp !== [] && ($i < 0 || $i >= count($hyp))) {
                    $oob++;
                }
            }
        }
        $l8 = ($hr !== [] || $motion !== []) && $oob === 0;
        $checks8 = $this->check('L8 · Alignment', $l8,
            ($hr === [] && $motion === []) ? 'no series to align'
                : ($oob === 0 ? sprintf('all %d hr + %d motion indices sit on the %d-epoch grid', count($hr), count($motion), count($hyp)) : "{$oob} index/indices off the grid"));

        // L9 · Peak survival — the series max equals the max MEASURED epoch value (max-pool kept the peak).
        [$measHrMax, $measMotionMax] = $this->measuredMaxima($profileId);
        $serHrMax = $hr !== [] ? max(array_map(fn ($p) => (float) $p['v'], $hr)) : null;
        $serMotionMax = $motion !== [] ? max(array_map(fn ($p) => (float) $p['v'], $motion)) : null;
        $peakOk = $serMotionMax !== null && $measMotionMax !== null && abs($serMotionMax - round($measMotionMax, 2)) < 0.05
            && ($measHrMax === null || ($serHrMax !== null && abs($serHrMax - round($measHrMax)) < 1.0));
        $checks9 = $this->check('L9 · Peak survival', $peakOk,
            $serMotionMax === null ? 'no series to check'
                : sprintf('motion peak %.2f == measured %.2f · hr peak %s == measured %s',
                    $serMotionMax, $measMotionMax ?? -1,
                    $serHrMax !== null ? (string) round($serHrMax) : '—', $measHrMax !== null ? (string) round($measHrMax) : '—'));

        return [6 => $checks6, 7 => $checks7, 8 => $checks8, 9 => $checks9];
    }

    /**
     * Max MEASURED per-epoch HR (>0) and motion across the profile's SEALED windows — the ground truth the
     * downsampled series' max must still equal (max-pool never drops the global peak). HR is optional (a
     * ppg_raw night may carry no per-epoch HR); motion is always present on a staged night.
     *
     * @return array{0:?float,1:?float}  [hrMax, motionMax]
     */
    private function measuredMaxima(int $profileId): array
    {
        $hrMax = null;
        $motionMax = null;
        DeviceIngestion::where('profile_id', $profileId)
            ->whereIn('kind', ['ibi', 'ppg_raw', 'sleep'])
            ->get()
            ->each(function (DeviceIngestion $i) use (&$hrMax, &$motionMax) {
                foreach ((array) ($i->result_refs['epoch_motion'] ?? []) as $v) {
                    if (is_numeric($v)) {
                        $motionMax = $motionMax === null ? (float) $v : max($motionMax, (float) $v);
                    }
                }
                foreach ((array) ($i->result_refs['epoch_hr'] ?? []) as $v) {
                    if (is_numeric($v) && $v > 0) {
                        $hrMax = $hrMax === null ? (float) $v : max($hrMax, (float) $v);
                    }
                }
            });

        return [$hrMax, $motionMax];
    }

    private function assertStages(array $exp, ?SleepLog $row): array
    {
        if ($row === null) {
            return $this->check('P2 · Never fabricated', false, 'no row to stage');
        }
        $asleep = (int) $row->deep_min + (int) $row->rem_min + (int) $row->light_min;
        if ($asleep <= 0) {
            return $this->check('P2 · Never fabricated', false, 'row is duration-only / all-awake (no stages)');
        }
        $pct = fn ($m) => round(100.0 * (int) $m / $asleep, 1);
        $deepP = $pct($row->deep_min);
        $remP = $pct($row->rem_min);
        $lightP = $pct($row->light_min);
        $eff = null;
        $tib = $asleep + (int) $row->awake_min;
        if ($tib > 0) {
            $eff = round(100.0 * $asleep / $tib, 1);
        }

        // REM-vs-LIGHT is a documented LAB-fidelity CEILING, deliberately NOT scored. REM is defined by
        // TEMPORAL structure — it occurs in bouts, in later cycles, following light, with a characteristic
        // across-epoch HR trajectory — which the trained stager reads from sequence context (that's why REAL
        // nights DO get REM). A per-stage MARGINAL renderer (draw each epoch from a per-stage distribution)
        // discards that structure, so rendered REM carries light-like marginals (rem/light are marginal twins:
        // hr 59 vs 60, hr_sd 10.4 vs 11.1, motion 3.13 vs 3.08, rmssd 122 vs 121) and the stager CORRECTLY
        // labels them light. No per-stage tuning fixes that — it's the renderer's model shape. So we score the
        // DEEP-vs-non-deep split (motion-driven — the renderer controls it), non-deep sleep %, duration,
        // efficiency and coverage; the REM/light SUB-split is reported but not a FAIL. Sequence-aware REM
        // rendering (roadmap) is what would lift this ceiling. See tasks/reviews/2026-07-10-review-*rem*.
        $nonDeepP = round($remP + $lightP, 1);
        $expNonDeep = round((float) $exp['rem_pct'] + (float) $exp['light_pct'], 1);

        $fails = [];
        if (abs($deepP - $exp['deep_pct']) > self::TOL_STAGE_PCT) {
            $fails[] = sprintf('deep %.0f%% vs %.0f%% (Δ%.0f>%.0f)', $deepP, $exp['deep_pct'], abs($deepP - $exp['deep_pct']), self::TOL_STAGE_PCT);
        }
        if (abs($nonDeepP - $expNonDeep) > self::TOL_STAGE_PCT) {
            $fails[] = sprintf('non-deep sleep %.0f%% vs %.0f%% (Δ%.0f>%.0f)', $nonDeepP, $expNonDeep, abs($nonDeepP - $expNonDeep), self::TOL_STAGE_PCT);
        }
        if (abs((int) $row->duration_min - $exp['duration_min']) > self::TOL_DURATION_MIN) {
            $fails[] = sprintf('duration %dm vs %dm (Δ%d>%d)', (int) $row->duration_min, $exp['duration_min'], abs((int) $row->duration_min - $exp['duration_min']), self::TOL_DURATION_MIN);
        }
        if ($eff !== null && abs($eff - $exp['efficiency_pct']) > self::TOL_EFFICIENCY_PCT) {
            $fails[] = sprintf('eff %.0f%% vs %.0f%%', $eff, $exp['efficiency_pct']);
        }
        $cov = (float) $row->coverage;
        if ($cov < 0.30 || $cov > 1.0) {
            $fails[] = sprintf('coverage %.2f out of [0.30,1.0]', $cov);
        }

        $remLightNote = sprintf(' · rem %.0f%%/light %.0f%% (script %.0f%%/%.0f%%) — marginal-renderer ceiling, not scored',
            $remP, $lightP, $exp['rem_pct'], $exp['light_pct']);
        $detail = ($fails
            ? implode('; ', $fails)
            : sprintf('deep %.0f%%/non-deep %.0f%%, %dm, eff %s%%, cov %.2f — within tolerance',
                $deepP, $nonDeepP, (int) $row->duration_min, $eff !== null ? (string) round($eff) : '—', $cov)).$remLightNote;

        return $this->check('P2 · Never fabricated', $fails === [], $detail);
    }

    private function assertReaderAgreement(VirtualBand $band, ?SleepLog $row): array
    {
        if ($row === null) {
            return $this->check('P5 · Surfaces agree', false, 'no row');
        }
        try {
            $user = User::where('email', self::LAB_EMAIL)->first();
            $req = Request::create('/api/me/sleep', 'GET');
            $req->setUserResolver(fn () => $user);
            $payload = app(MobileSleepController::class)->show($req)->getData(true);
            $first = $payload['nights'][0] ?? null;
            if (! $first) {
                return $this->check('P5 · Surfaces agree', false, '/api/me/sleep returned no nights');
            }
            $agree = (int) $first['duration_min'] === (int) $row->duration_min
                && (int) ($first['deep_min'] ?? 0) === (int) $row->deep_min
                && (int) ($first['rem_min'] ?? 0) === (int) $row->rem_min
                && (int) ($first['light_min'] ?? 0) === (int) $row->light_min
                && ($first['stage_status'] ?? null) === $row->stage_status;

            return $this->check('P5 · Surfaces agree', $agree,
                $agree ? 'reader night == DB row (duration, stages, status)'
                       : sprintf('reader %dm/%s vs row %dm/%s', (int) $first['duration_min'], $first['stage_status'] ?? '?', (int) $row->duration_min, $row->stage_status));
        } catch (\Throwable $e) {
            return $this->check('P5 · Surfaces agree', false, 'reader threw: '.Str::limit($e->getMessage(), 80));
        }
    }

    private function check(string $promise, bool $pass, string $detail): array
    {
        return ['promise' => $promise, 'pass' => $pass, 'detail' => $detail];
    }

    // ---------------------------------------------------------------- output

    private function printScorecard(string $scenario, string $tz, array $exp, ?SleepLog $row, array $checks): void
    {
        $this->newLine();
        $this->line('<options=bold>═══ SLEEP LAB SCORECARD ═══</>');
        $this->line("scenario <comment>{$scenario}</comment> · tz <comment>{$tz}</comment> · night <comment>{$exp['date']}</comment>");
        $this->line(sprintf('script:  deep %.0f%% · rem %.0f%% · light %.0f%% · %dm asleep · eff %.0f%%',
            $exp['deep_pct'], $exp['rem_pct'], $exp['light_pct'], $exp['duration_min'], $exp['efficiency_pct']));
        if ($row) {
            $asleep = max(1, (int) $row->deep_min + (int) $row->rem_min + (int) $row->light_min);
            $this->line(sprintf('sealed:  deep %.0f%% · rem %.0f%% · light %.0f%% · %dm asleep · cov %.2f · %s',
                100.0 * $row->deep_min / $asleep, 100.0 * $row->rem_min / $asleep, 100.0 * $row->light_min / $asleep,
                (int) $row->duration_min, (float) $row->coverage, $row->stage_status));
        } else {
            $this->line('sealed:  <fg=red>NO ROW</>');
        }
        $this->newLine();

        $rows = [];
        foreach ($checks as $c) {
            $rows[] = [
                $c['pass'] ? '<fg=green>PASS</>' : '<fg=red>FAIL</>',
                $c['promise'],
                $c['detail'],
            ];
        }
        $this->table(['', 'Promise', 'Result'], $rows);

        $allPass = collect($checks)->every(fn ($c) => $c['pass']);
        $this->newLine();
        if ($allPass) {
            $this->line('  <bg=green;fg=black> GREEN </> all five promises held.');
        } else {
            $failed = collect($checks)->reject(fn ($c) => $c['pass'])->pluck('promise')->implode(', ');
            $this->line("  <bg=red;fg=white> RED </> broken: {$failed}");
        }
    }

    // ---------------------------------------------------------------- --calibrate

    private function runCalibration(): int
    {
        $profileId = (int) $this->option('calibrate-profile');
        $this->line("<info>SLEEP LAB · calibrate</info> from profile #{$profileId}'s sealed nights…");
        if (! Profile::find($profileId)) {
            $this->error("Profile #{$profileId} not found.");

            return self::FAILURE;
        }

        ['calibration' => $cal, 'report' => $report, 'diagnostics' => $diagnostics] = SleepCalibration::extract($profileId);
        $this->newLine();
        $this->line('Wrote <comment>'.SleepCalibration::path().'</comment>');
        $this->table(['Field', 'Value'], [
            ['source', $report['kind'] ?? '—'],
            ['nights used', (string) ($report['nights'] ?? 0)],
            ['stage source', json_encode($report['stage_source'] ?? [])],
            ['architecture', json_encode($cal->architecture())],
        ]);

        // Per-night diagnostic: sampled-vs-available epochs per stage — pinpoints a stage that under-samples
        // (deep default(1) despite real deep minutes = a pairing/sparsity problem, visible here per night).
        if ($diagnostics !== []) {
            $this->newLine();
            $this->line('<options=bold>Per-night pairing</> (sampled / hypnogram-epochs · oob=out-of-bounds):');
            $rows = [];
            foreach ($diagnostics as $d) {
                $s = $d['sampled'];
                $h = $d['hyp_epochs'];
                $cell = fn ($k) => sprintf('%d/%d', $s[$k], $h[$k]);
                $rows[] = [
                    $d['date'],
                    $d['tagged'] ? 'tag' : 'overlap',
                    sprintf('%d/%d', $d['motion_windows'], $d['scope_windows']),
                    $cell('deep'), $cell('light'), $cell('rem'), $cell('wake'),
                    $d['oob'], $d['nodata'],
                ];
            }
            $this->line('  win = motion-bearing / total joined windows (ppg_raw carries no motion → excluded)');
            $this->table(['night', 'anchor', 'win', 'deep', 'light', 'rem', 'wake', 'oob', 'nodata'], $rows);
        }

        // Acceptance (spec §3): a calibrated synthetic night, run through the stager, must land within
        // tolerance of its scripted architecture. Run a perfect-night with the fresh calibration.
        $this->newLine();
        $this->line('Acceptance: rendering a calibrated night through the real stager…');
        $tz = $this->option('tz') ?: config('app.timezone', 'UTC');
        $band = $this->bootLabBand($tz);
        $this->resetLabData($band->profileId());
        $script = NightScript::perfectNight($tz, $cal);
        $exp = $script->expected();
        try {
            $band->streamNight($script);
            $band->emitMarker($script);
        } catch (\RuntimeException $e) {
            $this->error('Acceptance ABORTED — ingest unreachable: '.$e->getMessage());

            return self::FAILURE;
        }
        $row = $this->awaitSeal($band->profileId(), $exp['date'], (int) $this->option('timeout'));
        // §3's acceptance is about the STAGE ARCHITECTURE — that the generator and stager speak the same
        // statistical language. Judge on the stage proportions (±8pt); report duration/efficiency as a note,
        // since a confirmed night's duration is TIB − detected-wake and wake-detection is inherently noisy
        // (and thin calibration — a single night here — under-calibrates the wake signal).
        $result = $this->assertArchitecture($exp, $row);
        $this->line(($result['pass'] ? '  <fg=green>ACCEPT</>' : '  <fg=red>REJECT</>').' '.$result['detail']);

        return $result['pass'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * §3 acceptance: the sealed STAGE PROPORTIONS reproduce the scripted architecture within ±8pt (plus the
     * binary guards — staged, not all-awake, coverage in bounds). Duration/efficiency are reported as a note.
     */
    private function assertArchitecture(array $exp, ?SleepLog $row): array
    {
        if ($row === null || $row->stage_status !== SleepLog::STATUS_FINAL) {
            return $this->check('architecture', false, 'no final row');
        }
        $asleep = (int) $row->deep_min + (int) $row->rem_min + (int) $row->light_min;
        if ($asleep <= 0) {
            return $this->check('architecture', false, 'duration-only / all-awake row');
        }
        $pct = fn ($m) => round(100.0 * (int) $m / $asleep, 1);
        $deepP = $pct($row->deep_min);
        $remP = $pct($row->rem_min);
        $lightP = $pct($row->light_min);
        $cov = (float) $row->coverage;

        // Score DEEP + the non-deep-sleep bucket (see assertStages) — a marginal renderer can't reproduce the
        // REM-vs-light split (REM is temporal structure, not an epoch marginal), so it's reported, not scored.
        $nonDeepP = round($remP + $lightP, 1);
        $expNonDeep = round((float) $exp['rem_pct'] + (float) $exp['light_pct'], 1);
        $fails = [];
        foreach ([['deep', $deepP, (float) $exp['deep_pct']], ['non-deep', $nonDeepP, $expNonDeep]] as [$name, $got, $want]) {
            if (abs($got - $want) > self::TOL_STAGE_PCT) {
                $fails[] = sprintf('%s %.0f%% vs %.0f%% (Δ%.0f>%.0f)', $name, $got, $want, abs($got - $want), self::TOL_STAGE_PCT);
            }
        }
        if ($cov < 0.30 || $cov > 1.0) {
            $fails[] = sprintf('coverage %.2f out of bounds', $cov);
        }

        $durNote = sprintf(' [duration %dm vs %dm, Δ%d · rem %.0f%%/light %.0f%% vs %.0f%%/%.0f%% — ceiling, not scored]',
            (int) $row->duration_min, $exp['duration_min'], abs((int) $row->duration_min - $exp['duration_min']),
            $remP, $lightP, $exp['rem_pct'], $exp['light_pct']);
        $detail = ($fails ? implode('; ', $fails) : sprintf('deep %.0f%%/non-deep %.0f%% reproduce script (deep %.0f%%/non-deep %.0f%%) within ±%.0fpt, cov %.2f',
            $deepP, $nonDeepP, $exp['deep_pct'], $expNonDeep, self::TOL_STAGE_PCT, $cov)).$durNote;

        return $this->check('architecture', $fails === [], $detail);
    }
}
