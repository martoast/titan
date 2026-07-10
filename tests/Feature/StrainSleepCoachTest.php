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

        $p->activitySessions()->create(['started_at' => now(), 'duration_min' => 40, 'trimp' => 70, 'source' => 'titan_band']);
        $worked = Strain::assess($p)['strain'];

        $this->assertGreaterThan($rest, $worked);
        $this->assertLessThanOrEqual(21.0, $worked);
        $this->assertGreaterThan(0, $worked);
    }

    public function test_all_day_elevated_hr_raises_strain_without_a_logged_workout(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $p->update(['birthdate' => now()->subYears(30)->toDateString(), 'sex' => 'M']);
        $p->recoveryLogs()->create(['logged_at' => now()->toDateString(), 'resting_hr' => 55]);

        // Baseline: a quiet day of resting HR only → should stay light (near-zero HR load).
        $start = now()->startOfDay();
        for ($m = 0; $m < 90; $m++) {
            $p->hrSamples()->create(['recorded_at' => $start->copy()->addMinutes($m), 'bpm' => 58, 'confidence' => 95]);
        }
        $quiet = Strain::assess($p)['strain'];

        // Now 60 minutes of sustained elevated HR (~140 bpm ≈ Z3) with NO workout logged.
        for ($m = 120; $m < 180; $m++) {
            $p->hrSamples()->create(['recorded_at' => $start->copy()->addMinutes($m), 'bpm' => 140, 'confidence' => 95]);
        }
        $elevated = Strain::assess($p)['strain'];

        $this->assertGreaterThan($quiet, $elevated);          // 24/7 HR load moved the number
        $this->assertGreaterThan(0, $elevated);
        $this->assertLessThanOrEqual(21.0, $elevated);
    }

    public function test_hr_load_inside_a_workout_is_not_double_counted(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $p->update(['birthdate' => now()->subYears(30)->toDateString(), 'sex' => 'M']);
        $p->recoveryLogs()->create(['logged_at' => now()->toDateString(), 'resting_hr' => 55]);

        // A logged workout window with its own TRIMP, and elevated HR samples that fall INSIDE it.
        $start = now()->startOfDay()->addHours(9);
        $p->activitySessions()->create([
            'started_at' => $start, 'ended_at' => $start->copy()->addMinutes(45),
            'duration_min' => 45, 'trimp' => 70, 'source' => 'titan_band',
        ]);
        for ($m = 0; $m < 45; $m++) {
            $p->hrSamples()->create(['recorded_at' => $start->copy()->addMinutes($m), 'bpm' => 150, 'confidence' => 95]);
        }

        // Strain should equal the workout-only strain (the in-window HR samples add nothing on top).
        $withHr = Strain::assess($p)['strain'];
        $p->hrSamples()->delete();
        $workoutOnly = Strain::assess($p)['strain'];

        $this->assertEqualsWithDelta($workoutOnly, $withHr, 0.05);   // in-workout HR not double-counted
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
