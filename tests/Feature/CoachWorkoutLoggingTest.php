<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkoutSet;
use App\Services\Coach\CoachTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CoachWorkoutLoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_log_set_records_a_set_and_stores_weight_in_kg(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $tools = new CoachTools($p);

        // "bench press, 8 reps, a 45 on each side" → coach passes the computed total of 135 lb.
        $res = $tools->dispatch('log_set', ['exercise' => 'bench press', 'reps' => 8, 'weight' => 135, 'unit' => 'lb']);

        $this->assertTrue($res['ok']);
        $this->assertSame('Bench Press', $res['exercise']);
        $this->assertSame(1, $res['set_number']);

        // 135 lb → ~61.2 kg stored canonically.
        $set = WorkoutSet::first();
        $this->assertNotNull($set);
        $this->assertSame(8, $set->reps);
        $this->assertEqualsWithDelta(61.23, (float) $set->weight_kg, 0.05);
    }

    public function test_consecutive_sets_append_to_the_same_session_and_increment(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $tools = new CoachTools($p);

        $tools->dispatch('log_set', ['exercise' => 'bench press', 'reps' => 8, 'weight' => 135, 'unit' => 'lb']);
        $second = $tools->dispatch('log_set', ['exercise' => 'bench press', 'reps' => 6, 'weight' => 155, 'unit' => 'lb']);

        // One session, one exercise, two sets.
        $this->assertSame(1, $p->workouts()->count());
        $this->assertSame(2, $second['set_number']);
        $this->assertSame(2, WorkoutSet::count());
    }

    public function test_finish_workout_closes_the_session_with_a_count(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $tools = new CoachTools($p);

        $tools->dispatch('start_workout', ['name' => 'Push day']);
        $tools->dispatch('log_set', ['exercise' => 'overhead press', 'reps' => 5, 'weight' => 95, 'unit' => 'lb']);
        $done = $tools->dispatch('finish_workout', []);

        $this->assertTrue($done['ok']);
        $this->assertSame(1, $done['sets']);
        $this->assertSame('Push day', $p->workouts()->first()->name);
    }

    public function test_log_set_is_offered_as_a_tool(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $names = array_map(fn ($t) => $t['function']['name'], (new CoachTools($p))->withAllTools()->schemas());
        $this->assertContains('log_set', $names);
        $this->assertContains('start_workout', $names);
        $this->assertContains('finish_workout', $names);
    }
}
