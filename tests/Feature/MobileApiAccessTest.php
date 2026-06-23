<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The native app reaches owner-only routes (device management, coach) with its bearer token via
 * the `auth.any` middleware — the same routes the web UI reaches with a session.
 */
class MobileApiAccessTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        [, $plain] = ApiToken::mint($user, 'ios', ['*']);

        return $plain;
    }

    public function test_device_pair_works_with_a_bearer_token(): void
    {
        $user = User::factory()->create();

        $res = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson('/api/devices/pair', ['source' => 'bangle']);

        $res->assertStatus(201)->assertJsonStructure(["device_id", "secret"]);
    }

    public function test_device_pair_still_works_with_a_web_session(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/devices/pair', ['source' => 'bangle'])
            ->assertStatus(201)->assertJsonStructure(["device_id", "secret"]);
    }

    public function test_device_pair_is_rejected_without_auth(): void
    {
        $this->postJson('/api/devices/pair', ['source' => 'bangle'])->assertStatus(401);
    }

    public function test_invalid_bearer_token_is_rejected(): void
    {
        $this->withHeader('Authorization', 'Bearer titan_not_a_real_token')
            ->postJson('/api/devices/pair', ['source' => 'bangle'])
            ->assertStatus(401);
    }

    public function test_coach_routes_require_auth(): void
    {
        // The mobile coach group is behind auth.any — unauthenticated is rejected.
        $this->postJson('/api/coach/send', ['message' => 'hi'])->assertStatus(401);
    }
}
