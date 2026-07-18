<?php

namespace Tests\Feature;

use App\Jobs\SealNightJob;
use App\Models\DeviceIngestion;
use App\Models\RecoveryLog;
use App\Models\User;
use App\Services\Wearables\BiosignalClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A CONFIRMED session (start/stop on the watch → SealNightJob with bed/wake) must produce a recovery_logs
 * row — whole-night HRV/RHR from its own overnight IBI — not just sleep stages. Regression for the bug where
 * sealConfirmedSession staged sleep but never called sealRecovery (only the auto path did), so a night
 * recorded on the watch left readiness permanently HRV-blind (stuck provisional/sleep-only) despite the band
 * being worn all night.
 */
class ConfirmedSealRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmed_night_seal_writes_a_recovery_log_from_overnight_ibi(): void
    {
        config(['services.biosignal.url' => 'http://biosignal:8000', 'services.biosignal.token' => 't']);

        Http::fake([
            '*/process/sleep' => function ($request) {
                $data = $request->data();
                $n = (int) round((Carbon::parse($data['end'])->timestamp - Carbon::parse($data['start'])->timestamp) / 30);

                return Http::response(['algo_version' => 'test', 'metrics' => [
                    'duration_min' => 300, 'deep_min' => 60, 'rem_min' => 50, 'light_min' => 180, 'awake_min' => 10,
                    'bedtime' => $data['start'], 'wake_time' => $data['end'], 'quality' => 82,
                    'coverage' => 0.9, 'hypnogram_30s' => array_fill(0, max(1, $n), 'light'),
                ]]);
            },
            '*/process/hrv' => Http::response(['algo_version' => 'test', 'metrics' => [
                'hrv_ms' => 68, 'resting_hr' => 52, 'resp_rate' => 13.5, 'valid' => true,
            ]]),
        ]);

        $profile = User::factory()->create()->ensureProfile();
        $tz = config('app.timezone');
        $wake = time();
        $n = 600;                     // 300-min night (> NAP_MAX_MIN, so it seals as a night not a nap)
        $bed = $wake - $n * 30;

        // Duty-cycle IBI bursts across the night — each window carries the persisted ibi_ms that
        // ProcessWindowJob extracts from ppg_raw; rmssd under the artifact ceiling so it isn't dropped.
        for ($e = 0; $e < $n; $e += 30) {
            $start = $bed + $e * 30;
            $end = $start + 30 * 30;
            $w = DeviceIngestion::create([
                'batch_uid' => substr(hash('sha256', $profile->id.'-'.$e.'-'.mt_rand()), 0, 40),
                'profile_id' => $profile->id,
                'source' => 'titan_band',
                'kind' => 'ppg_raw',
                'status' => DeviceIngestion::STATUS_PROCESSED,
                'window_start' => Carbon::createFromTimestamp($start, $tz),
                'window_end' => Carbon::createFromTimestamp($end, $tz),
                'result_refs' => ['ibi_ms' => array_fill(0, 20, 950), 'rmssd' => 45],
            ]);
            $w->forceFill(['created_at' => Carbon::createFromTimestamp($end, $tz)])->saveQuietly();
        }

        Queue::fake();
        (new SealNightJob($profile->id, null, true, $bed, $wake, 0))->handle(app(BiosignalClient::class));

        $recovery = RecoveryLog::where('profile_id', $profile->id)->first();
        $this->assertNotNull($recovery, 'the confirmed night seal wrote a recovery_logs row');
        $this->assertSame(68, $recovery->hrv_ms, 'whole-night HRV came from the overnight IBI');
        $this->assertSame(52, $recovery->resting_hr);
    }
}
