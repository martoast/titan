<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Coach\PersonalContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * COACH v3 situational lenses: PersonalContext assembles only the ACTIVE lenses (relevance-gated) into a
 * budgeted block, each with a cross-domain directive — the generalisation of the cycle lens.
 */
class PersonalContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_lenses_for_a_fresh_profile(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $this->assertSame('', PersonalContext::digest($p));
    }

    public function test_recovery_lens_fires_on_sleep_debt_with_a_cross_domain_directive(): void
    {
        $p = User::factory()->create()->ensureProfile();
        // Target 8h but only sleeping ~5h → real debt → the recovery lens should activate.
        $p->update(['settings' => ['sleep_target_h' => 8]]);
        for ($d = 1; $d <= 7; $d++) {
            $p->sleepLogs()->create([
                'slept_at' => Carbon::today()->subDays($d)->toDateString(),
                'is_nap' => false, 'duration_min' => 300,   // 5h
                'bedtime' => '00:30:00', 'wake_time' => '05:30:00',
                'stage_status' => 'final', 'updated_via' => 'biosignal:sealed',
            ]);
        }

        $digest = PersonalContext::digest($p->refresh());

        $this->assertStringContainsString('RECOVERY', $digest);
        $this->assertStringContainsString('sleep debt', $digest);
        // Cross-domain, not siloed: the directive must connect training + nutrition.
        $this->assertStringContainsString('training', $digest);
        $this->assertStringContainsString('nutrition', $digest);
    }

    public function test_digest_respects_its_budget(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $p->update(['settings' => ['sleep_target_h' => 8]]);
        for ($d = 1; $d <= 7; $d++) {
            $p->sleepLogs()->create([
                'slept_at' => Carbon::today()->subDays($d)->toDateString(),
                'is_nap' => false, 'duration_min' => 300,
                'bedtime' => '00:30:00', 'wake_time' => '05:30:00',
                'stage_status' => 'final', 'updated_via' => 'biosignal:sealed',
            ]);
        }

        $this->assertLessThanOrEqual(120, strlen(PersonalContext::digest($p->refresh(), 120)));
    }
}
