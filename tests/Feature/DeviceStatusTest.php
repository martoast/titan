<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WearableConnection;
use App\Services\Coach\CoachTools;
use App\Support\DeviceStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DeviceStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_unpaired_when_no_connection(): void
    {
        $s = DeviceStatus::assess(User::factory()->create()->ensureProfile());
        $this->assertFalse($s['paired']);
        $this->assertSame('unpaired', $s['verdict']);
        $this->assertStringContainsString('Pair', $s['guidance']);
    }

    public function test_fresh_sync_reads_as_live_with_battery_and_streams(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $p->wearableConnections()->create([
            'provider' => 'device', 'source' => 'titan-band', 'status' => 'connected',
            'last_sync_at' => Carbon::now()->subMinutes(10), 'last_payload_type' => 'recovery',
            'battery_pct' => 72, 'firmware' => 'P0.4',
        ]);
        $p->recoveryLogs()->create(['logged_at' => Carbon::today()->toDateString(), 'hrv_ms' => 70, 'resting_hr' => 52]);
        $p->sleepLogs()->create(['slept_at' => Carbon::today()->toDateString(), 'duration_min' => 460]);

        $s = DeviceStatus::assess($p->refresh());
        $this->assertTrue($s['paired']);
        $this->assertTrue($s['connected']);
        $this->assertSame('live', $s['verdict']);
        $this->assertSame('Band', $s['source']);
        $this->assertSame(72, $s['battery_pct']);
        $this->assertContains('recovery', $s['streams']);
        $this->assertContains('sleep', $s['streams']);
    }

    public function test_old_sync_reads_as_offline_with_guidance(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $p->wearableConnections()->create([
            'provider' => 'device', 'source' => 'titan-band', 'status' => 'connected',
            'last_sync_at' => Carbon::now()->subDays(5),
        ]);

        $s = DeviceStatus::assess($p->refresh());
        $this->assertSame('offline', $s['verdict']);
        $this->assertFalse($s['connected']);
        $this->assertStringContainsString('sync', $s['guidance']);
    }

    public function test_ingest_captures_battery_and_firmware_telemetry(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $conn = $p->wearableConnections()->create(['provider' => 'device', 'source' => 'titan-band', 'status' => 'connected']);

        app(\App\Services\Wearables\DeviceIngestionService::class)->ingest($conn, [
            'device' => ['battery' => 64, 'firmware' => 'P0.5'],
            'summaries' => [],
        ]);

        $conn->refresh();
        $this->assertSame(64, $conn->battery_pct);
        $this->assertSame('P0.5', $conn->firmware);
        $this->assertNotNull($conn->last_sync_at);
    }

    public function test_tool_returns_a_device_card(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $p->wearableConnections()->create(['provider' => 'device', 'source' => 'titan-band', 'status' => 'connected', 'last_sync_at' => Carbon::now()->subMinutes(5), 'battery_pct' => 80]);

        $res = (new CoachTools($p->refresh()))->dispatch('device_status', []);
        $this->assertSame('device', $res['card']['type']);
        $this->assertSame(80, $res['card']['battery_pct']);
        $this->assertStringContainsString('titan-card', $res['_show']);
    }
}
