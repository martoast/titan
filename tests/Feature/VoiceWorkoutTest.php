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

    public function test_parses_exercise_weight_and_reps(): void
    {
        $user = User::factory()->create();
        $user->ensureProfile();

        $r = $this->speak($user, 'bench press 80 kilos 8 reps')->assertOk()->json();
        $this->assertTrue($r['ok']);
        $this->assertSame('Bench Press', $r['exercise_name']);
        $this->assertEqualsWithDelta(80.0, $r['weight_kg'], 0.01);
        $this->assertSame(8, $r['reps']);
    }

    public function test_handles_rpe_pounds_and_bare_numbers(): void
    {
        $user = User::factory()->create();
        $user->ensureProfile();

        // RPE + "by" phrasing.
        $a = $this->speak($user, 'squat 100 by 5 rpe 8')->json();
        $this->assertSame(5, $a['reps']);
        $this->assertEqualsWithDelta(100.0, $a['weight_kg'], 0.01);
        $this->assertEqualsWithDelta(8.0, $a['rpe'], 0.01);

        // Pounds → kg.
        $b = $this->speak($user, 'overhead press 45 pounds 10 reps')->json();
        $this->assertEqualsWithDelta(20.5, $b['weight_kg'], 0.01);   // 45 lb ≈ 20.4 → 20.5
        $this->assertSame(10, $b['reps']);

        // Bare numbers, no units → bigger is weight, smaller is reps.
        $c = $this->speak($user, 'deadlift 140 5')->json();
        $this->assertEqualsWithDelta(140.0, $c['weight_kg'], 0.01);
        $this->assertSame(5, $c['reps']);

        // Bodyweight: reps only → weight 0.
        $d = $this->speak($user, 'pull ups 12 reps')->json();
        $this->assertSame(12, $d['reps']);
        $this->assertEqualsWithDelta(0.0, $d['weight_kg'], 0.01);
    }

    public function test_repeated_sets_stack_under_one_exercise(): void
    {
        $user = User::factory()->create();
        $user->ensureProfile();

        $first = $this->speak($user, 'bench press 80 kilos 8 reps')->json();
        $wid = $first['workout_id'];
        $second = $this->speak($user, 'bench press 80 kilos 7 reps', $wid)->json();

        $this->assertSame($wid, $second['workout_id']);
        $this->assertSame($first['workout_exercise_id'], $second['workout_exercise_id']); // same card
        $this->assertFalse($second['is_new_exercise']);
        $this->assertSame(2, $second['set_number']);
        $this->assertSame(2, WorkoutSet::count());
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
