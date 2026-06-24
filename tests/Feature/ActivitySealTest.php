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

    public function test_in_motion_hr_recomputed_from_raw_ppg_overrides_onchip(): void
    {
        Storage::fake('raw');
        config(['services.biosignal.url' => 'http://biosignal:8000', 'services.biosignal.token' => 't']);

        // The estimator (faked here; its algorithm is proven in biosignal/tests/test_inmotion_hr.py)
        // returns a clean HR series peaking at 165 with high coverage — the accurate workout HR.
        Http::fake([
            '*/process/inmotion-hr' => Http::response([
                'algo_version' => 'v1',
                't' => [1, 3, 5, 7],
                'bpm' => [150.0, 158.0, 165.0, 160.0],
                'confidence' => [80.0, 88.0, 92.0, 85.0],
                'reliable' => [true, true, true, true],
                'cadence_collision' => [false, false, false, false],
                'summary' => ['hr_mean' => 158.0, 'hr_max' => 165.0, 'hr_min' => 150.0, 'coverage' => 0.9, 'n_windows' => 4],
            ]),
            '*/process/activity' => Http::response(['algo_version' => 'v1', 'metrics' => [
                'sessions' => [[
                    'start' => '2026-06-15T12:00:00Z', 'duration_min' => 30.0, 'mean_hr' => 158.0,
                    'trimp' => 60.0, 'calories_kcal' => 380, 'activity_type' => 'run', 'activity_confidence' => 0.95,
                ]], 'session_count' => 1,
            ]]),
            '*/process/fitness' => Http::response(['algo_version' => 'v1', 'vo2max' => 50.0, 'plusminus' => 5.6,
                'methods' => ['demographic'], 'fitness_level' => 'high', 'fitness_percentile_band' => 3, 'hrr' => null]),
        ]);

        $user = User::factory()->create();
        $profile = $user->ensureProfile();
        $profile->update(['birthdate' => '1991-01-01', 'sex' => 'M', 'height_cm' => 180]);

        $this->storeWorkoutWindow($profile->id);   // on-chip HR = flat 150 (the cadence-locked value)
        $this->storePpgRawWindow($profile->id);     // raw PPG the band streamed live during the workout

        dispatch_sync(new SealActivityJob($profile->id));

        $session = ActivitySession::where('profile_id', $profile->id)->first();
        $this->assertNotNull($session);
        // HR was recomputed from raw PPG, not the on-chip register.
        $this->assertSame('ppg_inmotion', $session->hr_source);
        $this->assertEqualsWithDelta(0.9, $session->hr_quality, 0.001);
        $this->assertSame(165, $session->max_hr);   // peak of the in-motion series, not the flat 150 on-chip

        // The raw PPG actually reached the estimator.
        Http::assertSent(fn ($r) => str_contains($r->url(), '/process/inmotion-hr')
            && is_array($r['ppg'] ?? null) && count($r['ppg']) > 0);
    }

    public function test_offline_workout_without_ppg_keeps_onchip_hr(): void
    {
        Storage::fake('raw');
        config(['services.biosignal.url' => 'http://biosignal:8000', 'services.biosignal.token' => 't']);
        Http::fake([
            '*/process/activity' => Http::response(['metrics' => ['sessions' => [[
                'duration_min' => 30.0, 'mean_hr' => 150.0, 'trimp' => 58.0, 'calories_kcal' => 370,
                'activity_type' => 'run', 'activity_confidence' => 0.9,
            ]], 'session_count' => 1]]),
            '*/process/fitness' => Http::response(['vo2max' => 49.0, 'plusminus' => 5.6,
                'methods' => ['demographic'], 'fitness_level' => 'good', 'fitness_percentile_band' => 2, 'hrr' => null]),
        ]);

        $user = User::factory()->create();
        $profile = $user->ensureProfile();
        $profile->update(['birthdate' => '1991-01-01', 'sex' => 'M', 'height_cm' => 180]);
        $this->storeWorkoutWindow($profile->id);   // accel + on-chip HR only, no ppg_raw window

        dispatch_sync(new SealActivityJob($profile->id));

        $session = ActivitySession::where('profile_id', $profile->id)->first();
        $this->assertSame('onchip', $session->hr_source);
        $this->assertNull($session->hr_quality);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/process/inmotion-hr'));
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

    public function test_non_locomotion_workout_seals_a_strength_session(): void
    {
        Storage::fake('raw');
        config(['services.biosignal.url' => 'http://biosignal:8000', 'services.biosignal.token' => 't']);

        Http::fake([
            // Not locomotion → the gym path runs.
            '*/process/activity' => Http::response(['metrics' => ['sessions' => [[
                'start' => '2026-06-15T18:00:00Z', 'duration_min' => 25.0, 'mean_hr' => 120.0,
                'trimp' => 30.0, 'calories_kcal' => 200, 'activity_type' => 'other', 'activity_confidence' => 0.8,
            ]], 'session_count' => 1]]),
            '*/process/fitness' => Http::response(['vo2max' => 48.0, 'plusminus' => 5.6,
                'methods' => ['demographic'], 'fitness_level' => 'good', 'fitness_percentile_band' => 2, 'hrr' => null]),
            '*/process/gym' => Http::response(['algo_version' => 'v1',
                'sets' => [
                    ['exercise' => 'squats', 'reps' => 10, 'confidence' => 1.0, 'is_lift' => true],
                    ['exercise' => 'squats', 'reps' => 9, 'confidence' => 1.0, 'is_lift' => true],
                    ['exercise' => 'dumbbell_shoulder_press', 'reps' => 10, 'confidence' => 1.0, 'is_lift' => true],
                ],
                'summary' => ['n_sets' => 3, 'total_reps' => 29, 'exercises' => []],
            ]),
        ]);

        $user = User::factory()->create();
        $profile = $user->ensureProfile();
        $profile->update(['birthdate' => '1991-01-01', 'sex' => 'M', 'height_cm' => 180]);
        $this->storeWorkoutWindow($profile->id);

        dispatch_sync(new SealActivityJob($profile->id));

        // A strength workout with the detected exercises + sets + reps.
        $workout = \App\Models\Workout::where('profile_id', $profile->id)->first();
        $this->assertNotNull($workout);
        $this->assertSame(2, $workout->exercises()->count());        // squats + shoulder press
        $squats = $workout->exercises()->whereHas('exercise', fn ($q) => $q->where('slug', 'squats'))->first();
        $this->assertSame(2, $squats->sets()->count());              // two squat sets
        $this->assertEqualsWithDelta(10, $squats->sets()->max('reps'), 0);

        // The activity row is labelled a strength session.
        $this->assertSame('strength', ActivitySession::where('profile_id', $profile->id)->value('activity_type'));
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

    /** A raw-PPG (Shape B) window the band streams live during a connected workout: PPG + per-sample
     *  accel triplets at sample_rate_hz, overlapping the workout session's time range. */
    private function storePpgRawWindow(int $profileId, int $endsAgoMin = 60): void
    {
        $fs = 25;
        $n = 30 * $fs;                 // ~30 s of raw PPG @ 25 Hz
        $ppg = $accel = [];
        for ($i = 0; $i < $n; $i++) {
            $ppg[] = 200 + (int) round(100 * sin(2 * M_PI * 2.4 * $i / $fs)); // a pulsatile-ish trace
            $accel[] = [0, 0, 1000];   // milli-g triplets
        }
        $end = CarbonImmutable::now()->subMinutes($endsAgoMin);
        $start = $end->subMinutes(30);
        $window = [
            'kind' => 'ppg_raw', 'start' => $start->toIso8601ZuluString(), 'end' => $end->toIso8601ZuluString(),
            'sample_rate_hz' => $fs, 'ppg' => $ppg, 'accel_xyz' => $accel,
        ];

        $key = "raw/{$profileId}/ppg-test.ppg.gz";
        Storage::disk('raw')->put($key, gzencode(json_encode($window)));

        DeviceIngestion::create([
            'batch_uid' => 'ppgtest-'.$profileId.'-'.$endsAgoMin, 'profile_id' => $profileId,
            'source' => 'titan_band', 'kind' => 'ppg_raw', 'object_key' => $key,
            'window_start' => $start, 'window_end' => $end, 'status' => DeviceIngestion::STATUS_QUEUED,
        ]);
    }
}
