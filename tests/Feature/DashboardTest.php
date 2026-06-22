<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_user_sees_the_future_self_cta(): void
    {
        $user = User::factory()->create();
        $user->ensureProfile();

        $resp = $this->actingAs($user)->get('/dashboard');
        $resp->assertOk();
        $resp->assertSee('Meet your future self');          // no goal yet → the emotional CTA
        $resp->assertSee('Today');
    }

    public function test_weight_history_renders_a_trajectory_graph(): void
    {
        $user = User::factory()->create();
        $p = $user->ensureProfile();
        $p->bodyMetrics()->create(['weight_kg' => 84.0, 'taken_at' => now()->subDays(60)->toDateString()]);
        $p->bodyMetrics()->create(['weight_kg' => 80.5, 'taken_at' => now()->toDateString()]);

        $resp = $this->actingAs($user)->get('/dashboard');
        $resp->assertOk();
        $resp->assertSee('Your trajectory');
        $resp->assertSee('Weight');
        $resp->assertSee('<polyline', false);               // the sparkline actually drew
    }

    public function test_weight_trajectory_respects_imperial_units(): void
    {
        // Regression: the dashboard hard-coded kg while Progress showed lb — same user,
        // two different numbers. Imperial users must see lb on both.
        $user = User::factory()->create();
        $p = $user->ensureProfile();
        $p->settings = array_merge($p->settings ?? [], ['units' => 'imperial']);
        $p->save();
        $p->bodyMetrics()->create(['weight_kg' => 84.0, 'taken_at' => now()->subDays(60)->toDateString()]);
        $p->bodyMetrics()->create(['weight_kg' => 82.6, 'taken_at' => now()->toDateString()]);

        $resp = $this->actingAs($user)->get('/dashboard');
        $resp->assertOk();
        // 84.0 → 82.6 kg == 185.2 → 182.1 lb, delta -3.1 lb (delta renders contiguously).
        $resp->assertSee('-3.1 lb');
        $resp->assertSee('182.1');       // current value converted to lb
    }

    public function test_weight_trajectory_shows_kg_for_metric_units(): void
    {
        $user = User::factory()->create();
        $p = $user->ensureProfile();
        $p->settings = array_merge($p->settings ?? [], ['units' => 'metric']);
        $p->save();
        $p->bodyMetrics()->create(['weight_kg' => 84.0, 'taken_at' => now()->subDays(60)->toDateString()]);
        $p->bodyMetrics()->create(['weight_kg' => 80.5, 'taken_at' => now()->toDateString()]);

        $resp = $this->actingAs($user)->get('/dashboard');
        $resp->assertOk();
        $resp->assertSee('-3.5 kg');     // 84.0 → 80.5 kg, delta -3.5 kg
    }
}
