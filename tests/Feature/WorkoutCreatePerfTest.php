<?php

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Guards the /workouts/create progressive-overload hints against N+1 regression.
 * It used to run one query PER exercise (scaling with the library); now it's one
 * batched query. This locks the query count to roughly constant regardless of size.
 */
class WorkoutCreatePerfTest extends TestCase
{
    use RefreshDatabase;

    private int $exSeq = 0;

    private function makeExercises(int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            $id = $this->exSeq++;
            Exercise::create([
                'name' => "Exercise $id", 'slug' => "ex-$id",
                'muscle_group' => 'misc', 'category' => 'compound',
            ]);
        }
    }

    public function test_create_page_query_count_does_not_scale_with_library_size(): void
    {
        $user = User::factory()->create();
        $user->ensureProfile();

        // Small library.
        $this->makeExercises(5);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($user)->get('/workouts/create')->assertOk();
        $small = count(DB::getQueryLog());

        // Big library — 8x the exercises.
        $this->makeExercises(35);
        DB::flushQueryLog();
        $this->actingAs($user)->get('/workouts/create')->assertOk();
        $big = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Constant-ish: a few more exercises must NOT mean a few dozen more queries.
        $this->assertLessThan(12, $big, "Create page ran $big queries — looks like an N+1.");
        $this->assertLessThanOrEqual(2, $big - $small, "Query count grew by ".($big - $small)." when the library grew 8x.");
    }

    public function test_progression_hint_reflects_last_session(): void
    {
        $user = User::factory()->create();
        $p = $user->ensureProfile();
        $this->makeExercises(1);
        $ex = Exercise::first();

        // Two sessions; the most recent (today) should drive the hint.
        $old = $p->workouts()->create(['name' => 'Old', 'performed_at' => now()->subDays(7)]);
        $oe = $old->exercises()->create(['exercise_id' => $ex->id, 'order' => 1]);
        $oe->sets()->create(['set_number' => 1, 'reps' => 8, 'weight_kg' => 60, 'is_warmup' => false]);

        $new = $p->workouts()->create(['name' => 'New', 'performed_at' => now()]);
        $ne = $new->exercises()->create(['exercise_id' => $ex->id, 'order' => 1]);
        $ne->sets()->create(['set_number' => 1, 'reps' => 12, 'weight_kg' => 70, 'is_warmup' => false]);

        $resp = $this->actingAs($user)->get('/workouts/create');
        $resp->assertOk();
        // The most recent session was 12 reps @ 70 → "Last: 12 × 70 kg".
        $resp->assertSee('Last: 12 × 70 kg');
    }
}
