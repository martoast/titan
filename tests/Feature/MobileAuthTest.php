<?php

namespace Tests\Feature;

use App\Models\PushToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The native iOS app logs in for a bearer token (reusing the ApiToken system), then calls the
 * auth.token API routes with it — including registering its APNs push token.
 */
class MobileAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_returns_a_bearer_token_and_it_authenticates(): void
    {
        $user = User::factory()->create(['password' => Hash::make('s3cret-pass')]);

        $res = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 's3cret-pass',
            'device_name' => "Alex's iPhone",
        ]);

        $res->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']]);
        $token = $res->json('token');
        $this->assertStringStartsWith('titan_', $token);

        // The token authenticates an auth.token route.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/me')->assertOk();
    }

    public function test_login_rejects_bad_credentials(): void
    {
        $user = User::factory()->create(['password' => Hash::make('right-pass')]);

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong-pass'])
            ->assertStatus(422);
    }

    public function test_push_token_registers_for_the_authed_user(): void
    {
        $user = User::factory()->create(['password' => Hash::make('pw1234567')]);
        $token = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'pw1234567'])
            ->json('token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/devices/push-token', ['token' => 'apns-device-token-abc', 'platform' => 'ios'])
            ->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseHas('push_tokens', [
            'user_id' => $user->id, 'platform' => 'ios', 'token' => 'apns-device-token-abc',
        ]);

        // Re-registering the same device token is idempotent (no duplicate row).
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/devices/push-token', ['token' => 'apns-device-token-abc'])
            ->assertOk();
        $this->assertSame(1, PushToken::where('token', 'apns-device-token-abc')->count());
    }

    public function test_logout_revokes_the_token(): void
    {
        $user = User::factory()->create(['password' => Hash::make('pw1234567')]);
        $token = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'pw1234567'])
            ->json('token');

        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/logout')->assertOk();

        // The revoked token no longer authenticates.
        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/me')->assertStatus(401);
    }
}
