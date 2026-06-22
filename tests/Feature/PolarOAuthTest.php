<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WearableConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * F-DEV-05 — Polar AccessLink OAuth2 connect flow (devices.polar.connect / .callback).
 * The flow degrades gracefully when Polar isn't configured.
 */
class PolarOAuthTest extends TestCase
{
    use RefreshDatabase;

    private function configurePolar(): void
    {
        config([
            'services.polar.client_id' => 'test-client',
            'services.polar.client_secret' => 'test-secret',
            'services.polar.redirect' => 'https://titan.test/devices/polar/callback',
            'services.polar.base_url' => 'https://www.polaraccesslink.com',
        ]);
    }

    public function test_guest_cannot_start_connect(): void
    {
        $this->get(route('devices.polar.connect'))->assertRedirect('/login');
    }

    public function test_connect_degrades_gracefully_when_not_configured(): void
    {
        config(['services.polar.client_id' => null, 'services.polar.client_secret' => null]);

        $user = User::factory()->create();
        $user->ensureProfile();

        $resp = $this->actingAs($user)->get(route('devices.polar.connect'));

        $resp->assertRedirect('/devices');
        $resp->assertSessionHas('apple_health_error', fn ($m) => str_contains($m, "isn't configured"));
    }

    public function test_connect_redirects_to_polar_when_configured(): void
    {
        $this->configurePolar();

        $user = User::factory()->create();
        $profile = $user->ensureProfile();

        $resp = $this->actingAs($user)->get(route('devices.polar.connect'));

        $resp->assertRedirect();
        $location = $resp->headers->get('Location');
        $this->assertStringContainsString('flow.polar.com/oauth2/authorization', $location);
        $this->assertStringContainsString('state='.$profile->id, $location);
    }

    public function test_callback_handles_user_cancellation(): void
    {
        $this->configurePolar();

        $user = User::factory()->create();
        $user->ensureProfile();

        $resp = $this->actingAs($user)->get(route('devices.polar.callback', ['error' => 'access_denied']));

        $resp->assertRedirect('/devices');
        $resp->assertSessionHas('apple_health_error', 'Polar authorization was cancelled.');
        $this->assertDatabaseCount('wearable_connections', 0);
    }

    public function test_callback_rejects_state_mismatch(): void
    {
        $this->configurePolar();

        $user = User::factory()->create();
        $user->ensureProfile();

        $resp = $this->actingAs($user)->get(route('devices.polar.callback', ['code' => 'abc', 'state' => '999999']));

        $resp->assertRedirect('/devices');
        $resp->assertSessionHas('apple_health_error', fn ($m) => str_contains($m, 'could not be verified'));
        $this->assertDatabaseCount('wearable_connections', 0);
    }

    public function test_successful_callback_records_the_connection(): void
    {
        $this->configurePolar();

        Http::fake([
            'polarremote.com/*' => Http::response([
                'access_token' => 'tok_123',
                'token_type' => 'bearer',
                'x_user_id' => 424242,
                'expires_in' => 3600,
            ], 200),
            // registerUser POSTs to the AccessLink base — accept anything.
            '*' => Http::response('', 200),
        ]);

        $user = User::factory()->create();
        $profile = $user->ensureProfile();

        $resp = $this->actingAs($user)->get(route('devices.polar.callback', [
            'code' => 'auth_code_xyz',
            'state' => (string) $profile->id,
        ]));

        $resp->assertRedirect('/devices');
        $resp->assertSessionHas('status', fn ($m) => str_contains($m, 'Polar connected'));

        $this->assertDatabaseHas('wearable_connections', [
            'profile_id' => $profile->id,
            'source' => 'polar',
            'provider' => 'POLAR',
            'status' => 'connected',
            'terra_user_id' => '424242',
        ]);
    }
}
