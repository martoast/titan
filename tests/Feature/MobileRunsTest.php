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
}
