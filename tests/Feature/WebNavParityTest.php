<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Smoke test for the 5-tab web IA redesign: every tab landing page + a few folded-in
 * children render (200) through the rebuilt titan-layout shell.
 */
class WebNavParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_five_tabs_and_key_children_render(): void
    {
        $user = User::factory()->create();
        $user->ensureProfile()->update(['onboarded_at' => now()]);

        // The 5 tab landings + a sample of folded-in children.
        foreach (['/dashboard', '/progress', '/community', '/you', '/meals', '/recovery', '/sleep', '/devices'] as $path) {
            $this->actingAs($user)->get($path)->assertOk();
        }
    }

    public function test_community_shows_optin_gate_when_disabled(): void
    {
        $user = User::factory()->create();
        $user->ensureProfile()->update(['onboarded_at' => now(), 'community_enabled' => false]);

        $this->actingAs($user)->get('/community')
            ->assertOk()
            ->assertSee('Join the community');
    }
}
