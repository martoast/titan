<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\BodyMetric;
use App\Models\Fast;
use App\Models\User;
use App\Services\Coach\CoachTools;
use App\Support\Fasting;
use App\Support\Hydration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class HydrationFastingTest extends TestCase
{
    use RefreshDatabase;

    public function test_hydration_target_and_logging(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $this->assertSame(2500, Hydration::today($p)['target_ml']);   // default, no weight

        BodyMetric::create(['profile_id' => $p->id, 'taken_at' => now(), 'weight_kg' => 80]);
        $this->assertSame(2800, Hydration::targetMl($p->fresh()));     // 80 * 35 = 2800

        $card = Hydration::add($p, 500);
        $this->assertSame(500, $card['total_ml']);
        Hydration::add($p, 750);
        $this->assertSame(1250, Hydration::today($p)['total_ml']);
    }

    public function test_fasting_stage_progression(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $this->assertFalse(Fasting::card($p)['active']);

        Fast::create(['profile_id' => $p->id, 'started_at' => now()->subHours(13), 'goal_hours' => 16]);
        $card = Fasting::card($p->fresh());
        $this->assertTrue($card['active']);
        $this->assertSame('Fat-burning', $card['stage']);   // 13h ≥ 12h
        $this->assertEqualsWithDelta(13, $card['elapsed_h'], 0.2);
        $this->assertEqualsWithDelta(81, $card['pct'], 2);   // 13/16
    }

    public function test_coach_starts_and_ends_a_fast(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $tools = app(CoachTools::class, ['profile' => $p]);

        $start = $tools->dispatch('start_fast', ['goal_hours' => 18]);
        $this->assertTrue($start['active']);
        $this->assertEqualsWithDelta(18, $start['goal_h'], 0.01);
        $this->assertSame(1, Fast::where('profile_id', $p->id)->whereNull('ended_at')->count());

        $end = $tools->dispatch('end_fast', []);
        $this->assertTrue($end['ok']);
        $this->assertSame(0, Fast::where('profile_id', $p->id)->whereNull('ended_at')->count());
    }

    public function test_mobile_endpoints(): void
    {
        $user = User::factory()->create();
        $user->ensureProfile();
        [, $token] = ApiToken::mint($user, 'ios', ['*']);
        $h = $this->withHeader('Authorization', "Bearer {$token}");

        $h->postJson('/api/me/hydration', ['ml' => 500])->assertOk()->assertJsonPath('total_ml', 500);
        $h->getJson('/api/me/hydration')->assertOk()->assertJsonPath('type', 'hydration');

        $h->postJson('/api/me/fasting/start', ['goal_hours' => 16])->assertOk()->assertJsonPath('active', true);
        $h->getJson('/api/me/fasting')->assertOk()->assertJsonPath('active', true);
        $h->postJson('/api/me/fasting/end')->assertOk()->assertJsonPath('active', false);
    }
}
