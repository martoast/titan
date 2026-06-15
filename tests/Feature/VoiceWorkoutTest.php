<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkoutSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoiceWorkoutTest extends TestCase
{
    use RefreshDatabase;

    private function speak(User $user, string $transcript, ?int $workoutId = null)
    {
        return $this->actingAs($user)->postJson('/workouts/live/voice', array_filter([
            'transcript' => $transcript,
            'workout_id' => $workoutId,
        ]));
    }

    private function lastSet(array $json): array
    {
        $ex = $json['session']['exercises'];
        $sets = end($ex)['sets'];

        return end($sets);
    }

    public function test_parses_exercise_weight_and_reps(): void
    {
        $user = User::factory()->create();
        $user->ensureProfile();

        $r = $this->speak($user, 'bench press 80 kilos 8 reps')->assertOk()->json();
        $this->assertTrue($r['ok']);
        $this->assertSame('add_set', $r['action']);
        $this->assertSame('Bench Press', $r['session']['exercises'][0]['name']);
        $set = $this->lastSet($r);
        $this->assertEqualsWithDelta(80.0, $set['weight'], 0.01);
        $this->assertSame(8, $set['reps']);
    }

    public function test_handles_rpe_pounds_and_bare_numbers(): void
    {
        $user = User::factory()->create();
        $user->ensureProfile();

        $a = $this->lastSet($this->speak($user, 'squat 100 by 5 rpe 8')->json());
        $this->assertSame(5, $a['reps']);
        $this->assertEqualsWithDelta(100.0, $a['weight'], 0.01);
        $this->assertEqualsWithDelta(8.0, $a['rpe'], 0.01);

        $b = $this->lastSet($this->speak($user, 'overhead press 45 pounds 10 reps')->json());
        $this->assertEqualsWithDelta(20.5, $b['weight'], 0.01);   // 45 lb ≈ 20.4 → 20.5
        $this->assertSame(10, $b['reps']);

        $c = $this->lastSet($this->speak($user, 'deadlift 140 5')->json());   // bare: bigger=weight
        $this->assertEqualsWithDelta(140.0, $c['weight'], 0.01);
        $this->assertSame(5, $c['reps']);

        $d = $this->lastSet($this->speak($user, 'pull ups 12 reps')->json()); // bodyweight → 0
        $this->assertSame(12, $d['reps']);
        $this->assertEqualsWithDelta(0.0, $d['weight'], 0.01);
    }

    public function test_repeated_sets_stack_under_one_exercise(): void
    {
        $user = User::factory()->create();
        $user->ensureProfile();

        $first = $this->speak($user, 'bench press 80 kilos 8 reps')->json();
        $wid = $first['session']['workout_id'];
        $second = $this->speak($user, 'bench press 80 kilos 7 reps', $wid)->json();

        $this->assertSame($wid, $second['session']['workout_id']);
        $this->assertCount(1, $second['session']['exercises']);            // one exercise card
        $this->assertCount(2, $second['session']['exercises'][0]['sets']); // two sets stacked
        $this->assertSame(2, WorkoutSet::count());
    }

    public function test_voice_correction_updates_the_last_set(): void
    {
        $user = User::factory()->create();
        $user->ensureProfile();
        $wid = $this->speak($user, 'bench press 80 kilos 8 reps')->json()['session']['workout_id'];

        // "make it 10 reps" → just the reps change; weight stays 80.
        $r = $this->speak($user, 'change the last set to 10 reps', $wid)->assertOk()->json();
        $this->assertSame('update_set', $r['action']);
        $set = WorkoutSet::latest('id')->first();
        $this->assertSame(10, $set->reps);
        $this->assertEqualsWithDelta(80.0, $set->weight_kg, 0.01);

        // "make it 85 kilos" → weight changes, reps stay 10.
        $this->speak($user, 'actually make it 85 kilos', $wid);
        $set->refresh();
        $this->assertEqualsWithDelta(85.0, $set->weight_kg, 0.01);
        $this->assertSame(10, $set->reps);
        $this->assertSame(1, WorkoutSet::count());      // corrected, not duplicated
    }

    public function test_voice_delete_and_undo_remove_the_last_set(): void
    {
        $user = User::factory()->create();
        $user->ensureProfile();
        $wid = $this->speak($user, 'squat 100 kilos 5 reps')->json()['session']['workout_id'];
        $this->speak($user, 'squat 100 kilos 5 reps', $wid);
        $this->assertSame(2, WorkoutSet::count());

        $this->speak($user, 'delete that set', $wid)->assertOk();
        $this->assertSame(1, WorkoutSet::count());

        $r = $this->speak($user, 'undo', $wid)->assertOk()->json();
        $this->assertSame(0, WorkoutSet::count());
        $this->assertSame('undo', $r['action']);
    }

    public function test_finish_workout_voice_command_seals_and_redirects(): void
    {
        $user = User::factory()->create();
        $user->ensureProfile();
        $wid = $this->speak($user, 'bench press 80 kilos 8 reps')->json()['session']['workout_id'];

        $r = $this->speak($user, "finish workout", $wid)->assertOk()->json();
        $this->assertTrue($r['ok']);
        $this->assertSame('finish', $r['action']);
        $this->assertStringContainsString("/workouts/{$wid}", $r['redirect']);
        $this->assertNotNull(\App\Models\Workout::find($wid)->duration_min);   // duration sealed
    }

    public function test_voice_can_remove_a_whole_exercise(): void
    {
        $user = User::factory()->create();
        $user->ensureProfile();
        $wid = $this->speak($user, 'bench press 80 kilos 8 reps')->json()['session']['workout_id'];
        $this->speak($user, 'squat 100 kilos 5 reps', $wid);
        $this->assertSame(2, \App\Models\WorkoutExercise::count());

        $this->speak($user, 'remove the squats', $wid)->assertOk();
        $this->assertSame(1, \App\Models\WorkoutExercise::count());
        $this->assertSame(1, WorkoutSet::count());      // only the bench set remains
    }

    public function test_unparseable_speech_is_rejected_gracefully(): void
    {
        $user = User::factory()->create();
        $user->ensureProfile();

        $r = $this->speak($user, 'hey what time is it')->assertOk()->json();
        $this->assertFalse($r['ok']);
        $this->assertArrayHasKey('message', $r);
        $this->assertSame(0, WorkoutSet::count());
    }
}
