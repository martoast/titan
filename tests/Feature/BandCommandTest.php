<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WearableConnection;
use App\Services\Coach\CoachTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BandCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_queue_and_drain_round_trip(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $conn = $p->wearableConnections()->create(['provider' => 'device', 'source' => 'titan-band', 'status' => 'connected']);

        $conn->queueCommand('buzz');
        $conn->queueCommand('sync');
        $this->assertCount(2, $conn->refresh()->pending_commands);

        $drained = $conn->drainCommands();
        $this->assertSame('buzz', $drained[0]['type']);
        $this->assertSame('sync', $drained[1]['type']);
        $this->assertSame([], $conn->refresh()->pending_commands);   // cleared on read
    }

    public function test_coach_buzz_tool_queues_a_command(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $conn = $p->wearableConnections()->create(['provider' => 'device', 'source' => 'titan-band', 'status' => 'connected']);

        $res = (new CoachTools($p->refresh()))->dispatch('buzz_band', []);
        $this->assertTrue($res['ok']);
        $this->assertSame('buzz', $conn->refresh()->pending_commands[0]['type']);
    }

    public function test_command_tools_guide_when_no_band(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $res = (new CoachTools($p))->dispatch('request_sync', []);
        $this->assertArrayHasKey('note', $res);
    }

    public function test_command_tools_are_gated_until_device_intent(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $core = array_map(fn ($s) => $s['function']['name'], (new CoachTools($p))->schemas());
        $this->assertNotContains('buzz_band', $core);
        $this->assertContains('device_status', $core);   // status is core, control is gated

        $routed = array_map(fn ($s) => $s['function']['name'], (new CoachTools($p))->route('find my band, make it buzz')->schemas());
        $this->assertContains('buzz_band', $routed);
    }

    public function test_poll_endpoint_drains_with_hmac(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $sharedKey = hash('sha256', 'test-device-secret');   // = device_token_hash; the band keys the HMAC on this
        $conn = $p->wearableConnections()->create([
            'provider' => 'device', 'source' => 'titan-band', 'status' => 'connected',
            'device_id' => 'band-1', 'device_token_hash' => $sharedKey,
        ]);
        $conn->queueCommand('buzz');

        $t = (string) time();
        $v1 = hash_hmac('sha256', $t.'.', $sharedKey);   // GET body is empty
        $resp = $this->withHeaders(['X-Device-Id' => 'band-1', 'X-Titan-Signature' => "t={$t},v1={$v1}", 'Accept' => 'application/json'])
            ->get('/api/devices/commands');

        $resp->assertOk()->assertJsonPath('commands.0.type', 'buzz');
        $this->assertSame([], $conn->refresh()->pending_commands);   // drained
    }
}
