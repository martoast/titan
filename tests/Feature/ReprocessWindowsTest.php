<?php

namespace Tests\Feature;

use App\Models\DeviceIngestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * sleep:reprocess re-runs stored raw windows through the CURRENT biosignal + reseals — used after a biosignal
 * fix (the RMSSD saturation / motion fixes) so the LAB calibration + recovery read corrected features. The
 * full reprocess→reseal path is validated live (idempotent: one row, stages preserved) and is the same
 * unseal→ProcessWindowJob→SealNightJob pattern as sleep:recover-stages. This pins the SAFETY guards: a dry-run
 * never mutates, and an --apply is always scoped to one deliberately-chosen profile (never fleet-wide).
 */
class ReprocessWindowsTest extends TestCase
{
    use RefreshDatabase;

    private function window(int $profileId, string $start): DeviceIngestion
    {
        return DeviceIngestion::create([
            'batch_uid' => substr(hash('sha256', $start.$profileId.mt_rand()), 0, 40),
            'profile_id' => $profileId,
            'source' => 'titan_band',
            'kind' => 'ppg_raw',
            'status' => DeviceIngestion::STATUS_SEALED,
            'window_start' => Carbon::parse($start, 'UTC'),
            'window_end' => Carbon::parse($start, 'UTC')->addSeconds(30),
            'result_refs' => ['sealed' => true, 'sleep_log_id' => 99, 'epoch_rmssd' => [250.0]],
        ]);
    }

    public function test_dry_run_lists_windows_without_mutating(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $w = $this->window($p->id, Carbon::now('UTC')->subDay()->toDateTimeString());

        $this->artisan('sleep:reprocess', ['--profile' => $p->id, '--days' => 3])
            ->expectsOutputToContain('DRY RUN')
            ->assertExitCode(0);

        // Untouched: still sealed, markers intact — a dry-run must never re-queue or reseal.
        $this->assertSame(DeviceIngestion::STATUS_SEALED, $w->refresh()->status);
        $this->assertTrue($w->result_refs['sealed']);
    }

    public function test_apply_requires_a_profile(): void
    {
        // Reprocessing mutates real sealed history, so a fleet-wide --apply is refused.
        $this->artisan('sleep:reprocess', ['--apply' => true])
            ->expectsOutputToContain('--apply requires --profile')
            ->assertExitCode(1);
    }

    public function test_scans_only_the_requested_profile_and_range(): void
    {
        $a = User::factory()->create()->ensureProfile();
        $b = User::factory()->create()->ensureProfile();
        $this->window($a->id, Carbon::now('UTC')->subDay()->toDateTimeString());
        $this->window($b->id, Carbon::now('UTC')->subDay()->toDateTimeString());    // other profile — excluded
        $this->window($a->id, Carbon::now('UTC')->subDays(30)->toDateTimeString());  // out of range — excluded

        $this->artisan('sleep:reprocess', ['--profile' => $a->id, '--days' => 3])
            ->expectsOutputToContain('1 window(s) across 1 night(s)')
            ->assertExitCode(0);
    }
}
