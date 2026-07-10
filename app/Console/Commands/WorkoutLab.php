<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\MobileRunsController;
use App\Models\ActivitySession;
use App\Models\DeviceIngestion;
use App\Models\Exercise;
use App\Models\Profile;
use App\Models\User;
use App\Models\WearableConnection;
use App\Models\Workout;
use App\Services\Lab\VirtualAthlete;
use App\Services\Lab\WorkoutCalibration;
use App\Services\Lab\WorkoutScript;
use App\Services\Simulator\BiosignalSimulator;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * WORKOUT LAB — the virtual-athlete simulator + end-to-end verification harness (tasks/specs/WORKOUT_LAB.md).
 *
 *   php artisan workout:lab --scenario=steady-run
 *   php artisan workout:lab --scenario=gym-lift --tz=America/Mexico_City
 *   php artisan workout:lab --calibrate
 *
 * A {@see WorkoutScript} describes the TRUE workout; a {@see VirtualAthlete} renders it into the exact wire
 * windows and streams them HMAC-signed through the REAL /api/devices/ingest into the REAL queue + REAL
 * biosignal service, then fires the watch's confirmed End marker; the harness asserts at every layer
 * (session row shape, seal decision, route/splits/RE + TRIMP/zones vs the script within the §5 tolerances,
 * one-session invariant, sets-belong, reader agreement) and prints a SCORECARD (scenario × the FIVE
 * PROMISES → PASS/FAIL). Runs on a DEDICATED lab profile, never a real user.
 *
 * Phase 1 wires `steady-run` + `gym-lift` green (spec §6.1). The WorkoutScript vocabulary already expresses
 * the Phase-2 scars (effort blocks, GPS, timestamped set events, offline-buffer / BLE-drop / strap-dropout /
 * cadence-lock / hint-absent / auto-detect / clock drift); those scenarios land in Phase 2.
 */
class WorkoutLab extends Command
{
    protected $signature = 'workout:lab
        {--scenario=steady-run : Which scenario to run (steady-run | gym-lift)}
        {--tz= : IANA timezone the workout is done in (defaults to app timezone)}
        {--calibrate : Refresh the generator calibration from real sealed sessions instead of running a scenario}
        {--calibrate-profile=1 : Which real profile to calibrate from}
        {--timeout=180 : Seconds to wait for the async seal to finalize}
        {--ingest-url= : Override the ingest URL (default: the app server reachable from this container)}
        {--seed= : Deterministic RNG seed}';

    protected $description = 'WORKOUT LAB: render a scripted workout through the real pipeline and prove the five promises.';

    /** §5 tolerances — a miss is a FAIL, not a warning. */
    private const TOL_HR_BPM = 5;

    private const TOL_SPLIT_S_PER_KM = 2;

    private const TOL_DURATION_MIN = 2;

    private const TOL_DISTANCE_PCT = 8.0;

    private const LAB_EMAIL = 'workout-lab@titan.local';

    public function handle(): int
    {
        if ($this->option('calibrate')) {
            return $this->runCalibration();
        }

        $tz = $this->option('tz') ?: config('app.timezone', 'UTC');
        $scenario = (string) $this->option('scenario');

        $athlete = $this->bootLabAthlete($tz);
        $cal = WorkoutCalibration::load();

        $this->line("<info>WORKOUT LAB</info> · scenario <comment>{$scenario}</comment> · tz <comment>{$tz}</comment> · calibration <comment>".($cal->source()['kind'] ?? 'unknown').'</comment>');
        $this->newLine();

        $script = $this->buildScenario($scenario, $tz, $cal);
        if ($script === null) {
            $this->error("Unknown scenario '{$scenario}'. Phase 1 ships: steady-run, gym-lift.");

            return self::FAILURE;
        }

        $this->resetLabData($athlete->profileId());

        $expected = $script->expected();
        $this->line('Streaming the scripted workout → real ingest API ('.$athlete->ingestUrl().')…');
        try {
            // Fail LOUDLY + fast if the API is unreachable — never spin in "waiting for seal" on data that
            // never landed. streamWorkout throws with the HTTP status the instant a batch/marker misses.
            $stream = $athlete->streamWorkout($script);
        } catch (\RuntimeException $e) {
            $this->newLine();
            $this->error('INGEST FAILED — '.$e->getMessage());

            return self::FAILURE;
        }
        $this->line(sprintf(
            '  %d workout windows in %d batches (%d delivered)%s · End marker: %s',
            $stream['windows'], $stream['batches'], $stream['delivered'],
            $stream['ppg_windows'] ? " + {$stream['ppg_windows']} ppg_raw" : '',
            $stream['marker'] ? $script->endSignal : 'absent',
        ));

        $row = $this->awaitSeal($athlete->profileId(), (int) $this->option('timeout'));

        // A gym session's logged sets are written by the coach DURING the lift; replay them here (keyed
        // inside the session span, exactly as log_set would) so P4 (sets-belong) is real.
        if ($row && $script->setEvents) {
            $this->replaySetEvents($athlete->profileId(), $script, $row);
        }

        $scorecard = $this->assertPromises($script, $expected, $row, $athlete, $stream);
        $this->printScorecard($scenario, $tz, $expected, $row, $scorecard);

        return collect($scorecard)->every(fn ($p) => $p['pass']) ? self::SUCCESS : self::FAILURE;
    }

