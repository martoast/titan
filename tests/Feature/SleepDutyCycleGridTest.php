<?php

namespace Tests\Feature;

use App\Jobs\SealNightJob;
use App\Models\DeviceIngestion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The duty-cycle "17-minute night" bug. The band streams ~30 s HRV/PPG bursts every few minutes overnight
 * (firmware SLEEP_DUTY, to survive the night on one charge), so a full night is dozens of SHORT windows
 * separated by quiet gaps. The seal used to CONCATENATE each burst's one-or-two epochs into a contiguous
 * block, so a 6h50m night collapsed to ~18 minutes ("36 windows × 30 s") — the absurd duration_min:17
 * sleep rows. buildNightGrid must instead place each burst at its REAL offset inside [bed,wake] and hold
 * the nearest sample across the gaps, so the staging grid spans the whole night.
 */
class SleepDutyCycleGridTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<int,DeviceIngestion> $windows */
    private function grid(SealNightJob $job, array $windows, CarbonImmutable $t0, CarbonImmutable $t1): ?array
    {
        $m = new \ReflectionMethod(SealNightJob::class, 'buildNightGrid');
        $m->setAccessible(true);

        return $m->invoke($job, collect($windows), $t0, $t1);
    }

    public function test_duty_cycle_bursts_reconstruct_a_full_night_grid(): void
    {
        $profile = User::factory()->create()->ensureProfile();
        $wake = time();
        $bed = $wake - 410 * 60;                         // a 6h50m night
        $t0 = CarbonImmutable::createFromTimestamp($bed, 'UTC');
        $t1 = CarbonImmutable::createFromTimestamp($wake, 'UTC');

        // The real overnight shape: one 30 s burst (→ 1 epoch) every 3 minutes, low motion (asleep).
        $windows = [];
        for ($t = $bed + 60; $t < $wake - 60; $t += 180) {
            $windows[] = DeviceIngestion::create([
                'batch_uid' => substr(hash('sha256', $t.'-'.mt_rand()), 0, 40),
                'profile_id' => $profile->id, 'source' => 'titan_band', 'kind' => 'ppg_raw',
                'status' => DeviceIngestion::STATUS_PROCESSED,
                'window_start' => Carbon::createFromTimestamp($t, 'UTC'),
                'window_end' => Carbon::createFromTimestamp($t + 30, 'UTC'),
                'result_refs' => ['epoch_motion' => [2.0], 'epoch_hr' => [58.0], 'epoch_rmssd' => [45.0]],
            ]);
        }
        $this->assertGreaterThan(100, count($windows), 'a real night is ~130 duty-cycle bursts');

        $job = new SealNightJob($profile->id, null, true, $bed, $wake, 0);
        $grid = $this->grid($job, $windows, $t0, $t1);

        $this->assertNotNull($grid, 'a night of bursts must reconstruct into a grid');
        // The grid spans the WHOLE night — 410 min / 30 s = 820 epochs — NOT the ~136 the old concat gave.
        $this->assertEqualsWithDelta(820, count($grid['motion']), 2, 'grid spans bed→wake, not the sum of bursts');
        $this->assertCount(count($grid['motion']), $grid['hr'], 'hr grid is the same length');
        // No nulls slip through — every gap epoch inherits a neighbouring burst.
        $this->assertCount(0, array_filter($grid['motion'], fn ($v) => $v === null), 'gaps are hold-filled');
        // A quiet (asleep) night stays quiet across the gaps — the held-forward low motion, not a spike block.
        $this->assertLessThan(5.0, array_sum($grid['motion']) / count($grid['motion']), 'quiet gaps read asleep');
        // HR is carried across the gaps too (held ~58), so the stager sees a plausible overnight HR.
        $this->assertEqualsWithDelta(58.0, array_sum($grid['hr']) / count($grid['hr']), 1.0, 'hr held across gaps');
    }

    public function test_a_thin_night_with_too_few_samples_returns_null(): void
    {
        $profile = User::factory()->create()->ensureProfile();
        $wake = time();
        $bed = $wake - 410 * 60;
        $t0 = CarbonImmutable::createFromTimestamp($bed, 'UTC');
        $t1 = CarbonImmutable::createFromTimestamp($wake, 'UTC');

        // Only three bursts landed all night (airplane mode / dead band) → too thin to trust a staging.
        $windows = [];
        foreach ([$bed + 60, $bed + 3600, $wake - 600] as $t) {
            $windows[] = DeviceIngestion::create([
                'batch_uid' => substr(hash('sha256', $t.'-'.mt_rand()), 0, 40),
                'profile_id' => $profile->id, 'source' => 'titan_band', 'kind' => 'ppg_raw',
                'status' => DeviceIngestion::STATUS_PROCESSED,
                'window_start' => Carbon::createFromTimestamp($t, 'UTC'),
                'window_end' => Carbon::createFromTimestamp($t + 30, 'UTC'),
                'result_refs' => ['epoch_motion' => [2.0]],
            ]);
        }

        $job = new SealNightJob($profile->id, null, true, $bed, $wake, 0);
        $this->assertNull($this->grid($job, $windows, $t0, $t1), 'a handful of samples falls back to duration-only');
    }
}
