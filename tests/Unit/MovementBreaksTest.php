<?php

namespace Tests\Unit;

use App\Support\MovementBreaks;
use PHPUnit\Framework\TestCase;

class MovementBreaksTest extends TestCase
{
    public function test_active_day_meets_the_goal_with_short_sits(): void
    {
        // Movement spread across every waking hour 7–22.
        $hourly = array_fill(0, 24, 2);
        for ($h = 7; $h < 23; $h++) {
            $hourly[$h] = 60;
        }
        $r = MovementBreaks::assess($hourly);

        $this->assertNotNull($r);
        $this->assertSame(16, $r['active']);
        $this->assertSame(16, $r['waking']);
        $this->assertSame(0, $r['longest_sit']);
        $this->assertTrue($r['met']);
    }

    public function test_long_unbroken_sit_is_detected(): void
    {
        // Active morning, then a 6-hour sedentary block in the afternoon.
        $hourly = array_fill(0, 24, 2);
        for ($h = 7; $h < 12; $h++) {
            $hourly[$h] = 80;
        }                                   // 7–11 active
        for ($h = 12; $h < 18; $h++) {
            $hourly[$h] = 1;
        }                                   // 12–17 sedentary (6 h)
        for ($h = 18; $h < 23; $h++) {
            $hourly[$h] = 80;
        }                                   // 18–22 active
        $r = MovementBreaks::assess($hourly);

        $this->assertSame(10, $r['active']);
        $this->assertSame(6, $r['longest_sit']);
        $this->assertTrue($r['met']);          // still 10 active hours
    }

    public function test_null_without_a_valid_hourly_profile(): void
    {
        $this->assertNull(MovementBreaks::assess(null));
        $this->assertNull(MovementBreaks::assess([1, 2, 3]));        // wrong length
        $this->assertNull(MovementBreaks::assess(array_fill(0, 24, 0))); // no activity
    }
}
