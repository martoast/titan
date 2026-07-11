<?php

namespace Tests\Feature;

use App\Models\MotionSample;
use App\Models\User;
use App\Services\Wearables\DeviceIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The continuous overnight-motion channel: the band's `motion_trend` summary (T10 frames) →
 * motion_samples rows. The night seal reads these for the sleep-timeline movement strip; here we pin
 * the ingestion half (persist, dedup, clamp, skip-unstamped).
 */
class MotionTrendTest extends TestCase
{
    use RefreshDatabase;

    private function ingest(User $user, array $samples): void
    {
        $conn = $user->profile->wearableConnections()->create([
            'provider' => 'device', 'source' => 'titan-band', 'status' => 'connected',
        ]);
        app(DeviceIngestionService::class)->ingest($conn, [
            'summaries' => [['kind' => 'motion_trend', 'samples' => $samples]],
        ]);
    }

    public function test_motion_trend_summary_persists_samples(): void
    {
        $user = User::factory()->create();
        $user->ensureProfile();
        $t = Carbon::now('UTC')->timestamp;

        $this->ingest($user, [
            ['t' => $t - 60, 'motion' => 120],
            ['t' => $t - 30, 'motion' => 40],
            ['t' => $t, 'motion' => 900],
            ['t' => 0, 'motion' => 77],          // unstamped → dropped
        ]);

        $this->assertSame(3, MotionSample::where('profile_id', $user->profile->id)->count());
        // The wire's uint16 range is honored (column is a smallint) — an over-range value is clamped.
        $this->ingest($user, [['t' => $t + 30, 'motion' => 999999]]);
        $this->assertSame(65535, (int) MotionSample::where('profile_id', $user->profile->id)
            ->orderByDesc('recorded_at')->first()->motion);
    }

    public function test_resend_does_not_double_insert(): void
    {
        $user = User::factory()->create();
        $user->ensureProfile();
        $t = Carbon::now('UTC')->timestamp;
        $batch = [['t' => $t - 30, 'motion' => 30], ['t' => $t, 'motion' => 60]];

        $this->ingest($user, $batch);
        $this->ingest($user, $batch);   // same points again (retry / ring overlap)

        $this->assertSame(2, MotionSample::where('profile_id', $user->profile->id)->count());
    }
}
