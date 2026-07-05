<?php

namespace Tests\Feature;

use App\Models\ActivitySession;
use App\Models\RecoveryLog;
use App\Models\SleepLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A FULL 24 h in a Titan user's life, driven through the REAL device→server pipeline against the
 * REAL biosignal DSP (no Http fakes) — the Whoop-parity end-to-end proof:
 *
 *   overnight sleep (raw-PPG HRV bursts + a confirmed wake marker) → a RECOVERY score + a SLEEP log
 *   a morning run   (running-cadence accel + GPS + HR)             → a saved RUN activity_session
 *   an afternoon lift (lifting accel + HR, no GPS)                 → a saved STRENGTH activity_session
 *
 * Signals are synthesised to be physiologically plausible (a real pulse waveform the DSP can extract
 * IBI + HRV from; a running/lifting accel cadence the classifier can read) so the assertions exercise
 * the actual algorithms, not stubs. Run inside the container where `biosignal:8000` resolves:
 *   docker exec -e RUN_FULLDAY=1 fitness-ai-laravel.test-1 php artisan test --filter=FullDay24hTest
 * Guarded by RUN_FULLDAY so the normal hermetic suite (no biosignal service) skips it.
 */
class FullDay24hTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'fullday-device-secret';
    private string $sharedKey;

    protected function setUp(): void
    {
        parent::setUp();
        if (! env('RUN_FULLDAY')) {
            $this->markTestSkipped('Set RUN_FULLDAY=1 (needs the live biosignal service).');
        }
        Storage::fake('raw');
        config([
            'queue.default' => 'sync',
            'services.biosignal.url' => env('BIOSIGNAL_URL', 'http://biosignal:8000'),
            'services.biosignal.token' => env('BIOSIGNAL_TOKEN', ''),
        ]);
        $this->sharedKey = hash('sha256', $this->secret);
    }

    public function test_a_full_day_produces_recovery_sleep_and_both_workouts(): void
    {
        // Freeze the clock at a safe mid-day so "today" is deterministic and unambiguous across the
        // app tz + UTC (otherwise a test run near the UTC/local midnight boundary makes today-strain
        // and the recovery baselines straddle two calendar days). The HMAC still signs with real time.
        Carbon::setTestNow(Carbon::parse('2026-07-04 15:00:00', 'UTC'));

        $user = User::factory()->create();
        $profile = $user->ensureProfile();
        $profile->update(['birthdate' => '1991-01-01', 'sex' => 'M', 'height_cm' => 180]);
        $profile->wearableConnections()->create([
            'provider' => 'device', 'source' => 'titan_band', 'status' => 'connected',
            'device_id' => 'band-1', 'device_token_hash' => $this->sharedKey, 'timezone' => 'UTC',
        ]);

        // Seed ~3 weeks of prior sealed nights so the recovery SCORE + per-metric BASELINES exist (the
        // Whoop screen needs a personal baseline to compare against). Slight day-to-day variation.
        for ($d = 21; $d >= 1; $d--) {
            $date = Carbon::today('UTC')->subDays($d)->toDateString();
            $profile->recoveryLogs()->create([
                'logged_at' => $date,
                'hrv_ms' => 60 + ($d % 5) * 3,      // ~60-72 ms baseline
                'resting_hr' => 50 + ($d % 3),      // ~50-52 bpm
                'resp_rate' => 15.0 + ($d % 4) * 0.2,
                'updated_via' => 'biosignal:sealed:seed',
            ]);
            $profile->sleepLogs()->create([
                'slept_at' => $date, 'duration_min' => 430 + ($d % 4) * 15, 'quality' => 80, 'updated_via' => 'seed',
            ]);
        }

        // ---- OVERNIGHT: 12 clean 30 s HRV bursts across the night (the band's sleep duty-cycle) ----
        $night = Carbon::parse('2026-07-03 23:30:00', 'UTC');   // bed
        $burstEnd = $night;
        for ($i = 0; $i < 12; $i++) {
            $start = $night->copy()->addMinutes($i * 30);       // a burst every 30 min
            $end = $start->copy()->addSeconds(30);
            $burstEnd = $end;
            $ppg = $this->synthPpg(30, 25, 52.0 + $i * 0.2, 48.0, 100 + $i);   // ~52 bpm resting, HRV ~48 ms
            $this->ingest([
                'kind' => 'ppg_raw',
                'start' => $start->toIso8601ZuluString(),
                'end' => $end->toIso8601ZuluString(),
                'sample_rate_hz' => 25,
                'ppg' => $ppg,
                'src' => 'banglejs2',
            ]);
        }
        // The "I'm awake" marker → seals the night (recovery + sleep).
        $this->ingestSummary([
            'kind' => 'sleep_session',
            'confirmed' => true,
            'bedtime' => $night->timestamp,
            'wake' => $burstEnd->timestamp,
        ]);

        // ---- MORNING RUN + AFTERNOON LIFT (earlier TODAY, so Day Strain counts them) ----
        $runStart = Carbon::now('UTC')->subMinutes(150);
        $this->ingest($this->workoutWindow($runStart, 12, 'run', hr: 150, withGps: true));
        $liftStart = Carbon::now('UTC')->subMinutes(60);
        $this->ingest($this->workoutWindow($liftStart, 12, 'strength', hr: 135, withGps: false));

        // ---- ASSERT the Whoop-style outputs landed ----
        $recovery = RecoveryLog::where('profile_id', $profile->id)->first();
        $this->assertNotNull($recovery, 'overnight sleep must produce a recovery_logs row');
        $this->assertNotNull($recovery->hrv_ms, 'recovery must carry a whole-night HRV (the recovery score input)');
        $this->assertNotNull($recovery->resting_hr, 'recovery must carry an overnight resting HR');
        $this->assertGreaterThan(20, $recovery->hrv_ms);
        $this->assertLessThan(150, $recovery->hrv_ms);
        $this->assertTrue(str_starts_with((string) $recovery->updated_via, 'biosignal:sealed'),
            'recovery must be the whole-night SEALED read, not a per-window provisional');

        $sleep = SleepLog::where('profile_id', $profile->id)->first();
        $this->assertNotNull($sleep, 'the confirmed wake marker must produce a sleep_logs row');

        $sessions = ActivitySession::where('profile_id', $profile->id)->get();
        $dump = $sessions->map(fn ($s) => $s->activity_type.'('.$s->started_at.', hr='.$s->avg_hr.', dist='.$s->distance_km.')')->implode(' | ');
        $run = $sessions->firstWhere('activity_type', 'run')
            ?? $sessions->first(fn ($s) => in_array($s->activity_type, ['run', 'walk'], true));
        $this->assertNotNull($run, 'the morning run must seal into an activity_sessions row. Sessions: ['.$dump.']');
        $this->assertNotNull($run->avg_hr, 'the run must carry HR');

        $lift = $sessions->firstWhere('activity_type', 'strength');
        $this->assertNotNull($lift, 'the afternoon lift must seal into a strength activity_sessions row');

        // ---- The WHOOP-style recovery screen: a % score + each metric with a baseline + trend ----
        $dash = $this->auth($user)->getJson('/api/me/dashboard')->assertOk()->json();
        $this->assertIsInt($dash['readiness']['score'] ?? null, 'a 0-100 recovery score (the Whoop % ring)');
        $metrics = collect($dash['recovery']['metrics'] ?? []);
        // All four Whoop recovery metrics present — respiratory rate now comes from the whole-night
        // IBI (RSA), so a night of 30 s bursts produces it even though no single burst is long enough.
        foreach (['hrv', 'rhr', 'resp', 'sleep'] as $key) {
            $m = $metrics->firstWhere('key', $key);
            $this->assertNotNull($m, "recovery breakdown must include '$key' (value + baseline + trend)");
            $this->assertIsNumeric($m['value'], "$key has a value");
            $this->assertNotNull($m['baseline'], "$key has a personal baseline to compare against");
            $this->assertContains($m['trend'], ['up', 'down', 'flat'], "$key has a trend arrow");
        }

        // ---- The WHOOP three-ring hero: recovery %, sleep performance %, day strain ----
        $rings = $dash['rings'] ?? [];
        $this->assertIsInt($rings['recovery'] ?? null, 'the recovery ring');
        $this->assertIsInt($rings['sleep_performance'] ?? null, 'the sleep-performance ring');
        $this->assertNotNull($rings['strain'] ?? null, 'the day-strain ring');

        // ---- The WHOOP sleep breakdown: performance, stages, and the headline metrics ----
        $sleepResp = $this->auth($user)->getJson('/api/me/sleep')->assertOk()->json();
        $detail = $sleepResp['detail'] ?? null;
        $this->assertNotNull($detail, '/api/me/sleep must return the Whoop-style detail');
        $this->assertNotNull($detail['performance_pct'] ?? null, 'sleep performance %');
        $this->assertCount(4, $detail['stages'] ?? [], 'four sleep stages (deep/rem/light/awake)');
        foreach ($detail['stages'] as $st) {
            $this->assertArrayHasKey('min', $st);
            $this->assertArrayHasKey('pct', $st);
        }

        // ---- The WHOOP strain screen: day strain building through the day + workout contributions ----
        $strain = $this->auth($user)->getJson('/api/me/strain')->assertOk()->json();
        $this->assertIsNumeric($strain['strain'] ?? null, 'a 0-21 day strain');
        $this->assertGreaterThan(0, $strain['strain'], 'today\'s run + lift must raise strain above 0');
        $this->assertNotNull($strain['target']['low'] ?? null, 'a recovery-based target band');
        $this->assertNotNull($strain['target']['high'] ?? null);
        $this->assertGreaterThanOrEqual(2, count($strain['curve'] ?? []), 'a strain curve through the day');
        $this->assertGreaterThanOrEqual(2, count($strain['contributions'] ?? []),
            'both workouts appear as strain contributions');
        $this->assertNotNull(collect($strain['contributions'])->firstWhere('activity_type', 'run'));
        $this->assertNotNull(collect($strain['contributions'])->firstWhere('activity_type', 'strength'));

        // ---- The WHOOP Overview history: daily recovery / sleep / strain series + averages ----
        $ov = $this->auth($user)->getJson('/api/me/overview?days=30')->assertOk()->json();
        $this->assertGreaterThanOrEqual(29, count($ov['points']), 'a ~30-day series');
        $this->assertNotNull($ov['averages']['recovery'] ?? null, 'a period recovery average');
        $this->assertNotNull($ov['averages']['sleep_performance'] ?? null);
        $pts = collect($ov['points']);
        $this->assertNotNull($pts->firstWhere(fn ($p) => $p['recovery'] !== null), 'some day has a recovery score');
        $this->assertNotNull($pts->firstWhere(fn ($p) => ($p['strain'] ?? null) !== null), 'some day has strain');
        $this->assertNotNull($pts->last()['date'] ?? null);

        Carbon::setTestNow();   // release the frozen clock
    }

    private function auth(User $user): self
    {
        [, $token] = \App\Models\ApiToken::mint($user, 'ios', ['*']);

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    // ---- ingest helpers -------------------------------------------------------------------------

    private function ingest(array $window): void { $this->ship(['windows' => [$window]]); }
    private function ingestSummary(array $summary): void { $this->ship(['summaries' => [$summary]]); }

    private function ship(array $body): void
    {
        $payload = array_merge(['batch_uid' => substr(hash('sha256', json_encode($body).microtime()), 0, 32)], $body);
        $json = json_encode($payload);
        $t = (string) time();
        $v1 = hash_hmac('sha256', $t.'.'.$json, $this->sharedKey);
        $resp = $this->call('POST', '/api/devices/ingest', [], [], [], [
            'HTTP_X_DEVICE_ID' => 'band-1',
            'HTTP_X_TITAN_SIGNATURE' => "t={$t},v1={$v1}",
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $json);
        $resp->assertStatus(202);
    }

    // ---- signal synthesis -----------------------------------------------------------------------

    /** Beat-based synthetic PPG: RR ~ N(60/hr, sdnn), a smooth systolic+dicrotic pulse per beat @ fs. */
    private function synthPpg(int $durS, int $fs, float $hr, float $sdnnMs, int $seed): array
    {
        mt_srand($seed);
        $meanRr = 60.0 / $hr;
        $beats = [];
        $t = 0.0;
        // Respiratory sinus arrhythmia: the beat-to-beat interval breathes at ~15 br/min (0.25 Hz), so
        // the whole-night IBI carries the respiratory signal the RSA estimator reads (± noise for HRV).
        $respHz = 0.25;
        while ($t < $durS) {
            $beats[] = $t;
            $rsa = 0.045 * sin(2 * M_PI * $respHz * $t);
            $t += max(0.35, $meanRr + $rsa + $this->gauss() * $sdnnMs / 1000.0);
        }
        $n = $durS * $fs;
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $ts = $i / $fs;
            $v = 0.0;
            foreach ($beats as $b) {
                $d = $ts - $b;
                if ($d < -0.1 || $d > 0.6) { continue; }
                $v += exp(-($d * $d) / (2 * 0.04 * 0.04)) + 0.35 * exp(-(($d - 0.22) ** 2) / (2 * 0.05 * 0.05));
            }
            $out[] = (int) round(($v + $this->gauss() * 0.02) * 2000);   // scale to an int16-ish range
        }

        return $out;
    }

    /**
     * A workout window: running-cadence (or lifting) 3-axis accel @ 25 Hz + per-second HR + (for a run)
     * a GPS track advancing ~2.8 m/s, tagged `ended` so it seals at once.
     */
    private function workoutWindow(Carbon $start, int $minutes, string $kind, int $hr, bool $withGps): array
    {
        mt_srand(7);
        $fs = 25;
        $secs = $minutes * 60;
        $n = $secs * $fs;
        $ax = $ay = $az = [];
        $cadenceHz = $withGps ? 2.8 : 0.5;                     // run stride vs lifting reps
        $amp = $withGps ? 500 : 300;
        for ($i = 0; $i < $n; $i++) {
            $ts = $i / $fs;
            $bounce = $amp * sin(2 * M_PI * $cadenceHz * $ts);
            $ax[] = (int) round(80 * sin(2 * M_PI * $cadenceHz * $ts + 1.0) + $this->gauss() * 30);
            $ay[] = (int) round(60 * sin(2 * M_PI * $cadenceHz * $ts + 2.0) + $this->gauss() * 30);
            $az[] = (int) round(1000 + $bounce + $this->gauss() * 30);
        }
        $hrBySec = [];
        for ($s = 0; $s < $secs; $s++) { $hrBySec[] = (int) round($hr + 8 * sin($s / 40.0)); }
        $counts = array_fill(0, (int) ceil($secs / 30), $withGps ? 120 : 40);

        $speed = $grade = $track = [];
        if ($withGps) {
            $lat = 37.77; $lon = -122.42;
            for ($s = 0; $s < $secs; $s++) {
                $speed[] = 10.0;                              // km/h (~2.8 m/s)
                $grade[] = 0.0;
                $lat += 0.000025; $lon += 0.000025;          // ~2.8 m/s NE
                $track[] = ['t' => ($start->timestamp + $s) * 1000, 'lat' => $lat, 'lon' => $lon, 'alt' => 30.0];
            }
        }

        return array_filter([
            'kind' => 'workout',
            'start' => $start->toIso8601ZuluString(),
            'end' => $start->copy()->addSeconds($secs)->toIso8601ZuluString(),
            'accel_xyz' => ['x' => $ax, 'y' => $ay, 'z' => $az],
            'accel_fs' => $fs,
            'accel_unit' => 'mg',
            'hr_bpm' => $hrBySec,
            'accel_counts' => $counts,
            'gps' => ['speed_kmh' => $speed, 'grade' => $grade, 'track' => $track],
            'src' => 'banglejs2',
            'ended' => true,
            'activity_kind' => $kind,
        ], fn ($v) => $v !== null && $v !== []);
    }

    private function gauss(): float
    {
        $u1 = (mt_rand() + 1) / (mt_getrandmax() + 1);
        $u2 = (mt_rand() + 1) / (mt_getrandmax() + 1);

        return sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2);
    }
}
