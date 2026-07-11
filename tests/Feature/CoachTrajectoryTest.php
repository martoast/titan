<?php

namespace Tests\Feature;

use App\Models\ActivitySession;
use App\Models\RecoveryLog;
use App\Models\SleepLog;
use App\Models\User;
use App\Support\CoachTrajectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * COACH v2 · Phase 1 — the always-on trajectory digest. It must surface the DIRECTION of each domain
 * (7d vs 28d) with a confidence tag, stay within budget, and never fabricate a domain it has no data for.
 */
class CoachTrajectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_profile_yields_no_trajectory_block(): void
    {
        $profile = User::factory()->create()->ensureProfile();
        $this->assertSame('', CoachTrajectory::digest($profile), 'no data → no block (an empty line would read as "flat", a lie)');
    }

    public function test_digest_surfaces_direction_confidence_and_stays_in_budget(): void
    {
        $profile = User::factory()->create()->ensureProfile();
        $profile->update(['settings' => array_merge((array) $profile->settings, [
            'macro_targets' => ['calories' => 2800, 'protein_g' => 200, 'carbs_g' => 280, 'fat_g' => 80],
        ])]);

        // 28 nights of recovery + sleep. The last 7 days are BETTER HRV (↑) and SHORTER sleep (↓) than the
        // 28d baseline, so the digest must show those directions.
        for ($d = 27; $d >= 0; $d--) {
            $recent = $d < 7;
            RecoveryLog::create([
                'profile_id' => $profile->id,
                'logged_at' => Carbon::today()->subDays($d)->toDateString(),
                'hrv_ms' => $recent ? 72 : 60,
                'resting_hr' => $recent ? 52 : 56,
                'updated_via' => 'biosignal:sealed',
            ]);
            SleepLog::create([
                'profile_id' => $profile->id,
                'slept_at' => Carbon::today()->subDays($d)->toDateString(),
                'is_nap' => false,
                'stage_status' => SleepLog::STATUS_FINAL,
                'duration_min' => $recent ? 390 : 450,   // 6.5h recent vs 7.5h baseline
                'quality' => null,
            ]);
        }

        // A week of meals, protein consistently short of the 200g target.
        for ($d = 6; $d >= 0; $d--) {
            $profile->meals()->create([
                'name' => 'Day '.$d, 'eaten_at' => Carbon::now()->subDays($d),
                'calories' => 2400, 'protein_g' => 150, 'carbs_g' => 250, 'fat_g' => 70, 'source' => 'manual',
            ]);
        }

        // Three training sessions this week.
        for ($d = 5; $d >= 1; $d -= 2) {
            ActivitySession::create([
                'profile_id' => $profile->id, 'source' => 'band', 'visibility' => 'private',
                'started_at' => Carbon::now()->subDays($d), 'ended_at' => Carbon::now()->subDays($d)->addHour(),
                'duration_min' => 60, 'kind' => 'strength', 'is_training' => true, 'trimp' => 90,
            ]);
        }

        $digest = CoachTrajectory::digest($profile);

        $this->assertNotSame('', $digest);
        $this->assertLessThanOrEqual(900, strlen($digest), 'digest stays within the ~900-char budget');

        // Every core domain is present.
        foreach (['Sleep:', 'Recovery:', 'Training:', 'Nutrition:'] as $domain) {
            $this->assertStringContainsString($domain, $digest);
        }
        // Confidence is tagged (never a soft number flatly).
        $this->assertStringContainsString('conf:', $digest);
        // Directions: HRV up vs baseline, sleep down vs baseline.
        $this->assertStringContainsString('HRV 72ms ↑', $digest);
        $this->assertMatchesRegularExpression('/Sleep: 6\.5h.*↓/u', $digest);
        // Nutrition surfaces the recurring protein gap (200 target − 150 logged).
        $this->assertStringContainsString('protein short ~50g', $digest);
    }
}
