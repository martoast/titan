<?php

namespace Tests\Unit;

use App\Support\GlucoseResponse;
use PHPUnit\Framework\TestCase;

/**
 * A meal's glucose response math (CGM_INTEGRATION P2) — baseline → peak Δ, time-to-peak, time-to-baseline,
 * and the 2-h incremental AUC. Pure, no DB.
 */
class GlucoseResponseTest extends TestCase
{
    /** A classic post-meal curve: baseline 90, rises to a 145 peak at 45 min, back to baseline by 100 min. */
    private array $curve = [
        ['min' => 0, 'mg' => 90],
        ['min' => 15, 'mg' => 110],
        ['min' => 30, 'mg' => 135],
        ['min' => 45, 'mg' => 145],
        ['min' => 60, 'mg' => 130],
        ['min' => 90, 'mg' => 105],
        ['min' => 100, 'mg' => 92],
        ['min' => 120, 'mg' => 88],
    ];

    public function test_peak_delta_and_timing(): void
    {
        $r = GlucoseResponse::compute(90, $this->curve);
        $this->assertSame(145, $r['peak_mg_dl']);
        $this->assertSame(55, $r['peak_delta']);        // 145 − 90
        $this->assertSame(45, $r['time_to_peak_min']);
        $this->assertSame(100, $r['time_to_baseline_min']);  // first ≤ 100 (baseline+10) after the peak
        $this->assertSame('large', $r['spike']);        // Δ55 ≥ 50
    }

    public function test_incremental_auc_is_area_above_baseline(): void
    {
        // AUC only counts glucose ABOVE baseline, trapezoidal over 2 h. A flat-at-baseline curve → 0.
        $flat = [['min' => 0, 'mg' => 90], ['min' => 60, 'mg' => 90], ['min' => 120, 'mg' => 90]];
        $this->assertSame(0.0, GlucoseResponse::incrementalAuc(90, $flat));
        // A simple triangle: 0→+60 over 0–120 min back to 0 → area = ½·base·height = ½·120·60 = 3600.
        $tri = [['min' => 0, 'mg' => 90], ['min' => 60, 'mg' => 150], ['min' => 120, 'mg' => 90]];
        $this->assertEqualsWithDelta(3600.0, GlucoseResponse::incrementalAuc(90, $tri), 0.001);
    }

    public function test_spike_bands(): void
    {
        $this->assertSame('small', GlucoseResponse::spikeBand(20));
        $this->assertSame('moderate', GlucoseResponse::spikeBand(40));
        $this->assertSame('large', GlucoseResponse::spikeBand(60));
    }

    public function test_no_return_to_baseline_is_null(): void
    {
        // Rises and stays elevated within the window → time_to_baseline_min null (honest: didn't recover).
        $r = GlucoseResponse::compute(90, [['min' => 0, 'mg' => 90], ['min' => 30, 'mg' => 150], ['min' => 120, 'mg' => 140]]);
        $this->assertSame(60, $r['peak_delta']);
        $this->assertNull($r['time_to_baseline_min']);
    }

    public function test_empty_returns_null(): void
    {
        $this->assertNull(GlucoseResponse::compute(90, []));
        $this->assertNull(GlucoseResponse::compute(90, [['min' => -5, 'mg' => 90]]));   // only pre-meal
    }
}
