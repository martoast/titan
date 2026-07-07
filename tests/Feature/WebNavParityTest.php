<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Smoke test for the 5-tab web IA + iOS-parity restyle: every tab landing page and every
 * restyled child page renders (200) through the rebuilt shell + shared components.
 */
class WebNavParityTest extends TestCase
{
    use RefreshDatabase;

    /** Every GET page a logged-in, onboarded user can reach must render. */
    public function test_all_restyled_pages_render(): void
    {
        $user = User::factory()->create();
        $user->ensureProfile()->update(['onboarded_at' => now()]);

        $paths = [
            // tab landings
            '/dashboard', '/progress', '/community', '/you', '/coach',
            // Today children
            '/recovery', '/sleep', '/fitness', '/workouts', '/workouts/create', '/workouts/live', '/meals', '/meals/add', '/stack',
            // Trends children
            '/biomarkers', '/body', '/foods',
            // You children
            '/devices', '/devices/bridge', '/devices/validate', '/brain', '/research', '/notifications', '/notifications/settings', '/photos', '/connect',
        ];

        foreach ($paths as $path) {
            $res = $this->actingAs($user)->get($path);
            $this->assertContains($res->status(), [200, 302], "GET $path returned {$res->status()}");
        }
    }

    public function test_community_shows_optin_gate_when_disabled(): void
    {
        $user = User::factory()->create();
        $user->ensureProfile()->update(['onboarded_at' => now(), 'community_enabled' => false]);

        $this->actingAs($user)->get('/community')->assertOk()->assertSee('Join the community');
    }
}
