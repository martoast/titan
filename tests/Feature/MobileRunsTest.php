<?php

namespace Tests\Feature;

use App\Models\ActivitySession;
use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The native app's runs list + Strava-style detail (GET /api/me/runs, /api/me/runs/{id}). */
class MobileRunsTest extends TestCase
{
    use RefreshDatabase;

    public function test_runs_index_and_detail(): void
    {
        config(['services.mapbox.token' => 'pk.test']);
        $user = User::factory()->create();
        $profile = $user->ensureProfile();
        [, $token] = ApiToken::mint($user, 'ios', ['*']);

        $session = ActivitySession::create([
            'profile_id' => $profile->id, 'source' => 'titan_band',
            'started_at' => now()->subHour(), 'ended_at' => now()->subMinutes(30), 'duration_min' => 30,
            'activity_type' => 'run', 'distance_km' => 5.02, 'avg_hr' => 150, 'max_hr' => 172,
            'route_polyline' => '_p~iF~ps|U', 'route_bounds' => ['min_lat' => 37.77, 'min_lon' => -122.42, 'max_lat' => 37.79, 'max_lon' => -122.40],
            'moving_time_s' => 1500, 'avg_pace_s_per_km' => 299, 'gap_s_per_km' => 290,
            'elevation_gain_m' => 42, 'elevation_profile' => [['d_km' => 0.0, 'alt_m' => 10.0], ['d_km' => 5.0, 'alt_m' => 12.0]],
            'splits' => ['km' => [['index' => 1, 'pace_s_per_unit' => 295, 'avg_hr' => 149]], 'mi' => []],
            'best_efforts' => ['1k' => ['distance_m' => 1000, 'elapsed_s' => 290, 'pace_s_per_km' => 290]],
            'relative_effort' => 64, 'updated_via' => 'biosignal:sealed',
        ]);

        // Unauthenticated is rejected (check first — withHeader is sticky on the test case).
        $this->getJson('/api/me/runs')->assertStatus(401);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/me/runs')->assertOk()
            ->assertJsonPath('runs.0.id', $session->id)
            ->assertJsonPath('runs.0.has_route', true)
            ->assertJsonPath('runs.0.distance_km', 5.02);

        $detail = $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/me/runs/{$session->id}")->assertOk();
        $detail->assertJsonPath('relative_effort', 64)
            ->assertJsonPath('gap_s_per_km', 290)
            ->assertJsonPath('best_efforts.1k.elapsed_s', 290);
        $this->assertStringContainsString('api.mapbox.com', $detail->json('map_url_large'));

        // Another user's token can't read it.
        $other = User::factory()->create();
        [, $otherToken] = ApiToken::mint($other, 'ios', ['*']);
        $this->withHeader('Authorization', "Bearer {$otherToken}")
            ->getJson("/api/me/runs/{$session->id}")->assertNotFound();
    }

