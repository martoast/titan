<?php

namespace Tests\Feature;

use App\Jobs\ProcessWindowJob;
use App\Jobs\ReactToSpotReading;
use App\Models\DeviceIngestion;
use App\Models\RecoveryLog;
use App\Models\User;
use App\Services\Coach\CoachTools;
use App\Services\Notifications\NotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SpotReadingTest extends TestCase
{
    use RefreshDatabase;

    public function test_tool_queues_a_capture_command(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $conn = $p->wearableConnections()->create(['provider' => 'TITAN_BAND', 'source' => 'titan_band', 'status' => 'connected']);

        $res = (new CoachTools($p->refresh()))->dispatch('spot_reading', []);
        $this->assertTrue($res['ok']);
        $this->assertSame('capture', $conn->refresh()->pending_commands[0]['type']);
    }

    public function test_tool_is_gated_until_reading_intent(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $core = array_map(fn ($s) => $s['function']['name'], (new CoachTools($p))->schemas());
        $this->assertNotContains('spot_reading', $core);

        $routed = array_map(fn ($s) => $s['function']['name'], (new CoachTools($p))->route('take a reading right now, how recovered am I?')->schemas());
        $this->assertContains('spot_reading', $routed);
    }

    public function test_spot_window_skips_daily_recovery_and_fires_the_reaction(): void
    {
        Storage::fake('raw');
        config(['services.biosignal.url' => 'http://biosignal:8000', 'services.biosignal.token' => 't']);
        Http::fake(['*/process/hrv' => Http::response(['algo_version' => 'v1', 'metrics' => [
            'hrv_ms' => 48, 'resting_hr' => 71, 'valid' => true,
        ]])]);

        $p = User::factory()->create()->ensureProfile();
        $p->wearableConnections()->create(['provider' => 'TITAN_BAND', 'source' => 'titan_band', 'status' => 'connected', 'timezone' => 'UTC']);

        $window = ['kind' => 'ppg_raw', 'purpose' => 'spot', 'ppg' => array_fill(0, 60, 1.0), 'sample_rate_hz' => 25,
            'start' => '2026-06-17T15:00:00Z', 'end' => '2026-06-17T15:01:00Z'];
        $key = "raw/{$p->id}/spot-test.ppg.gz";
        Storage::disk('raw')->put($key, gzencode(json_encode($window)));
        DeviceIngestion::create([
            'batch_uid' => 'spot-1', 'profile_id' => $p->id, 'source' => 'titan_band', 'kind' => 'ppg_raw',
            'object_key' => $key, 'window_start' => CarbonImmutable::parse($window['start']),
            'window_end' => CarbonImmutable::parse($window['end']), 'status' => DeviceIngestion::STATUS_QUEUED,
        ]);

        // The sync queue runs ReactToSpotReading inline, so we see the whole chain's effect.
        dispatch_sync(new ProcessWindowJob('spot-1'));

        // A spot reading is a snapshot — it must NOT create/overwrite the day's recovery row.
        $this->assertDatabaseCount('recovery_logs', 0);
        // The window is marked a processed spot reading…
        $refs = DeviceIngestion::where('batch_uid', 'spot-1')->first()->result_refs;
        $this->assertTrue($refs['spot'] ?? false);
        $this->assertSame(48, $refs['hrv_ms']);
        // …and the reaction posted the interpreted spot card to chat.
        $msg = $p->conversations()->where('title', 'Daily Briefings')->first()?->messages()->latest('id')->first()?->content;
        $this->assertStringContainsString('"type":"spot"', (string) $msg);
        $this->assertStringContainsString('"hrv_ms":48', (string) $msg);
    }

    public function test_reaction_posts_a_spot_card_with_baseline_verdict(): void
    {
        $p = User::factory()->create()->ensureProfile();
        // 14-day baseline ≈ 60ms; a 72ms reading should read "above baseline".
        foreach (range(1, 6) as $d) {
            RecoveryLog::create(['profile_id' => $p->id, 'logged_at' => now()->subDays($d)->toDateString(), 'hrv_ms' => 60]);
        }

        (new ReactToSpotReading($p->id, 72, 54, true))->handle(app(NotificationService::class));

        $this->assertDatabaseHas('notifications', ['profile_id' => $p->id, 'type' => 'spot']);
        $msg = $p->conversations()->where('title', 'Daily Briefings')->first()->messages()->latest('id')->first()->content;
        $this->assertStringContainsString('titan-card', $msg);
        $this->assertStringContainsString('"type":"spot"', $msg);
        $this->assertStringContainsString('"verdict":"high"', $msg);
        $this->assertStringContainsString('"baseline_ms":60', $msg);
    }

    public function test_noisy_capture_asks_for_a_redo(): void
    {
        $p = User::factory()->create()->ensureProfile();

        (new ReactToSpotReading($p->id, null, null, false))->handle(app(NotificationService::class));

        $msg = $p->conversations()->where('title', 'Daily Briefings')->first()->messages()->latest('id')->first()->content;
        $this->assertStringContainsString("couldn't", strtolower($msg));
        $this->assertStringNotContainsString('titan-card', $msg);   // no card on a failed read
    }
}
