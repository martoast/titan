<?php

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\PushSubscription;
use App\Models\User;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSet;
use App\Services\Coach\CoachTools;
use App\Support\WeeklyReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class WeeklyReviewTest extends TestCase
{
    use RefreshDatabase;

    private function activeWeek(): User
    {
        $u = User::factory()->create();
        $p = $u->ensureProfile();
        $p->update(['settings' => array_merge($p->settings ?? [], [
            'timezone' => 'UTC',
            'macro_targets' => ['calories' => 2200, 'protein_g' => 160],
        ])]);

        // 4 training sessions with sets this week.
        $ex = Exercise::firstOrCreate(['slug' => 'squat'], ['name' => 'Squat', 'muscle_group' => 'legs', 'category' => 'compound']);
        foreach (range(1, 4) as $i) {
            $w = $p->workouts()->create(['performed_at' => Carbon::now()->subDays($i), 'name' => 'S'.$i]);
            $we = WorkoutExercise::create(['workout_id' => $w->id, 'exercise_id' => $ex->id, 'order' => 1]);
            WorkoutSet::create(['workout_exercise_id' => $we->id, 'set_number' => 1, 'reps' => 5, 'weight_kg' => 100]);
        }
        // Meals across 6 days near target.
        foreach (range(0, 5) as $d) {
            $p->meals()->create(['eaten_at' => Carbon::now()->subDays($d)->setHour(12), 'name' => 'Meal', 'calories' => 2150, 'protein_g' => 165, 'source' => 'manual']);
        }
        // Body metrics: down 0.4kg over the week.
        $p->bodyMetrics()->create(['taken_at' => Carbon::now()->subDays(9), 'weight_kg' => 82.4]);
        $p->bodyMetrics()->create(['taken_at' => Carbon::now()->subDay(), 'weight_kg' => 82.0]);

        return $u->refresh();
    }

    public function test_compile_synthesizes_the_week(): void
    {
        $r = WeeklyReview::compile($this->activeWeek()->profile);

        $this->assertNotNull($r);
        $keys = array_column($r['metrics'], 'key');
        $this->assertContains('training', $keys);
        $this->assertContains('nutrition', $keys);
        $this->assertContains('weight', $keys);
        $this->assertNotEmpty($r['headline']);

        $training = collect($r['metrics'])->firstWhere('key', 'training');
        $this->assertStringContainsString('4 sessions', $training['value']);
    }

    public function test_compile_is_null_for_a_blank_week(): void
    {
        $u = User::factory()->create();
        $this->assertNull(WeeklyReview::compile($u->ensureProfile()));
    }

    public function test_tool_returns_a_review_card(): void
    {
        $res = (new CoachTools($this->activeWeek()->profile))->dispatch('weekly_review', []);
        $this->assertSame('review', $res['card']['type']);
        $this->assertArrayHasKey('metrics', $res['card']);
        $this->assertStringContainsString('titan-card', $res['_show']);
    }

    public function test_command_pushes_once_per_week(): void
    {
        $u = $this->activeWeek();
        $p = $u->profile;
        PushSubscription::create(['profile_id' => $p->id, 'endpoint' => 'https://x/'.$p->id, 'endpoint_hash' => hash('sha256', 'r'.$p->id), 'p256dh' => 'k', 'auth' => 'a']);

        $this->artisan('coach:weekly-review')->assertSuccessful();
        $this->assertDatabaseHas('notifications', ['profile_id' => $p->id, 'type' => 'review']);
        $this->assertSame(1, \App\Models\Notification::where('profile_id', $p->id)->count());

        $this->artisan('coach:weekly-review')->assertSuccessful();   // deduped
        $this->assertSame(1, \App\Models\Notification::where('profile_id', $p->id)->count());
    }
}
