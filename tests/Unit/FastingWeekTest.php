<?php

namespace Tests\Unit;

use App\Support\FastingWeek;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Fasting history helpers (FASTING_EVIDENCE T5) — fast length + goal-hit. Pure; the full week payload
 * (recent fasts, strip, streak) is DB-backed and field-tested by Henry.
 */
class FastingWeekTest extends TestCase
{
    public function test_fast_hours_from_start_and_end(): void
    {
        $start = Carbon::parse('2026-07-13 20:00:00');
        $this->assertSame(16.0, FastingWeek::fastHours($start, $start->copy()->addHours(16)));
        $this->assertSame(13.5, FastingWeek::fastHours($start, $start->copy()->addMinutes(810)));
    }

    public function test_goal_hit_with_rounding_tolerance(): void
    {
        $this->assertTrue(FastingWeek::hitGoal(16.0, 16));
        $this->assertTrue(FastingWeek::hitGoal(15.95, 16));   // within tolerance
        $this->assertFalse(FastingWeek::hitGoal(14.0, 16));   // short
        $this->assertTrue(FastingWeek::hitGoal(18.0, 16));    // over
        // Null / zero goal falls back to the default 16h.
        $this->assertFalse(FastingWeek::hitGoal(12.0, null));
        $this->assertTrue(FastingWeek::hitGoal(16.0, null));
    }
}
