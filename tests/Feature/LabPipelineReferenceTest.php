<?php

namespace Tests\Feature;

use App\Models\DeviceIngestion;
use App\Models\MotionSample;
use App\Models\User;
use App\Support\Lab\PipelineFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * LAB PIPELINE FIDELITY · Part A — the fingerprint introspection that turns real ingested data into the
 * canonical reference (and, in the gate, measures a LAB night the same way).
 */
class LabPipelineReferenceTest extends TestCase
{
    use RefreshDatabase;

    private function ppgWindow(int $pid, int $startTs, float $hr, float $motion): void
    {
        DeviceIngestion::create([
            'batch_uid' => substr(hash('sha256', $pid.'-'.$startTs.'-'.mt_rand()), 0, 40),
            'profile_id' => $pid, 'source' => 'titan_band', 'kind' => 'ppg_raw',
            'status' => DeviceIngestion::STATUS_PROCESSED,
            'window_start' => Carbon::createFromTimestamp($startTs),
            'window_end' => Carbon::createFromTimestamp($startTs + 29),   // real 29 s burst
            'result_refs' => ['epoch_hr' => [$hr], 'epoch_motion' => [$motion], 'epoch_rmssd' => [120], 'ibi_ms' => array_fill(0, 26, 900)],
        ]);
    }

    public function test_fingerprint_measures_jitter_geometry_and_channels(): void
    {
        $profile = User::factory()->create()->ensureProfile();
        $base = Carbon::parse('2026-07-11 05:00:00')->timestamp;

        // Four sparse bursts, HR 60 -> 70 -> 64 -> 72 → |ΔHR| = 10, 6, 8 (median 8).
        foreach ([[0, 60.0], [180, 70.0], [360, 64.0], [540, 72.0]] as [$off, $hr]) {
            $this->ppgWindow($profile->id, $base + $off, $hr, 2.0);
        }
        // Dense T10 motion (milli-g EMA), 1 per 30 s.
        foreach (range(0, 6) as $i) {
            MotionSample::create([
                'profile_id' => $profile->id, 'recorded_at' => Carbon::createFromTimestamp($base + $i * 30),
                'motion' => 30 + $i * 10, 'source' => 'titan-band',
            ]);
        }

        $fp = PipelineFingerprint::forProfile($profile->id);

        $this->assertSame(4, $fp['ppg_raw']['window_count']);
        $this->assertSame(29.0, $fp['ppg_raw']['window_len_sec']['p50'], 'a real burst is ~29 s');
        $this->assertSame(1.0, $fp['ppg_raw']['epochs_per_window']['p50'], 'one ppg_raw window = ONE epoch');
        $this->assertSame(8.0, $fp['hr_jitter_abs_dhr']['p50'], 'median |ΔHR| across the epoch_hr series');
        $this->assertEqualsWithDelta(2.0, $fp['epoch_motion_proxy']['p50'], 0.01);
        $this->assertSame(7, $fp['t10_motion']['n'], 'dense motion channel measured');
        $this->assertNotNull($fp['t10_motion']['coverage_pct']);
        $this->assertArrayHasKey('ppg_raw', $fp['kinds']);
        $this->assertContains('epoch_hr', $fp['kinds']['ppg_raw']['result_refs_keys']);
    }

    public function test_stats_helper_is_percentile_correct(): void
    {
        $s = PipelineFingerprint::stats([5, 1, 3, 2, 4]);
        $this->assertSame(1.0, $s['min']);
        $this->assertSame(5.0, $s['max']);
        $this->assertSame(3.0, $s['p50']);
        $this->assertSame(0, PipelineFingerprint::stats([])['n']);
    }
}
