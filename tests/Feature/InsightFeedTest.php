<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\BodyMetric;
use App\Models\Profile;
use App\Models\RecoveryLog;
use App\Models\User;
use App\Support\InsightFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The insight feed: ranked cards (anomaly first), goal progress, and the mobile endpoint.
 */
class InsightFeedTest extends TestCase
{
    use RefreshDatabase;

    private function recovery(Profile $p, string $date, int $hrv): void
    {
        RecoveryLog::create(['profile_id' => $p->id, 'logged_at' => $date, 'hrv_ms' => $hrv, 'updated_via' => 'test']);
    }

    public function test_anomaly_fires_and_ranks_first(): void
    {
        $this->travelTo(Carbon::parse('2026-06-20'));
        $p = User::factory()->create()->ensureProfile();
        // 20 days of steady ~70 HRV, then a sharp drop today → anomaly.
        $base = Carbon::parse('2026-06-01');
        for ($i = 0; $i < 19; $i++) {
            $this->recovery($p, $base->copy()->addDays($i)->toDateString(), 70 + ($i % 3));
        }
        $this->recovery($p, '2026-06-20', 44);   // today, well below baseline

        $feed = InsightFeed::build($p->fresh());
        $this->assertNotEmpty($feed);
        $this->assertSame('anomaly', $feed[0]['kind']);
        $this->assertSame('alert', $feed[0]['tone']);
    }

    public function test_goal_card_appears_with_a_trend(): void
    {
        $this->travelTo(Carbon::parse('2026-06-20'));
        $p = User::factory()->create()->ensureProfile();
        $start = Carbon::parse('2026-05-21');
        for ($i = 0; $i <= 30; $i++) {
            BodyMetric::create(['profile_id' => $p->id, 'taken_at' => $start->copy()->addDays($i)->toDateString(), 'weight_kg' => 90 - 0.15 * $i]);
        }
        $p->goals()->create([
            'metric' => 'weight', 'direction' => 'down', 'start_value' => 90, 'target_value' => 80,
            'target_date' => '2026-12-01', 'unit' => 'kg', 'status' => 'active',
        ]);

        $kinds = array_column(InsightFeed::build($p->fresh()), 'kind');
        $this->assertContains('goal', $kinds);
    }

    public function test_endpoint_returns_feed(): void
    {
        $this->travelTo(Carbon::parse('2026-06-20'));
        $user = User::factory()->create();
        $p = $user->ensureProfile();
        $base = Carbon::parse('2026-06-01');
        for ($i = 0; $i < 19; $i++) {
            $this->recovery($p, $base->copy()->addDays($i)->toDateString(), 70 + ($i % 3));
        }
        $this->recovery($p, '2026-06-20', 44);
        [, $token] = ApiToken::mint($user, 'ios', ['*']);

        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/me/insights')
            ->assertOk()
            ->assertJsonStructure(['insights' => [['type', 'kind', 'tone', 'title', 'detail']]]);
    }

    public function test_empty_when_no_data(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $this->assertSame([], InsightFeed::build($p));
    }
}
