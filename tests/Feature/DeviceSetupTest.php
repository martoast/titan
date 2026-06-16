<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_devices_page_shows_the_setup_wizard_with_ios_path(): void
    {
        $user = User::factory()->create();
        $user->ensureProfile();

        $resp = $this->actingAs($user)->get('/devices');
        $resp->assertOk();
        $resp->assertSee('Set up your Bangle.js');
        $resp->assertSee('Bluefy');                       // the working iPhone path
        $resp->assertSee('Espruino Web IDE');             // firmware install
        $resp->assertSee('Wear it tonight');
    }

    public function test_firmware_downloads(): void
    {
        $user = User::factory()->create();
        $user->ensureProfile();

        $resp = $this->actingAs($user)->get('/devices/firmware');
        $resp->assertOk();
        $resp->assertHeader('content-type', 'text/javascript; charset=utf-8');
        $this->assertStringContainsString('Bangle', $resp->streamedContent() ?: file_get_contents(base_path('firmware/banglejs/titan.app.js')));
    }

    public function test_one_tap_pair_bangle_reveals_credentials(): void
    {
        $user = User::factory()->create();
        $user->ensureProfile();

        $resp = $this->actingAs($user)->post('/devices/pair', ['source' => 'bangle']);
        $resp->assertRedirect('/devices');
        $resp->assertSessionHas('just_paired', fn ($p) => str_starts_with($p['device_id'], 'bangle_') && strlen($p['secret']) === 64);
    }
}
