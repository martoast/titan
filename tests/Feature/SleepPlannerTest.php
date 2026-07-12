<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SleepPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The Sleep Planner: bedtime = target wake − tonight's sleep need − a fall-asleep buffer, from data we
 * already compute. Degrades honestly (null) without sleep history rather than inventing a bedtime.
 */
class SleepPlannerTest extends TestCase
{
    use RefreshDatabase;

    private function profileWithNights(string $wake = '06:45:00'): \App\Models\Profile
    {
        $p = User::factory()->create()->ensureProfile();
        for ($d = 1; $d <= 7; $d++) {
            $p->sleepLogs()->create([
                'slept_at' => Carbon::today()->subDays($d)->toDateString(),
                'is_nap' => false, 'duration_min' => 450,   // 7.5h
                'bedtime' => '23:00:00', 'wake_time' => $wake,
                'stage_status' => 'final', 'updated_via' => 'biosignal:sealed',
            ]);
        }

        return $p->refresh();
    }

    public function test_plans_a_bedtime_before_the_typical_wake(): void
    {
        $plan = SleepPlanner::plan($this->profileWithNights('06:45:00'));

        $this->assertNotNull($plan);
        $this->assertSame('06:45', $plan['target_wake']);          // median of the nights' wake times
        $this->assertMatchesRegularExpression('/^\d\d:\d\d$/', $plan['bedtime']);
        // ~7.5h+ of need before a 06:45 wake lands bedtime in the evening.
        $bedHour = (int) substr($plan['bedtime'], 0, 2);
        $this->assertGreaterThanOrEqual(20, $bedHour);
        $this->assertLessThanOrEqual(23, $bedHour);
        $this->assertCount(2, $plan['window']);
        $this->assertNotEmpty($plan['reason']);
    }

    public function test_explicit_target_wake_is_respected(): void
    {
        $plan = SleepPlanner::plan($this->profileWithNights(), '05:30');
        $this->assertSame('05:30', $plan['target_wake']);
    }

    public function test_null_without_sleep_history(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $this->assertNull(SleepPlanner::plan($p));
    }
}
