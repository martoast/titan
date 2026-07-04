<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Guards the HR wiring in SealActivityJob against two regressions found in the HR deep-dive:
 *   1. hrEpoch was downsampled with stride $fs*30 (=750) instead of 30, so the per-epoch HR handed
 *      to /process/activity was ~25× too short and the service silently dropped HR from TRIMP/cal.
 *   2. the in-motion HR series and the GPS speed series were unequal length, so /process/fitness
 *      422'd (RunCapture) and threw the whole seal — the run saved with no VO2max, or not at all.
 * Both are enforced here by asserting the shapes of the outgoing biosignal requests.
 */
class WorkoutHrPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_activity_hr_is_per_epoch_and_fitness_run_hr_matches_speed_length(): void
    {
        Storage::fake('raw');
        config(['services.biosignal.url' => 'http://biosignal:8000', 'services.biosignal.token' => 't']);
        Http::fake([
            '*/process/activity' => Http::response(['metrics' => ['sessions' => [[
                'duration_min' => 20.0, 'mean_hr' => 150.0, 'trimp' => 60.0, 'calories_kcal' => 220,
                'activity_type' => 'run', 'activity_confidence' => 0.9, 'distance_km' => 4.0,
            ]], 'session_count' => 1]]),
            '*/process/fitness' => Http::response(['vo2max' => 52.0, 'plusminus' => 5.2,
                'methods' => ['run_model'], 'fitness_level' => 'high', 'fitness_percentile_band' => 3, 'hrr' => null]),
            '*/process/route' => Http::response(['valid' => false]),
            '*' => Http::response([]),
        ]);

        $user = User::factory()->create();
        $profile = $user->ensureProfile();
        $profile->update(['birthdate' => '1991-01-01', 'sex' => 'M', 'height_cm' => 180]);

        $secret = 'test-device-secret';
        $sharedKey = hash('sha256', $secret);
        $profile->wearableConnections()->create([
            'provider' => 'device', 'source' => 'titan_band', 'status' => 'connected',
            'device_id' => 'band-1', 'device_token_hash' => $sharedKey,
        ]);

        // A 20-min RUN window: per-second HR + per-second GPS speed (a real outdoor run), ended.
        $fs = 25;
        $secs = 20 * 60;                       // 1200 s
        $hr = array_fill(0, $secs, 150);       // per-second HR
        $speed = array_fill(0, $secs, 10.0);   // per-second GPS speed (km/h)
        $grade = array_fill(0, $secs, 0.0);
        $n = $secs * $fs;
        $ax = $ay = array_fill(0, $n, 0);
        $az = array_fill(0, $n, 1000);
        $counts = array_fill(0, (int) ceil($secs / 30), 40);   // per-30s epoch counts (40 epochs)
        $end = now();
        $start = $end->copy()->subMinutes(20);
        $window = [
            'kind' => 'workout',
            'start' => $start->toIso8601ZuluString(),
            'end' => $end->toIso8601ZuluString(),
            'accel_xyz' => ['x' => $ax, 'y' => $ay, 'z' => $az],
            'accel_fs' => $fs, 'accel_unit' => 'mg',
            'hr_bpm' => $hr, 'accel_counts' => $counts,
            'gps' => ['speed_kmh' => $speed, 'grade' => $grade, 'track' => []],
            'src' => 'banglejs2',
            'ended' => true,
            'activity_kind' => 'run',
        ];
        $body = json_encode([
            'batch_uid' => substr(hash('sha256', json_encode($window)), 0, 32),
            'windows' => [$window],
        ]);

        $t = (string) time();
        $v1 = hash_hmac('sha256', $t.'.'.$body, $sharedKey);
        $resp = $this->call('POST', '/api/devices/ingest', [], [], [], [
            'HTTP_X_DEVICE_ID' => 'band-1',
            'HTTP_X_TITAN_SIGNATURE' => "t={$t},v1={$v1}",
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body);
        $resp->assertStatus(202);

        // /process/activity: the HR series must be per-EPOCH — same length as accel_counts (~40),
        // NOT a stride-750 near-empty array. Assert it's in the per-epoch ballpark, never < counts/2.
        Http::assertSent(function ($request) use ($counts) {
            if (! str_contains($request->url(), '/process/activity')) {
                return false;
            }
            $d = $request->data();
            $hr = $d['hr_bpm'] ?? [];
            $c = $d['accel_counts'] ?? [];
            // per-epoch HR present and aligned to the epoch count (not 25× too short)
            return is_array($hr) && count($hr) >= count($c) - 1 && count($hr) <= count($c) + 1 && count($hr) >= 30;
        });

        // /process/fitness: run.hr and run.speed_kmh (and grade) MUST be equal length or the
        // endpoint 422s and the whole seal throws.
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/process/fitness')) {
                return false;
            }
            $run = $request->data()['run'] ?? null;
            if (! is_array($run) || ! isset($run['hr'], $run['speed_kmh'])) {
                return false;
            }
            $okHr = count($run['hr']) === count($run['speed_kmh']);
            $okGrade = ! isset($run['grade']) || count($run['grade']) === count($run['hr']);

            return $okHr && $okGrade;
        });
    }
}
