<?php

namespace Tests\Unit;

use App\Models\Profile;
use App\Support\StepGoal;
use Tests\TestCase;

class StepGoalTest extends TestCase
{
    public function test_target_is_personalized_by_age(): void
    {
        $young = new Profile(['birthdate' => now()->subYears(30)->toDateString()]);
        $older = new Profile(['birthdate' => now()->subYears(68)->toDateString()]);

        // Benefit plateaus earlier with age (Paluch 2022) — and neither is the 10k myth.
        $this->assertSame(8500, StepGoal::targetFor($young));
        $this->assertSame(7000, StepGoal::targetFor($older));
        $this->assertSame(8000, StepGoal::targetFor(null));          // sensible default
        $this->assertLessThan(10000, StepGoal::targetFor($young));
    }

    public function test_assess_bands_and_progress(): void
    {
        $reached = StepGoal::assess(9000, 8500);
        $this->assertSame('excellent', $reached['band']);
        $this->assertSame(100, $reached['pct']);
        $this->assertSame(0, $reached['to_go']);

        $building = StepGoal::assess(3000, 8500);
        $this->assertSame('low', $building['band']);
        $this->assertSame(5500, $building['to_go']);
        $this->assertEqualsWithDelta(35, $building['pct'], 1);       // 3000/8500 ≈ 35%

        $this->assertSame('good', StepGoal::assess(7200, 8500)['band']);
        $this->assertSame('fair', StepGoal::assess(5000, 8500)['band']);
    }
}
