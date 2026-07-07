<?php

namespace Tests\Feature;

use App\Jobs\SealNightJob;
use App\Models\DeviceIngestion;
use App\Models\SleepLog;
use App\Models\User;
use App\Services\Wearables\BiosignalClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The lost-sleep-stages bug: a watch-confirmed night seals the instant the "I'm awake" marker lands,
 * but each raw PPG/accel window only gains the per-epoch motion staging needs once its ProcessWindowJob
 * has run. When a night replays in bulk (offline overnight → reconnect), the seal can outrun that
 * processing — staging on nothing, writing a duration-only row, and marking the raw windows sealed so
 * the stages are lost for good. The seal must instead DEFER until its own windows are processed.
 */
class SleepSealStagingDeferTest extends TestCase
{
    use RefreshDatabase;

    private function window(int $profileId, int $start, int $end, string $status): DeviceIngestion
    {
        return DeviceIngestion::create([
            'batch_uid' => substr(hash('sha256', $start.'-'.$end.'-'.$status.'-'.mt_rand()), 0, 40),
            'profile_id' => $profileId,
            'source' => 'titan_band',
            'kind' => 'ppg_raw',
            'status' => $status,
            'window_start' => Carbon::createFromTimestamp($start, 'UTC'),
            'window_end' => Carbon::createFromTimestamp($end, 'UTC'),
        ]);
    }

    public function test_confirmed_seal_defers_while_its_windows_are_still_processing(): void
    {
        config(['services.biosignal.url' => 'http://biosignal:8000', 'services.biosignal.token' => 't']);

        $profile = User::factory()->create()->ensureProfile();
        $wake = time();
        $bed = $wake - 410 * 60;   // a 6h50m night

        // The night's raw windows have arrived but are still QUEUED (ProcessWindowJob hasn't run yet).
        $w1 = $this->window($profile->id, $bed + 60, $bed + 300, DeviceIngestion::STATUS_QUEUED);

        Queue::fake();
        (new SealNightJob($profile->id, null, true, $bed, $wake, 0))->handle(app(BiosignalClient::class));

        $this->assertSame(0, SleepLog::where('profile_id', $profile->id)->count(), 'must not seal before the staging inputs are ready');
        $this->assertSame(DeviceIngestion::STATUS_QUEUED, $w1->refresh()->status, 'pending windows must not be sealed away un-staged');
        Queue::assertPushed(SealNightJob::class, fn (SealNightJob $j) => $j->confirmed && $j->stagingDefers === 1);
    }

    public function test_falls_back_to_duration_only_once_windows_are_done_but_thin(): void
    {
        config(['services.biosignal.url' => 'http://biosignal:8000', 'services.biosignal.token' => 't']);

        $profile = User::factory()->create()->ensureProfile();
        $wake = time();
        $bed = $wake - 410 * 60;

        // Processed, but no epoch motion (genuinely thin signal) → staging can't produce stages.
        $this->window($profile->id, $bed + 60, $bed + 300, DeviceIngestion::STATUS_PROCESSED);

        Queue::fake();
        (new SealNightJob($profile->id, null, true, $bed, $wake, 0))->handle(app(BiosignalClient::class));

        Queue::assertNotPushed(SealNightJob::class);
        $log = SleepLog::where('profile_id', $profile->id)->where('is_nap', false)->first();
        $this->assertNotNull($log, 'once its windows are done, an honest duration-only night is written');
        $this->assertEqualsWithDelta(410, (int) $log->duration_min, 1);
        $this->assertNull($log->deep_min, 'no fabricated stages');
        $this->assertSame('biosignal:sealed-session-marker', $log->updated_via);
    }

    public function test_deferral_is_bounded_so_a_stuck_night_still_seals(): void
    {
        config(['services.biosignal.url' => 'http://biosignal:8000', 'services.biosignal.token' => 't']);

        $profile = User::factory()->create()->ensureProfile();
        $wake = time();
        $bed = $wake - 410 * 60;
        $this->window($profile->id, $bed + 60, $bed + 300, DeviceIngestion::STATUS_QUEUED);

        Queue::fake();
        // At the defer cap, stop waiting and write the honest fallback rather than looping forever.
        (new SealNightJob($profile->id, null, true, $bed, $wake, SealNightJob::MAX_STAGING_DEFERS))
            ->handle(app(BiosignalClient::class));

        Queue::assertNotPushed(SealNightJob::class);
        $this->assertSame(1, SleepLog::where('profile_id', $profile->id)->count(), 'at the cap, the honest duration-only row is written');
    }
}