    // ---------------------------------------------------------------- scenarios

    private function buildScenario(string $scenario, string $tz, WorkoutCalibration $cal): ?WorkoutScript
    {
        return match ($scenario) {
            'steady-run' => WorkoutScript::steadyRun($tz, $cal),
            'gym-lift' => WorkoutScript::gymLift($tz, $cal),
            default => null,
        };
    }

    // ---------------------------------------------------------------- lab isolation

    private function bootLabAthlete(string $tz): VirtualAthlete
    {
        $user = User::firstOrCreate(
            ['email' => self::LAB_EMAIL],
            ['name' => 'Workout Lab', 'password' => bcrypt(Str::random(40))],
        );
        $profile = $user->ensureProfile();
        $profile->forceFill(array_filter([
            'birthdate' => $profile->birthdate ?: '1991-01-01',
            'sex' => $profile->sex ?: 'M',
            'height_cm' => $profile->height_cm ?: 180,
        ]))->save();

        $secret = bin2hex(random_bytes(16));
        $device = WearableConnection::where('profile_id', $profile->id)
            ->where('device_id', 'like', 'tb_wlab_%')->first();
        if (! $device) {
            $device = WearableConnection::create([
                'profile_id' => $profile->id,
                'provider' => 'titan_band',
                'source' => 'titan_band',
                'device_id' => 'tb_wlab_'.Str::lower(Str::random(10)),
                'device_token_hash' => hash('sha256', $secret),
                'timezone' => $tz,
                'status' => 'connected',
                'scopes' => ['ibi', 'accel', 'workout', 'sleep', 'recovery'],
            ]);
        } else {
            $device->update(['device_token_hash' => hash('sha256', $secret), 'timezone' => $tz, 'status' => 'connected']);
        }

        $seed = $this->option('seed') !== null ? (int) $this->option('seed') : 424242;
        $ingestUrl = $this->option('ingest-url') ?: 'http://localhost/api/devices/ingest';

        return new VirtualAthlete(new BiosignalSimulator($seed), $device, $secret, WorkoutCalibration::load(), $ingestUrl);
    }

    /** Wipe the lab profile's ingestion + activity/strength rows so the one-session invariant is meaningful. */
    private function resetLabData(int $profileId): void
    {
        DeviceIngestion::where('profile_id', $profileId)->delete();
        ActivitySession::where('profile_id', $profileId)->delete();
        // Cascade the strength rows (workout → exercises → sets) so replayed sets never double-count.
        foreach (Workout::where('profile_id', $profileId)->with('exercises')->get() as $w) {
            foreach ($w->exercises as $we) {
                $we->sets()->delete();
            }
            $w->exercises()->delete();
            $w->delete();
        }
    }

    // ---------------------------------------------------------------- await the async seal

