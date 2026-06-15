<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SleepRegularityPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_sleep_page_shows_regularity_score_with_enough_timed_nights(): void
    {
        $user = User::factory()->create();
        $profile = $user->ensureProfile();

        // 8 consistent recent nights with bedtime + wake time.
        for ($d = 1; $d <= 8; $d++) {
            $profile->sleepLogs()->create([
                'slept_at' => now()->subDays($d)->toDateString(),
                'duration_min' => 480, 'bedtime' => '23:00', 'wake_time' => '07:00',
            ]);
        }

        $this->actingAs($user)->get('/sleep')->assertOk()
            ->assertSee('Sleep regularity')
            ->assertSee('Very regular')        // a tight schedule → excellent band
            ->assertSee('SRI / 100', false);
    }

    public function test_sleep_page_prompts_when_too_few_timed_nights(): void
    {
        $user = User::factory()->create();
        $user->ensureProfile()->sleepLogs()->create([
            'slept_at' => now()->subDay()->toDateString(), 'duration_min' => 480,
            'bedtime' => '23:00', 'wake_time' => '07:00',
        ]);

        $this->actingAs($user)->get('/sleep')->assertOk()
            ->assertSee('to see your regularity score');
    }

    public function test_sleep_page_shows_circadian_rhythm_with_hourly_days(): void
    {
        $user = User::factory()->create();
        $profile = $user->ensureProfile();

        // 7 days with a strong active-day / still-night hourly profile.
        $hourly = [];
        for ($i = 0; $i < 24; $i++) {
            $hourly[] = ($i >= 8 && $i <= 21) ? 80 : 2;
        }
        for ($d = 1; $d <= 7; $d++) {
            $profile->dailyActivity()->create([
                'date' => now()->subDays($d)->toDateString(), 'steps' => 8000, 'hourly' => $hourly, 'source' => 'titan_band',
            ]);
        }

        $this->actingAs($user)->get('/sleep')->assertOk()
            ->assertSee('Circadian rhythm')
            ->assertSee('Strong rhythm')
            ->assertSee('rhythm / 100', false);
    }
}
