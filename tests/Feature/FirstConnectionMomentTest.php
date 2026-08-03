<?php

namespace Tests\Feature;

use App\Jobs\ReactToFirstConnection;
use App\Models\User;
use App\Services\Wearables\DeviceIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FirstConnectionMomentTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_data_after_pairing_dispatches_the_live_moment(): void
    {
        Queue::fake();
        $p = User::factory()->create()->ensureProfile();
        $conn = $p->wearableConnections()->create([
            'provider' => 'TITAN_BAND', 'source' => 'titan_band', 'status' => 'connected',
            'device_id' => 'band-x', 'last_sync_at' => null,
        ]);

        app(DeviceIngestionService::class)->ingest($conn->refresh(), [
            'batch_uid' => 'b1',
            'windows' => [['kind' => 'ibi', 'ibi_ms' => [800, 810, 790], 'start' => now()->toIso8601String(), 'end' => now()->toIso8601String()]],
        ]);

        Queue::assertPushed(ReactToFirstConnection::class, fn ($j) => $j->connectionId === $conn->id);
    }

    public function test_second_sync_does_not_re_announce(): void
    {
        Queue::fake();
        $p = User::factory()->create()->ensureProfile();
        $conn = $p->wearableConnections()->create([
            'provider' => 'TITAN_BAND', 'source' => 'titan_band', 'status' => 'connected',
            'device_id' => 'band-y', 'last_sync_at' => now()->subHour(),   // already synced before
        ]);

        app(DeviceIngestionService::class)->ingest($conn->refresh(), [
            'batch_uid' => 'b2',
            'windows' => [['kind' => 'ibi', 'ibi_ms' => [800], 'start' => now()->toIso8601String(), 'end' => now()->toIso8601String()]],
        ]);

        Queue::assertNotPushed(ReactToFirstConnection::class);
    }

    public function test_empty_heartbeat_does_not_trigger(): void
    {
        Queue::fake();
        $p = User::factory()->create()->ensureProfile();
        $conn = $p->wearableConnections()->create([
            'provider' => 'TITAN_BAND', 'source' => 'titan_band', 'status' => 'connected',
            'device_id' => 'band-z', 'last_sync_at' => null,
        ]);

        // A bare check-in with no windows/summaries shouldn't fake a "you're live" moment.
        app(DeviceIngestionService::class)->ingest($conn->refresh(), ['batch_uid' => 'b3', 'device' => ['battery' => 88]]);

        Queue::assertNotPushed(ReactToFirstConnection::class);
    }

    public function test_the_job_posts_a_one_time_celebration(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $conn = $p->wearableConnections()->create([
            'provider' => 'TITAN_BAND', 'source' => 'titan_band', 'status' => 'connected',
            'device_id' => 'band-1', 'battery_pct' => 76,
        ]);

        (new ReactToFirstConnection($conn->id))->handle(app(\App\Services\Notifications\NotificationService::class));

        $this->assertDatabaseHas('notifications', ['profile_id' => $p->id, 'type' => 'band_live']);
        $convo = $p->conversations()->days()->first();
        $this->assertNotNull($convo);
        $this->assertStringContainsString('is live', $convo->messages()->latest('id')->first()->content);
        $this->assertContains($conn->id, $p->refresh()->settings['bands_announced']);

        // Run again → no second notification (deduped per band).
        (new ReactToFirstConnection($conn->id))->handle(app(\App\Services\Notifications\NotificationService::class));
        $this->assertSame(1, \App\Models\Notification::where('profile_id', $p->id)->where('type', 'band_live')->count());
    }
}
