<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\DailyFocus;
use App\Support\SleepCoach;
use App\Support\Strain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StrainSleepCoachTest extends TestCase
{
    use RefreshDatabase;

    public function test_strain_target_tracks_recovery(): void
    {
        $this->assertSame('push', Strain::targetFor(80)['mode']);      // well recovered → push
        $this->assertSame('maintain', Strain::targetFor(50)['mode']);
        $this->assertSame('restrain', Strain::targetFor(20)['mode']);  // run down → hold back
    }

    public function test_strain_rises_with_workout_load(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $p->dailyActivity()->create(['date' => now()->toDateString(), 'steps' => 2000, 'source' => 'manual']);
        $rest = Strain::assess($p)['strain'];

        $p->activitySessions()->create(['started_at' => now(), 'trimp' => 70, 'source' => 'titan_band']);
        $worked = Strain::assess($p)['strain'];

        $this->assertGreaterThan($rest, $worked);
        $this->assertLessThanOrEqual(21.0, $worked);
        $this->assertGreaterThan(0, $worked);
    }

    public function test_sleep_coach_flags_debt_on_short_nights(): void
    {
        $p = User::factory()->create()->ensureProfile();
        for ($d = 1; $d <= 5; $d++) {
            $p->sleepLogs()->create(['slept_at' => now()->subDays($d)->toDateString(), 'duration_min' => 300]); // 5 h
        }

        $s = SleepCoach::assess($p);
        $this->assertNotNull($s);
        $this->assertGreaterThan(1.5, $s['debt_h']);          // real debt accumulated
        $this->assertLessThan(80, $s['performance_pct']);     // last night fell short
        $this->assertContains($s['band'], ['debt', 'low']);
        $this->assertGreaterThan($s['baseline_h'], $s['need_h']); // need is raised by the debt
    }

    public function test_sleep_coach_rewards_full_nights(): void
    {
        $p = User::factory()->create()->ensureProfile();
        for ($d = 1; $d <= 5; $d++) {
            $p->sleepLogs()->create(['slept_at' => now()->subDays($d)->toDateString(), 'duration_min' => 485]); // ~8 h
        }

        $s = SleepCoach::assess($p);
        $this->assertGreaterThanOrEqual(90, $s['performance_pct']);
        $this->assertLessThan(1.0, $s['debt_h']);
        $this->assertContains($s['band'], ['optimal', 'good']);
    }

    public function test_daily_focus_prioritises_sleep_when_in_debt(): void
    {
        $p = User::factory()->create()->ensureProfile();
        for ($d = 1; $d <= 4; $d++) {
            $p->sleepLogs()->create(['slept_at' => now()->subDays($d)->toDateString(), 'duration_min' => 290]); // ~4.8 h
        }

        $focus = DailyFocus::compute($p);                     // no recovery data → readiness null → debt wins
        $this->assertSame('sleep', $focus['focus']);
        $this->assertNotEmpty($focus['headline']);
    }
}
