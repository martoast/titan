<?php

namespace Tests\Feature;

use App\Jobs\SealNightJob;
use App\Models\DeviceIngestion;
use App\Models\MotionSample;
use App\Models\SleepLog;
use App\Models\User;
use App\Services\Wearables\BiosignalClient;
use App\Support\SleepDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * SLEEP TIMELINE v2 (movement & peaks) — the server half. A scripted night sealed through the real
 * SealNightJob must persist two SPARSE, gap-honest per-epoch overlay series (hr_series / motion_series)
 * aligned to the hypnogram's epoch grid, and expose them via SleepDetail. This test proves the four §5
 * promises deterministically (no biosignal container needed — the stager is faked to a valid response;
 * the series themselves are built server-side from the windows' per-epoch features):
 *   1. Movement fidelity — motion is high in the scripted restless bout, low during scripted deep.
 *   2. Peak survival     — a one-epoch HR arousal spike survives max-pool downsampling (not averaged away).
 *   3. Gap honesty       — a scripted charge gap has NO hr/motion points (a hole, never interpolated).
 *   4. Alignment         — every series index is a valid epoch on the one hypnogram grid.
 */
class SleepSeriesTest extends TestCase
{
    use RefreshDatabase;

    /** Epoch layout of the scripted night (30s epochs from bed). */
    private const DEEP = [0, 120];        // calm valley           motion 2, hr 50
    private const RESTLESS = [300, 320];  // the tossing bout      motion 16, hr 70 (spike 98 @ 310)
    private const SPIKE_EPOCH = 310;      // the 3am arousal peak  hr 98 (one epoch)
    private const GAP = [400, 470];       // band off charging     NO windows → a hole
    private const N_EPOCHS = 700;         // 350-min night (a real night, not a nap)

    public function test_seals_persist_gap_honest_max_pool_series_and_reader_exposes_them(): void
    {
        config(['services.biosignal.url' => 'http://biosignal:8000', 'services.biosignal.token' => 't']);

        // Fake the stager: a valid staged response whose hypnogram is sized to the posted span, with NODATA
        // exactly across the scripted charge gap (the series must never place a point there).
        Http::fake(['*/process/sleep' => function ($request) {
            $data = $request->data();
            $t0 = Carbon::parse($data['start'])->timestamp;
            $t1 = Carbon::parse($data['end'])->timestamp;
            $n = (int) round(($t1 - $t0) / 30);
            $hyp = array_fill(0, $n, 'light');
            for ($i = self::GAP[0]; $i < self::GAP[1] && $i < $n; $i++) {
                $hyp[$i] = 'nodata';
            }

            return Http::response(['algo_version' => 'test', 'metrics' => [
                'duration_min' => 330, 'deep_min' => 60, 'rem_min' => 40, 'light_min' => 220, 'awake_min' => 20,
                'bedtime' => $data['start'], 'wake_time' => $data['end'], 'quality' => 80,
                'coverage' => 0.9, 'hypnogram_30s' => $hyp,
            ]]);
        }]);

        $profile = User::factory()->create()->ensureProfile();
        $wake = time();
        $bed = $wake - self::N_EPOCHS * 30;   // 700 epochs = 350 min

        $this->seedNight($profile->id, $bed);

        Queue::fake();
        (new SealNightJob($profile->id, null, true, $bed, $wake, 0))->handle(app(BiosignalClient::class));

        $row = SleepLog::where('profile_id', $profile->id)->where('is_nap', false)->first();
        $this->assertNotNull($row, 'a night row was sealed');
        $this->assertSame('final', $row->stage_status);

        $hr = $row->hr_series;
        $motion = $row->motion_series;
        $this->assertIsArray($hr);
        $this->assertIsArray($motion);
        $this->assertNotEmpty($hr, 'hr_series persisted');
        $this->assertNotEmpty($motion, 'motion_series persisted');

        // Shape: index-based sparse {i,v}, capped at ≤180 points each.
        $this->assertLessThanOrEqual(180, count($hr));
        $this->assertLessThanOrEqual(180, count($motion));
        foreach ([$hr, $motion] as $series) {
            foreach ($series as $p) {
                $this->assertIsInt($p['i']);
                $this->assertIsNumeric($p['v']);
            }
        }
        // Downsampling must actually have engaged (>180 measured epochs), so the peak test is meaningful.
        $this->assertGreaterThan(180, self::N_EPOCHS - (self::GAP[1] - self::GAP[0]));

        // ---- §5.2 Peak survival: the one-epoch HR spike (98) survives max-pool at its real epoch.
        $peak = collect($hr)->firstWhere('v', 98);
        $this->assertNotNull($peak, 'the arousal HR spike survived downsampling (max-pool, not mean)');
        $this->assertGreaterThanOrEqual(self::RESTLESS[0], $peak['i']);
        $this->assertLessThan(self::RESTLESS[1], $peak['i']);
        $this->assertSame(98, collect($hr)->max('v'), 'the global HR peak is preserved');

        // ---- §5.1 Movement fidelity: restless bout is high, scripted deep is calm.
        $inSpan = fn ($p, $span) => $p['i'] >= $span[0] && $p['i'] < $span[1];
        $restlessMotion = collect($motion)->filter(fn ($p) => $inSpan($p, self::RESTLESS));
        $deepMotion = collect($motion)->filter(fn ($p) => $inSpan($p, self::DEEP));
        $this->assertNotEmpty($restlessMotion, 'motion measured across the restless bout');
        $this->assertNotEmpty($deepMotion, 'motion measured across deep sleep');
        $this->assertGreaterThanOrEqual(15.0, $restlessMotion->max('v'), 'restless teeth are tall');
        $this->assertLessThanOrEqual(3.0, $deepMotion->max('v'), 'deep sleep is a calm valley');
        $this->assertGreaterThan($deepMotion->max('v'), $restlessMotion->max('v'));

        // ---- §5.3 Gap honesty: NO hr/motion points inside the scripted charge gap.
        foreach ([$hr, $motion] as $series) {
            foreach ($series as $p) {
                $this->assertFalse($inSpan($p, self::GAP), "a point ({$p['i']}) fell inside the charge gap");
            }
        }

        // ---- §5.4 Alignment: every series index is a valid epoch on the one hypnogram grid.
        $hyp = $row->hypnogram;
        $this->assertIsArray($hyp);
        foreach ([$hr, $motion] as $series) {
            foreach ($series as $p) {
                $this->assertGreaterThanOrEqual(0, $p['i']);
                $this->assertLessThan(count($hyp), $p['i']);
            }
        }

        // ---- Reader (SleepDetail) exposes both series, and the stray per-stage `color` copy (§4) is gone.
        $detail = SleepDetail::forProfile($profile);
        $this->assertNotNull($detail);
        $this->assertSame($hr, $detail['hr_series']);
        $this->assertSame($motion, $detail['motion_series']);
        foreach ($detail['stages'] as $stage) {
            $this->assertArrayNotHasKey('color', $stage, 'the stray per-stage color copy was removed (§4)');
            $this->assertArrayHasKey('key', $stage);
        }
    }

    /**
     * The movement strip prefers the DENSE continuous channel (T10 → motion_samples) over the sparse
     * HRV-burst proxy. A night whose duty-cycle bursts land on only a handful of epochs (the dotted
     * proxy) but whose always-on offline motion frame filled every epoch must seal a motion_series
     * built from the continuous channel — denser than the proxy, and in the proxy's absence of those
     * values. A fully-connected night (no T10) keeps the proxy; that's the SleepSeriesTest above.
     */
    public function test_seal_prefers_dense_continuous_motion_channel_over_sparse_burst_proxy(): void
    {
        config(['services.biosignal.url' => 'http://biosignal:8000', 'services.biosignal.token' => 't']);
        Http::fake(['*/process/sleep' => function ($request) {
            $data = $request->data();
            $n = (int) round((Carbon::parse($data['end'])->timestamp - Carbon::parse($data['start'])->timestamp) / 30);

            return Http::response(['algo_version' => 'test', 'metrics' => [
                'duration_min' => 200, 'deep_min' => 40, 'rem_min' => 30, 'light_min' => 120, 'awake_min' => 10,
                'bedtime' => $data['start'], 'wake_time' => $data['end'], 'quality' => 75,
                'coverage' => 0.9, 'hypnogram_30s' => array_fill(0, max(1, $n), 'light'),
            ]]);
        }]);

        $profile = User::factory()->create()->ensureProfile();
        $tz = config('app.timezone');
        $wake = time();
        $n = 520;                       // 260-min night (> NAP_MAX_MIN so it seals as a night, not a nap)
        $bed = $wake - $n * 30;

        // SPARSE burst proxy: three thin windows spanning the whole night — the dotted coverage the HRV
        // duty-cycle leaves. Small proxy-unit motion values (max ~4). Spanning bed→wake so the seal's
        // window-derived staging span covers the full night (and thus every continuous sample).
        $proxyEpochs = 0;
        foreach ([['e' => 0, 'c' => 5], ['e' => 260, 'c' => 5], ['e' => 510, 'c' => 10]] as $w) {
            $this->seedWindow($profile->id, $bed, $w['e'], $w['c'], motion: 4.0, hr: 60.0);
            $proxyEpochs += $w['c'];
        }

        // DENSE continuous channel: one T10 point per epoch, on a distinct milli-g scale (500, with a
        // 3000 spike) so the assertion can tell which channel the seal chose.
        $rows = [];
        for ($e = 0; $e < $n; $e++) {
            $rows[] = [
                'profile_id' => $profile->id,
                'recorded_at' => Carbon::createFromTimestamp($bed + $e * 30, $tz)->toDateTimeString(),
                'motion' => $e === 150 ? 3000 : 500,
                'source' => 'titan-band',
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        MotionSample::insert($rows);

        Queue::fake();
        (new SealNightJob($profile->id, null, true, $bed, $wake, 0))->handle(app(BiosignalClient::class));

        $row = SleepLog::where('profile_id', $profile->id)->where('is_nap', false)->first();
        $this->assertNotNull($row, 'a night row was sealed');
        $motion = $row->motion_series;
        $this->assertIsArray($motion);

        // The DENSE continuous channel won: many more points than the ~20-epoch proxy, capped at 180.
        $this->assertGreaterThan($proxyEpochs, count($motion));
        $this->assertLessThanOrEqual(180, count($motion));
        // The values are the continuous channel's milli-g (500 / 3000), NOT the proxy's ~4 — the spike
        // is the fingerprint the sparse proxy could never contain, proving the preference engaged.
        $this->assertSame(3000, collect($motion)->max('v'));
        $this->assertGreaterThanOrEqual(500, collect($motion)->min('v'));

        // The HR overlay is untouched by the motion preference — still built from the proxy windows.
        $this->assertNotEmpty($row->hr_series);
    }

    /** Seed one duty-cycle window of `count` epochs at `baseEpoch`, filled with a flat motion + HR. */
    private function seedWindow(int $profileId, int $bed, int $baseEpoch, int $count, float $motion, float $hr): void
    {
        $tz = config('app.timezone');
        $start = $bed + $baseEpoch * 30;
        $end = $start + $count * 30;
        $w = DeviceIngestion::create([
            'batch_uid' => substr(hash('sha256', $profileId.'-'.$baseEpoch.'-'.mt_rand()), 0, 40),
            'profile_id' => $profileId,
            'source' => 'titan_band',
            'kind' => 'ppg_raw',
            'status' => DeviceIngestion::STATUS_PROCESSED,
            'window_start' => Carbon::createFromTimestamp($start, $tz),
            'window_end' => Carbon::createFromTimestamp($end, $tz),
            'result_refs' => [
                'epoch_motion' => array_fill(0, $count, $motion),
                'epoch_hr' => array_fill(0, $count, $hr),
            ],
        ]);
        $w->forceFill(['created_at' => Carbon::createFromTimestamp($end, $tz)])->saveQuietly();
    }

    /**
     * Seed the night's duty-cycle windows (10 epochs each) with per-epoch motion + HR, skipping the charge
     * gap entirely. Motion/HR are stage-shaped: calm deep, restless bout with a one-epoch HR spike.
     */
    private function seedNight(int $profileId, int $bed): void
    {
        $tz = config('app.timezone');
        $motionFor = function (int $e): float {
            if ($e >= self::RESTLESS[0] && $e < self::RESTLESS[1]) {
                return 16.0;                    // the tossing bout
            }
            if ($e < self::DEEP[1]) {
                return 2.0;                     // deep — dead calm
            }

            return $e >= 470 ? 3.0 : 4.0;       // light / tail
        };
        $hrFor = function (int $e): float {
            if ($e === self::SPIKE_EPOCH) {
                return 98.0;                    // the arousal peak (one epoch)
            }
            if ($e >= self::RESTLESS[0] && $e < self::RESTLESS[1]) {
                return 70.0;
            }
            if ($e < self::DEEP[1]) {
                return 50.0;
            }

            return $e >= 470 ? 55.0 : 60.0;
        };

        for ($e = 0; $e < self::N_EPOCHS; $e += 10) {
            // Skip any 10-epoch chunk that overlaps the charge gap → a true NODATA hole (no windows).
            if ($e + 10 > self::GAP[0] && $e < self::GAP[1]) {
                continue;
            }
            $epochMotion = [];
            $epochHr = [];
            for ($k = 0; $k < 10; $k++) {
                $epochMotion[] = $motionFor($e + $k);
                $epochHr[] = $hrFor($e + $k);
            }
            $start = $bed + $e * 30;
            $end = $start + 10 * 30;
            $w = DeviceIngestion::create([
                'batch_uid' => substr(hash('sha256', $profileId.'-'.$e.'-'.mt_rand()), 0, 40),
                'profile_id' => $profileId,
                'source' => 'titan_band',
                'kind' => 'ppg_raw',
                'status' => DeviceIngestion::STATUS_PROCESSED,
                // Create in the APP timezone so Eloquent's naive-datetime round-trip is lossless: the cast
                // reads a stored wall-string back in the app tz, so a UTC-forced Carbon would re-read shifted
                // (desyncing these windows from the seal's UTC-epoch scope). Store what the seal will read.
                'window_start' => Carbon::createFromTimestamp($start, $tz),
                'window_end' => Carbon::createFromTimestamp($end, $tz),
                'result_refs' => ['epoch_motion' => $epochMotion, 'epoch_hr' => $epochHr],
            ]);
            // Live ingestion (arrived at sample time) so the confirmed drain-hold isn't tripped.
            $w->forceFill(['created_at' => Carbon::createFromTimestamp($end, $tz)])->saveQuietly();
        }
    }
}
