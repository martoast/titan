<?php

namespace Tests\Feature;

use App\Jobs\ReactToMealLogged;
use App\Models\Meal;
use App\Models\Profile;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The proactive low-protein nudge: when a meal is logged late in the day but protein is still short,
 * the coach drops a note in the chat. Once a day, evening only, opt-in.
 */
class MealProteinNudgeTest extends TestCase
{
    use RefreshDatabase;

    /** A profile with a known 200 g protein target and the given protein already logged today. */
    private function profileWithProtein(int $proteinSoFar, string $intensity = 'balanced'): Profile
    {
        $profile = User::factory()->create()->ensureProfile();
        $profile->update(['settings' => [
            'timezone' => 'UTC',   // pin so the travelTo() time-of-day gate is unambiguous
            'macro_targets' => ['calories' => 2800, 'protein_g' => 200],
            'coaching_intensity' => $intensity,
        ]]);
        $profile->meals()->create([
            'name' => 'Lunch', 'eaten_at' => now(),
            'calories' => 500, 'protein_g' => $proteinSoFar, 'carbs_g' => 40, 'fat_g' => 15, 'source' => 'manual',
        ]);

        return $profile->fresh();
    }

    private function fireNudge(Profile $profile): void
    {
        $meal = $profile->meals()->latest('id')->first();
        (new ReactToMealLogged($meal->id))->handle(app(NotificationService::class));
    }

    private function briefingBody(Profile $profile): ?string
    {
        $convo = $profile->conversations()->days()->first();

        return $convo?->messages()->latest('id')->first()?->content;
    }

    public function test_nudges_when_behind_on_protein_in_the_evening(): void
    {
        $this->travelTo(Carbon::parse('2026-06-24 19:00:00', 'UTC'));
        $profile = $this->profileWithProtein(80);

        $this->fireNudge($profile);

        $body = $this->briefingBody($profile);
        $this->assertNotNull($body, "expected a protein nudge in today's chat");
        $this->assertStringContainsString('80g', $body);
        $this->assertStringContainsString('200g', $body);
        $this->assertSame('2026-06-24', data_get($profile->fresh()->settings, 'protein_nudged'));
    }

    public function test_celebrates_when_protein_target_is_hit(): void
    {
        $this->travelTo(Carbon::parse('2026-06-24 13:00:00', 'UTC'));   // a win isn't evening-gated
        $profile = $this->profileWithProtein(210);                      // over the 200 g target

        $this->fireNudge($profile);

        $body = $this->briefingBody($profile);
        $this->assertNotNull($body, "expected a protein win in today's chat");
        $this->assertStringContainsString('locked in', $body);
        $this->assertStringContainsString('200g', $body);
        $this->assertSame('2026-06-24', data_get($profile->fresh()->settings, 'protein_won'));
    }

    public function test_no_nudge_when_protein_is_on_track(): void
    {
        $this->travelTo(Carbon::parse('2026-06-24 19:00:00', 'UTC'));
        $profile = $this->profileWithProtein(185);   // 92% of target

        $this->fireNudge($profile);

        $this->assertNull($this->briefingBody($profile));
    }

    public function test_no_nudge_early_in_the_day(): void
    {
        $this->travelTo(Carbon::parse('2026-06-24 09:00:00', 'UTC'));
        $profile = $this->profileWithProtein(20);

        $this->fireNudge($profile);

        $this->assertNull($this->briefingBody($profile));
    }

    public function test_nudges_at_most_once_per_day(): void
    {
        $this->travelTo(Carbon::parse('2026-06-24 19:00:00', 'UTC'));
        $profile = $this->profileWithProtein(80);

        $this->fireNudge($profile);
        $this->fireNudge($profile->fresh());   // a second meal/log later the same evening

        $count = $profile->conversations()->days()->first()->messages()->count();
        $this->assertSame(1, $count);
    }

    public function test_respects_opt_out(): void
    {
        $this->travelTo(Carbon::parse('2026-06-24 19:00:00', 'UTC'));
        $profile = $this->profileWithProtein(80, intensity: 'minimal');   // meal nudges off

        $this->fireNudge($profile);

        $this->assertNull($this->briefingBody($profile));
    }
}
