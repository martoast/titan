<?php

namespace Tests\Unit;

use App\Support\GlucoseMetrics;
use PHPUnit\Framework\TestCase;

/**
 * The headline CGM metrics (CGM_INTEGRATION P1) — average / TIR / variability / GMI. Pure, no DB.
 */
class GlucoseMetricsTest extends TestCase
{
    public function test_mean_sd_cv(): void
    {
        $vals = [90, 110, 100, 120, 80];   // mean 100
        $this->assertSame(100.0, GlucoseMetrics::mean($vals));
        // population SD of {90,110,100,120,80} about mean 100 = sqrt((100+100+0+400+400)/5) = sqrt(200)
        $this->assertEqualsWithDelta(sqrt(200), GlucoseMetrics::sd($vals), 0.001);
        $this->assertEqualsWithDelta(sqrt(200) / 100 * 100, GlucoseMetrics::cv($vals), 0.001);
    }

    public function test_time_in_range(): void
    {
        // 70–140 band: 3 of 5 inside (60 and 200 are out).
        $this->assertEqualsWithDelta(60.0, GlucoseMetrics::timeInRange([60, 90, 120, 200, 100]), 0.001);
        // custom band
        $this->assertEqualsWithDelta(100.0, GlucoseMetrics::timeInRange([90, 100, 110], 80, 120), 0.001);
    }

    public function test_gmi_from_mean_matches_the_ada_formula(): void
    {
        // GMI = 3.31 + 0.02392 × mean. Mean 100 → 5.7%.
        $this->assertSame(5.7, GlucoseMetrics::gmi(100.0));
        $this->assertSame(GlucoseMetrics::gmi(100.0), GlucoseMetrics::gmi([90, 110, 100, 120, 80]));
    }

    public function test_summary_shape_and_stability_flag(): void
    {
        $s = GlucoseMetrics::summary([90, 110, 100, 120, 80]);
        $this->assertSame(5, $s['n']);
        $this->assertSame(100, $s['average_mg_dl']);
        $this->assertSame(100, $s['time_in_range_pct']);   // all within 70–140
        $this->assertSame(70, $s['range_low']);
        $this->assertSame(80, $s['min_mg_dl']);
        $this->assertSame(120, $s['max_mg_dl']);
        // CV = sqrt(200)/100 ≈ 14.1% < 36 → stable.
        $this->assertTrue($s['stable']);
    }

    public function test_empty_and_null_tolerant(): void
    {
        $this->assertNull(GlucoseMetrics::mean([]));
        $this->assertNull(GlucoseMetrics::timeInRange([null, null]));
        $this->assertSame(['n' => 0], GlucoseMetrics::summary([]));
        // A spiky day: nulls ignored, high CV → not stable.
        $s = GlucoseMetrics::summary([70, null, 200, 90, 210, 80]);
        $this->assertSame(5, $s['n']);
        $this->assertFalse($s['stable']);
    }
}
