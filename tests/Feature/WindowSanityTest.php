<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Wearables\DeviceIngestionService;
use App\Services\Wearables\WindowSanity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WindowSanityTest extends TestCase
{
    use RefreshDatabase;

    /** A realistic, varying PPG waveform (not a flatline). */
    private function ppg(int $n = 1500): array
    {
        return array_map(fn ($i) => 2048 + (int) round(300 * sin($i / 4.0)), range(0, $n - 1));
    }

    public function test_a_clean_ppg_window_passes(): void
    {
        $w = ['kind' => 'ppg_raw', 'ppg' => $this->ppg(), 'sample_rate_hz' => 25,
            'start' => '2026-06-18T02:00:00Z', 'end' => '2026-06-18T02:01:00Z'];   // 60s @ 25Hz = 1500
        $this->assertTrue(WindowSanity::check($w, \Carbon\CarbonImmutable::parse('2026-06-18T03:00:00Z'))['ok']);
    }

    public function test_a_clean_ibi_window_passes(): void
    {
        $w = ['kind' => 'ibi', 'ibi_ms' => [812, 798, 805, 820, 790, 808], 'start' => '2026-06-18T02:00:00Z', 'end' => '2026-06-18T02:00:05Z'];
        $this->assertTrue(WindowSanity::check($w)['ok']);
    }

    public function test_corrupt_windows_are_rejected_with_the_right_reason(): void
    {
        $now = \Carbon\CarbonImmutable::parse('2026-06-18T03:00:00Z');
        $flat = array_fill(0, 1500, 2048);
        $varying = $this->ppg();

        $cases = [
            'ppg_flatline' => ['kind' => 'ppg_raw', 'ppg' => $flat, 'sample_rate_hz' => 25, 'start' => '2026-06-18T02:00:00Z', 'end' => '2026-06-18T02:01:00Z'],
            'ppg_non_finite' => ['kind' => 'ppg_raw', 'ppg' => [1, 2, 'NaN', 4, 5, 6, 7, 8, 9, 10], 'sample_rate_hz' => 25],
            'ppg_too_short' => ['kind' => 'ppg_raw', 'ppg' => [1, 2, 3], 'sample_rate_hz' => 25],
            'bad_sample_rate' => ['kind' => 'ppg_raw', 'ppg' => $varying, 'sample_rate_hz' => 0],
            // 1500 samples across 5s implies 300Hz, but the device claims 25Hz → grossly wrong clock.
            'sample_rate_mismatch' => ['kind' => 'ppg_raw', 'ppg' => $varying, 'sample_rate_hz' => 25, 'start' => '2026-06-18T02:00:00Z', 'end' => '2026-06-18T02:00:05Z'],
            'ibi_empty' => ['kind' => 'ibi', 'ibi_ms' => []],
            'ibi_implausible' => ['kind' => 'ibi', 'ibi_ms' => [50, 60, 9000, 12000]],
            'ibi_flatline' => ['kind' => 'ibi', 'ibi_ms' => [800, 800, 800, 800, 800, 800]],
            'ibi_non_finite' => ['kind' => 'ibi', 'ibi_ms' => [800, null, 810]],
            'end_before_start' => ['kind' => 'ibi', 'ibi_ms' => [810, 800], 'start' => '2026-06-18T02:05:00Z', 'end' => '2026-06-18T02:00:00Z'],
            'timestamp_in_future' => ['kind' => 'ibi', 'ibi_ms' => [810, 800], 'start' => '2026-06-25T00:00:00Z', 'end' => '2026-06-25T00:01:00Z'],
            'window_too_long' => ['kind' => 'ppg_raw', 'ppg' => $varying, 'sample_rate_hz' => 25, 'start' => '2026-06-17T00:00:00Z', 'end' => '2026-06-18T02:00:00Z'],
        ];

        foreach ($cases as $expectedReason => $window) {
            $res = WindowSanity::check($window, $now);
            $this->assertFalse($res['ok'], "expected '{$expectedReason}' window to be rejected");
            $this->assertSame($expectedReason, $res['reason']);
        }
    }

    public function test_missing_sample_rate_is_rejected(): void
    {
        $res = WindowSanity::check(['kind' => 'ppg_raw', 'ppg' => $this->ppg()]);
        $this->assertFalse($res['ok']);
        $this->assertSame('bad_sample_rate', $res['reason']);
    }

    public function test_ingest_drops_corrupt_windows_but_keeps_clean_ones(): void
    {
        Storage::fake('raw');
        $p = User::factory()->create()->ensureProfile();
        $conn = $p->wearableConnections()->create(['provider' => 'TITAN_BAND', 'source' => 'titan_band', 'status' => 'connected', 'timezone' => 'UTC']);

        $result = app(DeviceIngestionService::class)->ingest($conn, [
            'batch_uid' => (string) \Illuminate\Support\Str::ulid(),
            'windows' => [
                ['kind' => 'ibi', 'ibi_ms' => [812, 798, 805, 820], 'start' => '2026-06-18T02:00:00Z', 'end' => '2026-06-18T02:00:04Z'],   // clean
                ['kind' => 'ppg_raw', 'ppg' => array_fill(0, 1500, 2048), 'sample_rate_hz' => 25, 'start' => '2026-06-18T02:00:00Z', 'end' => '2026-06-18T02:01:00Z'], // flatline
            ],
        ]);

        $this->assertSame(1, $result['windows_queued']);
        $this->assertSame(1, $result['windows_rejected']);
        $this->assertDatabaseCount('device_ingestions', 1);   // only the clean window was ledgered
    }
}
