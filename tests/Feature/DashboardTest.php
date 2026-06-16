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
}
