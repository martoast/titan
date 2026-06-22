<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * F-DUO-01 — the brother-vs-brother duo dashboard at /duo.
 */
class DuoPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/duo')->assertRedirect('/login');
    }

    public function test_not_onboarded_user_is_funneled_to_onboarding(): void
    {
        $user = User::factory()->notOnboarded()->create();

        $this->actingAs($user)->get('/duo')->assertRedirect(route('onboarding'));
    }

    public function test_duo_dashboard_renders_for_an_onboarded_user(): void
    {
        $user = User::factory()->create();
        $user->ensureProfile();

        $resp = $this->actingAs($user)->get('/duo');

        $resp->assertOk();
        $resp->assertViewIs('duo.index');
        $resp->assertViewHas('comparison');
        $resp->assertViewHas('leaderboard');
        // meId resolves to the current user's profile id so the view can highlight "you".
        $resp->assertViewHas('meId', $user->refresh()->profile->id);
    }
}
