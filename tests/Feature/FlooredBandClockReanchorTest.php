<?php

namespace Tests\Feature;

use App\Models\DeviceIngestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A dead-battery band boots with a FLOORED clock, not a 1970 one — and that used to sail straight past
 * the ingest guard.
 *
 * The firmware deliberately floors its RTC on boot (`CFG.CLOCK_FLOOR`, currently 2026-01-01) so frames
 * are "at least plausible", and its comment promises "the server re-anchors the offset". The server's
 * own guard, though, only re-anchored below an ABSOLUTE floor of 2020-01-01 — which the firmware's
 * floored value clears comfortably. The two guards cancelled: the firmware moved the bad timestamp from
 * a value we caught (1970) to one we ignored.
 *
 * Live cost, 2026-08-17: Tester B's band rebooted, floored to 2026-01-01, and free-ran with a rock-steady
 * 227.7-day offset. 144 windows of a real night were filed as 2026-01-01 and sealed as a confident
 * "Jan 1" night, while her actual night was simply missing from the app.
 *
 * These tests pin the relative staleness rule that replaces the date, and — the part the batch-wide
 * offset exists for — that the SPACING between a batch's windows survives the correction.
 */
class FlooredBandClockReanchorTest extends TestCase
{
    use RefreshDatabase;

