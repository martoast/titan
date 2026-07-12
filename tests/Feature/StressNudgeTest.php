<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\StressSample;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The proactive stress nudge (stress:nudge): offer a breathing minute when stress has HELD high, gated
 * on the opt-in 'stress' reminder type and rate-limited so it never nags.
 */
class StressNudgeTest extends TestCase
{
    use RefreshDatabase;

    private function heldHighProfile(bool $stressRemindersOn = true): \App\Models\Profile
    {
        $p = User::factory()->create()->ensureProfile();
        // 'stress' is default-on only at the all-in tier.
        $p->update(['settings' => ['coaching_intensity' => $stressRemindersOn ? 'intense' : 'balanced']]);
        foreach ([10, 30, 45] as $ago) {
            StressSample::create(['profile_id' => $p->id, 'recorded_at' => now()->subMinutes($ago), 'stress' => 70, 'source' => 'derived']);
        }

        return $p->refresh();
    }

    public function test_nudges_once_when_stress_holds_high(): void
    {
        $p = $this->heldHighProfile();

        $this->artisan('stress:nudge')->assertSuccessful();
        $this->assertSame(1, Notification::where('profile_id', $p->id)->where('type', 'stress')->count());

        // Cooldown: a second run inside the window sends nothing more.
        $this->artisan('stress:nudge')->assertSuccessful();
        $this->assertSame(1, Notification::where('profile_id', $p->id)->where('type', 'stress')->count());
    }

    public function test_respects_the_opt_out(): void
    {
        $p = $this->heldHighProfile(stressRemindersOn: false);   // balanced → stress reminders off

        $this->artisan('stress:nudge')->assertSuccessful();
        $this->assertSame(0, Notification::where('profile_id', $p->id)->count());
    }

    public function test_no_nudge_without_sustained_high(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $p->update(['settings' => ['coaching_intensity' => 'intense']]);
        // A single high spike among calm — not sustained.
        StressSample::create(['profile_id' => $p->id, 'recorded_at' => now()->subMinutes(10), 'stress' => 70, 'source' => 'derived']);
        StressSample::create(['profile_id' => $p->id, 'recorded_at' => now()->subMinutes(30), 'stress' => 15, 'source' => 'derived']);

        $this->artisan('stress:nudge')->assertSuccessful();
        $this->assertSame(0, Notification::where('profile_id', $p->refresh()->id)->count());
    }
}