    /** Poll for a fully-sealed ActivitySession (the real redis queue seals it out-of-process). */
    private function awaitSeal(int $profileId, int $timeoutSec): ?ActivitySession
    {
        $deadline = microtime(true) + $timeoutSec;
        // Guard the \r progress bar on a TTY — piped/CI output would have it eat lines. Off-TTY we print a
        // plain heartbeat line instead so the log stays readable (the spec's ten-second-readability rule).
        $tty = stream_isatty(STDOUT) && $this->output->isDecorated();
        $bar = $tty ? $this->output->createProgressBar($timeoutSec) : null;
        if ($bar) {
            $bar->setFormat(' waiting for seal [%bar%] %elapsed%');
            $bar->start();
        } else {
            $this->line('  waiting for the async seal…');
        }
        $last = null;
        while (microtime(true) < $deadline) {
            $last = ActivitySession::where('profile_id', $profileId)->orderByDesc('id')->first();
            // A window-based full seal stamps updated_via='biosignal:sealed'; the marker-only guaranteed
            // row stamps '…-session-marker'. Either means the seal resolved — stop waiting.
            if ($last && str_starts_with((string) $last->updated_via, 'biosignal:sealed')) {
                $bar?->finish();
                $this->newLine($bar ? 2 : 1);

                return $last;
            }
            usleep(2_000_000);
            $bar?->advance(2);
        }
        $bar?->finish();
        $this->newLine($bar ? 2 : 1);

        return $last;
    }

    // ---------------------------------------------------------------- sets replay (P4)

    /**
     * Materialize the script's logged sets as ONE session Workout (many exercises) inside the span, linked
     * by the deterministic W-4 FK (workouts.activity_session_id) exactly as the coach's log_workout does —
     * which is what MobileRunsController::strengthDetail reads back.
     */
    private function replaySetEvents(int $profileId, WorkoutScript $script, ActivitySession $row): void
    {
        $workout = Workout::create([
            'profile_id' => $profileId,
            'activity_session_id' => $row->id,
            'performed_at' => $script->startAt->addMinutes((int) ($script->setEvents[0]['at_min'] ?? 0)),
            'name' => 'Strength',
            'updated_via' => 'workout-lab:log_set',
        ]);
        foreach ($script->setEvents as $order => $ev) {
            $exercise = Exercise::firstOrCreate(
                ['slug' => Exercise::slugFor($ev['name'])],
                ['name' => $ev['name'], 'muscle_group' => $ev['muscle_group'] ?? null],
            );
            $we = $workout->exercises()->create(['exercise_id' => $exercise->id, 'order' => $order + 1]);
            foreach ($ev['sets'] as $i => $set) {
                $we->sets()->create([
                    'set_number' => $i + 1,
                    'reps' => $set['reps'],
                    'weight_kg' => $set['weight_kg'],
                ]);
            }
        }
    }

    // ---------------------------------------------------------------- assertions (derive from the script)

    /**
     * @return array<int,array{promise:string,pass:bool,detail:string}>
     */
    private function assertPromises(WorkoutScript $script, array $exp, ?ActivitySession $row, VirtualAthlete $athlete, array $stream): array
    {
        $profileId = $athlete->profileId();
        $allRows = ActivitySession::where('profile_id', $profileId)->get();
        $checks = [];

        // ---- P1 · Never lost: a worn workout ALWAYS produces a session; nothing quarantined/failed.
        $stuck = DeviceIngestion::where('profile_id', $profileId)
            ->whereIn('status', [DeviceIngestion::STATUS_QUARANTINE, DeviceIngestion::STATUS_FAILED])->count();
        $checks[1] = $this->check('P1 · Never lost',
            $row !== null && $stuck === 0,
            $row === null ? 'no ActivitySession row was produced' : ($stuck > 0 ? "{$stuck} window(s) quarantined/failed" : 'session produced, nothing lost'));

        // ---- P2 · Never fabricated: right type, real metrics within tolerance, no phantom.
        $checks[2] = $this->assertMetrics($script, $exp, $row);

        // ---- P3 · Never split or merged: exactly ONE session.
        $checks[3] = $this->check('P3 · Never split/merged',
            $allRows->count() === $exp['session_count'],
            "session rows={$allRows->count()} (want {$exp['session_count']})");

        // ---- P4 · Sets belong to their session (gym) / no foreign sets (run).
        $checks[4] = $this->assertSetsBelong($script, $exp, $row, $profileId);

        // ---- P5 · Every surface agrees: the /api/runs reader + streak match the sealed row.
        $checks[5] = $this->assertReaderAgreement($athlete, $row, $script);

        return $checks;
    }

