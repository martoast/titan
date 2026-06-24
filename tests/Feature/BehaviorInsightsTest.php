<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\RecoveryLog;
use App\Models\User;
use App\Services\Coach\CoachTools;
use App\Support\BehaviorCorrelations;
use App\Support\Journal;
use App\Support\Stats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The correlation engine (Titan's moat). Plants a known effect — alcohol nights → low next-day HRV —
 * and proves the engine surfaces it while ignoring an unrelated behavior. Plus the stats primitives,
 * the journal tool, and the Discovery push.
 */
class BehaviorInsightsTest extends TestCase
{
    use RefreshDatabase;

    public function test_stats_primitives(): void
    {
        // Cleanly separated groups → tiny p, strong effect.
        $low = [40, 42, 41, 43, 39, 44, 40, 42];
        $high = [68, 70, 69, 71, 67, 72, 69, 70];
        $this->assertLessThan(0.01, Stats::mannWhitneyP($low, $high));
        $this->assertLessThan(-0.9, Stats::cliffsDelta($low, $high));   // low << high

        // Overlapping groups → not significant.
        $a = [50, 55, 60, 52, 58];
        $b = [53, 51, 59, 57, 54];
        $this->assertGreaterThan(0.05, Stats::mannWhitneyP($a, $b));

        // BH monotonic + ≤ raw scaling.
        $q = Stats::benjaminiHochberg(['a' => 0.001, 'b' => 0.04, 'c' => 0.5]);
        $this->assertLessThanOrEqual($q['c'], $q['b']);
        $this->assertLessThanOrEqual($q['b'], $q['a']);
    }

    /** Build a profile where alcohol nights (even days) precede low HRV, and `meditated` is unrelated. */
    private function plantedProfile(): Profile
    {
        $profile = User::factory()->create()->ensureProfile();
        $base = Carbon::parse('2026-05-01');

        for ($i = 0; $i < 44; $i++) {
            $day = $base->copy()->addDays($i)->toDateString();
            $alcohol = $i % 2 === 0;

            $keys = ['social'];                 // logged every day → the day counts as journaled
            if ($alcohol) {
                $keys[] = 'alcohol';
            }
            if ($i % 3 === 0) {
                $keys[] = 'meditated';          // unrelated to the HRV split
            }
            Journal::log($profile, $day, $keys);

            // Next morning's recovery reflects THAT day's alcohol.
            RecoveryLog::create([
                'profile_id' => $profile->id,
                'logged_at' => $base->copy()->addDays($i + 1)->toDateString(),
                'hrv_ms' => $alcohol ? 46 + ($i % 4) : 69 + ($i % 4),
                'resting_hr' => $alcohol ? 58 : 51,
                'updated_via' => 'test',
            ]);
        }

        return $profile->fresh();
    }

    public function test_detects_planted_effect_and_ignores_noise(): void
    {
        $this->travelTo(Carbon::parse('2026-06-20'));
        $profile = $this->plantedProfile();

        $sig = BehaviorCorrelations::compute($profile);
        $this->assertGreaterThanOrEqual(1, $sig);

        $alcoholHrv = $profile->behaviorImpacts()
            ->where('behavior_key', 'alcohol')->where('outcome_key', 'hrv')->first();
        $this->assertNotNull($alcoholHrv);
        $this->assertTrue($alcoholHrv->significant, 'alcohol→HRV should be significant');
        $this->assertLessThan(0, $alcoholHrv->pct_change, 'alcohol should LOWER HRV');

        // The unrelated behavior must NOT be flagged significant.
        $meditatedHrv = $profile->behaviorImpacts()
            ->where('behavior_key', 'meditated')->where('outcome_key', 'hrv')->first();
        $this->assertTrue($meditatedHrv === null || ! $meditatedHrv->significant, 'meditated→HRV should not be significant');
    }

    public function test_card_phrases_direction(): void
    {
        $this->travelTo(Carbon::parse('2026-06-20'));
        $profile = $this->plantedProfile();
        BehaviorCorrelations::compute($profile);

        $card = BehaviorCorrelations::card($profile);
        $this->assertSame('impacts', $card['type']);
        $alcohol = collect($card['items'])->firstWhere('behavior', 'Alcohol');
        $this->assertNotNull($alcohol);
        $this->assertSame('bad', $alcohol['direction']);      // lowering HRV is bad
        $this->assertLessThan(0, $alcohol['pct']);
    }

    public function test_log_behavior_tool_records_journal(): void
    {
        $profile = User::factory()->create()->ensureProfile();
        $tools = app(CoachTools::class, ['profile' => $profile]);

        $res = $tools->dispatch('log_behavior', ['add' => ['alcohol', 'screens_late', 'not_a_real_key']]);
        $this->assertTrue($res['ok']);
        $this->assertContains('Alcohol', $res['logged']);
        $this->assertNotContains('not_a_real_key', $res['logged']);   // invalid keys dropped

        $tools->dispatch('log_behavior', ['remove' => ['screens_late']]);
        $today = Journal::today($profile->fresh());
        $this->assertEqualsCanonicalizing(['alcohol'], Journal::forDate($profile->fresh(), $today));
    }

    public function test_command_announces_a_discovery_once(): void
    {
        $this->travelTo(Carbon::parse('2026-06-20'));
        $profile = $this->plantedProfile();

        $this->artisan('insights:behavior', ['--profile' => $profile->id])->assertExitCode(0);

        $convo = $profile->conversations()->where('title', 'Daily Briefings')->first();
        $this->assertNotNull($convo);
        $this->assertStringContainsStringIgnoringCase('alcohol', $convo->messages()->latest('id')->first()->content);

        $before = $convo->messages()->count();
        $this->artisan('insights:behavior', ['--profile' => $profile->id])->assertExitCode(0);   // re-run
        $this->assertSame($before, $convo->fresh()->messages()->count(), 'should not re-announce');
    }
}
