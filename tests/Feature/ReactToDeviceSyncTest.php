<?php

namespace Tests\Feature;

use App\Jobs\ReactToDeviceSync;
use App\Models\Notification;
use App\Models\Profile;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Services\Wearables\DeviceIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ReactToDeviceSyncTest extends TestCase
{
    use RefreshDatabase;

    /** Seed a recovery baseline so Readiness::compute returns a real score. */
    private function withReadiness(): Profile
    {
        $p = User::factory()->create()->ensureProfile();
        $p->update(['settings' => array_merge($p->settings ?? [], ['timezone' => 'UTC'])]);
        foreach (range(0, 16) as $d) {
            $p->recoveryLogs()->create([
                'logged_at' => Carbon::today()->subDays($d)->toDateString(),
                'hrv_ms' => 65 + ($d % 4), 'resting_hr' => 52 + ($d % 3), 'updated_via' => 'biosignal:sealed',
            ]);
        }
        $p->sleepLogs()->create(['slept_at' => Carbon::today()->toDateString(), 'duration_min' => 450, 'quality' => 80]);

        return $p->refresh();
    }

    public function test_recovery_ingest_dispatches_the_reaction(): void
    {
        Bus::fake();
        $p = User::factory()->create()->ensureProfile();
        $conn = $p->wearableConnections()->create(['provider' => 'device', 'source' => 'titan-band', 'status' => 'connected']);

        app(DeviceIngestionService::class)->ingest($conn, [
            'summaries' => [['kind' => 'recovery', 'date' => Carbon::today()->toDateString(), 'hrv_ms' => 68, 'resting_hr' => 51]],
        ]);

        Bus::assertDispatched(ReactToDeviceSync::class, fn ($j) => $j->profileId === $p->id);
    }

    public function test_reaction_posts_the_morning_read_and_dedupes(): void
    {
        $p = $this->withReadiness();

        (new ReactToDeviceSync($p->id))->handle(app(NotificationService::class));

        $this->assertDatabaseHas('notifications', ['profile_id' => $p->id, 'type' => 'sync']);
        $brief = $p->conversations()->where('title', 'Daily Briefings')->first();
        $this->assertNotNull($brief);
        $msg = $brief->messages()->where('role', 'assistant')->latest('id')->first();
        $this->assertStringContainsString('synced', $msg->content);
        $this->assertSame(Carbon::now('UTC')->toDateString(), data_get($p->refresh()->settings, 'device_greeted'));

        // Second sync the same day → no duplicate greeting.
        (new ReactToDeviceSync($p->id))->handle(app(NotificationService::class));
        $this->assertSame(1, Notification::where('profile_id', $p->id)->where('type', 'sync')->count());
    }

    public function test_reaction_respects_the_briefing_preference(): void
    {
        $p = $this->withReadiness();
        \App\Support\Reminders::setIntensity($p, 'minimal');
        \App\Support\Reminders::setType($p->refresh(), 'briefing', false);

        (new ReactToDeviceSync($p->id))->handle(app(NotificationService::class));
        $this->assertSame(0, Notification::where('profile_id', $p->id)->count());
    }

    public function test_reaction_waits_when_readiness_not_ready(): void
    {
        $p = User::factory()->create()->ensureProfile();   // no recovery data → no score yet
        (new ReactToDeviceSync($p->id))->handle(app(NotificationService::class));

        $this->assertSame(0, Notification::where('profile_id', $p->id)->count());
        $this->assertNull(data_get($p->refresh()->settings, 'device_greeted'));   // not marked → a later sync can react
    }
}
