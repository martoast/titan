<?php

namespace Tests\Unit;

use App\Models\DailyActivity;
use PHPUnit\Framework\TestCase;

/**
 * Steps must be BAND-primary with an Apple-Health fallback — per day, never max across the two, never
 * sum (review 2026-07-13). Covers the pure merge decision `mergeCumulativeValue`. No DB.
 */
class DailyActivityMergeTest extends TestCase
{
    /** band write, non-zero, day not yet owned → takes over whatever health had (even if smaller). */
    public function test_band_takes_over_from_a_larger_health_value(): void
    {
        // Health had 8000; the band syncs 6000 → the trusted band wins, not the larger phone number.
        $this->assertSame(6000, DailyActivity::mergeCumulativeValue(8000, 6000, incomingBand: true, rowBandOwned: false));
    }

    /** band re-sync on a band-owned day → grows monotonically. */
    public function test_band_grows_monotonically_when_it_owns_the_day(): void
    {
        $this->assertSame(6900, DailyActivity::mergeCumulativeValue(6000, 6900, incomingBand: true, rowBandOwned: true));
        $this->assertSame(6900, DailyActivity::mergeCumulativeValue(6900, 6000, incomingBand: true, rowBandOwned: true)); // a lower re-sync can't shrink it
    }

    /** health write on a band-owned day → skipped; the band's number stays. */
    public function test_health_cannot_override_a_band_owned_day(): void
    {
        $this->assertSame(6881, DailyActivity::mergeCumulativeValue(6881, 9000, incomingBand: false, rowBandOwned: true));
    }

    /** health-only day → health fills (max within health). */
    public function test_health_fills_an_unclaimed_day(): void
    {
        $this->assertSame(5373, DailyActivity::mergeCumulativeValue(0, 5373, incomingBand: false, rowBandOwned: false));
        $this->assertSame(5373, DailyActivity::mergeCumulativeValue(5000, 5373, incomingBand: false, rowBandOwned: false));
    }

    /** the band-0 edge: a band write of 0 must NOT lock out the health fallback. */
    public function test_band_zero_does_not_lock_out_health(): void
    {
        // band 0 on an unclaimed day → keep current (0), don't claim ownership…
        $this->assertSame(0, DailyActivity::mergeCumulativeValue(0, 0, incomingBand: true, rowBandOwned: false));
        // …so a later health write still fills the day (ownership stays false in mergeDaily).
        $this->assertSame(2000, DailyActivity::mergeCumulativeValue(0, 2000, incomingBand: false, rowBandOwned: false));
    }

    /** null current value tolerated (first write of the day). */
    public function test_null_current_is_treated_as_zero(): void
    {
        $this->assertSame(1200, DailyActivity::mergeCumulativeValue(null, 1200, incomingBand: true, rowBandOwned: false));
    }
}
