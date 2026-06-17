<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Coach\CoachTools;
use App\Support\AthleteScore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AthleteScoreTest extends TestCase
{
    use RefreshDatabase;

    private function athlete()
    {
        $p = User::factory()->create()->ensureProfile();
        $p->update(['sex' => 'M', 'birthdate' => Carbon::today()->subYears(35)->toDateString()]);
        // Strong cardio, good recovery, decent activity.
        $p->activitySessions()->create(['started_at' => now()->subDay(), 'source' => 'titan_band', 'vo2max' => 55]);
        $p->recoveryLogs()->create(['logged_at' => Carbon::today()->toDateString(), 'resting_hr' => 50, 'hrv_ms' => 85]);
        foreach (range(0, 10) as $i) {
            $p->dailyActivity()->create(['date' => Carbon::today()->subDays($i)->toDateString(), 'steps' => 11000, 'source' => 'manual']);
        }

        return $p->refresh();
    }

    public function test_assess_composes_pillars_into_a_high_score(): void
    {
        $a = AthleteScore::assess($this->athlete());

        $this->assertNotNull($a);
        $this->assertGreaterThan(70, $a['score']);
        $this->assertSame(55.0, $a['vo2max']);
        $this->assertNotNull($a['fitness_age']);
        $pillars = array_column($a['pillars'], 'key');
        $this->assertContains('cardio', $pillars);
        $this->assertContains('recovery', $pillars);
        $this->assertContains('activity', $pillars);
    }

    public function test_strength_pillar_uses_relative_strength_when_lifts_exist(): void
    {
        $p = $this->athlete();
        $p->bodyMetrics()->create(['taken_at' => Carbon::today()->toDateString(), 'weight_kg' => 80]);
        $w = $p->workouts()->create(['performed_at' => now(), 'name' => 'Legs']);
        $ex = \App\Models\Exercise::firstOrCreate(['slug' => 'back-squat'], ['name' => 'Back Squat', 'muscle_group' => 'legs', 'category' => 'compound']);
        $we = \App\Models\WorkoutExercise::create(['workout_id' => $w->id, 'exercise_id' => $ex->id, 'order' => 1]);
        \App\Models\WorkoutSet::create(['workout_exercise_id' => $we->id, 'set_number' => 1, 'reps' => 5, 'weight_kg' => 160]);

        $a = AthleteScore::assess($p->refresh());
        $strength = collect($a['pillars'])->firstWhere('key', 'strength');
        $this->assertNotNull($strength);
        $this->assertStringContainsString('bodyweight', $strength['detail']);   // relative-strength detail
    }

    public function test_the_tool_returns_a_fitness_card(): void
    {
        $res = (new CoachTools($this->athlete()))->dispatch('fitness_score', []);
        $this->assertSame('fitness', $res['card']['type']);
        $this->assertArrayHasKey('pillars', $res['card']);
        $this->assertStringContainsString('titan-card', $res['_show']);
    }

    public function test_guides_when_no_signals(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $p->update(['birthdate' => Carbon::today()->subYears(30)->toDateString()]);
        $res = (new CoachTools($p->refresh()))->dispatch('fitness_score', []);
        $this->assertArrayHasKey('note', $res);
    }
}
