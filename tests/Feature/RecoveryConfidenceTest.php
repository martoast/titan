<?php

namespace Tests\Feature;

use App\Jobs\SealNightJob;
use App\Models\DeviceIngestion;
use App\Models\RecoveryLog;
use App\Models\User;
use App\Support\RecoveryConfidence;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RecoveryConfidenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_source_classification_from_updated_via(): void
    {
        $this->assertSame('sealed', RecoveryConfidence::source('biosignal:sealed'));
        $this->assertSame('window', RecoveryConfidence::source('biosignal:v1'));
        $this->assertSame('provider', RecoveryConfidence::source('device:summary'));
        $this->assertSame('manual', RecoveryConfidence::source('manual:user'));
        $this->assertSame('unknown', RecoveryConfidence::source(null));
    }

    public function test_sealed_read_with_deep_baseline_is_high_confidence(): void
    {
        $p = User::factory()->create()->ensureProfile();
        // 20 nights of sealed HRV history.
        foreach (range(1, 20) as $d) {
            RecoveryLog::create([
                'profile_id' => $p->id, 'logged_at' => Carbon::today()->subDays($d)->toDateString(),
                'hrv_ms' => 65, 'updated_via' => 'biosignal:sealed',
            ]);
        }
        $today = RecoveryLog::create([
            'profile_id' => $p->id, 'logged_at' => Carbon::today()->toDateString(),
            'hrv_ms' => 68, 'updated_via' => 'biosignal:sealed',
            'quality' => ['windows_used' => 9, 'windows_dropped' => 0, 'valid' => true],
        ]);

        $c = RecoveryConfidence::assess($p->refresh(), $today);
        $this->assertSame('high', $c['level']);
        $this->assertSame('sealed', $c['source']);
        $this->assertNull($c['note']);   // nothing to caveat
    }

    public function test_sealed_but_thin_baseline_is_building(): void
    {
        $p = User::factory()->create()->ensureProfile();
        foreach (range(1, 5) as $d) {
            RecoveryLog::create([
                'profile_id' => $p->id, 'logged_at' => Carbon::today()->subDays($d)->toDateString(),
                'hrv_ms' => 64, 'updated_via' => 'biosignal:sealed',
            ]);
        }
        $today = RecoveryLog::create([
            'profile_id' => $p->id, 'logged_at' => Carbon::today()->toDateString(),
            'hrv_ms' => 70, 'updated_via' => 'biosignal:sealed',
        ]);

        $c = RecoveryConfidence::assess($p->refresh(), $today);
        $this->assertSame('building', $c['level']);
        $this->assertStringContainsString('baseline', strtolower((string) $c['note']));
    }

    public function test_spot_window_and_manual_reads_are_low(): void
    {
        $p = User::factory()->create()->ensureProfile();
        foreach (range(1, 20) as $d) {
            RecoveryLog::create(['profile_id' => $p->id, 'logged_at' => Carbon::today()->subDays($d)->toDateString(), 'hrv_ms' => 60, 'updated_via' => 'biosignal:sealed']);
        }
        $window = RecoveryLog::create(['profile_id' => $p->id, 'logged_at' => Carbon::today()->toDateString(), 'hrv_ms' => 50, 'updated_via' => 'biosignal:v1']);
        $cWin = RecoveryConfidence::assess($p->refresh(), $window);
        $this->assertSame('low', $cWin['level']);
        $this->assertStringContainsString('single', strtolower((string) $cWin['note']));

        $manual = new RecoveryLog(['hrv_ms' => 55, 'updated_via' => 'manual:user', 'logged_at' => Carbon::today()->toDateString()]);
        $manual->profile_id = $p->id;
        $cMan = RecoveryConfidence::assess($p->refresh(), $manual);
        $this->assertSame('low', $cMan['level']);
        $this->assertStringContainsString('estimate', strtolower((string) $cMan['note']));
    }

    public function test_dropped_windows_surface_in_the_caveat(): void
    {
        $p = User::factory()->create()->ensureProfile();
        foreach (range(1, 20) as $d) {
            RecoveryLog::create(['profile_id' => $p->id, 'logged_at' => Carbon::today()->subDays($d)->toDateString(), 'hrv_ms' => 62, 'updated_via' => 'biosignal:sealed']);
        }
        $today = RecoveryLog::create([
            'profile_id' => $p->id, 'logged_at' => Carbon::today()->toDateString(), 'hrv_ms' => 66, 'updated_via' => 'biosignal:sealed',
            'quality' => ['windows_used' => 7, 'windows_dropped' => 2, 'valid' => true],
        ]);

        $c = RecoveryConfidence::assess($p->refresh(), $today);
        $this->assertSame('high', $c['level']);
        $this->assertStringContainsString('2 of 9 windows', (string) $c['note']);
    }

    public function test_seal_persists_window_quality_and_drops_artifacts(): void
    {
        config(['services.biosignal.url' => 'http://biosignal:8000', 'services.biosignal.token' => 't']);
        Http::fake(['*/process/hrv' => Http::response(['algo_version' => 'v1', 'metrics' => [
            'hrv_ms' => 66, 'resting_hr' => 54, 'valid' => true,
        ]])]);

        $p = User::factory()->create()->ensureProfile();
        $night = Carbon::yesterday()->toDateString();
        // 3 windows: 2 clean, 1 artifact (rmssd above the ceiling) → dropped from the aggregate.
        foreach ([['r' => 60, 'd' => false], ['r' => 70, 'd' => false], ['r' => 350, 'd' => true]] as $i => $w) {
            DeviceIngestion::create([
                'batch_uid' => "seal-{$i}", 'profile_id' => $p->id, 'source' => 'titan_band', 'kind' => 'ppg_raw',
                'object_key' => null, 'status' => DeviceIngestion::STATUS_PROCESSED,
                'window_start' => CarbonImmutable::parse($night.' 02:0'.$i.':00Z'),
                'window_end' => CarbonImmutable::parse($night.' 02:0'.$i.':30Z'),
                'result_refs' => ['ibi_ms' => [820, 810, 800, 815, 805], 'rmssd' => $w['r']],
            ]);
        }

        dispatch_sync(new SealNightJob($p->id, $night));

        $log = RecoveryLog::where('profile_id', $p->id)->where('logged_at', $night)->first();
        $this->assertNotNull($log);
        $this->assertSame('biosignal:sealed', $log->updated_via);
        $this->assertSame(2, $log->quality['windows_used']);
        $this->assertSame(1, $log->quality['windows_dropped']);
    }

    public function test_briefing_prefers_the_sealed_night_and_speaks_its_caveat(): void
    {
        // AI offline → deterministic fallback, which still honours confidence.
        $ai = \Mockery::mock(\App\Services\Ai\AiService::class);
        $ai->shouldReceive('chat')->andThrow(new \App\Exceptions\AiException('offline'));
        $this->app->instance(\App\Services\Ai\AiService::class, $ai);

        $p = User::factory()->create()->ensureProfile();
        // A noisy daytime spot-window row is the NEWEST, but a sealed overnight read exists today too.
        RecoveryLog::create(['profile_id' => $p->id, 'logged_at' => Carbon::today()->toDateString(), 'hrv_ms' => 71, 'resting_hr' => 55, 'updated_via' => 'biosignal:sealed']);
        $win = RecoveryLog::create(['profile_id' => $p->id, 'logged_at' => Carbon::today()->toDateString(), 'hrv_ms' => 44, 'updated_via' => 'biosignal:v1']);
        $win->update(['updated_at' => now()->addMinute()]);   // make the window the newest row

        $msg = app(\App\Services\Coach\CoachBriefingService::class)->morningBriefing($p->refresh());

        // It used the SEALED 71ms read, not the 44ms spot window…
        $this->assertStringContainsString('71', $msg);
        $this->assertStringNotContainsString('44', $msg);
        // …and because the baseline is only 1 night, it spoke the caveat.
        $this->assertStringContainsString('night', strtolower($msg));
    }

    public function test_seal_fails_safe_when_validity_is_missing(): void
    {
        config(['services.biosignal.url' => 'http://biosignal:8000', 'services.biosignal.token' => 't']);
        // Service returns metrics with NO `valid` key — an unexpected/erroring shape.
        Http::fake(['*/process/hrv' => Http::response(['algo_version' => 'v1', 'metrics' => ['hrv_ms' => 66]])]);

        $p = User::factory()->create()->ensureProfile();
        $night = Carbon::yesterday()->toDateString();
        DeviceIngestion::create([
            'batch_uid' => 'seal-x', 'profile_id' => $p->id, 'source' => 'titan_band', 'kind' => 'ppg_raw',
            'object_key' => null, 'status' => DeviceIngestion::STATUS_PROCESSED,
            'window_start' => CarbonImmutable::parse($night.' 02:00:00Z'),
            'window_end' => CarbonImmutable::parse($night.' 02:05:00Z'),
            'result_refs' => ['ibi_ms' => [820, 810, 800, 815, 805, 812, 808, 818, 803, 811, 809, 814], 'rmssd' => 60],
        ]);

        dispatch_sync(new SealNightJob($p->id, $night));

        // No recovery row written from an unvalidated read…
        $this->assertDatabaseCount('recovery_logs', 0);
        // …but the window is still sealed so the night isn't reprocessed forever.
        $this->assertSame(DeviceIngestion::STATUS_SEALED, DeviceIngestion::where('batch_uid', 'seal-x')->first()->status);
    }
}