    private function assertMetrics(WorkoutScript $script, array $exp, ?ActivitySession $row): array
    {
        if ($row === null) {
            return $this->check('P2 · Never fabricated', false, 'no row to score');
        }
        $fails = [];

        // Right type (the watch's hint honoured).
        if ($row->activity_type !== $exp['activity_type']) {
            $fails[] = "type {$row->activity_type} vs {$exp['activity_type']}";
        }
        // Route presence: a run has a drawable route; a lift NEVER does.
        if ($exp['has_route'] && ! $row->hasRoute()) {
            $fails[] = 'run has no route';
        }
        if (! $exp['has_route'] && $row->hasRoute()) {
            $fails[] = 'lift fabricated a route';
        }
        // Duration.
        if ($row->duration_min !== null && abs((int) $row->duration_min - $exp['duration_min']) > self::TOL_DURATION_MIN) {
            $fails[] = sprintf('duration %dm vs %dm', (int) $row->duration_min, $exp['duration_min']);
        }
        // Avg / max HR within ±5 bpm.
        if ($row->avg_hr !== null && abs((int) $row->avg_hr - $exp['avg_hr']) > self::TOL_HR_BPM) {
            $fails[] = sprintf('avg HR %d vs %d', (int) $row->avg_hr, $exp['avg_hr']);
        }
        if ($row->max_hr !== null && abs((int) $row->max_hr - $exp['max_hr']) > self::TOL_HR_BPM) {
            $fails[] = sprintf('max HR %d vs %d', (int) $row->max_hr, $exp['max_hr']);
        }
        // TRIMP present + inside the calibrated envelope.
        $trimp = $row->trimp !== null ? (float) $row->trimp : 0.0;
        if ($trimp <= 0 || $trimp < $exp['trimp_min'] || $trimp > $exp['trimp_max']) {
            $fails[] = sprintf('TRIMP %.0f out of [%.0f,%.0f]', $trimp, $exp['trimp_min'], $exp['trimp_max']);
        }

        if ($exp['is_run']) {
            // Distance within ±8%, splits ±2s/km of the scripted pace, GAP + elevation + RE present.
            if ($row->distance_km === null || abs((float) $row->distance_km - $exp['distance_km']) > $exp['distance_km'] * self::TOL_DISTANCE_PCT / 100) {
                $fails[] = sprintf('distance %s vs %.2f km', $row->distance_km !== null ? number_format((float) $row->distance_km, 2) : '—', $exp['distance_km']);
            }
            $splitFail = $this->splitFailure($row, $exp['avg_pace_s_per_km']);
            if ($splitFail !== null) {
                $fails[] = $splitFail;
            }
            if ($row->gap_s_per_km === null) {
                $fails[] = 'no GAP';
            }
            if ($row->elevation_gain_m === null) {
                $fails[] = 'no elevation';
            }
            $re = $row->relative_effort;
            if ($re === null || $re < $exp['re_min'] || $re > $exp['re_max']) {
                $fails[] = sprintf('RE %s out of [%.0f,%.0f]', $re ?? '—', $exp['re_min'], $exp['re_max']);
            }
        } else {
            // A lift's headline is time-in-zone; it must be populated.
            if (empty($row->hr_zones)) {
                $fails[] = 'no HR zones';
            }
        }

        $detail = $fails
            ? implode('; ', $fails)
            : $this->metricSummary($row, $exp);

        return $this->check('P2 · Never fabricated', $fails === [], $detail);
    }

    /** Every km split within ±2s/km of the scripted pace, or a description of the worst offender. */
    private function splitFailure(ActivitySession $row, int $pace): ?string
    {
        $km = $row->splits['km'] ?? [];
        if (! is_array($km) || $km === []) {
            return 'no km splits';
        }
        $worst = null;
        foreach ($km as $s) {
            $p = $s['pace_s_per_unit'] ?? $s['pace_s_per_km'] ?? null;
            if ($p === null) {
                continue;
            }
            // The final partial km runs short on distance → its pace is noisy; only judge full splits.
            if (($s['distance_m'] ?? $s['dist_m'] ?? 1000) < 900) {
                continue;
            }
            $d = abs((float) $p - $pace);
            if ($worst === null || $d > $worst['d']) {
                $worst = ['d' => $d, 'p' => (float) $p];
            }
        }
        if ($worst && $worst['d'] > self::TOL_SPLIT_S_PER_KM) {
            return sprintf('split %ds/km vs %ds/km (Δ%.0f>%d)', (int) $worst['p'], $pace, $worst['d'], self::TOL_SPLIT_S_PER_KM);
        }

        return null;
    }