    public function test_lift_detail_returns_hr_zones_and_strength_sets(): void
    {
        $user = User::factory()->create();
        $profile = $user->ensureProfile();
        [, $token] = ApiToken::mint($user, 'ios', ['*']);

        $startedAt = now()->subHour();
        $session = ActivitySession::create([
            'profile_id' => $profile->id, 'source' => 'titan_band',
            'started_at' => $startedAt, 'ended_at' => now()->subMinutes(20), 'duration_min' => 40,
            'activity_type' => 'strength', 'avg_hr' => 118, 'max_hr' => 165,
            'hr_zones' => ['z1' => 5, 'z2' => 10, 'z3' => 15, 'z4' => 8, 'z5' => 2],
            'trimp' => 44.5, 'calories_kcal' => 280, 'vo2max' => 50.0, 'fitness_level' => 'high',
            'updated_via' => 'biosignal:sealed',
        ]);

        // The seal links the logged Workout to its session by FK (workouts.activity_session_id).
        $exercise = \App\Models\Exercise::firstOrCreate(['slug' => 'squats'],
            ['name' => 'Squats', 'muscle_group' => 'legs', 'category' => 'compound', 'equipment' => 'barbell']);
        $workout = \App\Models\Workout::create([
            'profile_id' => $profile->id, 'activity_session_id' => $session->id,
            'performed_at' => $startedAt, 'name' => 'Gym session',
            'duration_min' => 40, 'updated_via' => 'biosignal:sealed',
        ]);
        $we = \App\Models\WorkoutExercise::create(['workout_id' => $workout->id, 'exercise_id' => $exercise->id, 'order' => 0]);
        \App\Models\WorkoutSet::create(['workout_exercise_id' => $we->id, 'set_number' => 1, 'reps' => 10, 'weight_kg' => 0]);
        \App\Models\WorkoutSet::create(['workout_exercise_id' => $we->id, 'set_number' => 2, 'reps' => 8, 'weight_kg' => 0]);

        $detail = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/me/runs/{$session->id}")->assertOk();

        // A lift has no map, but it has the HR-zone story + VO2max + sets/reps.
        $detail->assertJsonPath('activity_type', 'strength')
            ->assertJsonPath('map_url_large', null)
            ->assertJsonPath('polyline', null)
            ->assertJsonPath('hr_zones.z5', 2)
            ->assertJsonPath('trimp', 44.5)
            ->assertJsonPath('vo2max', 50)   // 50.0 serializes to 50 in JSON
            ->assertJsonPath('strength.total_sets', 2)
            ->assertJsonPath('strength.total_reps', 18)
            ->assertJsonPath('strength.exercises.0.name', 'Squats')
            ->assertJsonPath('strength.exercises.0.sets.0.reps', 10);
    }

    public function test_a_stale_open_session_does_not_sticky_claim_a_much_later_logged_workout(): void
    {
        // Regression (audit F1): an abandoned "start a run" placeholder (open, never ended) 6h ago must NOT
        // claim a lift logged now. sessionIdFor's null-ended branch is capped at +4h like the seal's linker —
        // otherwise the stale session sticky-mislinks the workout, and the seal's whereNull guard never re-links.
        $user = User::factory()->create();
        $profile = $user->ensureProfile();

        ActivitySession::create([
            'profile_id' => $profile->id, 'source' => 'titan_band',
            'started_at' => now()->subHours(6), 'ended_at' => null,
            'activity_type' => 'run', 'updated_via' => 'coach:start_activity',
        ]);

        $res = (new \App\Services\Assistant\AssistantTools($user))->dispatch('log_workout', [
            'name' => 'Evening lift',
            'exercises' => [['name' => 'Squats', 'sets' => [['reps' => 5, 'weight_kg' => 100]]]],
        ]);
        $this->assertTrue($res['ok'] ?? false);
        $this->assertNull(\App\Models\Workout::find($res['workout_id'])->activity_session_id,
            'a >4h-old open placeholder must not claim this workout');

        // Positive control: a session opened 20 min ago IS the one it belongs to.
        $fresh = ActivitySession::create([
            'profile_id' => $profile->id, 'source' => 'titan_band',
            'started_at' => now()->subMinutes(20), 'ended_at' => null,
            'activity_type' => 'strength', 'updated_via' => 'coach:start_workout',
        ]);
        $res2 = (new \App\Services\Assistant\AssistantTools($user))->dispatch('log_workout', [
            'name' => 'Now lift',
            'exercises' => [['name' => 'Bench', 'sets' => [['reps' => 8, 'weight_kg' => 60]]]],
        ]);
        $this->assertSame($fresh->id, \App\Models\Workout::find($res2['workout_id'])->activity_session_id,
            'a recent open session is correctly linked');
    }

