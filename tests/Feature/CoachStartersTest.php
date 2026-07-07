<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Coach\CoachService;
use App\Support\Nav;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gender-aware first-run coach prompts + cycle-gated navigation (subtle personalization).
 */
class CoachStartersTest extends TestCase
{
    use RefreshDatabase;

    private function profile(array $attrs, array $settings = [])
    {
        $user = User::factory()->create();
        $p = $user->ensureProfile();
        $p->fill($attrs);
        $p->settings = array_merge($p->settings ?? [], $settings);
        $p->save();

        return $p->fresh();
    }

    public function test_blank_profile_falls_back_to_generic_starters(): void
    {
        $p = $this->profile(['sex' => 'M', 'primary_goal' => null]);

        $this->assertSame(CoachService::STARTERS, CoachService::startersFor($p));
    }

    public function test_male_muscle_goal_gets_muscle_framing(): void
    {
        $p = $this->profile(['sex' => 'M', 'primary_goal' => 'Build muscle']);

        $starters = CoachService::startersFor($p);

        $this->assertStringContainsString('build muscle', strtolower(implode(' ', $starters)));
        $this->assertStringContainsString('add muscle', strtolower(implode(' ', $starters)));
        $this->assertStringNotContainsString('tone up', strtolower(implode(' ', $starters)));
    }

    public function test_female_focus_and_fatloss_drive_tailored_starters(): void
    {
        $p = $this->profile(
            ['sex' => 'F', 'primary_goal' => 'Lose fat / get lean'],
            ['intake' => ['focus_areas' => ['Rounder glutes', 'Flat tummy'], 'goal' => 'Lose fat / get lean']],
        );

        $starters = CoachService::startersFor($p);
        $joined = strtolower(implode(' ', $starters));

        // Headline focus area, in their words.
        $this->assertStringContainsString('rounder glutes', $joined);
        // Fat-loss nutrition framing + female training voice.
        $this->assertStringContainsString('deficit', $joined);
        $this->assertStringContainsString('tone up', $joined);
    }

    public function test_cycle_aware_starter_only_for_cycle_users(): void
    {
        $female = $this->profile(
            ['sex' => 'F', 'primary_goal' => 'Lose fat / get lean'],
            ['intake' => ['focus_areas' => ['Lean legs']], 'cycle' => [
                'enabled' => true, 'avg_cycle' => 28, 'last_period' => now()->subDays(8)->toDateString(),
                'avg_period' => 5, 'luteal_length' => 14,
            ]],
        );
        $male = $this->profile(['sex' => 'M', 'primary_goal' => 'Build muscle']);

        $this->assertStringContainsString('cycle phase', strtolower(implode(' ', CoachService::startersFor($female))));
        $this->assertStringNotContainsString('cycle', strtolower(implode(' ', CoachService::startersFor($male))));

        // Nav mirrors it: Cycle appears for her, not for him.
        $this->assertContains('cycle', array_column(Nav::flat($female), 'path'));
        $this->assertNotContains('cycle', array_column(Nav::flat($male), 'path'));
    }

    public function test_nav_groups_are_stable_and_grouped(): void
    {
        $p = $this->profile(['sex' => 'M']);
        $groups = Nav::groups($p);

        // The 5-tab IA mirrors the native iOS app.
        $this->assertSame(['Coach', 'Today', 'Trends', 'Community', 'You'], array_keys($groups));
        // Primary bottom-bar tabs all exist as destinations.
        foreach (Nav::PRIMARY as $path) {
            $this->assertContains($path, array_column(Nav::flat($p), 'path'));
        }
    }
}
