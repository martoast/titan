<?php

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The workouts history paginates (15/page) so the payload stays light no matter how
 * many sessions accrue, and the page's query count stays flat as history grows.
 */
class WorkoutsIndexPaginationTest extends TestCase
{
    use RefreshDatabase;

    private function seedWorkouts(User $user, int $count): void
    {
        $p = $user->ensureProfile();
        $ex = Exercise::firstOrCreate(['slug' => 'bench'], ['name' => 'Bench', 'muscle_group' => 'chest', 'category' => 'compound']);
        for ($i = 0; $i < $count; $i++) {
            $w = $p->workouts()->create(['name' => "Session $i", 'performed_at' => now()->subDays($i)]);
            $we = $w->exercises()->create(['exercise_id' => $ex->id, 'order' => 1]);
            $we->sets()->create(['set_number' => 1, 'reps' => 8, 'weight_kg' => 60, 'is_warmup' => false]);
        }
    }

    public function test_history_paginates_at_15_per_page(): void
    {
        $user = User::factory()->create();
        $this->seedWorkouts($user, 20);

        $resp = $this->actingAs($user)->get('/workouts');
        $resp->assertOk();
        $resp->assertSee('20 sessions recorded');     // total, not the page size
        $resp->assertSee('Older');                     // a next-page control exists
        $resp->assertSee('Page 1 / 2');

        // Page 2 holds the remaining 5.
        $resp2 = $this->actingAs($user)->get('/workouts?page=2');
        $resp2->assertOk();
        $resp2->assertSee('Newer');
        $resp2->assertSee('Page 2 / 2');
    }

    public function test_index_query_count_is_flat_as_history_grows(): void
    {
        $user = User::factory()->create();

        $this->seedWorkouts($user, 5);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($user)->get('/workouts')->assertOk();
        $few = count(DB::getQueryLog());

        $this->seedWorkouts($user, 25);  // 30 total now
        DB::flushQueryLog();
        $this->actingAs($user)->get('/workouts')->assertOk();
        $many = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Pagination caps the eager loads, so 6x the history must not balloon the queries.
        $this->assertLessThanOrEqual($few + 1, $many, "Index queries grew from $few to $many as history grew.");
    }
}
