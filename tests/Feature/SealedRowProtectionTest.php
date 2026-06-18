<?php

namespace Tests\Feature;

use App\Jobs\ProcessWindowJob;
use App\Jobs\SealNightJob;
use App\Models\DeviceIngestion;
use App\Models\RecoveryLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The whole-night seal is authoritative. Neither a late per-window job nor a re-seal driven
 * by a lone straggler window may replace a good sealed recovery read with a noisier one.
 */
class SealedRowProtectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_late_window_does_not_clobber_a_sealed_recovery_row(): void
    {
        Storage::fake('raw');
        config(['services.biosignal.url' => 'http://biosignal:8000', 'services.biosignal.token' => 't']);
        // A lone late window would compute a much noisier single-window value.
        Http::fake(['*/process/hrv' => Http::response(['algo_version' => 'v1', 'metrics' => [
            'hrv_ms' => 45, 'resting_hr' => 70, 'valid' => true,
        ]])]);

        $p = User::factory()->create()->ensureProfile();
        $date = '2026-06-17';
        // The night is already sealed with the authoritative whole-night read.
        RecoveryLog::create([
            'profile_id' => $p->id, 'logged_at' => $date, 'hrv_ms' => 72, 'resting_hr' => 50,
            'updated_via' => 'biosignal:sealed',
            'quality' => ['beats' => 20000, 'windows_used' => 38, 'windows_dropped' => 0, 'valid' => true],
        ]);

        // A straggler window arrives and processes AFTER the seal.
        $window = ['kind' => 'ibi', 'ibi_ms' => array_fill(0, 80, 900)];
        $key = "raw/{$p->id}/late.ndjson.gz";
        Storage::disk('raw')->put($key, gzencode(json_encode($window)));
        DeviceIngestion::create([
            'batch_uid' => 'late-1', 'profile_id' => $p->id, 'source' => 'titan_band', 'kind' => 'ibi',
            'object_key' => $key, 'window_start' => CarbonImmutable::parse($date.' 03:00:00Z'),
            'window_end' => CarbonImmutable::parse($date.' 03:05:00Z'), 'status' => DeviceIngestion::STATUS_QUEUED,
        ]);

        dispatch_sync(new ProcessWindowJob('late-1'));

        $r = RecoveryLog::where('profile_id', $p->id)->where('logged_at', $date)->first();
        $this->assertSame(72, $r->hrv_ms);                       // NOT clobbered to 45
        $this->assertSame('biosignal:sealed', $r->updated_via);  // confidence stays "sealed"
        $this->assertDatabaseCount('recovery_logs', 1);          // no duplicate row
    }

    public function test_reseal_with_a_lone_straggler_keeps_the_better_read(): void
    {
        config(['services.biosignal.url' => 'http://biosignal:8000', 'services.biosignal.token' => 't']);
        // The straggler-only re-seal would compute a worse aggregate from far fewer beats.
        Http::fake(['*/process/hrv' => Http::response(['algo_version' => 'v1', 'metrics' => [
            'hrv_ms' => 50, 'resting_hr' => 64, 'valid' => true,
        ]])]);

        $p = User::factory()->create()->ensureProfile();
        $night = Carbon::yesterday()->toDateString();
        // A complete, better sealed read already exists (20k beats).
        RecoveryLog::create([
            'profile_id' => $p->id, 'logged_at' => $night, 'hrv_ms' => 72, 'resting_hr' => 50,
            'updated_via' => 'biosignal:sealed',
            'quality' => ['beats' => 20000, 'windows_used' => 38, 'windows_dropped' => 0, 'valid' => true],
        ]);
        // A lone unsealed straggler window (enough beats to pass the count gate, far fewer than the night).
        DeviceIngestion::create([
            'batch_uid' => 'straggler', 'profile_id' => $p->id, 'source' => 'titan_band', 'kind' => 'ibi',
            'object_key' => null, 'status' => DeviceIngestion::STATUS_PROCESSED,
            'window_start' => CarbonImmutable::parse($night.' 03:00:00Z'),
            'window_end' => CarbonImmutable::parse($night.' 03:05:00Z'),
            'result_refs' => ['ibi_ms' => array_fill(0, 50, 900)],
        ]);

        dispatch_sync(new SealNightJob($p->id, $night));

        $r = RecoveryLog::where('profile_id', $p->id)->where('logged_at', $night)->first();
        $this->assertSame(72, $r->hrv_ms);                          // kept the better sealed read
        $this->assertSame(20000, $r->quality['beats']);             // quality not downgraded
        // …and the straggler is still sealed so it doesn't reprocess forever.
        $this->assertSame(DeviceIngestion::STATUS_SEALED, DeviceIngestion::where('batch_uid', 'straggler')->first()->status);
    }
}
