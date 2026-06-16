<?php

namespace Tests\Feature;

use App\Models\DailyActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailyStepsTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_step_log_upserts_today_and_shows_on_fitness(): void
    {
        $user = User::factory()->create();
        $profile = $user->ensureProfile();
        $profile->update(['birthdate' => now()->subYears(34)->toDateString()]);

        $this->actingAs($user)->post('/fitness/steps', ['steps' => 9200])
            ->assertRedirect('/fitness');

        $this->assertDatabaseHas('daily_activity', [
            'profile_id' => $profile->id, 'steps' => 9200, 'source' => 'manual',
        ]);

        // Re-logging the same day updates in place (no duplicate row).
        $this->actingAs($user)->post('/fitness/steps', ['steps' => 10100]);
        $this->assertSame(1, DailyActivity::where('profile_id', $profile->id)->count());
        $this->assertSame(10100, (int) DailyActivity::first()->steps);

        // 10,100 ≥ the 8,500 target for a 34-year-old → goal reached.
        $this->actingAs($user)->get('/fitness')->assertOk()
            ->assertSee('Today\'s movement', false)
            ->assertSee('Goal reached');
    }

    public function test_fitness_shows_progress_toward_goal_when_short(): void
    {
        $user = User::factory()->create();
        $profile = $user->ensureProfile();
        $profile->dailyActivity()->create(['date' => now()->toDateString(), 'steps' => 3000, 'source' => 'manual']);

        $this->actingAs($user)->get('/fitness')->assertOk()
            ->assertSee('to your')->assertSee('lower all-cause mortality');
    }

    public function test_dashboard_shows_the_daily_loop(): void
    {
        $user = User::factory()->create();
        $user->ensureProfile()->dailyActivity()->create(['date' => now()->toDateString(), 'steps' => 6400, 'source' => 'manual']);

        // Steps now feed the Strain card (ambient load); the dashboard leads with the Recovery →
        // Strain → Sleep loop + today's focus, not a raw steps tile.
        $this->actingAs($user)->get('/dashboard')->assertOk()
            ->assertSee('Recovery')->assertSee('Strain')->assertSee('Sleep');
    }
}
