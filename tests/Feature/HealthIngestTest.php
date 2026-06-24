<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\User;
use App\Support\Readiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Live HealthKit sync: the phone's aggregated Apple Health data upserts into Titan's models so the
 * whole engine (recovery, sleep, activity, weight) works with no band.
 */
class HealthIngestTest extends TestCase
{
    use RefreshDatabase;

    private function auth(): array
    {
        $user = User::factory()->create();
        $user->ensureProfile();
        [, $token] = ApiToken::mint($user, 'ios', ['*']);
        return [$user, $this->withHeader('Authorization', "Bearer {$token}")];
    }

    private function payload(): array
    {
        return [
            'recovery' => [['date' => '2026-06-24', 'hrv_ms' => 62, 'resting_hr' => 54, 'resp_rate' => 14.2]],
            'sleep' => [['date' => '2026-06-24', 'duration_min' => 445, 'deep_min' => 90, 'rem_min' => 110, 'light_min' => 230, 'awake_min' => 15]],
            'activity' => [['date' => '2026-06-24', 'steps' => 8200, 'active_kcal' => 520, 'distance_km' => 6.1, 'floors' => 12]],
            'body' => [['date' => '2026-06-24', 'weight_kg' => 81.2, 'body_fat_pct' => 18.4]],
            'workouts' => [['started_at' => '2026-06-24T17:00:00Z', 'ended_at' => '2026-06-24T17:45:00Z', 'type' => 'running', 'distance_km' => 5.0, 'active_kcal' => 400, 'avg_hr' => 150]],
            'vo2max' => 48.2,
        ];
    }

    public function test_ingest_upserts_into_all_models(): void
    {
        [$user, $h] = $this->auth();

        $h->postJson('/api/me/health/ingest', $this->payload())
            ->assertOk()->assertJsonPath('ok', true)
            ->assertJsonPath('counts.recovery', 1)->assertJsonPath('counts.sleep', 1)
            ->assertJsonPath('counts.activity', 1)->assertJsonPath('counts.body', 1)
            ->assertJsonPath('counts.workouts', 1);

        $p = $user->refresh()->profile;
        $this->assertSame(62, $p->recoveryLogs()->first()->hrv_ms);
        $this->assertSame(445, $p->sleepLogs()->first()->duration_min);
        $this->assertSame(8200, $p->dailyActivity()->first()->steps);
        $this->assertEqualsWithDelta(81.2, (float) $p->bodyMetrics()->first()->weight_kg, 0.01);
        $this->assertSame(1, $p->activitySessions()->count());
        $this->assertEqualsWithDelta(48.2, $p->settings['health']['vo2max'], 0.01);

        // The recovery engine now produces a score from Apple Health data alone.
        $this->assertIsArray(Readiness::compute($p));
    }

    public function test_ingest_is_idempotent(): void
    {
        [$user, $h] = $this->auth();
        $h->postJson('/api/me/health/ingest', $this->payload())->assertOk();
        $h->postJson('/api/me/health/ingest', $this->payload())->assertOk();   // re-sync same day

        $p = $user->refresh()->profile;
        $this->assertSame(1, $p->recoveryLogs()->count());
        $this->assertSame(1, $p->sleepLogs()->count());
        $this->assertSame(1, $p->dailyActivity()->count());
        $this->assertSame(1, $p->activitySessions()->count());
    }

    public function test_status_reflects_connection(): void
    {
        [, $h] = $this->auth();
        $h->getJson('/api/me/health')->assertOk()->assertJsonPath('connected', false);
        $h->postJson('/api/me/health/ingest', ['activity' => [['date' => '2026-06-24', 'steps' => 5000]]])->assertOk();
        $h->getJson('/api/me/health')->assertOk()->assertJsonPath('connected', true);
    }

    public function test_requires_auth(): void
    {
        $this->postJson('/api/me/health/ingest', [])->assertStatus(401);
    }
}
