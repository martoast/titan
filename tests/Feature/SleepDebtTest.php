<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SleepDebt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The sleep-debt LEDGER: accrues on short nights, pays down (bounded) on big ones, clamps [0, cap] over a
 * ~2-week horizon, and — the trust rule — a low_confidence night never moves the balance.
 */
class SleepDebtTest extends TestCase
{
    use RefreshDatabase;

    private function profile(): \App\Models\Profile
    {
        $p = User::factory()->create()->ensureProfile();
        $p->update(['settings' => ['sleep_target_h' => 8]]);   // baseline 8h

        return $p->refresh();
    }

    private function night(\App\Models\Profile $p, int $agoDays, float $hours, bool $low = false): void
    {
        $p->sleepLogs()->create([
            'slept_at' => Carbon::today()->subDays($agoDays)->toDateString(),
            'is_nap' => false, 'duration_min' => (int) round($hours * 60),
            'bedtime' => '23:00:00', 'wake_time' => '07:00:00',
            'stage_status' => 'final', 'low_confidence' => $low, 'updated_via' => 'biosignal:sealed',
        ]);
    }

    public function test_a_run_of_short_nights_accrues_debt(): void
    {
        $p = $this->profile();
        for ($d = 5; $d >= 1; $d--) {
            $this->night($p, $d, 5.0);   // 3h short each
        }
        $ledger = SleepDebt::forProfile($p);

        $this->assertGreaterThan(2.0, $ledger['balance_h']);
        $this->assertContains($ledger['band'], ['moderate', 'heavy']);
        $this->assertGreaterThan(0.0, $ledger['added_last_night_h']);
        $this->assertTrue($ledger['payback']['clearable']);
        $this->assertGreaterThan(0, $ledger['payback']['extra_min_per_night']);
    }

    public function test_a_big_night_pays_back_but_only_up_to_the_cap(): void
    {
        $p = $this->profile();
        for ($d = 5; $d >= 2; $d--) {
            $this->night($p, $d, 5.0);   // build debt
        }
        $this->night($p, 1, 11.0);       // then a huge night (3h surplus, but payback is bounded to 1.5)

        $ledger = SleepDebt::forProfile($p);
        $this->assertGreaterThan(0.0, $ledger['paid_back_last_night_h']);
        $this->assertLessThanOrEqual(1.5, $ledger['paid_back_last_night_h']);
        $this->assertSame(0.0, $ledger['added_last_night_h']);
    }

    public function test_two_weeks_of_good_sleep_returns_to_rested(): void
    {
        $p = $this->profile();
        for ($d = 14; $d >= 1; $d--) {
            $this->night($p, $d, 9.0);   // over baseline every night
        }
        $ledger = SleepDebt::forProfile($p);

        $this->assertSame(0.0, $ledger['balance_h']);
        $this->assertSame('none', $ledger['band']);
        $this->assertStringContainsString('rested', strtolower($ledger['payback']['plan']));
    }

    public function test_a_low_confidence_night_does_not_move_the_ledger(): void
    {
        $p = $this->profile();
        for ($d = 8; $d >= 2; $d--) {
            $this->night($p, $d, 9.0);   // rested → balance 0
        }
        $this->night($p, 1, 4.0, low: true);   // a jittery 4h ESTIMATE must not invent debt

        $ledger = SleepDebt::forProfile($p);
        $this->assertSame(0.0, $ledger['balance_h'], 'a low_confidence short night adds no debt');
        $this->assertTrue(collect($ledger['history'])->contains(fn ($h) => $h['unmeasured'] === true));
    }
}
