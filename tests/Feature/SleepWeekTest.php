<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SleepWeek;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The Sleep Week aggregate: 7-night row + cumulative score + streak + heat strip + tip, honest about
 * low-confidence nights (shown but excluded from the score + streak).
 */
class SleepWeekTest extends TestCase
{
    use RefreshDatabase;

    private function profile(): \App\Models\Profile
    {
        $p = User::factory()->create()->ensureProfile();
        $p->update(['settings' => ['sleep_target_h' => 8]]);   // baseline 8 → 8h night = 100%

        return $p->refresh();
    }

    private function night(\App\Models\Profile $p, int $agoDays, float $hours, bool $low = false): void
    {
        $p->sleepLogs()->create([
            'slept_at' => Carbon::today()->subDays($agoDays)->toDateString(),
            'is_nap' => false, 'duration_min' => (int) round($hours * 60),
            'bedtime' => '23:00:00', 'wake_time' => '07:00:00',
            'stage_status' => 'final', 'low_confidence' => $low, 'updated_via' => 'biosignal:sealed',
        ]);
    }

    public function test_week_shape_and_score(): void
    {
        $p = $this->profile();
        for ($d = 6; $d >= 0; $d--) {
            $this->night($p, $d, 8.0);   // every night 100%
        }
        $week = SleepWeek::forProfile($p);

        $this->assertCount(7, $week['days']);
        $this->assertSame('2026', substr($week['days'][0]['date'], 0, 4));  // oldest first, real date
        $this->assertSame(100, $week['week_score']);
        $this->assertSame('excellent', $week['week_band']);
        $this->assertSame(7, $week['nights_logged']);
        $this->assertSame(7, $week['streak']['current']);
        $this->assertNotNull($week['tip']);
    }

    public function test_low_confidence_night_is_excluded_from_score_and_streak(): void
    {
        $p = $this->profile();
        for ($d = 6; $d >= 1; $d--) {
            $this->night($p, $d, 8.0);
        }
        $this->night($p, 0, 4.0, low: true);   // last night: low-signal 4h estimate

        $week = SleepWeek::forProfile($p);

        // The low-confidence night is shown but scored null and not counted.
        $last = collect($week['days'])->firstWhere('date', Carbon::today()->toDateString());
        $this->assertTrue($last['logged']);
        $this->assertTrue($last['low_confidence']);
        $this->assertNull($last['score']);
        $this->assertFalse($last['hit_need']);
        $this->assertSame(6, $week['nights_logged']);              // only the 6 confident nights
        $this->assertSame(100, $week['week_score']);               // not dragged down by the estimate
        $this->assertFalse($week['streak']['slept_well_last_night']);
    }

    public function test_missing_days_are_hollow_not_hidden(): void
    {
        $p = $this->profile();
        $this->night($p, 1, 8.0);   // only one night this week
        $week = SleepWeek::forProfile($p);

        $this->assertCount(7, $week['days']);
        $logged = collect($week['days'])->filter(fn ($d) => $d['logged']);
        $this->assertCount(1, $logged);
        $this->assertTrue(collect($week['days'])->contains(fn ($d) => $d['logged'] === false && $d['score'] === null));
    }
}
