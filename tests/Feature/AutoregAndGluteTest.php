<?php

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\User;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSet;
use App\Services\Coach\CoachTools;
use App\Support\Autoregulator;
use App\Support\MesocycleGenerator;
use App\Support\TrainingPlaybook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AutoregAndGluteTest extends TestCase
{
    use RefreshDatabase;

    // ---- Autoregulation ----

    public function test_autoregulate_is_insufficient_with_no_data(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $a = Autoregulator::assess($p);
        $this->assertSame('insufficient', $a['verdict']);
    }

    private function logLift(User $u, string $slug, float $kg, int $reps, Carbon $when): void
    {
        $p = $u->profile;
        $ex = Exercise::firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug), 'muscle_group' => 'x', 'category' => 'compound']);
        $w = $p->workouts()->create(['performed_at' => $when, 'name' => 'S']);
        $we = WorkoutExercise::create(['workout_id' => $w->id, 'exercise_id' => $ex->id, 'order' => 1]);
        WorkoutSet::create(['workout_exercise_id' => $we->id, 'set_number' => 1, 'reps' => $reps, 'weight_kg' => $kg]);
    }

    public function test_deload_when_recovery_poor_and_performance_declining(): void
    {
        $u = User::factory()->create();
        $p = $u->ensureProfile();
        $p->recoveryLogs()->create(['logged_at' => Carbon::today()->toDateString(), 'energy' => 2, 'soreness' => 9]); // poor (subjective)

        // Lifts down: heavier 15 days ago than 4 days ago, two exercises.
        $this->logLift($u, 'squat', 100, 5, Carbon::now()->subDays(15));
        $this->logLift($u, 'bench', 80, 5, Carbon::now()->subDays(15));
        $this->logLift($u, 'squat', 90, 5, Carbon::now()->subDays(4));
        $this->logLift($u, 'bench', 72, 5, Carbon::now()->subDays(4));

        $a = Autoregulator::assess($p->refresh());
        $this->assertSame('declining', collect($a['signals'])->firstWhere('label', 'Performance')['state']);
        $this->assertSame('deload', $a['verdict']);
    }

    public function test_progress_when_recovered_and_improving(): void
    {
        $u = User::factory()->create();
        $p = $u->ensureProfile();
        $p->recoveryLogs()->create(['logged_at' => Carbon::today()->toDateString(), 'energy' => 9, 'soreness' => 2]); // good

        $this->logLift($u, 'squat', 90, 5, Carbon::now()->subDays(15));
        $this->logLift($u, 'bench', 70, 5, Carbon::now()->subDays(15));
        $this->logLift($u, 'squat', 100, 5, Carbon::now()->subDays(4));
        $this->logLift($u, 'bench', 78, 5, Carbon::now()->subDays(4));

        $a = Autoregulator::assess($p->refresh());
        $this->assertSame('improving', collect($a['signals'])->firstWhere('label', 'Performance')['state']);
        $this->assertSame('progress', $a['verdict']);
    }

    public function test_the_autoreg_tool_returns_a_card(): void
    {
        $u = User::factory()->create();
        $p = $u->ensureProfile();
        $p->recoveryLogs()->create(['logged_at' => Carbon::today()->toDateString(), 'energy' => 9, 'soreness' => 2]);
        $this->logLift($u, 'squat', 90, 5, Carbon::now()->subDays(15));
        $this->logLift($u, 'bench', 70, 5, Carbon::now()->subDays(15));
        $this->logLift($u, 'squat', 100, 5, Carbon::now()->subDays(4));
        $this->logLift($u, 'bench', 78, 5, Carbon::now()->subDays(4));

        $res = (new CoachTools($p->refresh()))->dispatch('autoregulate', []);
        $this->assertSame('autoreg', $res['card']['type']);
        $this->assertNotEmpty($res['card']['signals']);
    }

    // ---- Glute specialization ----

    public function test_glute_focus_prioritises_glutes_on_lower_days_with_the_shelf_work(): void
    {
        $b = MesocycleGenerator::build(['focus' => ['glutes'], 'days_per_week' => 4, 'weeks' => 5]);
        $week1 = $b['plan'][0];

        $gluteNames = [];
        $gluteDays = 0;
        foreach ($week1['days'] as $day) {
            $muscles = array_column($day['exercises'], 'muscle');
            if (in_array('glutes', $muscles, true)) {
                $gluteDays++;
                $this->assertSame('glutes', $day['exercises'][0]['muscle'], "glutes should lead {$day['name']}");
                $this->assertNotEmpty(array_intersect($muscles, ['quads', 'hamstrings', 'calves']), "{$day['name']} should be a lower-body day");
                foreach ($day['exercises'] as $e) {
                    if ($e['muscle'] === 'glutes') {
                        $gluteNames[] = $e['name'];
                    }
                }
            }
        }
        $this->assertGreaterThanOrEqual(2, $gluteDays);
        $joined = implode(' | ', $gluteNames);
        $this->assertStringContainsString('Hip Thrust', $joined);     // the heavy activator
        $this->assertStringContainsString('Abduction', $joined);      // the medius "shelf" finisher, every session

        // Focus muscle gets more weekly volume than a non-focus lower muscle.
        $this->assertGreaterThan($b['volume']['quads'][0], $b['volume']['glutes'][0]);
    }

    public function test_lower_body_focus_is_not_bolted_onto_upper_days(): void
    {
        // 5-day split has Upper / Lower / Push / Pull / Legs — glutes must stay on lower-region days only.
        $b = MesocycleGenerator::build(['focus' => ['glutes'], 'days_per_week' => 5, 'weeks' => 5]);
        foreach ($b['plan'][0]['days'] as $day) {
            $muscles = array_column($day['exercises'], 'muscle');
            if (in_array('glutes', $muscles, true)) {
                $this->assertNotEmpty(array_intersect($muscles, ['quads', 'hamstrings', 'calves']), "glutes wrongly placed on {$day['name']}");
            }
        }
    }

    public function test_playbook_has_the_glute_and_waist_topic(): void
    {
        $res = TrainingPlaybook::lookup('grow my glutes and define my waist');
        $this->assertSame('glutes_waist', $res['matched'][0]['topic']);
        $this->assertStringContainsStringIgnoringCase('abduction', $res['matched'][0]['body']);
    }
}
