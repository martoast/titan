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

    private function isLowConfidence(?array $metrics, ?float $validFraction = null): bool
    {
        $job = new SealNightJob(1);
        $m = new \ReflectionMethod($job, 'isLowConfidence');
        $m->setAccessible(true);

        return $m->invoke($job, $metrics, $validFraction);
    }

    private function stagesLowConfidence(?array $metrics, ?float $validFraction = null): bool
    {
        $job = new SealNightJob(1);
        $m = new \ReflectionMethod($job, 'stagesLowConfidence');
        $m->setAccessible(true);

        return $m->invoke($job, $metrics, $validFraction);
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
        $this->assertFalse($this->isLowConfidence(['coverage' => 0.85]), 'good coverage, plausible split = confident');

        // The stager's own split doubt lands on the STAGES flag, never on the night — it says nothing about
        // whether the duration was measured. (This assertion used to target isLowConfidence and had been
        // failing since dc8f1fb gated the soft tier; the split is what reconciles it.)
        $this->assertTrue($this->stagesLowConfidence(['coverage' => 0.70, 'stages_low_confidence' => true]), 'stager-flagged split = stages not trusted');
        $this->assertFalse($this->isLowConfidence(['coverage' => 0.70, 'stages_low_confidence' => true]), 'a bad split does not unmeasure the night');
    }

    public function test_poor_signal_flags_even_when_the_merge_inflated_coverage(): void
    {
        // Tester B's real night after the span-merge: coverage bridged to 0.99, but 0/170 windows were fully
        // valid → still an estimate. The valid-window fraction (measured on the raw windows) must fire
        // regardless of the inflated coverage.
        $this->assertTrue($this->isLowConfidence(['coverage' => 0.99], 0.0), 'zero valid windows = low confidence despite high coverage');
        $this->assertTrue($this->isLowConfidence(['coverage' => 0.99], 0.30), 'below half valid = low confidence');
        $this->assertFalse($this->isLowConfidence(['coverage' => 0.99], 0.80), 'mostly-valid windows + good coverage = confident');
    }

    public function test_php_side_stage_plausibility_catches_rem_collapse(): void
    {
        // Tester B's stages: deep 38% / REM 2% / light 60% — REM under 5% is physiologically implausible. On a
        // THIN night the PHP-side rule flags the split even when the stager didn't surface its own doubt.
        $this->assertTrue($this->stagesLowConfidence(['coverage' => 0.70, 'deep_min' => 150, 'rem_min' => 8, 'light_min' => 240], 0.9));
        // A dominant single stage (>70%) also flags.
        $this->assertTrue($this->stagesLowConfidence(['coverage' => 0.70, 'deep_min' => 20, 'rem_min' => 20, 'light_min' => 320], 0.9));
        // A healthy split does not.
        $this->assertFalse($this->stagesLowConfidence(['coverage' => 0.70, 'deep_min' => 90, 'rem_min' => 100, 'light_min' => 250], 0.9));

        // DELIBERATE, and worth knowing: on a WELL-measured night the soft tier is suppressed, so the same
        // REM-collapse split reads as an odd-but-real night (dc8f1fb — it stopped Alex's 99%-coverage nights
        // being permanently caveated). Only the IMPOSSIBLE tier pierces the gate. Whether that gate still
        // earns its keep now that flagging stages no longer costs the night its debt/streak is an open call.
        // Deep kept at a NORMAL 15% so this exercises the soft REM-collapse tier only — at 150 min it would
        // trip the deep-fraction ceiling and prove nothing about the gate.
        $wellMeasured = ['coverage' => 0.99, 'deep_min' => 60, 'rem_min' => 8, 'light_min' => 330];
        $this->assertFalse($this->stagesLowConfidence($wellMeasured, 0.9), 'soft tier stays gated at high coverage');
        $this->assertTrue(
            $this->stagesLowConfidence($wellMeasured + ['hypnogram_30s' => array_fill(0, 200, 'deep')], 0.9),
            'the impossible tier ignores the gate',
        );
    }

    public function test_impossible_split_flags_the_STAGES_but_not_the_night(): void
    {
        // Tester B 2026-08-04, the night that started this: 219 min deep (40% of sleep) in ONE unbroken 119.5-min
        // block, REM 6.8%, coverage 0.991 and every window valid. It clears the >5% REM trigger, no stage
        // reaches 70%, and the high coverage suppresses the soft tier — so it sealed clean and the coach
        // told her she was well rested. The impossibility tier is ungated and must catch it.
        $vel = [
            'coverage' => 0.991, 'deep_min' => 219, 'rem_min' => 37, 'light_min' => 296,
            'hypnogram_30s' => array_merge(array_fill(0, 239, 'deep'), array_fill(0, 887, 'light')),
        ];
        $this->assertTrue($this->stagesLowConfidence($vel, 1.0), '40% deep in one 2h block is not a readable split');
        // ...and CRITICALLY, the night itself stays trusted: she really slept 9h12m, and flagging the whole
        // night would drop it out of SleepDebt/SleepWeek to fix a problem that isn't about duration.
        $this->assertFalse($this->isLowConfidence($vel, 1.0), 'a misread split must not unmeasure a real night');

        // The BOUT ceiling stands on its own: 100 min unbroken deep inside an otherwise healthy 8h night is
        // only 21% deep overall, so no fraction rule would ever see it.
        $oneBlock = [
            'coverage' => 0.99, 'deep_min' => 100, 'rem_min' => 90, 'light_min' => 290,
            'hypnogram_30s' => array_merge(array_fill(0, 200, 'deep'), array_fill(0, 760, 'light')),
        ];
        $this->assertTrue($this->stagesLowConfidence($oneBlock, 1.0), 'a 100-min unbroken deep bout is an artifact');

        // ...and a normal night whose deep arrives in real-length bouts stays CONFIDENT on both flags — the
        // whole point is not to re-caveat every well-measured night (the 2026-07-14 regression).
        $healthy = [
            'coverage' => 0.99, 'deep_min' => 90, 'rem_min' => 100, 'light_min' => 250,
            'hypnogram_30s' => array_merge(
                ...array_fill(0, 6, array_merge(array_fill(0, 30, 'deep'), array_fill(0, 60, 'light'), array_fill(0, 20, 'rem'))),
            ),
        ];
        $this->assertFalse($this->stagesLowConfidence($healthy, 1.0), 'normal architecture must stay confident');
        $this->assertFalse($this->isLowConfidence($healthy, 1.0));
    }

    public function test_deep_fraction_ceiling_exempts_short_naps_but_the_bout_ceiling_does_not(): void
    {
        // A 40-min recovery nap that is a third deep is a REAL nap — judging it by a whole-night norm would
        // be a false positive (it would have wrongly flagged sleep_log #55).
        $nap = ['coverage' => 0.99, 'deep_min' => 15, 'rem_min' => 5, 'light_min' => 25,
            'hypnogram_30s' => array_merge(array_fill(0, 30, 'deep'), array_fill(0, 60, 'light'))];
        $this->assertFalse($this->stagesLowConfidence($nap, 1.0), 'a third-deep nap is plausible');

        // A missing hypnogram leaves only the fraction rule — it must not crash or silently pass a bad night.
        $noHyp = ['coverage' => 0.99, 'deep_min' => 250, 'rem_min' => 40, 'light_min' => 300];
        $this->assertTrue($this->stagesLowConfidence($noHyp, 1.0), 'fraction rule stands without a timeline');

        // A duration-only row has no stages to doubt.
        $this->assertFalse($this->stagesLowConfidence(null));
    }

    public function test_soft_stage_doubt_keeps_its_coverage_gate_on_the_stages_flag(): void
    {
        // REM 2% on a THIN night (coverage 0.60) is doubtful — flag the split.
        $thin = ['coverage' => 0.60, 'deep_min' => 60, 'rem_min' => 8, 'light_min' => 250];
        $this->assertTrue($this->stagesLowConfidence($thin, 0.9));
        // The same split on a WELL-measured night is an odd-but-real night, not an artifact — the gate that
        // exists so Alex's 99%-coverage nights aren't permanently caveated still applies to the soft tier.
        $wellMeasured = ['coverage' => 0.99, 'deep_min' => 60, 'rem_min' => 8, 'light_min' => 250];
        $this->assertFalse($this->stagesLowConfidence($wellMeasured, 0.9));
    }

    public function test_null_coverage_and_degenerate_sliver_flag_low_confidence(): void
    {
        // Review 2026-07-12: a staged night with NULL coverage must flag (NULL slipped the < 0.5 gate and
        // poisoned the debt ledger). A missing coverage is itself a low-confidence signal.
        $this->assertTrue($this->isLowConfidence(['deep_min' => 90, 'rem_min' => 100, 'light_min' => 250]));

        // A 7-min "night" (7 asleep, 1280 awake) sealed as a full night is degenerate — must flag even
        // though it has "coverage" and too little sleep to judge a stage split.
        $this->assertTrue($this->isLowConfidence(['coverage' => 0.99, 'deep_min' => 0, 'rem_min' => 0, 'light_min' => 7, 'awake_min' => 1280], 0.9));
    }
}
