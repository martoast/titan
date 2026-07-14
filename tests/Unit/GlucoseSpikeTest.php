<?php

namespace Tests\Unit;

use App\Support\GlucoseSpike;
use PHPUnit\Framework\TestCase;

/**
 * The active-spike detector (CGM_INTEGRATION P3) — the trigger for the walk-after-spike nudge. Pure, no DB.
 */
class GlucoseSpikeTest extends TestCase
{
    private const HIGH = 140;   // GlucoseMetrics::RANGE_HIGH

    public function test_elevated_and_still_near_peak_is_a_spike(): void
    {
        // Rising into a spike, latest is the peak — a walk now would help.
        $this->assertTrue(GlucoseSpike::isSpiking([110, 140, 170, 185], self::HIGH));
    }

    public function test_below_the_margin_is_not_a_spike(): void
    {
        // 150 is over range (140) but not over high+SPIKE_MARGIN (155) — edge noise, don't nudge.
        $this->assertFalse(GlucoseSpike::isSpiking([120, 140, 150], self::HIGH));
    }

    public function test_already_recovering_is_not_a_spike(): void
    {
        // Peaked at 190 but has fallen well below peak−tolerance — the moment has passed.
        $this->assertFalse(GlucoseSpike::isSpiking([150, 190, 175, 158], self::HIGH));
    }

    public function test_in_range_is_not_a_spike(): void
    {
        $this->assertFalse(GlucoseSpike::isSpiking([95, 100, 110], self::HIGH));
    }

    public function test_needs_at_least_two_points(): void
    {
        $this->assertFalse(GlucoseSpike::isSpiking([200], self::HIGH));
        $this->assertFalse(GlucoseSpike::isSpiking([], self::HIGH));
    }

    public function test_ignores_null_gaps(): void
    {
        // Nulls (sensor gaps) are dropped; the real series [160,180] still reads as a live spike.
        $this->assertTrue(GlucoseSpike::isSpiking([null, 160, null, 180], self::HIGH));
    }
}
