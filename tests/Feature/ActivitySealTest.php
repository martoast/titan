<?php

namespace Tests\Feature;

use App\Jobs\SealActivityJob;
use App\Models\ActivitySession;
use App\Models\DeviceIngestion;
use App\Models\RecoveryLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ActivitySealTest extends TestCase
{
    use RefreshDatabase;

    public function test_seals_a_workout_window_into_an_activity_session(): void
    {
        Storage::fake('raw');
        config(['services.biosignal.url' => 'http://biosignal:8000', 'services.biosignal.token' => 't']);

        // The biosignal service is faked: classification + TRIMP, then VO2max + HRR.
        Http::fake([
            '*/process/activity' => Http::response(['algo_version' => 'v1', 'metrics' => [
                'sessions' => [[
                    'start' => '2026-06-15T12:00:00Z', 'end' => '2026-06-15T12:30:00Z',
                    'duration_min' => 30.0, 'mean_accel_counts' => 40, 'mean_hr' => 150.0,
                    'intensity' => 0.7, 'trimp' => 58.8, 'calories_kcal' => 377,
                    'activity_type' => 'run', 'activity_confidence' => 0.97,
                    'activity_mix' => ['run' => 0.9, 'walk' => 0.1],
                ]],
                'session_count' => 1, 'total_active_min' => 30.0, 'total_trimp' => 58.8, 'total_calories_kcal' => 377,
            ]]),
            '*/process/fitness' => Http::response([
                'algo_version' => 'v1', 'vo2max' => 51.4, 'plusminus' => 5.6,
                'methods' => ['run_calibrated', 'uth_resting_hr'], 'fitness_level' => 'high',
                'fitness_percentile_band' => 3, 'hrr' => ['hrr_bpm' => 28.0, 'peak_hr' => 178, 'window_s' => 60.0],
            ]),
        ]);

        $user = User::factory()->create();
        $profile = $user->ensureProfile();
        $profile->update(['birthdate' => '1991-01-01', 'sex' => 'M', 'height_cm' => 180]);
        // Overnight resting HR — the wrist's strongest VO2max signal — should reach /process/fitness.
        RecoveryLog::create(['profile_id' => $profile->id, 'logged_at' => '2026-06-15', 'resting_hr' => 52]);

        $this->storeWorkoutWindow($profile->id);

        dispatch_sync(new SealActivityJob($profile->id));

        $session = ActivitySession::where('profile_id', $profile->id)->first();
        $this->assertNotNull($session, 'an activity_sessions row should be created');
        $this->assertSame('run', $session->activity_type);
        $this->assertEqualsWithDelta(0.97, $session->activity_confidence, 0.01);
        $this->assertEqualsWithDelta(58.8, $session->trimp, 0.1);
        $this->assertSame(377, $session->calories_kcal);
        $this->assertEqualsWithDelta(51.4, $session->vo2max, 0.1);
        $this->assertSame('high', $session->fitness_level);
        $this->assertEqualsWithDelta(28.0, $session->hrr_bpm, 0.1);
        $this->assertGreaterThan(0, $session->distance_km);   // from GPS speed
        $this->assertSame(150, $session->max_hr);             // peak of the workout HR series

        // The window is sealed → re-running is a no-op.
        $this->assertSame(DeviceIngestion::STATUS_SEALED, DeviceIngestion::first()->status);
        dispatch_sync(new SealActivityJob($profile->id));
        $this->assertSame(1, ActivitySession::count());

        // The overnight resting HR was forwarded to the fitness endpoint.
        Http::assertSent(fn ($r) => str_contains($r->url(), '/process/fitness') && ($r['resting_hr'] ?? null) == 52);
    }

    public function test_recent_unfinished_session_is_left_for_later(): void
    {
        Storage::fake('raw');
        config(['services.biosignal.url' => 'http://biosignal:8000']);
        Http::fake(['*' => Http::response([])]);

        $user = User::factory()->create();
        $profile = $user->ensureProfile();
        // A window that just ended now → within QUIET_MINUTES → not yet sealable.
        $this->storeWorkoutWindow($profile->id, endsAgoMin: 1);

        dispatch_sync(new SealActivityJob($profile->id));

        $this->assertSame(0, ActivitySession::count());
        $this->assertNotSame(DeviceIngestion::STATUS_SEALED, DeviceIngestion::first()->status);
        Http::assertNothingSent();
    }

    public function test_fitness_page_renders_with_and_without_sessions(): void
    {
        $user = User::factory()->create();
        $profile = $user->ensureProfile();

        // Empty state.
        $this->actingAs($user)->get('/fitness')->assertOk()->assertSee('VO₂max', false);

        // With a sealed session.
        ActivitySession::create([
            'profile_id' => $profile->id, 'source' => 'titan_band',
            'started_at' => now()->subHour(), 'ended_at' => now()->subMinutes(30), 'duration_min' => 30,
            'activity_type' => 'run', 'activity_confidence' => 0.97, 'distance_km' => 5.0,
            'avg_hr' => 150, 'max_hr' => 178, 'trimp' => 58.8, 'calories_kcal' => 377,
            'vo2max' => 51.4, 'fitness_level' => 'high', 'hrr_bpm' => 28.0, 'updated_via' => 'biosignal:sealed',
        ]);

        $this->actingAs($user)->get('/fitness')->assertOk()
            ->assertSee('Run')->assertSee('51.4')->assertSee('High');
    }

    private function storeWorkoutWindow(int $profileId, int $endsAgoMin = 60): void
    {
        $n = 1800; // 30 min @ 1 Hz HR / GPS
        $hr = $speed = $grade = [];
        for ($i = 0; $i < $n; $i++) {
            $hr[] = 150;
            $speed[] = 10.0;
            $grade[] = 0.0;
        }
        $fs = 25;
        $m = 30 * 60 * $fs;
        $ax = $ay = $az = [];
        for ($i = 0; $i < $m; $i++) {
            $ax[] = 0.0;
            $ay[] = 0.0;
            $az[] = 9.8;
        }
        $counts = array_fill(0, 60, 40);

        $end = CarbonImmutable::now()->subMinutes($endsAgoMin);
        $start = $end->subMinutes(30);
        $window = [
            'kind' => 'workout', 'start' => $start->toIso8601ZuluString(), 'end' => $end->toIso8601ZuluString(),
            'accel_xyz' => ['x' => $ax, 'y' => $ay, 'z' => $az], 'accel_fs' => $fs, 'accel_unit' => 'ms2',
            'accel_counts' => $counts, 'hr_bpm' => $hr, 'gps' => ['speed_kmh' => $speed, 'grade' => $grade],
        ];

        $key = "raw/{$profileId}/workout-test.ndjson.gz";
        Storage::disk('raw')->put($key, gzencode(json_encode($window)));

        DeviceIngestion::create([
            'batch_uid' => 'wtest-'.$profileId.'-'.$endsAgoMin, 'profile_id' => $profileId,
            'source' => 'titan_band', 'kind' => 'workout', 'object_key' => $key,
            'window_start' => $start, 'window_end' => $end, 'status' => DeviceIngestion::STATUS_QUEUED,
        ]);
    }
}
