<?php

namespace Tests\Feature;

use App\Models\BodyMetric;
use App\Models\Goal;
use App\Models\Profile;
use App\Models\User;
use App\Services\Coach\CoachTools;
use App\Support\WeightTrend;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The EWMA "true weight" trend + goal projection (the fat-loss engine). Verifies the smoothing math,
 * the weekly rate sign, an honest projection, and the coach tools.
 */
class WeightTrendTest extends TestCase
{
    use RefreshDatabase;

    private function weigh(Profile $p, string $date, float $kg): void
    {
        BodyMetric::create(['profile_id' => $p->id, 'taken_at' => $date, 'weight_kg' => $kg]);
    }

    public function test_ewma_smoothing_matches_the_formula(): void
    {
        $this->travelTo(Carbon::parse('2026-06-20'));
        $p = User::factory()->create()->ensureProfile();
        $base = Carbon::parse('2026-06-10');
        // three consecutive days: 80, 82, 78 → EWMA(α=.1): 80, 80.2, 79.98
        $this->weigh($p, $base->toDateString(), 80);
        $this->weigh($p, $base->copy()->addDay()->toDateString(), 82);
        $this->weigh($p, $base->copy()->addDays(2)->toDateString(), 78);

        $cur = WeightTrend::current($p);
        $this->assertNotNull($cur);
        $this->assertEqualsWithDelta(79.98, $cur['trend'], 0.01);
    }

    public function test_interpolates_gaps_and_trends_down(): void
    {
        $this->travelTo(Carbon::parse('2026-06-20'));
        $p = User::factory()->create()->ensureProfile();
        // declining ~0.2 kg/day over 30 days, with gaps (every ~3rd day)
        $start = Carbon::parse('2026-05-21');
        for ($i = 0; $i <= 30; $i += 3) {
            $this->weigh($p, $start->copy()->addDays($i)->toDateString(), 90 - 0.2 * $i);
        }

        $rate = WeightTrend::weeklyRateKg($p);
        $this->assertNotNull($rate);
        $this->assertLessThan(0, $rate, 'losing weight → negative weekly rate');
        $this->assertEqualsWithDelta(-1.4, $rate, 0.6);   // ~0.2 kg/day ≈ -1.4 kg/wk
    }

    public function test_projection_to_goal_is_on_track(): void
    {
        $this->travelTo(Carbon::parse('2026-06-20'));
        $p = User::factory()->create()->ensureProfile();
        $start = Carbon::parse('2026-05-21');
        for ($i = 0; $i <= 30; $i++) {
            $this->weigh($p, $start->copy()->addDays($i)->toDateString(), 90 - 0.15 * $i);
        }

        $proj = WeightTrend::projection($p, 80.0);   // goal below current, trending down
        $this->assertTrue($proj['on_track']);
        $this->assertGreaterThan(0, $proj['eta_days']);
        $this->assertNotNull($proj['projected_date']);
        $this->assertLessThan(0, $proj['daily_kcal']);   // a deficit
    }

    public function test_coach_sets_goal_and_reads_progress(): void
    {
        $this->travelTo(Carbon::parse('2026-06-20'));
        $p = User::factory()->create()->ensureProfile();
        $start = Carbon::parse('2026-05-25');
        for ($i = 0; $i <= 25; $i++) {
            $this->weigh($p, $start->copy()->addDays($i)->toDateString(), 88 - 0.1 * $i);
        }
        $tools = app(CoachTools::class, ['profile' => $p]);

        $set = $tools->dispatch('set_goal_weight', ['target_kg' => 80, 'by_date' => '2026-12-01']);
        $this->assertTrue($set['ok']);
        $goal = Goal::where('profile_id', $p->id)->where('metric', 'weight')->where('status', 'active')->first();
        $this->assertNotNull($goal);
        $this->assertEqualsWithDelta(80, $goal->target_value, 0.01);
        $this->assertSame('down', $goal->direction);

        $card = $tools->dispatch('weight_progress', []);
        $this->assertSame('weight', $card['type']);
        $this->assertEqualsWithDelta(80, $card['goal']['target_kg'], 0.01);
        $this->assertNotNull($card['trend_kg']);
    }

    public function test_setting_a_new_goal_archives_the_old(): void
    {
        $this->travelTo(Carbon::parse('2026-06-20'));
        $p = User::factory()->create()->ensureProfile();
        $this->weigh($p, '2026-06-18', 90);
        $tools = app(CoachTools::class, ['profile' => $p]);

        $tools->dispatch('set_goal_weight', ['target_kg' => 82]);
        $tools->dispatch('set_goal_weight', ['target_kg' => 80]);

        $this->assertSame(1, Goal::where('profile_id', $p->id)->where('metric', 'weight')->where('status', 'active')->count());
        $this->assertSame(1, Goal::where('profile_id', $p->id)->where('status', 'archived')->count());
    }
}
