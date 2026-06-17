<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Cycle;
use App\Support\MealCoach;
use App\Support\Readiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CycleAwareTest extends TestCase
{
    use RefreshDatabase;

    private function woman(array $cycle = [])
    {
        $p = User::factory()->create()->ensureProfile();
        $p->update(['sex' => 'F', 'settings' => ['timezone' => 'UTC', 'cycle' => ['enabled' => true] + $cycle]]);

        return $p->refresh();
    }

    // ---- Nutrition ----

    public function test_luteal_phase_bumps_the_calorie_target_protein_holds(): void
    {
        $p = $this->woman();
        Cycle::startPeriod($p, Carbon::today()->subDays(22));   // day 23 → luteal in a 28-day cycle

        $t = MealCoach::targets($p);
        $this->assertSame((int) round(2800 * 1.08), $t['calories']);  // ~8% luteal bump
        $this->assertSame(200, $t['protein_g']);                      // protein unchanged

        $note = MealCoach::cycleNote($p);
        $this->assertStringContainsString('Luteal', $note);
    }

    public function test_menstrual_phase_gives_an_iron_note_no_bump(): void
    {
        $p = $this->woman();
        Cycle::startPeriod($p, Carbon::today());   // day 1 → menstrual

        $this->assertSame(2800, MealCoach::targets($p)['calories']);  // no bump outside luteal
        $this->assertStringContainsString('iron', MealCoach::cycleNote($p));
    }

    public function test_non_tracking_user_is_unaffected(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $p->update(['sex' => 'M']);
        $this->assertSame(2800, MealCoach::targets($p->refresh())['calories']);
        $this->assertNull(MealCoach::cycleNote($p));
    }

    // ---- Readiness ----

    public function test_rhr_offset_raises_readiness_when_resting_hr_is_elevated(): void
    {
        // Mechanism: with an elevated resting HR, subtracting a phase offset should raise the score.
        $p = User::factory()->create()->ensureProfile();
        for ($d = 30; $d >= 1; $d--) {
            // Vary the baseline so its standard deviation is non-zero (a flat series → z forced to 0).
            $p->recoveryLogs()->create(['logged_at' => Carbon::today()->subDays($d)->toDateString(), 'resting_hr' => 54 + ($d % 3)]);
        }
        $today = $p->recoveryLogs()->create(['logged_at' => Carbon::today()->toDateString(), 'resting_hr' => 62]);
        $history = $p->recoveryLogs()->orderBy('logged_at')->get();

        $base = Readiness::fromData($history, $today, null, 0.0)['score'];
        $adjusted = Readiness::fromData($history, $today, null, 6.0)['score'];

        $this->assertGreaterThan($base, $adjusted);
    }

    public function test_luteal_readiness_is_phase_normalised_end_to_end(): void
    {
        $today = Carbon::parse('2026-06-23');            // fixed for determinism
        $s0 = $today->copy()->subDays(50);                // cycle 1 start
        $s1 = $today->copy()->subDays(22);                // cycle 2 start → today is day 23 (luteal)

        $woman = $this->woman();
        Cycle::startPeriod($woman, $s0);
        Cycle::startPeriod($woman, $s1);

        $man = User::factory()->create()->ensureProfile();
        $man->update(['sex' => 'M']);

        // Identical recovery data for both: follicular days low (52), luteal days high (58), incl. today.
        $rows = [];
        foreach ([4, 6, 8, 10] as $d) { $rows[] = [$s1->copy()->addDays($d), 52]; }     // follicular
        foreach ([16, 18, 20] as $d) { $rows[] = [$s1->copy()->addDays($d), 58]; }       // luteal
        foreach ([18, 20, 22] as $d) { $rows[] = [$s0->copy()->addDays($d), 58]; }        // luteal (cycle 1)
        $rows[] = [$today, 58];                                                            // today: luteal

        foreach ([$woman, $man] as $prof) {
            foreach ($rows as [$date, $rhr]) {
                $prof->recoveryLogs()->create(['logged_at' => $date->toDateString(), 'resting_hr' => $rhr]);
            }
        }

        $w = Readiness::compute($woman, $today);
        $m = Readiness::compute($man, $today);

        $this->assertTrue($w['cycle_adjusted'] ?? false);
        $this->assertSame('luteal', $w['cycle_phase'] ?? null);
        $this->assertStringContainsStringIgnoringCase('luteal', $w['note']);
        // The luteal elevation is neutralised, so the woman scores at least as high as the
        // identical-data man whose score still carries the (uncorrected) elevation.
        $this->assertGreaterThan($m['score'], $w['score']);
    }
}
