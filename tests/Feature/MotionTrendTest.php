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

    public function test_recorded_at_is_stored_in_app_tz_so_the_seal_grid_aligns(): void
    {
        // A connection whose phone tz differs from the server's app tz. recorded_at must still be stored
        // in APP tz (like device_ingestions.window_start), because the night seal reads it back through
        // the app-tz `datetime` cast and maps it onto window_start's epoch grid — storing the phone's tz
        // would land the whole night's movement on the wrong instants on any non-matching server.
        $user = User::factory()->create();
        $user->ensureProfile();
        $conn = $user->profile->wearableConnections()->create([
            'provider' => 'device', 'source' => 'titan-band', 'status' => 'connected',
            'timezone' => 'Pacific/Kiritimati',   // +14 — almost certainly ≠ the test's app tz
        ]);
        $t = Carbon::create(2026, 7, 10, 3, 30, 0, 'UTC')->timestamp;
        app(DeviceIngestionService::class)->ingest($conn, [
            'summaries' => [['kind' => 'motion_trend', 'samples' => [['t' => $t, 'motion' => 200]]]],
        ]);

        // Read back exactly as the seal does (raw stored wall-clock, interpreted in app tz) → the wire
        // instant must be recovered. If storage used the connection tz, this would be off by +14h.
        $stored = MotionSample::where('profile_id', $user->profile->id)->first()->getRawOriginal('recorded_at');
        $recovered = Carbon::parse((string) $stored, config('app.timezone'))->timestamp;
        $this->assertSame($t, $recovered, 'recorded_at round-trips to the wire instant under the app-tz cast');
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
