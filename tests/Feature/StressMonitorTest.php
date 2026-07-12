<?php

namespace Tests\Feature;

use App\Models\HrSample;
use App\Models\MotionSample;
use App\Models\RecoveryLog;
use App\Models\User;
use App\Support\StressMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The load-bearing correctness invariant for the Stress Monitor: an elevated HR reads as stress ONLY
 * when the user is still — a workout (elevated HR + motion) must never flag as a panic attack. Plus the
 * honesty gate: a thin baseline can't state a confident number.
 */
class StressMonitorTest extends TestCase
{
    use RefreshDatabase;

    /** Seed a solid HRV/RHR baseline (≥14 nights) so confidence is 'high' and levels aren't capped. */
    private function profileWithBaseline(int $todayHrv = 42, int $rest = 58): \App\Models\Profile
    {
        $user = User::factory()->create();
        $p = $user->ensureProfile();
        for ($d = 14; $d >= 1; $d--) {
            RecoveryLog::create([
                'profile_id' => $p->id, 'logged_at' => Carbon::today()->subDays($d)->toDateString(),
                'hrv_ms' => 62, 'resting_hr' => $rest, 'updated_via' => 'biosignal:sealed',
            ]);
        }
        // Today's (suppressed) read + the resting baseline.
        RecoveryLog::create([
            'profile_id' => $p->id, 'logged_at' => Carbon::today()->toDateString(),
            'hrv_ms' => $todayHrv, 'resting_hr' => $rest, 'updated_via' => 'biosignal:sealed',
        ]);

        return $p->refresh();
    }

    private function seedHr(\App\Models\Profile $p, int $bpm): void
    {
        foreach ([2, 4, 6] as $agoMin) {
            HrSample::create(['profile_id' => $p->id, 'recorded_at' => now()->subMinutes($agoMin), 'bpm' => $bpm, 'source' => 'band']);
        }
    }

    private function seedMotion(\App\Models\Profile $p, int $milliG): void
    {
        foreach ([2, 4, 6] as $agoMin) {
            MotionSample::create(['profile_id' => $p->id, 'recorded_at' => now()->subMinutes($agoMin), 'motion' => $milliG, 'source' => 'band']);
        }
    }

    public function test_elevated_hr_while_still_reads_as_stress(): void
    {
        $p = $this->profileWithBaseline();
        $this->seedHr($p, 112);
        $this->seedMotion($p, 15);   // still

        $r = StressMonitor::assess($p);

        $this->assertFalse($r['moving']);
        $this->assertGreaterThan(0.0, $r['stress']);
        $this->assertContains($r['level'], ['low', 'medium', 'high']);   // NOT 'calm'
        $this->assertNotNull($r['drivers']);
        $this->assertSame('high', $r['confidence']['level']);
    }

    public function test_elevated_hr_while_moving_is_not_stress(): void
    {
        $p = $this->profileWithBaseline();
        $this->seedHr($p, 112);      // same elevated HR…
        $this->seedMotion($p, 130);  // …but MOVING (a workout)

        $r = StressMonitor::assess($p);

        $this->assertTrue($r['moving']);
        $this->assertSame(0.0, $r['stress']);
        $this->assertSame('calm', $r['level']);
    }

    public function test_calm_when_hr_sits_near_rest(): void
    {
        $p = $this->profileWithBaseline();
        $this->seedHr($p, 61);       // basically resting
        $this->seedMotion($p, 12);

        $r = StressMonitor::assess($p);

        $this->assertFalse($r['moving']);
        $this->assertLessThan(0.75, $r['stress']);
        $this->assertSame('calm', $r['level']);
    }

    public function test_thin_baseline_reports_low_confidence_and_softens_the_level(): void
    {
        $user = User::factory()->create();
        $p = $user->ensureProfile();
        // Just a resting HR, no HRV baseline nights → can't call stress confidently.
        RecoveryLog::create(['profile_id' => $p->id, 'logged_at' => Carbon::today()->toDateString(), 'resting_hr' => 58, 'updated_via' => 'manual:user']);
        $this->seedHr($p, 120);
        $this->seedMotion($p, 15);

        $r = StressMonitor::assess($p->refresh());

        $this->assertSame('low', $r['confidence']['level']);
        $this->assertNotSame('high', $r['level']);   // never a confident high on a thin baseline
        $this->assertNotNull($r['confidence']['note']);
    }

    public function test_no_hr_in_window_is_calm_unknown(): void
    {
        $p = $this->profileWithBaseline();
        // No hr_samples at all.
        $r = StressMonitor::assess($p);

        $this->assertSame(0.0, $r['stress']);
        $this->assertNull($r['hr']);
    }

    public function test_sample_persists_a_strip_point_when_still_and_elevated(): void
    {
        $p = $this->profileWithBaseline();
        $this->seedHr($p, 112);
        $this->seedMotion($p, 15);

        $value = StressMonitor::sample($p);

        $this->assertNotNull($value);
        $this->assertGreaterThan(0, $value);
        $this->assertSame(1, \App\Models\StressSample::where('profile_id', $p->id)->count());
    }

    public function test_sample_writes_nothing_while_moving(): void
    {
        $p = $this->profileWithBaseline();
        $this->seedHr($p, 112);
        $this->seedMotion($p, 130);   // moving

        $this->assertNull(StressMonitor::sample($p));
        $this->assertSame(0, \App\Models\StressSample::where('profile_id', $p->id)->count());
    }

    public function test_day_strip_reads_back_persisted_samples(): void
    {
        $p = $this->profileWithBaseline();
        $this->seedHr($p, 112);
        $this->seedMotion($p, 15);
        StressMonitor::sample($p);

        $strip = StressMonitor::dayStrip($p);

        $this->assertCount(1, $strip['points']);
        $this->assertGreaterThan(0.0, $strip['peak']);
        $this->assertContains($strip['points'][0]['level'], ['low', 'medium', 'high']);
    }

    public function test_sustained_high_needs_consistent_elevation_not_a_spike(): void
    {
        $p = $this->profileWithBaseline();
        // Three recent samples, all ≥ medium (150 = 1.5) → sustained.
        foreach ([10, 30, 45] as $i => $ago) {
            \App\Models\StressSample::create(['profile_id' => $p->id, 'recorded_at' => now()->subMinutes($ago), 'stress' => 180, 'source' => 'derived']);
        }
        $this->assertNotNull(StressMonitor::sustainedHigh($p));

        // One calm sample in the window breaks it — a single spike must NOT trigger.
        \App\Models\StressSample::create(['profile_id' => $p->id, 'recorded_at' => now()->subMinutes(20), 'stress' => 40, 'source' => 'derived']);
        $this->assertNull(StressMonitor::sustainedHigh($p));
    }
}