    public function test_sets_attach_to_their_own_session_by_fk_not_proximity(): void
    {
        // Two lifts ~40 min apart, sets logged in BOTH. The ±20-min proximity matcher used to attach the
        // wrong lift's sets (or double them) when sessions sat close; the FK link (SealActivityJob →
        // workouts.activity_session_id) makes each detail show EXACTLY its own sets — never null, never doubled.
        $user = User::factory()->create();
        $profile = $user->ensureProfile();
        [, $token] = ApiToken::mint($user, 'ios', ['*']);

        $aStart = now()->subHours(3);
        $sessionA = ActivitySession::create([
            'profile_id' => $profile->id, 'source' => 'titan_band',
            'started_at' => $aStart, 'ended_at' => $aStart->copy()->addMinutes(15), 'duration_min' => 15,
            'activity_type' => 'strength', 'updated_via' => 'biosignal:sealed',
        ]);
        $bStart = $aStart->copy()->addMinutes(55);   // ~40 min gap between A's end and B's start
        $sessionB = ActivitySession::create([
            'profile_id' => $profile->id, 'source' => 'titan_band',
            'started_at' => $bStart, 'ended_at' => $bStart->copy()->addMinutes(15), 'duration_min' => 15,
            'activity_type' => 'strength', 'updated_via' => 'biosignal:sealed',
        ]);

        // Sets logged mid-lift in each, activity_session_id NULL (as before the seal links them).
        $squat = \App\Models\Exercise::firstOrCreate(['slug' => 'squats'], ['name' => 'Squats', 'muscle_group' => 'legs', 'category' => 'compound']);
        $bench = \App\Models\Exercise::firstOrCreate(['slug' => 'bench'], ['name' => 'Bench', 'muscle_group' => 'chest', 'category' => 'compound']);
        $wA = \App\Models\Workout::create(['profile_id' => $profile->id, 'performed_at' => $aStart->copy()->addMinutes(5), 'name' => 'A']);
        $weA = \App\Models\WorkoutExercise::create(['workout_id' => $wA->id, 'exercise_id' => $squat->id, 'order' => 0]);
        \App\Models\WorkoutSet::create(['workout_exercise_id' => $weA->id, 'set_number' => 1, 'reps' => 5, 'weight_kg' => 100]);
        \App\Models\WorkoutSet::create(['workout_exercise_id' => $weA->id, 'set_number' => 2, 'reps' => 5, 'weight_kg' => 100]);
        $wB = \App\Models\Workout::create(['profile_id' => $profile->id, 'performed_at' => $bStart->copy()->addMinutes(5), 'name' => 'B']);
        $weB = \App\Models\WorkoutExercise::create(['workout_id' => $wB->id, 'exercise_id' => $bench->id, 'order' => 0]);
        \App\Models\WorkoutSet::create(['workout_exercise_id' => $weB->id, 'set_number' => 1, 'reps' => 8, 'weight_kg' => 60]);
        \App\Models\WorkoutSet::create(['workout_exercise_id' => $weB->id, 'set_number' => 2, 'reps' => 8, 'weight_kg' => 60]);
        \App\Models\WorkoutSet::create(['workout_exercise_id' => $weB->id, 'set_number' => 3, 'reps' => 8, 'weight_kg' => 60]);

        // The seal-time deterministic backfill links each workout to the session it happened in.
        $job = new \App\Jobs\SealActivityJob($profile->id);
        $link = new \ReflectionMethod($job, 'linkWorkoutsToSession');
        $link->setAccessible(true);
        $link->invoke($job, $profile, $sessionA);
        $link->invoke($job, $profile, $sessionB);

        $this->assertSame($sessionA->id, $wA->fresh()->activity_session_id);
        $this->assertSame($sessionB->id, $wB->fresh()->activity_session_id, 'B is not stolen by A (whereNull guard)');

        // Each detail shows exactly its own sets.
        $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/me/runs/{$sessionA->id}")->assertOk()
            ->assertJsonPath('strength.total_sets', 2)
            ->assertJsonPath('strength.exercises.0.name', 'Squats');
        $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/me/runs/{$sessionB->id}")->assertOk()
            ->assertJsonPath('strength.total_sets', 3)
            ->assertJsonPath('strength.exercises.0.name', 'Bench');
    }
}
