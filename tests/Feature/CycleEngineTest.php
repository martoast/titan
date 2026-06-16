<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Cycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CycleEngineTest extends TestCase
{
    use RefreshDatabase;

    private function femaleProfile(array $cycleSettings = [])
    {
        $p = User::factory()->create()->ensureProfile();
        $p->update(['sex' => 'F', 'settings' => ['timezone' => 'UTC', 'cycle' => ['enabled' => true] + $cycleSettings]]);

        return $p->refresh();
    }

    public function test_no_data_prompts_to_log(): void
    {
        $p = $this->femaleProfile();
        $s = Cycle::status($p, Carbon::parse('2026-06-16'));
        $this->assertFalse($s['has_data']);
        $this->assertStringContainsString('No cycle logged', $s['note']);
    }

    public function test_phase_progression_across_a_28_day_cycle(): void
    {
        $p = $this->femaleProfile();
        $start = Carbon::parse('2026-06-01');
        Cycle::startPeriod($p, $start);

        // Day 2 → menstrual
        $this->assertSame('menstrual', Cycle::status($p, $start->copy()->addDays(1))['phase']);
        // Day 7 → follicular (after a 5-day period, before the fertile window starts on day 9)
        $this->assertSame('follicular', Cycle::status($p, $start->copy()->addDays(6))['phase']);
        // Day 14 → ovulation (28 − 14 luteal)
        $this->assertSame('ovulation', Cycle::status($p, $start->copy()->addDays(13))['phase']);
        // Day 12 → fertile window (ov − 2)
        $this->assertSame('fertile', Cycle::status($p, $start->copy()->addDays(11))['phase']);
        // Day 22 → luteal
        $this->assertSame('luteal', Cycle::status($p, $start->copy()->addDays(21))['phase']);
    }

    public function test_predictions_and_fertile_window(): void
    {
        $p = $this->femaleProfile();
        $start = Carbon::parse('2026-06-01');
        Cycle::startPeriod($p, $start);

        $s = Cycle::status($p, $start->copy()->addDays(6));   // cycle day 7
        $this->assertSame(7, $s['cycle_day']);
        $this->assertSame('2026-06-29', $s['next_period']['date']);   // +28
        $this->assertSame(22, $s['next_period']['in_days']);          // 29 − 7
        $this->assertSame('2026-06-14', $s['ovulation']['date']);     // day 14
        $this->assertSame('2026-06-09', $s['fertile_window']['start']); // ov − 5
        $this->assertSame('2026-06-15', $s['fertile_window']['end']);   // ov + 1
    }

    public function test_conception_likelihood_peaks_around_ovulation(): void
    {
        $p = $this->femaleProfile();
        $start = Carbon::parse('2026-06-01');
        Cycle::startPeriod($p, $start);

        $this->assertSame('high', Cycle::status($p, $start->copy()->addDays(13))['conception']['likelihood']); // ovulation day
        $this->assertSame('low', Cycle::status($p, $start->copy()->addDays(2))['conception']['likelihood']);   // day 3
    }

    public function test_hormonal_birth_control_suppresses_fertile_window(): void
    {
        $p = $this->femaleProfile(['birth_control' => 'pill']);
        $start = Carbon::parse('2026-06-01');
        Cycle::startPeriod($p, $start);

        $s = Cycle::status($p, $start->copy()->addDays(13));   // would be ovulation otherwise
        $this->assertSame('low', $s['conception']['likelihood']);
        $this->assertFalse($s['fertile_window']['applicable']);
        $this->assertStringContainsString('birth control', $s['conception']['note']);
    }

    public function test_average_length_learned_from_history(): void
    {
        $p = $this->femaleProfile();
        // Three cycles of 30 days each → engine should learn 30, not assume 28.
        foreach (['2026-03-03', '2026-04-02', '2026-05-02', '2026-06-01'] as $d) {
            Cycle::startPeriod($p, Carbon::parse($d));
        }
        $s = Cycle::status($p, Carbon::parse('2026-06-05'));
        $this->assertSame(30, $s['avg_length']);
        $this->assertSame('regular', $s['regularity']);
        $this->assertSame('2026-07-01', $s['next_period']['date']);   // 2026-06-01 + 30
    }

    public function test_late_period_is_flagged(): void
    {
        $p = $this->femaleProfile();
        $start = Carbon::parse('2026-06-01');
        Cycle::startPeriod($p, $start);
        $s = Cycle::status($p, $start->copy()->addDays(32));   // 4 days past a 28-day prediction
        $this->assertTrue($s['late']);
        $this->assertSame(4, $s['next_period']['late_days']);
    }

    public function test_recovery_by_phase_surfaces_the_luteal_rhr_shift(): void
    {
        $p = $this->femaleProfile();
        $start = Carbon::parse('2026-05-01');
        Cycle::startPeriod($p, $start);
        Cycle::startPeriod($p, $start->copy()->addDays(28));   // a second cycle so dates map cleanly

        // Follicular days (low RHR) vs luteal days (higher RHR).
        foreach ([3, 5, 8, 10] as $d) {
            $p->recoveryLogs()->create(['logged_at' => $start->copy()->addDays($d)->toDateString(), 'resting_hr' => 52]);
        }
        foreach ([20, 22, 24, 26] as $d) {
            $p->recoveryLogs()->create(['logged_at' => $start->copy()->addDays($d)->toDateString(), 'resting_hr' => 58]);
        }

        $insight = Cycle::recoveryByPhase($p, 200);
        $this->assertEqualsWithDelta(6.0, $insight['luteal_rhr_delta'], 0.1);
        $this->assertNotNull($insight['note']);
    }
}
