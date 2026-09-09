<?php

namespace Tests\Feature;

use App\Models\BodyMetric;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Correcting a weigh-in must actually correct it.
 *
 * `body_metrics.taken_at` is a DATE, and the store used a bare `create()` — so logging twice in one day
 * wrote TWO rows for that date and the trend read the first of them. Typing 82.6 instead of 82.1 and
 * fixing it therefore changed nothing on screen, with a duplicate left in the table. Found while driving
 * the App Review demo account through the app's own endpoints.
 */
class WeightCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private function actor(): User
    {
        $user = User::factory()->create();
        $user->ensureProfile();

        return $user;
    }

    public function test_logging_twice_in_one_day_corrects_rather_than_duplicates(): void
    {
        $user = $this->actor();

        $this->actingAs($user)->postJson('/api/me/weight', ['weight_kg' => 82.6])->assertOk();
        $corrected = $this->actingAs($user)->postJson('/api/me/weight', ['weight_kg' => 82.1])->assertOk();

        $this->assertSame(82.1, round((float) $corrected->json('latest_kg'), 1),
            'the correction must be what the card reports');
        $this->assertSame(1, BodyMetric::where('profile_id', $user->profile->id)->count(),
            'a second weigh-in on the same day must replace the first, not sit beside it');
    }

    public function test_correcting_the_weight_does_not_erase_a_recorded_body_fat(): void
    {
        // The app's weight sheet can send body fat, and its "quick correct" path does not. An update that
        // blindly wrote every column would wipe a reading the person had already entered.
        $user = $this->actor();

        $this->actingAs($user)->postJson('/api/me/weight', ['weight_kg' => 82.6, 'body_fat_pct' => 18.4])->assertOk();
        $this->actingAs($user)->postJson('/api/me/weight', ['weight_kg' => 82.1])->assertOk();

        $row = BodyMetric::where('profile_id', $user->profile->id)->sole();
        $this->assertSame(82.1, round((float) $row->weight_kg, 1));
        $this->assertSame(18.4, round((float) $row->body_fat_pct, 1), 'body fat must survive a weight correction');
    }

    public function test_separate_days_remain_separate_readings(): void
    {
        $user = $this->actor();

        $this->actingAs($user)->postJson('/api/me/weight',
            ['weight_kg' => 83.0, 'taken_at' => now()->subDay()->toDateString()])->assertOk();
        $this->actingAs($user)->postJson('/api/me/weight', ['weight_kg' => 82.1])->assertOk();

        $this->assertSame(2, BodyMetric::where('profile_id', $user->profile->id)->count(),
            'collapsing per day must not collapse the trend itself');
    }
}
