<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Coach\CoachTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PairBandTest extends TestCase
{
    use RefreshDatabase;

    public function test_pair_band_creates_connection_and_returns_a_pairing_card(): void
    {
        $p = User::factory()->create()->ensureProfile();

        $res = (new CoachTools($p))->dispatch('pair_band', []);

        $this->assertTrue($res['ok']);
        $this->assertSame('pairing', $res['card']['type']);
        $this->assertNotEmpty($res['card']['steps']);

        // A connected titan_band connection now exists.
        $conn = $p->wearableConnections()->where('source', 'titan_band')->first();
        $this->assertNotNull($conn);
        $this->assertSame('connected', $conn->status);
        $this->assertNotNull($conn->device_token_hash);

        // The bridge link carries a one-time pair token whose creds are cached.
        $this->assertStringContainsString('/devices/bridge?pair=', $res['card']['bridge_url']);
        $token = explode('pair=', $res['card']['bridge_url'])[1];
        $creds = Cache::get("titan:pair:{$token}");
        $this->assertSame($conn->id, $creds['connection_id']);
        $this->assertSame(hash('sha256', $creds['secret']), $conn->device_token_hash);
    }

    public function test_pair_band_is_gated_until_connect_intent(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $core = array_map(fn ($s) => $s['function']['name'], (new CoachTools($p))->schemas());
        $this->assertNotContains('pair_band', $core);

        $routed = array_map(fn ($s) => $s['function']['name'], (new CoachTools($p))->route('I got my band, help me connect it')->schemas());
        $this->assertContains('pair_band', $routed);
    }

    public function test_bridge_resolves_a_pair_token_one_time(): void
    {
        $u = User::factory()->create();
        $p = $u->ensureProfile();

        $res = (new CoachTools($p))->dispatch('pair_band', []);
        $token = explode('pair=', $res['card']['bridge_url'])[1];

        // First visit: the bridge loads the just-paired creds from the token.
        $this->actingAs($u)->get("/devices/bridge?pair={$token}")
            ->assertOk()
            ->assertViewHas('justPaired', fn ($jp) => is_array($jp) && ! empty($jp['secret']));

        // Token is single-use — pulled out of the cache.
        $this->assertNull(Cache::get("titan:pair:{$token}"));
    }
}
