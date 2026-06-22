<?php

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The live session works in the user's display units; weights store metric.
 * Voice: bare numbers follow the user's units; explicit "kilos"/"pounds" are honored as-is.
 */
class LiveWorkoutUnitsTest extends TestCase
{
    use RefreshDatabase;

    private function imperialUser(): User
    {
        $user = User::factory()->create();
        $p = $user->ensureProfile();
        $p->settings = array_merge($p->settings ?? [], ['units' => 'imperial']);
        $p->save();

        return $user;
    }

    public function test_manual_add_set_converts_lb_to_kg(): void
    {
        $user = $this->imperialUser();
        $ex = Exercise::create(['name' => 'Bench', 'slug' => 'bench', 'muscle_group' => 'chest', 'category' => 'compound']);
        $w = $user->profile->workouts()->create(['name' => 'Live session · now', 'performed_at' => now()]);
        $we = $w->exercises()->create(['exercise_id' => $ex->id, 'order' => 1]);

        $this->actingAs($user)->postJson(route('workouts.live.set'), [
            'workout_exercise_id' => $we->id,
            'reps' => 8,
            'weight_kg' => 185,   // lb on the wire (the UI is in display units)
        ])->assertOk();

        $this->assertEqualsWithDelta(83.9, (float) $we->sets()->first()->weight_kg, 0.2);
    }

    public function test_voice_bare_number_is_pounds_for_imperial(): void
    {
        $user = $this->imperialUser();

        $resp = $this->actingAs($user)->postJson(route('workouts.live.voice'), [
            'transcript' => 'bench press 185 8 reps',
        ])->assertOk();

        $set = $user->profile->workouts()->latest('id')->first()
            ->exercises()->first()->sets()->first();
        $this->assertEqualsWithDelta(83.9, (float) $set->weight_kg, 0.5);   // 185 lb → ~83.9 kg

        // And the snapshot the UI re-renders from reads back in lb.
        $weights = collect($resp->json('session.exercises'))->flatMap(fn ($e) => collect($e['sets'])->pluck('weight'));
        $this->assertEqualsWithDelta(185, (float) $weights->first(), 1.0);
    }

    public function test_voice_explicit_kilos_is_not_reconverted_for_imperial(): void
    {
        $user = $this->imperialUser();

        $this->actingAs($user)->postJson(route('workouts.live.voice'), [
            'transcript' => 'squat 100 kilos 5 reps',
        ])->assertOk();

        $set = $user->profile->workouts()->latest('id')->first()
            ->exercises()->first()->sets()->first();
        // Explicit "kilos" means metric already — must stay 100 kg, not 45.
        $this->assertEqualsWithDelta(100, (float) $set->weight_kg, 0.5);
    }

    public function test_metric_voice_bare_number_stays_kg(): void
    {
        $user = User::factory()->create();   // metric by default
        $user->ensureProfile();

        $this->actingAs($user)->postJson(route('workouts.live.voice'), [
            'transcript' => 'deadlift 100 5 reps',
        ])->assertOk();

        $set = $user->profile->workouts()->latest('id')->first()
            ->exercises()->first()->sets()->first();
        $this->assertEqualsWithDelta(100, (float) $set->weight_kg, 0.5);
    }
}
