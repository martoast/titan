<?php

namespace Tests\Unit;

use App\Support\EatingWindow;
use PHPUnit\Framework\TestCase;

/**
 * The eating-window math (FASTING_EVIDENCE T2). Pure — window bounds, wrap-aware containment, and per-day
 * adherence. No DB (the meal-grouped streak in forProfile is exercised by Henry on real data).
 */
class EatingWindowTest extends TestCase
{
    public function test_plan_window_lengths(): void
    {
        $this->assertSame(8 * 60, EatingWindow::windowLengthMin('16:8'));
        $this->assertSame(10 * 60, EatingWindow::windowLengthMin('14:10'));
        $this->assertSame(60, EatingWindow::windowLengthMin('omad'));
    }

    public function test_start_minute_of_day(): void
    {
        $this->assertSame(12 * 60, EatingWindow::startMinute('12:00'));
        $this->assertSame(20 * 60 + 30, EatingWindow::startMinute('20:30'));
    }

    public function test_same_day_window_containment(): void
    {
        $start = EatingWindow::startMinute('12:00');   // 16:8 window → 12:00–20:00
        $len = EatingWindow::windowLengthMin('16:8');
        $this->assertTrue(EatingWindow::contains(12 * 60, $start, $len));       // 12:00 open
        $this->assertTrue(EatingWindow::contains(19 * 60 + 59, $start, $len));  // 19:59 still in
        $this->assertFalse(EatingWindow::contains(20 * 60, $start, $len));      // 20:00 closed (exclusive end)
        $this->assertFalse(EatingWindow::contains(8 * 60, $start, $len));       // 08:00 before window
    }

    public function test_window_crossing_midnight_is_wrap_aware(): void
    {
        $start = EatingWindow::startMinute('20:00');   // 16:8 window → 20:00–04:00
        $len = EatingWindow::windowLengthMin('16:8');
        $this->assertTrue(EatingWindow::contains(23 * 60, $start, $len));   // 23:00 in
        $this->assertTrue(EatingWindow::contains(1 * 60, $start, $len));    // 01:00 next-day still in
        $this->assertFalse(EatingWindow::contains(5 * 60, $start, $len));   // 05:00 out
        $this->assertFalse(EatingWindow::contains(19 * 60, $start, $len));  // 19:00 before it opens
    }

    public function test_day_adherence(): void
    {
        $start = EatingWindow::startMinute('12:00');
        $len = EatingWindow::windowLengthMin('16:8');   // 12:00–20:00
        // All meals inside → adherent.
        $this->assertTrue(EatingWindow::dayAdherent([13 * 60, 15 * 60, 19 * 60], $start, $len));
        // One meal outside (breakfast at 08:00) → not adherent.
        $this->assertFalse(EatingWindow::dayAdherent([8 * 60, 13 * 60], $start, $len));
        // No meals logged → null (doesn't count for or against the streak).
        $this->assertNull(EatingWindow::dayAdherent([], $start, $len));
    }
}
