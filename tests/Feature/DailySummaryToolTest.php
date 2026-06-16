<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Coach\CoachTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailySummaryToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_summary_pulls_the_whole_day(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $today = now()->toDateString();

        $p->recoveryLogs()->create([
            'logged_at' => $today, 'hrv_ms' => 72, 'resting_hr' => 54, 'resp_rate' => 14.2, 'energy' => 8,
        ]);
        $p->sleepLogs()->create(['slept_at' => $today, 'duration_min' => 470, 'quality' => 88]);
        $p->dailyActivity()->create(['date' => $today, 'steps' => 9300, 'floors' => 12, 'source' => 'titan_band']);
        $p->workouts()->create(['performed_at' => now(), 'name' => 'Push day', 'duration_min' => 55]);

        $summary = (new CoachTools($p))->dispatch('daily_summary', ['date' => 'today']);

        $this->assertIsArray($summary);
        $this->assertTrue($summary['is_today']);
        $this->assertSame($today, $summary['date']);

        // Raw wearable vitals come through.
        $this->assertSame(72, $summary['vitals']['hrv_ms']);
        $this->assertSame(54, $summary['vitals']['resting_hr']);
        $this->assertEqualsWithDelta(14.2, $summary['vitals']['resp_rate'], 0.01);

        // The day's other sources are present.
        $this->assertSame(9300, $summary['activity']['steps']);
        $this->assertSame(12, $summary['activity']['floors']);
        $this->assertEqualsWithDelta(7.8, $summary['sleep']['duration_h'], 0.1);
        $this->assertSame('Push day', $summary['workouts'][0]['name']);
        $this->assertArrayHasKey('strain', $summary);
        $this->assertArrayHasKey('focus', $summary);
    }

    public function test_daily_summary_degrades_gracefully_on_an_empty_day(): void
    {
        $p = User::factory()->create()->ensureProfile();

        $summary = (new CoachTools($p))->dispatch('daily_summary', ['date' => 'yesterday']);

        $this->assertIsArray($summary);
        $this->assertFalse($summary['is_today']);
        $this->assertSame('yesterday', $summary['relative']);
        // No vitals logged → a note, not a crash.
        $this->assertIsString($summary['vitals']);
    }

    public function test_daily_summary_is_offered_as_a_tool(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $names = array_map(fn ($t) => $t['function']['name'], (new CoachTools($p))->schemas());
        $this->assertContains('daily_summary', $names);
    }
}
