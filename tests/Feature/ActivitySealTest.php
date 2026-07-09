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

    public function test_calories_and_trimp_aggregate_across_sub_sessions(): void
    {
        // A run with a >1-min stop splits into sub-sessions in biosignal; the seal must use the SUMMED
        // total_* — not sessions[0] — or a paused run reports only its first leg's calories/TRIMP (~50% low).
        Storage::fake('raw');
        config(['services.biosignal.url' => 'http://biosignal:8000', 'services.biosignal.token' => 't']);
        Http::fake([
            '*/process/activity' => Http::response(['metrics' => [
                'sessions' => [
                    ['duration_min' => 15.0, 'mean_hr' => 150.0, 'trimp' => 30.0, 'calories_kcal' => 190, 'activity_type' => 'run', 'activity_confidence' => 0.95],
                    ['duration_min' => 15.0, 'mean_hr' => 155.0, 'trimp' => 32.0, 'calories_kcal' => 200, 'activity_type' => 'run', 'activity_confidence' => 0.9],
                ],
                'session_count' => 2, 'total_active_min' => 30.0, 'total_trimp' => 62.0, 'total_calories_kcal' => 390,
            ]]),
            '*/process/fitness' => Http::response(['vo2max' => 50.0, 'fitness_level' => 'high']),
            '*' => Http::response([]),
        ]);

        $user = User::factory()->create();
        $profile = $user->ensureProfile();
        $profile->update(['birthdate' => '1991-01-01', 'sex' => 'M', 'height_cm' => 180]);
        $this->storeWorkoutWindow($profile->id);

        dispatch_sync(new SealActivityJob($profile->id));

        $s = ActivitySession::where('profile_id', $profile->id)->first();
        $this->assertNotNull($s);
        $this->assertSame(390, (int) $s->calories_kcal, 'calories are the SUM across legs (390), not sessions[0] (190)');
        $this->assertEqualsWithDelta(62.0, (float) $s->trimp, 0.1, 'TRIMP is the summed total, not the first leg');
    }

    public function test_seals_a_run_with_a_gps_route(): void
    {
        Storage::fake('raw');
        config(['services.biosignal.url' => 'http://biosignal:8000', 'services.biosignal.token' => 't']);

        Http::fake([
            '*/process/activity' => Http::response(['metrics' => ['sessions' => [[
                'duration_min' => 30.0, 'mean_hr' => 150.0, 'trimp' => 58.0, 'calories_kcal' => 370,
                'activity_type' => 'run', 'activity_confidence' => 0.95,
            ]], 'session_count' => 1]]),
            '*/process/fitness' => Http::response(['vo2max' => 50.0, 'plusminus' => 5.6,
                'methods' => ['demographic'], 'fitness_level' => 'high', 'fitness_percentile_band' => 3, 'hrr' => null]),
            // The route service (its geometry is proven in biosignal/tests/test_route.py) returns the summary.
            '*/process/route' => Http::response([
                'algo_version' => 'v1', 'valid' => true,
                'distance_km' => 5.02, 'moving_time_s' => 1500, 'elapsed_time_s' => 1560,
                'avg_pace_s_per_km' => 299, 'gap_s_per_km' => 290,
                'elevation_gain_m' => 42, 'elevation_loss_m' => 40,
                'elevation_profile' => [['d_km' => 0.0, 'alt_m' => 10.0], ['d_km' => 5.0, 'alt_m' => 12.0]],
                'splits_km' => [['index' => 1, 'pace_s_per_unit' => 300, 'elev_delta_m' => 8.0, 'avg_hr' => 150]],
                'splits_mi' => [['index' => 1, 'pace_s_per_unit' => 482, 'elev_delta_m' => 13.0, 'avg_hr' => 151]],
                'best_efforts' => ['1k' => ['distance_m' => 1000, 'elapsed_s' => 295, 'pace_s_per_km' => 295]],
                'relative_effort' => 64, 'polyline' => '_p~iF~ps|U_ulLnnqC',
                'bounds' => ['min_lat' => 37.77, 'min_lon' => -122.42, 'max_lat' => 37.79, 'max_lon' => -122.40],
            ]),
        ]);

        $user = User::factory()->create();
        $profile = $user->ensureProfile();
        $profile->update(['birthdate' => '1991-01-01', 'sex' => 'M', 'height_cm' => 180]);

        // A short coordinate track on the window (sorted + deduped by the seal job before the call).
        $track = [
            ['t' => 1750000000000, 'lat' => 37.7749, 'lon' => -122.4194, 'alt' => 10.0],
            ['t' => 1750000001000, 'lat' => 37.7750, 'lon' => -122.4193, 'alt' => 11.0],
            ['t' => 1750000002000, 'lat' => 37.7751, 'lon' => -122.4192, 'alt' => 12.0],
        ];
        $this->storeWorkoutWindow($profile->id, track: $track);

        dispatch_sync(new SealActivityJob($profile->id));

        $session = ActivitySession::where('profile_id', $profile->id)->first();
        $this->assertNotNull($session);
        $this->assertTrue($session->hasRoute());
        $this->assertSame('_p~iF~ps|U_ulLnnqC', $session->route_polyline);
        $this->assertEqualsWithDelta(5.02, $session->distance_km, 0.01);   // route distance preferred
        $this->assertSame(1500, $session->moving_time_s);
        $this->assertSame(299, $session->avg_pace_s_per_km);
        $this->assertSame(290, $session->gap_s_per_km);
        $this->assertSame(42, $session->elevation_gain_m);
        $this->assertSame(64, $session->relative_effort);
        $this->assertIsArray($session->splits);
        $this->assertArrayHasKey('km', $session->splits);
        $this->assertArrayHasKey('mi', $session->splits);
        $this->assertArrayHasKey('1k', $session->best_efforts);
        $this->assertSame(37.77, $session->route_bounds['min_lat']);
        $this->assertSame('4:59 /km', $session->formatPace($session->avg_pace_s_per_km));

        // The deduped, time-sorted track actually reached the route endpoint.
        Http::assertSent(fn ($r) => str_contains($r->url(), '/process/route')
            && is_array($r['track'] ?? null) && count($r['track']) === 3
            && ($r['hr_max'] ?? null) > 0);
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
        $this->assertNotNull($session->hr_zones);   // time-in-zone computed from the HR series
        $this->assertGreaterThan(0, $session->hardZoneMin());  // those 150-165 bpm reads land in the hard zones

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

    public function test_explicit_end_seals_immediately_despite_recent_window(): void
    {
        // Whoop-style: when the phone tags the run's final window `ended` (user/watch tapped End),
        // ProcessWindowJob dispatches SealActivityJob(force: true). It must seal at once — NOT wait
        // QUIET_MINUTES for the stream to fall quiet (the sibling test proves the no-force case waits).
        Storage::fake('raw');
        config(['services.biosignal.url' => 'http://biosignal:8000', 'services.biosignal.token' => 't']);
        Http::fake([
            '*/process/activity' => Http::response(['metrics' => ['sessions' => [[
                'duration_min' => 30.0, 'mean_hr' => 150.0, 'trimp' => 58.0, 'calories_kcal' => 370,
                'activity_type' => 'run', 'activity_confidence' => 0.95,
            ]], 'session_count' => 1]]),
            '*/process/fitness' => Http::response(['vo2max' => 50.0, 'plusminus' => 5.6,
                'methods' => ['demographic'], 'fitness_level' => 'high', 'fitness_percentile_band' => 3, 'hrr' => null]),
        ]);

        $user = User::factory()->create();
        $profile = $user->ensureProfile();
        $profile->update(['birthdate' => '1991-01-01', 'sex' => 'M', 'height_cm' => 180]);
        // Just ended (within QUIET_MINUTES) — without force this would be left for later.
        $this->storeWorkoutWindow($profile->id, endsAgoMin: 1);

        dispatch_sync(new SealActivityJob($profile->id, force: true));

        $session = ActivitySession::where('profile_id', $profile->id)->first();
        $this->assertNotNull($session, 'an explicit end must seal immediately');
        $this->assertSame('run', $session->activity_type);
        $this->assertSame(DeviceIngestion::STATUS_SEALED, DeviceIngestion::first()->status);
    }

    public function test_explicit_end_still_seals_a_short_run(): void
    {
        // A deliberately-ended run shorter than MIN_SESSION_MIN must still be saved — the floor only
        // exists to filter stray motion blips, not intentional short workouts.
        Storage::fake('raw');
        config(['services.biosignal.url' => 'http://biosignal:8000', 'services.biosignal.token' => 't']);
        Http::fake([
            '*/process/activity' => Http::response(['metrics' => ['sessions' => [[
                'duration_min' => 2.0, 'mean_hr' => 140.0, 'trimp' => 8.0, 'calories_kcal' => 30,
                'activity_type' => 'run', 'activity_confidence' => 0.9,
            ]], 'session_count' => 1]]),
            '*/process/fitness' => Http::response(['vo2max' => 50.0, 'plusminus' => 5.6,
                'methods' => ['demographic'], 'fitness_level' => 'high', 'fitness_percentile_band' => 3, 'hrr' => null]),
            '*' => Http::response([]),
        ]);

        $user = User::factory()->create();
        $profile = $user->ensureProfile();
        $profile->update(['birthdate' => '1991-01-01', 'sex' => 'M', 'height_cm' => 180]);
        $this->storeShortWorkoutWindow($profile->id);   // ~2 min — below MIN_SESSION_MIN (5)

        // Without force the short window is discarded (sealed as a no-op, no session row).
        dispatch_sync(new SealActivityJob($profile->id));
        $this->assertSame(0, ActivitySession::count());

        // ...but an explicit end keeps it.
        $this->storeShortWorkoutWindow($profile->id, uidSuffix: '-b');
        dispatch_sync(new SealActivityJob($profile->id, force: true));
        $this->assertSame(1, ActivitySession::count());
    }

    public function test_watch_lift_choice_overrides_a_run_classification(): void
    {
        // The user started a LIFT from the watch's heart-rate tab. Even if the accel classifier calls
        // it a 'run' (and a stray GPS fix leaked in), the explicit choice wins: sealed as strength,
        // no route map — and NO auto-invented exercises (those only come from explicit coach logging).
        Storage::fake('raw');
        config(['services.biosignal.url' => 'http://biosignal:8000', 'services.biosignal.token' => 't']);
        Http::fake([
            '*/process/activity' => Http::response(['metrics' => ['sessions' => [[
                'duration_min' => 30.0, 'mean_hr' => 150.0, 'trimp' => 58.0, 'calories_kcal' => 370,
                'activity_type' => 'run', 'activity_confidence' => 0.92,   // classifier says RUN…
            ]], 'session_count' => 1]]),
            '*/process/fitness' => Http::response(['vo2max' => 50.0, 'plusminus' => 5.6,
                'methods' => ['demographic'], 'fitness_level' => 'high', 'fitness_percentile_band' => 3, 'hrr' => null]),
            '*/process/gym' => Http::response(['algo_version' => 'v1', 'sets' => [
                ['exercise' => 'squats', 'reps' => 10, 'confidence' => 1.0, 'is_lift' => true],
            ], 'summary' => ['n_sets' => 1, 'total_reps' => 10, 'exercises' => []]]),
            '*/process/route' => Http::response(['valid' => true, 'distance_km' => 5.0, 'polyline' => 'abc']),
        ]);

        $user = User::factory()->create();
        $profile = $user->ensureProfile();
        $profile->update(['birthdate' => '1991-01-01', 'sex' => 'M', 'height_cm' => 180]);
        // …but the watch said LIFT, and a GPS fix leaked in.
        $track = [
            ['t' => 1750000000000, 'lat' => 37.7749, 'lon' => -122.4194, 'alt' => 10.0],
            ['t' => 1750000001000, 'lat' => 37.7750, 'lon' => -122.4193, 'alt' => 11.0],
        ];
        $this->storeWorkoutWindow($profile->id, track: $track, activityKind: 'strength');

        dispatch_sync(new SealActivityJob($profile->id));

        $session = ActivitySession::where('profile_id', $profile->id)->first();
        $this->assertNotNull($session);
        $this->assertSame('strength', $session->activity_type);     // choice wins over the 'run' guess
        $this->assertNull($session->route_polyline);                // no map on a lift
        $this->assertEqualsWithDelta(1.0, $session->activity_confidence, 0.001);
        $this->assertNull(\App\Models\Workout::where('profile_id', $profile->id)->first());   // NO fabricated exercises
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/process/gym'));      // gym analyzer never runs
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/process/route'));   // route pass skipped
    }

    public function test_watch_run_choice_overrides_a_non_locomotion_classification(): void
    {
        // The user started a RUN from the watch's running tab. Even if the classifier is unsure and
        // returns 'other', the explicit choice keeps it cardio — no phantom strength/gym analysis.
        Storage::fake('raw');
        config(['services.biosignal.url' => 'http://biosignal:8000', 'services.biosignal.token' => 't']);
        Http::fake([
            '*/process/activity' => Http::response(['metrics' => ['sessions' => [[
                'duration_min' => 30.0, 'mean_hr' => 150.0, 'trimp' => 58.0, 'calories_kcal' => 370,
                'activity_type' => 'other', 'activity_confidence' => 0.4,   // classifier unsure
            ]], 'session_count' => 1]]),
            '*/process/fitness' => Http::response(['vo2max' => 50.0, 'plusminus' => 5.6,
                'methods' => ['demographic'], 'fitness_level' => 'high', 'fitness_percentile_band' => 3, 'hrr' => null]),
            '*/process/step-distance' => Http::response(['estimated' => true, 'distance_km' => 5.2, 'steps' => 5000, 'stride_m' => 1.04, 'cadence_spm' => 166.0]),
            '*' => Http::response([]),
        ]);

        $user = User::factory()->create();
        $profile = $user->ensureProfile();
        $profile->update(['birthdate' => '1991-01-01', 'sex' => 'M', 'height_cm' => 180]);
        $this->storeWorkoutWindow($profile->id, activityKind: 'run');   // no track (treadmill), watch says RUN

        dispatch_sync(new SealActivityJob($profile->id));

        $session = ActivitySession::where('profile_id', $profile->id)->first();
        $this->assertNotNull($session);
        $this->assertSame('run', $session->activity_type);          // choice wins over 'other'
        $this->assertNull(\App\Models\Workout::where('profile_id', $profile->id)->first());  // NOT sealed as strength
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/process/gym'));   // gym analysis skipped
    }

    public function test_strength_session_seals_stats_without_fabricating_exercises(): void
    {
        // A Lift-face session seals with its real STATS (HR/zones/calories/VO₂max), but NEVER invents
        // exercises/sets from the accelerometer — that made-up "jumping jacks / bicep curls" list on a
        // cardio day is exactly the bug we removed. Real exercises only come from explicit coach logging.
        Storage::fake('raw');
        config(['services.biosignal.url' => 'http://biosignal:8000', 'services.biosignal.token' => 't']);

        Http::fake([
            '*/process/activity' => Http::response(['metrics' => ['sessions' => [[
                'start' => '2026-06-15T18:00:00Z', 'duration_min' => 25.0, 'mean_hr' => 120.0,
                'trimp' => 30.0, 'calories_kcal' => 200, 'activity_type' => 'other', 'activity_confidence' => 0.8,
            ]], 'session_count' => 1]]),
            '*/process/fitness' => Http::response(['vo2max' => 48.0, 'plusminus' => 5.6,
                'methods' => ['demographic'], 'fitness_level' => 'good', 'fitness_percentile_band' => 2, 'hrr' => null]),
            // If anything tried the gym analyzer it would 500 — proving we never call it.
            '*/process/gym' => Http::response(['error' => 'gym analyzer must not run'], 500),
        ]);

        $user = User::factory()->create();
        $profile = $user->ensureProfile();
        $profile->update(['birthdate' => '1991-01-01', 'sex' => 'M', 'height_cm' => 180]);
        $this->storeWorkoutWindow($profile->id, activityKind: 'strength');   // explicit Lift-face choice

        dispatch_sync(new SealActivityJob($profile->id));

        // Sealed as strength (from the explicit choice) with its stats…
        $session = ActivitySession::where('profile_id', $profile->id)->first();
        $this->assertSame('strength', $session->activity_type);
        $this->assertSame(48.0, (float) $session->vo2max);
        // …but ZERO fabricated exercises, and the gym analyzer is never called.
        $this->assertNull(\App\Models\Workout::where('profile_id', $profile->id)->first());
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/process/gym'));
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

    public function test_run_detail_page_renders_the_route(): void
    {
        config(['services.mapbox.token' => 'pk.test']);
        $user = User::factory()->create();
        $profile = $user->ensureProfile();

        $session = ActivitySession::create([
            'profile_id' => $profile->id, 'source' => 'titan_band',
            'started_at' => now()->subHour(), 'ended_at' => now()->subMinutes(30), 'duration_min' => 30,
            'activity_type' => 'run', 'distance_km' => 5.02, 'avg_hr' => 150, 'max_hr' => 172,
            'route_polyline' => '_p~iF~ps|U_ulLnnqC', 'route_bounds' => ['min_lat' => 37.77, 'min_lon' => -122.42, 'max_lat' => 37.79, 'max_lon' => -122.40],
            'moving_time_s' => 1500, 'avg_pace_s_per_km' => 299, 'gap_s_per_km' => 290,
            'elevation_gain_m' => 42, 'elevation_loss_m' => 40,
            'elevation_profile' => [['d_km' => 0.0, 'alt_m' => 10.0], ['d_km' => 2.5, 'alt_m' => 30.0], ['d_km' => 5.0, 'alt_m' => 12.0]],
            'splits' => ['km' => [['index' => 1, 'distance_m' => 1000, 'pace_s_per_unit' => 295, 'elev_delta_m' => 8.0, 'avg_hr' => 149, 'partial' => false]], 'mi' => []],
            'best_efforts' => ['1k' => ['distance_m' => 1000, 'elapsed_s' => 290, 'pace_s_per_km' => 290]],
            'relative_effort' => 64, 'updated_via' => 'biosignal:sealed',
        ]);

        $res = $this->actingAs($user)->get(route('fitness.run', $session))->assertOk();
        $res->assertSee('5.02 km');           // distance
        $res->assertSee('4:59 /km');          // avg pace (299 s)
        $res->assertSee('Best efforts');
        $res->assertSee('api.mapbox.com', false);   // the static route map URL is rendered

        // Another profile can't view it.
        $other = User::factory()->create();
        $this->actingAs($other)->get(route('fitness.run', $session))->assertNotFound();
    }

    public function test_workouts_separated_by_a_gap_split_into_distinct_sessions(): void
    {
        // Regression: Carbon 3's signed diffInMinutes made the >20min gap rule never fire, so every
        // unsealed workout merged into ONE session. groupIntoSessions must split on a real gap.
        $job = new SealActivityJob(1);
        $group = new \ReflectionMethod($job, 'groupIntoSessions');
        $group->setAccessible(true);

        $mk = function (string $start, string $end): DeviceIngestion {
            $i = new DeviceIngestion;
            $i->window_start = CarbonImmutable::parse($start);
            $i->window_end = CarbonImmutable::parse($end);

            return $i;
        };

        // A morning run and an evening run, 8h apart → TWO sessions.
        $apart = collect([
            $mk('2026-06-15T07:00:00Z', '2026-06-15T07:30:00Z'),
            $mk('2026-06-15T15:00:00Z', '2026-06-15T15:30:00Z'),
        ]);
        $this->assertCount(2, $group->invoke($job, $apart));

        // Two contiguous windows (5-min gap) → ONE session.
        $contiguous = collect([
            $mk('2026-06-15T07:00:00Z', '2026-06-15T07:30:00Z'),
            $mk('2026-06-15T07:35:00Z', '2026-06-15T08:00:00Z'),
        ]);
        $this->assertCount(1, $group->invoke($job, $contiguous));
    }

    public function test_indoor_run_without_gps_estimates_distance_from_steps(): void
    {
        Storage::fake('raw');
        config(['services.biosignal.url' => 'http://biosignal:8000', 'services.biosignal.token' => 't']);
        Http::fake([
            '*/process/activity' => Http::response(['metrics' => ['sessions' => [[
                'duration_min' => 30.0, 'mean_hr' => 150.0, 'trimp' => 55.0, 'calories_kcal' => 360,
                'activity_type' => 'run', 'activity_confidence' => 0.9,
            ]], 'session_count' => 1]]),
            '*/process/fitness' => Http::response(['vo2max' => 48.0, 'plusminus' => 5.6,
                'methods' => ['demographic'], 'fitness_level' => 'good', 'fitness_percentile_band' => 2, 'hrr' => null]),
            '*/process/step-distance' => Http::response([
                'algo_version' => 'v1', 'estimated' => true,
                'cadence_spm' => 168.0, 'steps' => 5040, 'stride_m' => 1.05, 'distance_km' => 5.29,
            ]),
        ]);

        $user = User::factory()->create();
        $profile = $user->ensureProfile();
        $profile->update(['birthdate' => '1991-01-01', 'sex' => 'M', 'height_cm' => 178]);

        $this->storeIndoorWorkoutWindow($profile->id);   // accel + HR, NO gps at all
        dispatch_sync(new SealActivityJob($profile->id));

        $session = ActivitySession::where('profile_id', $profile->id)->first();
        $this->assertNotNull($session);
        $this->assertSame('run', $session->activity_type);
        $this->assertEqualsWithDelta(5.29, $session->distance_km, 0.01);   // from the step estimate
        $this->assertSame('steps', $session->distance_source);
        $this->assertNotNull($session->avg_pace_s_per_km);                 // pace derived from it
        // No GPS track ⇒ the route pass is skipped, and height reaches the estimator.
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/process/route'));
        Http::assertSent(fn ($r) => str_contains($r->url(), '/process/step-distance')
            && ($r['height_cm'] ?? null) == 178);
    }

    // ── Dual-path idempotency: the window-based `ended` seal AND the confirmed TW envelope can BOTH
    //    fire for one workout (live: streamed then confirmed; reconnect: backlog then envelope). They
    //    must converge on ONE activity_sessions row, in either order, and never when the envelope's
    //    guaranteed write precedes late-arriving windows. This is the double-count guard for the
    //    offline-workout durability fix.

    public function test_window_seal_then_confirmed_envelope_stays_one_row(): void
    {
        $this->fakeBiosignal();
        $profile = User::factory()->create()->ensureProfile();
        $profile->update(['birthdate' => '1991-01-01', 'sex' => 'M', 'height_cm' => 178]);

        $this->storeIndoorWorkoutWindow($profile->id);
        $w = DeviceIngestion::where('profile_id', $profile->id)->where('kind', 'workout')->first();
        [$s, $e] = [$w->window_start->timestamp, $w->window_end->timestamp];

        dispatch_sync(new SealActivityJob($profile->id));                                                   // window-based ended seal
        dispatch_sync(new SealActivityJob($profile->id, sessionStartEpoch: $s, sessionEndEpoch: $e, sessionKind: 'run')); // confirmed envelope

        $this->assertSame(1, ActivitySession::where('profile_id', $profile->id)->count(), 'one row after both seals');
    }

    public function test_confirmed_envelope_then_window_seal_stays_one_row(): void
    {
        $this->fakeBiosignal();
        $profile = User::factory()->create()->ensureProfile();
        $profile->update(['birthdate' => '1991-01-01', 'sex' => 'M', 'height_cm' => 178]);

        $this->storeIndoorWorkoutWindow($profile->id);
        $w = DeviceIngestion::where('profile_id', $profile->id)->where('kind', 'workout')->first();
        [$s, $e] = [$w->window_start->timestamp, $w->window_end->timestamp];

        dispatch_sync(new SealActivityJob($profile->id, sessionStartEpoch: $s, sessionEndEpoch: $e, sessionKind: 'run')); // confirmed first (windows present)
        dispatch_sync(new SealActivityJob($profile->id));                                                   // window-based second

        $this->assertSame(1, ActivitySession::where('profile_id', $profile->id)->count(), 'one row after both seals, reverse order');
    }

    public function test_guaranteed_write_then_late_windows_stays_one_row(): void
    {
        $this->fakeBiosignal();
        $profile = User::factory()->create()->ensureProfile();
        $profile->update(['birthdate' => '1991-01-01', 'sex' => 'M', 'height_cm' => 178]);

        // Envelope processed with NO windows yet (biosignal was down / airplane) → guaranteed write.
        // Its button-press start is a few seconds BEFORE the first window will begin — the realistic skew
        // that could split into two rows if the late window seal didn't reconcile.
        $end = CarbonImmutable::now()->subMinutes(60);
        $start = $end->subMinutes(30);
        dispatch_sync(new SealActivityJob($profile->id, sessionStartEpoch: $start->timestamp, sessionEndEpoch: $end->timestamp, sessionKind: 'run'));
        $this->assertSame(1, ActivitySession::where('profile_id', $profile->id)->count(), 'guaranteed write made the row');

        // Now the windows arrive (first window a few seconds after the button press) and get sealed.
        $this->storeIndoorWorkoutWindowAt($profile->id, $start->addSeconds(7), $end);
        dispatch_sync(new SealActivityJob($profile->id));

        $this->assertSame(1, ActivitySession::where('profile_id', $profile->id)->count(),
            'the late window seal must merge onto the guaranteed-write row, not create a second');
    }

    private function fakeBiosignal(): void
    {
        Storage::fake('raw');
        config(['services.biosignal.url' => 'http://biosignal:8000', 'services.biosignal.token' => 't']);
        Http::fake([
            '*/process/activity' => Http::response(['metrics' => ['sessions' => [[
                'duration_min' => 30.0, 'mean_hr' => 150.0, 'trimp' => 55.0, 'calories_kcal' => 360,
                'activity_type' => 'run', 'activity_confidence' => 0.9,
            ]], 'session_count' => 1]]),
            '*/process/fitness' => Http::response(['vo2max' => 48.0, 'plusminus' => 5.6, 'methods' => ['demographic'],
                'fitness_level' => 'good', 'fitness_percentile_band' => 2, 'hrr' => null]),
            '*/process/step-distance' => Http::response(['algo_version' => 'v1', 'estimated' => true,
                'cadence_spm' => 168.0, 'steps' => 5040, 'stride_m' => 1.05, 'distance_km' => 5.29]),
        ]);
    }

    /** A connected/indoor workout window: accel + on-chip HR, NO GPS (no track, no speed). */
    private function storeIndoorWorkoutWindow(int $profileId, int $endsAgoMin = 60): void
    {
        $end = CarbonImmutable::now()->subMinutes($endsAgoMin);
        $this->storeIndoorWorkoutWindowAt($profileId, $end->subMinutes(30), $end);
    }

    /** Same, but at an explicit [start, end] — for the dual-path skew test. */
    private function storeIndoorWorkoutWindowAt(int $profileId, CarbonImmutable $start, CarbonImmutable $end): void
    {
        $hr = array_fill(0, 1800, 150);
        $fs = 25;
        $m = 30 * 60 * $fs;
        $ax = $ay = array_fill(0, $m, 0.0);
        $az = array_fill(0, $m, 9.8);
        $counts = array_fill(0, 60, 40);
        $window = [
            'kind' => 'workout', 'start' => $start->toIso8601ZuluString(), 'end' => $end->toIso8601ZuluString(),
            'accel_xyz' => ['x' => $ax, 'y' => $ay, 'z' => $az], 'accel_fs' => $fs, 'accel_unit' => 'ms2',
            'accel_counts' => $counts, 'hr_bpm' => $hr,   // deliberately no 'gps'
        ];
        $key = "raw/{$profileId}/indoor-test.ndjson.gz";
        Storage::disk('raw')->put($key, gzencode(json_encode($window)));
        DeviceIngestion::create([
            'batch_uid' => 'indoor-'.$profileId, 'profile_id' => $profileId, 'source' => 'titan_band',
            'kind' => 'workout', 'object_key' => $key, 'window_start' => $start, 'window_end' => $end,
            'status' => DeviceIngestion::STATUS_QUEUED,
        ]);
    }

    /** A short (~2 min) workout window — below MIN_SESSION_MIN — to prove the explicit-end floor bypass. */
    private function storeShortWorkoutWindow(int $profileId, string $uidSuffix = ''): void
    {
        $fs = 25;
        $durMin = 2;
        $hr = array_fill(0, $durMin * 60, 140);
        $m = $durMin * 60 * $fs;
        $ax = $ay = array_fill(0, $m, 0.0);
        $az = array_fill(0, $m, 9.8);
        $counts = array_fill(0, max(1, (int) ($durMin * 2)), 40);
        $end = CarbonImmutable::now()->subMinutes(1);
        $start = $end->subMinutes($durMin);
        $window = [
            'kind' => 'workout', 'start' => $start->toIso8601ZuluString(), 'end' => $end->toIso8601ZuluString(),
            'accel_xyz' => ['x' => $ax, 'y' => $ay, 'z' => $az], 'accel_fs' => $fs, 'accel_unit' => 'ms2',
            'accel_counts' => $counts, 'hr_bpm' => $hr,
            'gps' => ['speed_kmh' => array_fill(0, $durMin * 60, 10.0), 'grade' => array_fill(0, $durMin * 60, 0.0), 'track' => []],
        ];
        $key = "raw/{$profileId}/short-test{$uidSuffix}.ndjson.gz";
        Storage::disk('raw')->put($key, gzencode(json_encode($window)));
        DeviceIngestion::create([
            'batch_uid' => 'short-'.$profileId.$uidSuffix, 'profile_id' => $profileId,
            'source' => 'titan_band', 'kind' => 'workout', 'object_key' => $key,
            'window_start' => $start, 'window_end' => $end, 'status' => DeviceIngestion::STATUS_QUEUED,
        ]);
    }

    private function storeWorkoutWindow(int $profileId, int $endsAgoMin = 60, array $track = [], ?string $activityKind = null): void
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
            'accel_counts' => $counts, 'hr_bpm' => $hr,
            'gps' => ['speed_kmh' => $speed, 'grade' => $grade, 'track' => $track],
        ];
        if ($activityKind !== null) {
            $window['activity_kind'] = $activityKind;
        }

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
