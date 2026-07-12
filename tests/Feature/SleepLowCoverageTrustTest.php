<?php

namespace Tests\Feature;

use App\Jobs\SealNightJob;
use App\Models\DeviceIngestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The low-coverage-night TRUST fix (Tester B's first night: band on all night, PPG contact poor → ~60% NODATA,
 * sealed as a confident "4h 11m" when she slept ~8h). Two guarantees, pinned on the private seal helpers
 * the way SleepDutyCycleSealTest pins the sparse-staging path:
 *   1. mergeOvernightSleepFragments bridges overnight fragments split by a NODATA hole into ONE night span
 *      (so it isn't truncated to the cleanest cluster) — but NEVER absorbs an evening/daytime cluster or a
 *      genuinely-separate sleep a full day apart.
 *   2. isLowConfidence flags a low-coverage or implausible-split night so the app/coach caveat it.
 */
class SleepLowCoverageTrustTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC']);
        date_default_timezone_set('UTC');
    }

    /** A window collection spanning [start, end] (epoch seconds), as one gap-cluster. */
    private function cluster(int $start, int $end): \Illuminate\Support\Collection
    {
        $w = DeviceIngestion::make([
            'batch_uid' => substr(hash('sha256', $start.'-'.$end), 0, 40),
            'profile_id' => 1, 'source' => 'titan_band', 'kind' => 'ppg_raw',
            'window_start' => Carbon::createFromTimestamp($start),
            'window_end' => Carbon::createFromTimestamp($end),
        ]);

        return collect([$w]);
    }

    private function merge(array $clusters): array
    {
        $job = new SealNightJob(1);
        $m = new \ReflectionMethod($job, 'mergeOvernightSleepFragments');
        $m->setAccessible(true);

        return $m->invoke($job, $clusters, 'UTC');
    }

    private function isLowConfidence(?array $metrics): bool
    {
        $job = new SealNightJob(1);
        $m = new \ReflectionMethod($job, 'isLowConfidence');
        $m->setAccessible(true);

        return $m->invoke($job, $metrics);
    }

    public function test_overnight_fragments_split_by_a_nodata_hole_merge_into_one_night(): void
    {
        // 23:00–00:30 and 04:00–07:00 (a 3.5 h NODATA hole between) — both anchor to core sleep hours.
        $a = strtotime('2026-07-12 23:00:00 UTC');
        $fragments = [
            $this->cluster($a, strtotime('2026-07-13 00:30:00 UTC')),
            $this->cluster(strtotime('2026-07-13 04:00:00 UTC'), strtotime('2026-07-13 07:00:00 UTC')),
        ];

        $merged = $this->merge($fragments);

        $this->assertCount(1, $merged, 'the two overnight fragments become one night');
        $span = $merged[0];
        $this->assertSame($a, (int) $span->min(fn ($i) => Carbon::parse($i->window_start)->timestamp), 'span starts at the real bedtime');
        $this->assertSame(strtotime('2026-07-13 07:00:00 UTC'), (int) $span->max(fn ($i) => Carbon::parse($i->window_end)->timestamp), 'span ends at the real wake');
    }

    public function test_an_evening_cluster_is_never_absorbed_into_the_night(): void
    {
        // 18:00–20:00 (NOT core sleep hours) then the night 23:00–07:00 — must stay two sessions (I2/I7).
        $fragments = [
            $this->cluster(strtotime('2026-07-12 18:00:00 UTC'), strtotime('2026-07-12 20:00:00 UTC')),
            $this->cluster(strtotime('2026-07-12 23:00:00 UTC'), strtotime('2026-07-13 07:00:00 UTC')),
        ];

        $this->assertCount(2, $this->merge($fragments));
    }

    public function test_a_gap_larger_than_the_cap_does_not_merge(): void
    {
        // Two night-ish fragments >4 h apart (a nap + a night, say) — not one night.
        $fragments = [
            $this->cluster(strtotime('2026-07-12 23:00:00 UTC'), strtotime('2026-07-13 00:00:00 UTC')),
            $this->cluster(strtotime('2026-07-13 06:00:00 UTC'), strtotime('2026-07-13 07:00:00 UTC')),
        ];

        $this->assertCount(2, $this->merge($fragments));
    }

    public function test_low_coverage_and_implausible_split_flag_low_confidence(): void
    {
        $this->assertTrue($this->isLowConfidence(null), 'duration-only = low confidence');
        $this->assertTrue($this->isLowConfidence(['coverage' => 0.40]), 'below 0.5 coverage = low confidence');
        $this->assertTrue($this->isLowConfidence(['coverage' => 0.90, 'stages_low_confidence' => true]), 'implausible split = low confidence');
        $this->assertFalse($this->isLowConfidence(['coverage' => 0.85]), 'good coverage, plausible split = confident');
    }
}