    private string $sharedKey;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*' => Http::response([])]);
        Storage::fake('raw');   // no MinIO in the test container
        Queue::fake();          // these assert the LEDGER's timestamps, not the DSP behind them
        $user = User::factory()->create();
        $user->ensureProfile();
        $this->sharedKey = hash('sha256', 'test-device-secret');
        $user->profile->wearableConnections()->create([
            'provider' => 'device', 'source' => 'titan_band', 'status' => 'connected',
            'device_id' => 'band-1', 'device_token_hash' => $this->sharedKey, 'timezone' => 'UTC',
        ]);
    }

    /** One ppg_raw window exactly as the band builds it, over an arbitrary [start,end]. */
    private function ppgWindow(\Carbon\CarbonInterface $start, \Carbon\CarbonInterface $end): array
    {
        // 30 s @ 25 Hz of a plausible, non-flat waveform — WindowSanity drops a flatline at the door.
        $ppg = [];
        for ($i = 0; $i < 750; $i++) {
            $ppg[] = 512 + (int) round(80 * sin($i / 4.0));
        }

        return [
            'kind' => 'ppg_raw',
            'start' => $start->toIso8601ZuluString(),
            'end' => $end->toIso8601ZuluString(),
            'ppg' => $ppg,
            'sample_rate_hz' => 25,
            'src' => 'banglejs2',
        ];
    }

    /** @param array<int,array<string,mixed>> $windows */
    private function send(array $windows): \Illuminate\Testing\TestResponse
    {
        $body = json_encode([
            'batch_uid' => substr(hash('sha256', json_encode($windows).microtime()), 0, 32),
            'windows' => $windows,
        ]);
        $t = (string) time();
        $v1 = hash_hmac('sha256', $t.'.'.$body, $this->sharedKey);

        return $this->call('POST', '/api/devices/ingest', [], [], [], [
            'HTTP_X_DEVICE_ID' => 'band-1',
            'HTTP_X_TITAN_SIGNATURE' => "t={$t},v1={$v1}",
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body);
    }

    public function test_a_floored_clock_window_lands_on_today_not_on_the_firmware_floor(): void
    {
        // The exact shape of Tester B's night: the firmware floor, months stale, above the 2020 date floor.
        $bandStart = now()->setDate(2026, 1, 1)->setTime(8, 13, 58);
        $this->send([$this->ppgWindow($bandStart, $bandStart->copy()->addSeconds(30))])
            ->assertStatus(202);

        $row = DeviceIngestion::sole();

        $this->assertNotSame('2026-01-01', $row->window_end->toDateString(),
            'a floored band clock was stored verbatim — the re-anchor never fired');
        $this->assertTrue($row->window_end->diffInMinutes(now()) < 5,
            'a re-anchored window must land at the upload time, got '.$row->window_end->toIso8601String());
    }

    public function test_the_batch_offset_preserves_the_spacing_between_windows(): void
    {
        // Three windows 3 minutes apart on a floored clock — the cadence a real night arrives at. A
        // per-window re-anchor would pile all three onto `now` and destroy the night's hypnogram grid.
        $base = now()->setDate(2026, 1, 1)->setTime(8, 13, 58);
        $this->send([
            $this->ppgWindow($base, $base->copy()->addSeconds(30)),
            $this->ppgWindow($base->copy()->addMinutes(3), $base->copy()->addMinutes(3)->addSeconds(30)),
            $this->ppgWindow($base->copy()->addMinutes(6), $base->copy()->addMinutes(6)->addSeconds(30)),
        ])->assertStatus(202);

        $ends = DeviceIngestion::orderBy('window_end')->pluck('window_end');

        $this->assertCount(3, $ends);
        $this->assertSame(180, (int) $ends[0]->diffInSeconds($ends[1]), 'lost the gap between windows 1 and 2');
        $this->assertSame(180, (int) $ends[1]->diffInSeconds($ends[2]), 'lost the gap between windows 2 and 3');
        // ...and the newest still lands at upload time, so the night is dated today.
        $this->assertTrue($ends[2]->diffInMinutes(now()) < 5);
    }

    public function test_a_correctly_clocked_live_window_is_never_touched(): void
    {
        $start = now()->subMinutes(2);
        $end = now()->subMinutes(1)->subSeconds(30);
        $this->send([$this->ppgWindow($start, $end)])->assertStatus(202);

        $row = DeviceIngestion::sole();
        $this->assertSame($end->toIso8601ZuluString(), $row->window_end->clone()->utc()->toIso8601ZuluString(),
            'a good clock must pass through untouched');
    }

    public function test_the_hr_and_motion_trend_points_are_corrected_too(): void
    {
        // The T5/T10 trend points carried the band's epoch through with NO clock guard at all, and they
        // are the FIRST thing a rebooted band dumps (it banks them to the ring while offline). On Tester B's
        // night that put 1,240 samples on 1970/Jan-1 dates, covering hours no PPG window reached.
        $bandNow = now()->setDate(2026, 1, 1)->setTime(15, 0, 0);
        $body = json_encode([
            'batch_uid' => substr(hash('sha256', 'trend'.microtime()), 0, 32),
            'summaries' => [
                ['kind' => 'hr_trend', 'samples' => [
                    ['t' => $bandNow->copy()->subMinutes(1)->timestamp, 'bpm' => 58, 'conf' => 90],
                    ['t' => $bandNow->timestamp, 'bpm' => 61, 'conf' => 92],
                ]],
                ['kind' => 'motion_trend', 'samples' => [
                    ['t' => $bandNow->copy()->subMinutes(1)->timestamp, 'motion' => 12],
                    ['t' => $bandNow->timestamp, 'motion' => 30],
                ]],
            ],
        ]);
        $t = (string) time();
        $v1 = hash_hmac('sha256', $t.'.'.$body, $this->sharedKey);
        $this->call('POST', '/api/devices/ingest', [], [], [], [
            'HTTP_X_DEVICE_ID' => 'band-1',
            'HTTP_X_TITAN_SIGNATURE' => "t={$t},v1={$v1}",
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body)->assertStatus(202);

        foreach ([\App\Models\HrSample::class, \App\Models\MotionSample::class] as $model) {
            $rows = $model::orderBy('recorded_at')->get();
            $this->assertCount(2, $rows, $model.': both points must land');
            $this->assertNotSame('2026-01-01', $rows->last()->recorded_at->toDateString(),
                $model.": a floored band clock wrote a Jan-1 sample");
            $this->assertTrue($rows->last()->recorded_at->diffInMinutes(now()) < 5, $model.': newest point should be ~now');
            // ...and the 1-minute spacing between the two points survives the correction.
            $this->assertSame(60, (int) $rows[0]->recorded_at->diffInSeconds($rows[1]->recorded_at));
        }
    }

    public function test_a_genuine_offline_backlog_is_not_mistaken_for_a_bad_clock(): void
    {
        // Store-and-forward is a real feature: the band buffers while unsynced and replays later. The
        // log ring holds ~16 h, so a day-old replay is legitimate data and must keep its OWN timestamps
        // — re-anchoring it would file last night's sleep as this morning's.
        $start = now()->subHours(20);
        $end = $start->copy()->addSeconds(30);
        $this->send([$this->ppgWindow($start, $end)])->assertStatus(202);

        $row = DeviceIngestion::sole();
        $this->assertSame($end->toIso8601ZuluString(), $row->window_end->clone()->utc()->toIso8601ZuluString(),
            'a legitimate 20h-old offline replay was re-anchored — that destroys store-and-forward');
    }
}
