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

        // The strength seal writes a Workout keyed on the same (profile, started_at).
        $exercise = \App\Models\Exercise::firstOrCreate(['slug' => 'squats'],
            ['name' => 'Squats', 'muscle_group' => 'legs', 'category' => 'compound', 'equipment' => 'barbell']);
        $workout = \App\Models\Workout::create([
            'profile_id' => $profile->id, 'performed_at' => $startedAt, 'name' => 'Gym session',
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
}
