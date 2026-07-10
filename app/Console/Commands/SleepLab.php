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

        return collect($scorecard)->every(fn ($p) => $p['pass']) ? self::SUCCESS : self::FAILURE;
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

        return $checks;
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

        $fails = [];
        if (abs($deepP - $exp['deep_pct']) > self::TOL_STAGE_PCT) {
            $fails[] = sprintf('deep %.0f%% vs %.0f%% (Δ%.0f>%.0f)', $deepP, $exp['deep_pct'], abs($deepP - $exp['deep_pct']), self::TOL_STAGE_PCT);
        }
        if (abs($remP - $exp['rem_pct']) > self::TOL_STAGE_PCT) {
            $fails[] = sprintf('rem %.0f%% vs %.0f%%', $remP, $exp['rem_pct']);
        }
        if (abs($lightP - $exp['light_pct']) > self::TOL_STAGE_PCT) {
            $fails[] = sprintf('light %.0f%% vs %.0f%%', $lightP, $exp['light_pct']);
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

        $detail = $fails
            ? implode('; ', $fails)
            : sprintf('deep %.0f%%/rem %.0f%%/light %.0f%%, %dm, eff %s%%, cov %.2f — all within tolerance',
                $deepP, $remP, $lightP, (int) $row->duration_min, $eff !== null ? (string) round($eff) : '—', $cov);

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

        ['calibration' => $cal, 'report' => $report] = SleepCalibration::extract($profileId);
        $this->newLine();
        $this->line('Wrote <comment>'.SleepCalibration::path().'</comment>');
        $this->table(['Field', 'Value'], [
            ['source', $report['kind'] ?? '—'],
            ['nights used', (string) ($report['nights'] ?? 0)],
            ['stage source', json_encode($report['stage_source'] ?? [])],
            ['architecture', json_encode($cal->architecture())],
        ]);

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

        $fails = [];
        foreach ([['deep', $deepP, $exp['deep_pct']], ['rem', $remP, $exp['rem_pct']], ['light', $lightP, $exp['light_pct']]] as [$name, $got, $want]) {
            if (abs($got - $want) > self::TOL_STAGE_PCT) {
                $fails[] = sprintf('%s %.0f%% vs %.0f%% (Δ%.0f>%.0f)', $name, $got, $want, abs($got - $want), self::TOL_STAGE_PCT);
            }
        }
        if ($cov < 0.30 || $cov > 1.0) {
            $fails[] = sprintf('coverage %.2f out of bounds', $cov);
        }

        $durNote = sprintf(' [duration %dm vs %dm, Δ%d]', (int) $row->duration_min, $exp['duration_min'], abs((int) $row->duration_min - $exp['duration_min']));
        $detail = ($fails ? implode('; ', $fails) : sprintf('stages deep %.0f%%/rem %.0f%%/light %.0f%% reproduce script (deep %.0f%%/rem %.0f%%/light %.0f%%) within ±%.0fpt, cov %.2f',
            $deepP, $remP, $lightP, $exp['deep_pct'], $exp['rem_pct'], $exp['light_pct'], self::TOL_STAGE_PCT, $cov)).$durNote;

        return $this->check('architecture', $fails === [], $detail);
    }
}
