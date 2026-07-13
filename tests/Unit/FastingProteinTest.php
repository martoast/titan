<?php

namespace Tests\Unit;

use App\Support\EatingWindow;
use App\Support\FastingProtein;
use PHPUnit\Framework\TestCase;

/**
 * The fasting protein guardrail (FASTING_EVIDENCE T4) — flag when the eating window is too short to fit
 * the protein still needed today, because fasting must not undercut muscle (elders/lifters need MORE
 * protein). Pure math here; the profile-level check() (target + today's protein) is field-tested on real
 * data by Henry.
 */
class FastingProteinTest extends TestCase
{
    public function test_achievable_scales_with_remaining_window_hours(): void
    {
        $this->assertSame(0.0, FastingProtein::achievableG(0));
        $this->assertSame(120.0, FastingProtein::achievableG(6));   // 6h × 20 g/h
        $this->assertSame(0.0, FastingProtein::achievableG(-3));    // clamped
    }

    public function test_squeeze_only_when_more_protein_than_time_allows(): void
    {
        // 6h → 120g achievable + 20g margin = 140g threshold.
        $this->assertTrue(FastingProtein::isSqueezed(150, 6));   // 150 > 140 → genuinely tight
        $this->assertFalse(FastingProtein::isSqueezed(130, 6));  // 130 ≤ 140 → doable, no nag
        // The margin: a high-protein 16:8 morning (176g, 8h → 160g achievable) does NOT flag at the start.
        $this->assertFalse(FastingProtein::isSqueezed(176, 8));  // 176 ≤ 180 threshold
        $this->assertTrue(FastingProtein::isSqueezed(185, 8));   // 185 > 180 → behind, flag
        // Window closed with real protein still owed → squeezed.
        $this->assertTrue(FastingProtein::isSqueezed(40, 0));    // 40 > 20 margin
        $this->assertFalse(FastingProtein::isSqueezed(15, 0));   // only 15g left → don't nag
        // No protein remaining → never a squeeze.
        $this->assertFalse(FastingProtein::isSqueezed(0, 2));
        $this->assertFalse(FastingProtein::isSqueezed(-10, 2));
    }

    public function test_window_hours_remaining_open_before_and_after(): void
    {
        $start = EatingWindow::startMinute('12:00');
        $len = EatingWindow::windowLengthMin('16:8');   // 12:00–20:00
        $this->assertSame(8.0, EatingWindow::windowHoursRemaining(10 * 60, $start, $len));   // 10:00 → whole window ahead
        $this->assertSame(2.0, EatingWindow::windowHoursRemaining(18 * 60, $start, $len));   // 18:00 → 2h left
        $this->assertSame(0.0, EatingWindow::windowHoursRemaining(21 * 60, $start, $len));   // 21:00 → done
    }
}
