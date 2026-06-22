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

    public function test_home_is_a_focused_today_screen_not_a_metrics_dump(): void
    {
        // The deep longitudinal review (trends + photo journey) lives on /progress now;
        // the home defers to it with one calm doorway instead of carrying it all.
        $user = User::factory()->create();
        $p = $user->ensureProfile();
        $p->bodyMetrics()->create(['weight_kg' => 84.0, 'taken_at' => now()->subDays(60)->toDateString()]);
        $p->bodyMetrics()->create(['weight_kg' => 80.5, 'taken_at' => now()->toDateString()]);

        $resp = $this->actingAs($user)->get('/dashboard');
        $resp->assertOk();
        $resp->assertSee('Your progress');                  // the single doorway card
        $resp->assertSee('/progress', false);               // pointing at the review page
        $resp->assertDontSee('Your trajectory');            // trends moved off the home
        $resp->assertDontSee('Your journey');               // photo strip moved off the home
    }

    public function test_explore_quicklinks_are_gone_no_nav_duplication(): void
    {
        // The old quick-links row duplicated the bottom tab bar + More menu. Removed.
        $user = User::factory()->create();
        $user->ensureProfile();

        $resp = $this->actingAs($user)->get('/dashboard');
        $resp->assertOk();
        // A bare grid of nav-duplicate chips no longer exists; nav lives in the shell only.
        $resp->assertDontSee('the detail lives in each domain');   // the old section comment cue
    }
}
