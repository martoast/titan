<?php

namespace Tests\Feature;

use App\Jobs\SealNightJob;
use App\Models\WearableConnection;
use App\Services\Lab\NightScript;
use App\Services\Lab\SleepCalibration;
use App\Services\Lab\VirtualBand;
use App\Services\Simulator\BiosignalSimulator;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * SLEEP LAB · the deterministic subset (spec §6). These pin the pure, HTTP-free core of the LAB — NightScript
 * expectation-derivation and VirtualBand wire rendering — so CI catches a drift in the ground-truth math or
 * the wire contract without needing the live biosignal service (the full `sleep:lab` E2E owns that).
 */
class SleepLabTest extends TestCase
{
    private function band(int $seed = 1, ?SleepCalibration $cal = null): VirtualBand
    {
        // An unsaved connection is enough: renderNight/wireWindow never touch the DB (only postSigned does).
        $device = new WearableConnection(['device_id' => 'tb_test', 'timezone' => 'UTC', 'profile_id' => 1]);

        return new VirtualBand(new BiosignalSimulator($seed), $device, 'secret', $cal);
    }

    private function calibration(): SleepCalibration
    {
        return new SleepCalibration(SleepCalibration::defaults());
    }

    public function test_perfect_night_script_derives_its_own_expected_architecture(): void
    {
        $wake = CarbonImmutable::parse('2026-07-10T07:00:00-06:00');
        $script = NightScript::perfectNight('America/Mexico_City', $this->calibration(), $wake);
        $exp = $script->expected();

        // A full night, not a nap; keyed to the local wake date.
        $this->assertFalse($exp['is_nap']);
        $this->assertGreaterThanOrEqual(SealNightJob::NAP_MAX_MIN, $exp['tib_min']);
        $this->assertSame('2026-07-10', $exp['date']);

        // Stage percentages are a partition of asleep time and track the calibration architecture.
        $this->assertEqualsWithDelta(100.0, $exp['deep_pct'] + $exp['rem_pct'] + $exp['light_pct'], 0.5);
        $arch = $this->calibration()->architecture();
        $this->assertEqualsWithDelta($arch['deep_pct'], $exp['deep_pct'], 2.0);
        $this->assertEqualsWithDelta($arch['rem_pct'], $exp['rem_pct'], 2.0);
        $this->assertEqualsWithDelta($arch['efficiency_pct'], $exp['efficiency_pct'], 2.0);

        // Duration = asleep time, and blocks sum to time-in-bed.
        $this->assertSame($exp['asleep_min'], $exp['duration_min']);
        $this->assertSame($script->totalMinutes(), $exp['tib_min']);
    }

    public function test_wire_window_shapes_match_the_ingest_contract(): void
    {
        $band = $this->band();
        $sim = new BiosignalSimulator(1);
        $start = CarbonImmutable::parse('2026-07-10T05:00:00Z');

        $ibi = $band->wireWindow($sim->generateWindow('deep', 30), $start, 30, 'ibi', 'deep');
        $this->assertSame('ibi', $ibi['kind']);
        $this->assertArrayHasKey('ibi_ms', $ibi);
        $this->assertArrayHasKey('accel_counts', $ibi);
        $this->assertSame(0.95, $ibi['confidence']);              // asleep stage → high confidence
        $this->assertStringEndsWith('Z', $ibi['start']);          // ISO8601 Zulu

        $wake = $band->wireWindow($sim->generateWindow('rest', 30), $start, 30, 'ibi', 'wake');
        $this->assertSame(0.7, $wake['confidence']);              // awake-in-bed → lower confidence

        $ppg = $band->wireWindow($sim->generateWindow('rem', 30), $start, 30, 'ppg_raw', 'rem', 25);
        $this->assertSame('ppg_raw', $ppg['kind']);
        $this->assertSame(25, $ppg['sample_rate_hz']);
        $this->assertArrayHasKey('ppg', $ppg);
        $this->assertArrayNotHasKey('accel_counts', $ppg);        // raw PPG omits accel, like the T2 log
    }

    public function test_render_night_places_sparse_duty_cycle_windows_and_honours_a_charge_gap(): void
    {
        $tz = 'America/Mexico_City';
        $bed = CarbonImmutable::parse('2026-07-10T05:00:00Z');
        $blocks = [
            ['stage' => 'light', 'minutes' => 60],
            ['stage' => 'deep', 'minutes' => 60],
            ['stage' => 'rem', 'minutes' => 60],
        ];
        $duty = ['burst_sec' => 30, 'period_sec' => 180];

        $clean = new NightScript('t', [3], $tz, $bed, $blocks, $duty);
        $withGap = new NightScript('t', [3], $tz, $bed, $blocks, $duty, chargeGaps: [
            ['start_min' => 60, 'dur_min' => 30],   // 30-min top-up in the middle → a NODATA hole
        ]);

        $cleanWins = $this->band()->renderNight($clean)['live'];
        $gapWins = $this->band()->renderNight($withGap)['live'];

        // A 180-min night at a 30s/180s cadence ≈ 60 bursts; the gap must DROP windows, never concatenate.
        $this->assertGreaterThan(40, count($cleanWins));
        $this->assertLessThan(count($cleanWins), count($gapWins));

        // No live window may fall inside the charge gap [bed+60m, bed+90m).
        $gapFrom = $bed->addMinutes(60)->timestamp;
        $gapTo = $bed->addMinutes(90)->timestamp;
        foreach ($gapWins as $w) {
            $ts = CarbonImmutable::parse($w['start'])->timestamp;
            $this->assertFalse($ts >= $gapFrom && $ts < $gapTo, 'a window landed inside the charge gap');
        }
    }

    public function test_ble_drop_windows_are_buffered_for_replay(): void
    {
        $bed = CarbonImmutable::parse('2026-07-10T05:00:00Z');
        $script = new NightScript('t', [3], 'UTC', $bed, [
            ['stage' => 'light', 'minutes' => 40],
            ['stage' => 'deep', 'minutes' => 40],
        ], ['burst_sec' => 30, 'period_sec' => 180], bleDrops: [
            ['start_min' => 10, 'dur_min' => 15, 'replay_skew_sec' => 3600],
        ]);

        $rendered = $this->band()->renderNight($script);
        $this->assertNotEmpty($rendered['buffered'], 'a BLE drop must buffer windows for store-and-forward replay');
        $this->assertSame(3600, $rendered['buffered'][0]['skew']);
    }

    public function test_render_night_is_deterministic_by_seed(): void
    {
        $bed = CarbonImmutable::parse('2026-07-10T05:00:00Z');
        $script = fn () => new NightScript('t', [3], 'UTC', $bed, [
            ['stage' => 'deep', 'minutes' => 30],
        ], ['burst_sec' => 30, 'period_sec' => 180]);

        $a = $this->band(seed: 7)->renderNight($script())['live'];
        $b = $this->band(seed: 7)->renderNight($script())['live'];

        $this->assertSame($a[0]['ibi_ms'], $b[0]['ibi_ms'], 'same seed must render an identical night');
    }
}
