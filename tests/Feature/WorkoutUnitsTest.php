<?php

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The workout logger collects + displays weight in the user's units, stores kg.
 */
class WorkoutUnitsTest extends TestCase
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

    public function test_logged_weight_in_lb_is_stored_as_kg(): void
    {
        $user = $this->imperialUser();
        $ex = Exercise::create(['name' => 'Bench', 'slug' => 'bench', 'muscle_group' => 'chest', 'category' => 'compound']);

        $this->actingAs($user)->post('/workouts', [
            'name' => 'Push',
            'performed_at' => now()->format('Y-m-d\TH:i'),
            'exercises' => [
                ['exercise_id' => $ex->id, 'sets' => [
                    ['reps' => 8, 'weight_kg' => 185, 'is_warmup' => 0],   // 185 lb on the wire
                ]],
            ],
        ])->assertRedirect('/workouts');

        $set = $user->profile->workouts()->first()->exercises()->first()->sets()->first();
        $this->assertEqualsWithDelta(83.9, (float) $set->weight_kg, 0.2);  // 185 lb → ~83.9 kg
    }

    public function test_create_page_labels_weight_in_lb_for_imperial(): void
    {
        $user = $this->imperialUser();
        Exercise::create(['name' => 'Bench', 'slug' => 'bench', 'muscle_group' => 'chest', 'category' => 'compound']);

        $resp = $this->actingAs($user)->get('/workouts/create');
        $resp->assertOk();
        $resp->assertSee('Weight (lb)');
        $resp->assertDontSee('Weight (kg)');
    }

    public function test_index_and_show_display_lb_for_imperial(): void
    {
        $user = $this->imperialUser();
        $ex = Exercise::create(['name' => 'Bench', 'slug' => 'bench', 'muscle_group' => 'chest', 'category' => 'compound']);
        $w = $user->profile->workouts()->create(['name' => 'Push', 'performed_at' => now()]);
        $we = $w->exercises()->create(['exercise_id' => $ex->id, 'order' => 1]);
        $we->sets()->create(['set_number' => 1, 'reps' => 8, 'weight_kg' => 83.9, 'is_warmup' => false]);  // ~185 lb

        $index = $this->actingAs($user)->get('/workouts');
        $index->assertOk();
        $index->assertSee('lb volume');
        $index->assertSee('185');             // 83.9 kg → ~185 lb top set

        $show = $this->actingAs($user)->get("/workouts/{$w->id}");
        $show->assertOk();
        $show->assertSee('185');
        $show->assertDontSee('83.9 kg');
    }
}
