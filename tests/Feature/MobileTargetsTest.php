<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\User;
use App\Support\Macros;
use App\Support\SleepCoach;
use App\Support\TargetSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Editable macro + sleep targets. The store (TargetSettings) is shared by the app endpoint and the
 * coach's set_targets tool, and a custom target must flow through to the macro rings and SleepCoach.
 */
class MobileTargetsTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_marks_custom_and_resolves(): void
    {
        $profile = User::factory()->create()->ensureProfile();

        $t = TargetSettings::update($profile, ['calories' => 3000, 'protein_g' => 180, 'sleep_h' => 7.5]);

        $this->assertSame(3000, $t['calories']);
        $this->assertSame(180, $t['protein_g']);
        $this->assertSame(7.5, $t['sleep_h']);
        $this->assertTrue($t['custom']);
        // Partial edits hold the other macros.
        $this->assertGreaterThan(0, $t['carbs_g']);
    }

    public function test_custom_protein_target_drives_the_macros_card(): void
    {
        $profile = User::factory()->create()->ensureProfile();
        TargetSettings::update($profile, ['protein_g' => 120]);

        $this->assertSame(120, Macros::today($profile->fresh())['protein']['target']);
    }

    public function test_sleep_target_override_flows_to_sleep_coach(): void
    {
        $profile = User::factory()->create()->ensureProfile();
        TargetSettings::update($profile, ['sleep_h' => 7]);

        $this->assertSame(7.0, SleepCoach::targetHours($profile->fresh()));
    }

    public function test_endpoint_shows_and_updates_targets(): void
    {
        $user = User::factory()->create();
        $user->ensureProfile();
        [, $token] = ApiToken::mint($user, 'ios', ['*']);
        $h = $this->withHeader('Authorization', "Bearer {$token}");

        $h->getJson('/api/me/targets')
            ->assertOk()->assertJsonStructure(['targets' => ['calories', 'protein_g', 'carbs_g', 'fat_g', 'sleep_h', 'custom']]);

        $h->patchJson('/api/me/targets', ['protein_g' => 200, 'sleep_h' => 7.5])
            ->assertOk()
            ->assertJsonPath('targets.protein_g', 200)
            ->assertJsonPath('targets.sleep_h', 7.5)
            ->assertJsonPath('targets.custom', true);

        $h->getJson('/api/me/targets')->assertOk()->assertJsonPath('targets.protein_g', 200);
    }

    public function test_targets_require_auth(): void
    {
        $this->getJson('/api/me/targets')->assertStatus(401);
        $this->patchJson('/api/me/targets', ['calories' => 2500])->assertStatus(401);
    }
}