    private function metricSummary(ActivitySession $row, array $exp): string
    {
        if ($exp['is_run']) {
            return sprintf('run · %s km · %s/km · avg %d/max %d bpm · TRIMP %.0f · RE %s — within tolerance',
                number_format((float) $row->distance_km, 2), gmdate('i:s', (int) $row->avg_pace_s_per_km),
                (int) $row->avg_hr, (int) $row->max_hr, (float) $row->trimp, $row->relative_effort ?? '—');
        }

        return sprintf('strength · %dm · avg %d/max %d bpm · TRIMP %.0f · Z4+ %smin — within tolerance',
            (int) $row->duration_min, (int) $row->avg_hr, (int) $row->max_hr, (float) $row->trimp, $row->hardZoneMin());
    }

    private function assertSetsBelong(WorkoutScript $script, array $exp, ?ActivitySession $row, int $profileId): array
    {
        if ($row === null) {
            return $this->check('P4 · Sets belong', false, 'no row');
        }
        // A run has no logged sets — assert none leaked onto it.
        if ($exp['set_count'] === 0) {
            $detail = $this->readerStrength($row) === null ? 'no sets (run) — none fabricated' : 'a run fabricated strength sets';

            return $this->check('P4 · Sets belong', $this->readerStrength($row) === null, $detail);
        }
        // A lift: the reader must attach EXACTLY this session's sets — never null, never doubled.
        $strength = $this->readerStrength($row);
        if ($strength === null) {
            return $this->check('P4 · Sets belong', false, 'logged sets did not attach to the session');
        }
        $got = (int) ($strength['total_sets'] ?? 0);
        $want = $exp['set_count'];

        return $this->check('P4 · Sets belong', $got === $want,
            $got === $want ? "all {$want} logged sets attached to their session" : "reader shows {$got} sets (want {$want})");
    }

