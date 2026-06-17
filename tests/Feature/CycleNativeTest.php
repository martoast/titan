<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Coach\CoachTools;
use App\Support\Cycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CycleNativeTest extends TestCase
{
    use RefreshDatabase;

    private function womanTracking(int $daysAgo = 8): User
    {
        $u = User::factory()->create();
        $p = $u->ensureProfile();
        $p->update(['sex' => 'F', 'settings' => array_merge($p->settings ?? [], ['cycle' => ['enabled' => true, 'avg_length' => 28, 'avg_period' => 5, 'luteal_length' => 14, 'birth_control' => 'none', 'intent' => 'tracking']])]);
        Cycle::startPeriod($p, Carbon::today()->subDays($daysAgo));   // day ~9 → follicular/fertile

        return $u->refresh();
    }

    public function test_guidance_covers_the_phases(): void
    {
        foreach (['menstrual', 'follicular', 'ovulation', 'luteal'] as $phase) {
            $g = Cycle::guidanceFor($phase);
            $this->assertNotEmpty($g['training']);
            $this->assertNotEmpty($g['nutrition']);
        }
    }

    public function test_coach_digest_is_present_for_a_tracking_woman(): void
    {
        $digest = Cycle::coachDigest($this->womanTracking()->profile);
        $this->assertNotEmpty($digest);
        $this->assertStringContainsString('phase', strtolower($digest));
        $this->assertStringContainsString('Training:', $digest);

        // No cycle tracked → no digest (won't appear for men / non-trackers).
        $this->assertSame('', Cycle::coachDigest(User::factory()->create()->ensureProfile()));
    }

    public function test_daily_checkin_card_carries_the_cycle_phase(): void
    {
        $res = (new CoachTools($this->womanTracking()->profile))->dispatch('daily_checkin', []);
        $this->assertArrayHasKey('cycle', $res['card']);
        $this->assertStringContainsString('Day', $res['card']['cycle']);
    }

    public function test_daily_summary_includes_what_the_phase_means_today(): void
    {
        $res = (new CoachTools($this->womanTracking()->profile))->dispatch('daily_summary', ['date' => 'today']);
        $this->assertArrayHasKey('cycle', $res);
        $this->assertArrayHasKey('means_today', $res['cycle']);
        $this->assertNotEmpty($res['cycle']['means_today']);
    }
}
