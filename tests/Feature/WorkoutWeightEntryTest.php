<?php

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkoutWeightEntryTest extends TestCase
{
    use RefreshDatabase;

    private function bandWorkout($profile): Workout
    {
        $workout = Workout::create([
            'profile_id' => $profile->id, 'performed_at' => now()->subHour(),
            'name' => 'Gym session', 'updated_via' => 'biosignal:sealed',
        ]);
        $ex = Exercise::create(['name' => 'Squats', 'slug' => 'squats', 'muscle_group' => 'legs', 'category' => 'compound']);
        $we = WorkoutExercise::create(['workout_id' => $workout->id, 'exercise_id' => $ex->id, 'order' => 0]);
        WorkoutSet::create(['workout_exercise_id' => $we->id, 'set_number' => 1, 'reps' => 10, 'weight_kg' => 0]);
        WorkoutSet::create(['workout_exercise_id' => $we->id, 'set_number' => 2, 'reps' => 9, 'weight_kg' => 0]);

        return $workout;
    }

    public function test_show_prompts_for_weights_on_a_band_detected_workout(): void
    {
        $user = User::factory()->create();
        $workout = $this->bandWorkout($user->ensureProfile());

        $this->actingAs($user)->get("/workouts/{$workout->id}")->assertOk()
            ->assertSee('Detected from your band')->assertSee('Add weights');
    }

    public function test_logs_the_load_against_detected_sets(): void
    {
        $user = User::factory()->create();
        $workout = $this->bandWorkout($user->ensureProfile());
        $sets = WorkoutSet::all();

        $resp = $this->actingAs($user)->put("/workouts/{$workout->id}/sets", [
            'sets' => [
                $sets[0]->id => ['weight_kg' => 80, 'reps' => 10, 'rpe' => 8],
                $sets[1]->id => ['weight_kg' => 80, 'reps' => 8],   // corrected the auto-count 9→8
            ],
        ]);

        $resp->assertRedirect("/workouts/{$workout->id}");
        $this->assertEqualsWithDelta(80.0, $sets[0]->fresh()->weight_kg, 0.01);
        $this->assertEqualsWithDelta(8.0, $sets[0]->fresh()->rpe, 0.01);
        $this->assertSame(8, $sets[1]->fresh()->reps);
        $this->assertEqualsWithDelta(800.0, $sets[0]->fresh()->volume(), 0.01); // 80kg × 10
    }

    public function test_cannot_edit_another_profiles_sets(): void
    {
        $owner = User::factory()->create();
        $workout = $this->bandWorkout($owner->ensureProfile());
        $set = WorkoutSet::first();

        $attacker = User::factory()->create();
        $attacker->ensureProfile();
        // The attacker's own (empty) workout id in the URL → tampered set id is ignored / 404.
        $this->actingAs($attacker)->put("/workouts/{$workout->id}/sets", [
            'sets' => [$set->id => ['weight_kg' => 999]],
        ])->assertNotFound();

        $this->assertEqualsWithDelta(0.0, $set->fresh()->weight_kg, 0.01);
    }
}