    /** The reader's strength detail for a session (via the real MobileRunsController::show). */
    private function readerStrength(ActivitySession $row): ?array
    {
        try {
            $user = User::where('email', self::LAB_EMAIL)->first();
            $req = Request::create('/api/runs/'.$row->id, 'GET');
            $req->setUserResolver(fn () => $user);
            $payload = app(MobileRunsController::class)->show($req, $row->fresh())->getData(true);

            return $payload['strength'] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function assertReaderAgreement(VirtualAthlete $athlete, ?ActivitySession $row, WorkoutScript $script): array
    {
        if ($row === null) {
            return $this->check('P5 · Surfaces agree', false, 'no row');
        }
        try {
            $user = User::where('email', self::LAB_EMAIL)->first();
            $req = Request::create('/api/runs', 'GET', ['tz' => $script->tz]);
            $req->setUserResolver(fn () => $user);
            $payload = app(MobileRunsController::class)->index($req)->getData(true);
            $first = $payload['runs'][0] ?? null;
            if (! $first) {
                return $this->check('P5 · Surfaces agree', false, '/api/runs returned no runs');
            }
            $listAgrees = (int) $first['id'] === (int) $row->id
                && ($first['activity_type'] ?? null) === $row->activity_type
                && (int) ($first['duration_min'] ?? -1) === (int) $row->duration_min;
            // The consistency surface: the streak/heat-strip counts this workout's day.
            $streakAgrees = (bool) ($payload['streak']['worked_out_today'] ?? false)
                && in_array($script->localDate(), (array) ($payload['active_days'] ?? []), true);

            return $this->check('P5 · Surfaces agree', $listAgrees && $streakAgrees,
                ! $listAgrees ? sprintf('reader row %s/%s vs %s/%s', $first['id'] ?? '?', $first['activity_type'] ?? '?', $row->id, $row->activity_type)
                    : ($streakAgrees ? 'run list + streak both reflect the session' : 'streak/heat-strip did not count the workout'));
        } catch (\Throwable $e) {
            return $this->check('P5 · Surfaces agree', false, 'reader threw: '.Str::limit($e->getMessage(), 80));
        }
    }

    private function check(string $promise, bool $pass, string $detail): array
    {
        return ['promise' => $promise, 'pass' => $pass, 'detail' => $detail];
    }

    // ---------------------------------------------------------------- output

    private function printScorecard(string $scenario, string $tz, array $exp, ?ActivitySession $row, array $checks): void
    {
        $this->newLine();
        $this->line('<options=bold>═══ WORKOUT LAB SCORECARD ═══</>');
        $this->line("scenario <comment>{$scenario}</comment> · tz <comment>{$tz}</comment> · date <comment>{$exp['date']}</comment>");
        if ($exp['is_run']) {
            $this->line(sprintf('script:  %s · %.2f km · %s/km · avg %d/max %d bpm · %dm',
                $exp['activity_type'], $exp['distance_km'], gmdate('i:s', $exp['avg_pace_s_per_km']), $exp['avg_hr'], $exp['max_hr'], $exp['duration_min']));
        } else {
            $this->line(sprintf('script:  %s · %dm · avg %d/max %d bpm · %d logged sets',
                $exp['activity_type'], $exp['duration_min'], $exp['avg_hr'], $exp['max_hr'], $exp['set_count']));
        }
        if ($row) {
            $this->line(sprintf('sealed:  %s · %s%s · avg %s/max %s bpm · %dm · TRIMP %s · %s',
                $row->activity_type ?? '—',
                $row->distance_km !== null ? number_format((float) $row->distance_km, 2).' km' : 'no-dist',
                $row->hasRoute() ? ' · route' : '',
                $row->avg_hr ?? '—', $row->max_hr ?? '—', (int) $row->duration_min,
                $row->trimp !== null ? (string) round((float) $row->trimp) : '—', $row->updated_via));
        } else {
            $this->line('sealed:  <fg=red>NO ROW</>');
        }
        $this->newLine();

        $rows = [];
        foreach ($checks as $c) {
            $rows[] = [$c['pass'] ? '<fg=green>PASS</>' : '<fg=red>FAIL</>', $c['promise'], $c['detail']];
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
        $this->line("<info>WORKOUT LAB · calibrate</info> from profile #{$profileId}'s sealed sessions…");
        if (! Profile::find($profileId)) {
            $this->error("Profile #{$profileId} not found.");

            return self::FAILURE;
        }

        ['calibration' => $cal, 'report' => $report] = WorkoutCalibration::extract($profileId);
        $this->newLine();
        $this->line('Wrote <comment>'.WorkoutCalibration::path().'</comment>');
        $this->table(['Field', 'Value'], [
            ['source', $report['kind'] ?? '—'],
            ['sessions used', (string) ($report['sessions'] ?? 0)],
            ['type source', json_encode($report['type_source'] ?? [])],
            ['run envelope', json_encode($cal->type('run')['trimp_per_min'] ?? [])],
            ['strength envelope', json_encode($cal->type('strength')['trimp_per_min'] ?? [])],
        ]);

        // Acceptance (spec §3): a calibrated synthetic run, run through the real pipeline, must land inside
        // the calibrated envelope + reproduce its scripted route within tolerance.
        $this->newLine();
        $this->line('Acceptance: rendering a calibrated steady-run through the real seal…');
        $tz = $this->option('tz') ?: config('app.timezone', 'UTC');
        $athlete = $this->bootLabAthlete($tz);
        $this->resetLabData($athlete->profileId());
        $script = WorkoutScript::steadyRun($tz, $cal);
        $exp = $script->expected();
        try {
            $athlete->streamWorkout($script);
        } catch (\RuntimeException $e) {
            $this->error('INGEST FAILED — '.$e->getMessage());

            return self::FAILURE;
        }
        $row = $this->awaitSeal($athlete->profileId(), (int) $this->option('timeout'));
        $result = $this->assertMetrics($script, $exp, $row);
        $this->line(($result['pass'] ? '  <fg=green>ACCEPT</>' : '  <fg=red>REJECT</>').' '.$result['detail']);

        return $result['pass'] ? self::SUCCESS : self::FAILURE;
    }
}
