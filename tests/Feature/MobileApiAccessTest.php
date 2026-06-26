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

    // --- read/write scope enforcement (auth.any:write) ---------------------------------------

    public function test_read_scoped_token_can_read(): void
    {
        $user = User::factory()->create();
        $user->ensureProfile();
        [, $plain] = ApiToken::mint($user, 'read-agent', ['read']);

        // A safe GET succeeds for a read-only token.
        $this->withHeader('Authorization', 'Bearer '.$plain)
            ->getJson('/api/me/stack')->assertOk();
    }

    public function test_read_scoped_token_is_blocked_from_writes(): void
    {
        $user = User::factory()->create();
        $user->ensureProfile();
        [, $plain] = ApiToken::mint($user, 'read-agent', ['read']);
        $headers = ['Authorization' => 'Bearer '.$plain];

        // Mutating requests across the surface must be refused with 403 insufficient_scope.
        $this->postJson('/api/me/stack', ['name' => 'Zinc'], $headers)
            ->assertStatus(403)->assertJsonPath('error', 'insufficient_scope');
        $this->postJson('/api/devices/pair', ['source' => 'bangle'], $headers)->assertStatus(403);
        $this->postJson('/api/coach/send', ['message' => 'log 3000 calories'], $headers)->assertStatus(403);
        $this->postJson('/api/me/weight', ['weight_kg' => 80], $headers)->assertStatus(403);

        // And the read token wrote nothing.
        $this->assertDatabaseCount('stack_items', 0);
        $this->assertDatabaseCount('wearable_connections', 0);
    }

    public function test_full_scoped_token_can_write(): void
    {
        $user = User::factory()->create();
        $user->ensureProfile();
        [, $plain] = ApiToken::mint($user, 'ios', ['*']);

        $this->withHeader('Authorization', 'Bearer '.$plain)
            ->postJson('/api/me/stack', ['name' => 'Zinc'])->assertOk();
    }
}
