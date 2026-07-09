<?php

namespace Tests\Feature;

use App\Jobs\SealNightJob;
use App\Models\DeviceIngestion;
use App\Models\SleepLog;
use App\Models\User;
use App\Services\Wearables\BiosignalClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The forward fix for the "17-minute night". The band duty-cycles overnight (~30 s burst every few
 * minutes), so a confirmed night arrives as dozens of SHORT windows. The seal must send them to the
 * stager as SPARSE samples at their real epoch — never concatenate them into a contiguous block — so the
 * night stages across its true bed→wake span. It must also refuse a mostly-hole coverage, and clamp an
 * absurd (forgot-to-mark-awake) span rather than write a 17-hour "sleep".
 */
class SleepDutyCycleSealTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Prod runs UTC (see CLAUDE.md); the dev app tz (America/Mexico_City) shifts the window_start
        // round-trip by 6h, which is a local-only artifact. Pin UTC so the test mirrors prod's scoping.
        config(['app.timezone' => 'UTC']);
        date_default_timezone_set('UTC');
    }

    private function fakeSleep(array $metrics): void
    {
        config(['services.biosignal.url' => 'http://biosignal:8000', 'services.biosignal.token' => 't']);
        Http::fake(['*/process/sleep' => Http::response(['algo_version' => 'test', 'metrics' => $metrics])]);
    }

    /** @return array<int,DeviceIngestion> a duty-cycle night: one 30 s burst (1 epoch) every 3 min */
    private function dutyCycleNight(int $profileId, int $bed, int $wake): array
    {
        $w = [];
        for ($t = $bed + 60; $t < $wake - 60; $t += 180) {
            $w[] = DeviceIngestion::create([
                'batch_uid' => substr(hash('sha256', $t.'-'.mt_rand()), 0, 40),
                'profile_id' => $profileId, 'source' => 'titan_band', 'kind' => 'ppg_raw',
                'status' => DeviceIngestion::STATUS_PROCESSED,
                'window_start' => Carbon::createFromTimestamp($t),
                'window_end' => Carbon::createFromTimestamp($t + 30),
                'result_refs' => ['epoch_motion' => [2.0], 'epoch_hr' => [56.0], 'epoch_rmssd' => [45.0]],
            ]);
        }

        return $w;
    }

    public function test_confirmed_duty_cycle_night_stages_across_the_full_span_via_sparse_samples(): void
    {
        // The stager (faked) reports a real full-night stage set with good coverage.
        $this->fakeSleep([
            'duration_min' => 350, 'deep_min' => 70, 'rem_min' => 90, 'light_min' => 190, 'awake_min' => 60,
            'bedtime' => '23:06:00', 'wake_time' => '04:44:00', 'quality' => 82, 'coverage' => 0.99,
            'hypnogram_30s' => array_fill(0, 820, 'light'),
        ]);

        $profile = User::factory()->create()->ensureProfile();
        $wake = time();
        $bed = $wake - 410 * 60;
        $this->dutyCycleNight($profile->id, $bed, $wake);

        Queue::fake();
        (new SealNightJob($profile->id, null, true, $bed, $wake, 0))->handle(app(BiosignalClient::class));

        // The seal sent the bursts as SPARSE samples at their real epochs — NOT a contiguous concat.
        // A concatenated block would have max(epoch) ≈ count; sparse duty-cycle bursts spread far wider.
        Http::assertSent(function ($req) {
            $b = $req->data();
            if (! str_contains($req->url(), '/process/sleep') || ! isset($b['sample_epochs'])) {
                return false;
            }
            $ep = $b['sample_epochs'];
            return count($ep) >= 10 && max($ep) >= 3 * count($ep);   // spread across the night, not concatenated
        });

        $log = SleepLog::where('profile_id', $profile->id)->first();
        $this->assertNotNull($log);
        $this->assertSame(350, (int) $log->duration_min, 'stages across the full span, not ~17 min');
        $this->assertSame(70, (int) $log->deep_min);
        $this->assertNotNull($log->hypnogram);
    }

    public function test_mostly_hole_coverage_falls_back_to_honest_duration_only(): void
    {
        // A night the band barely sampled: the stager returns low coverage → we must NOT headline stages.
        $this->fakeSleep([
            'duration_min' => 300, 'deep_min' => 60, 'rem_min' => 60, 'light_min' => 180, 'awake_min' => 30,
            'bedtime' => '23:06:00', 'wake_time' => '04:44:00', 'quality' => 80, 'coverage' => 0.12,
            'hypnogram_30s' => array_fill(0, 820, 'light'),
        ]);

        $profile = User::factory()->create()->ensureProfile();
        $wake = time();
        $bed = $wake - 410 * 60;
        $this->dutyCycleNight($profile->id, $bed, $wake);

        Queue::fake();
        (new SealNightJob($profile->id, null, true, $bed, $wake, 0))->handle(app(BiosignalClient::class));

        $log = SleepLog::where('profile_id', $profile->id)->first();
        $this->assertNotNull($log, 'a confirmed session always writes at least the honest duration-only row');
        $this->assertEqualsWithDelta(410, (int) $log->duration_min, 1, 'duration = the marker span');
        $this->assertNull($log->deep_min, 'no stages from mostly-hole coverage');
        $this->assertSame('biosignal:sealed-session-marker', $log->updated_via);
    }

    public function test_a_confirmed_nap_of_duty_cycle_bursts_keeps_its_stages(): void
    {
        // A 25-min nap is only ~8 duty-cycle bursts — the old 10-sample floor dropped it to duration-only.
        // With coverage as the gate (the stager bridges the small gaps), a real nap keeps its stages.
        $this->fakeSleep([
            'duration_min' => 18, 'deep_min' => 4, 'rem_min' => 2, 'light_min' => 12, 'awake_min' => 3,
            'bedtime' => '14:00:00', 'wake_time' => '14:25:00', 'quality' => 70, 'coverage' => 0.95,
            'hypnogram_30s' => array_fill(0, 50, 'light'),
        ]);

        $profile = User::factory()->create()->ensureProfile();
        $wake = time();
        $bed = $wake - 25 * 60;
        for ($t = $bed + 30; $t < $wake - 30; $t += 180) {   // ~8 bursts across the nap
            DeviceIngestion::create([
                'batch_uid' => substr(hash('sha256', $t.'-'.mt_rand()), 0, 40),
                'profile_id' => $profile->id, 'source' => 'titan_band', 'kind' => 'ppg_raw',
                'status' => DeviceIngestion::STATUS_PROCESSED,
                'window_start' => Carbon::createFromTimestamp($t),
                'window_end' => Carbon::createFromTimestamp($t + 30),
                'result_refs' => ['epoch_motion' => [2.0], 'epoch_hr' => [60.0]],
            ]);
        }

        Queue::fake();
        (new SealNightJob($profile->id, null, true, $bed, $wake, 0))->handle(app(BiosignalClient::class));

        $log = SleepLog::where('profile_id', $profile->id)->where('is_nap', true)->first();
        $this->assertNotNull($log, 'a confirmed nap seals its own row');
        $this->assertSame(4, (int) $log->deep_min, 'a short nap keeps its stages, not duration-only');
        $this->assertNotNull($log->hypnogram);
    }

    public function test_partial_coverage_counts_holes_as_sleep_so_debt_math_is_not_starved(): void
    {
        // A confirmed 6h50m night the band only half-sampled: staged asleep is small, but the user
        // DECLARED bed→wake, so the unsampled holes are presumed sleep. Duration must read ≈ the span
        // (span − detected wake), never the understated ~2.5h that would accrue phantom debt.
        $this->fakeSleep([
            'duration_min' => 150, 'deep_min' => 40, 'rem_min' => 30, 'light_min' => 80, 'awake_min' => 20,
            'bedtime' => '23:06:00', 'wake_time' => '04:44:00', 'quality' => 78, 'coverage' => 0.45,
            'hypnogram_30s' => array_fill(0, 820, 'light'),
        ]);

        $profile = User::factory()->create()->ensureProfile();
        $wake = time();
        $bed = $wake - 410 * 60;
        $this->dutyCycleNight($profile->id, $bed, $wake);

        Queue::fake();
        (new SealNightJob($profile->id, null, true, $bed, $wake, 0))->handle(app(BiosignalClient::class));

        $log = SleepLog::where('profile_id', $profile->id)->first();
        $this->assertNotNull($log);
        // ≈ the observed span (last sample ~3 min before the marker wake) − awake 20 ≈ 387, NOT the
        // understated staged 150. Holes within the session fold into light sleep.
        $this->assertGreaterThan(380, (int) $log->duration_min, 'holes within a declared session count as sleep');
        $this->assertLessThanOrEqual(391, (int) $log->duration_min);
        $this->assertSame(40, (int) $log->deep_min, 'real staged deep is preserved');
        $this->assertGreaterThan(80, (int) $log->light_min, 'the unsampled stretch folds into light sleep');
    }

    public function test_a_transient_staging_failure_retries_instead_of_sealing_a_lossy_row(): void
    {
        // biosignal restarts mid-deploy → 500. The confirmed seal must NOT swallow it and write a
        // duration-only row while marking the windows sealed forever — it must THROW so the queue retries.
        config(['services.biosignal.url' => 'http://biosignal:8000', 'services.biosignal.token' => 't']);
        Http::fake(['*/process/sleep' => Http::response('service restarting', 500)]);

        $profile = User::factory()->create()->ensureProfile();
        $wake = time();
        $bed = $wake - 410 * 60;
        $this->dutyCycleNight($profile->id, $bed, $wake);

        $threw = false;
        try {
            (new SealNightJob($profile->id, null, true, $bed, $wake, 0))->handle(app(BiosignalClient::class));
        } catch (\Throwable $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'a transient staging error must propagate so the job retries');
        $this->assertSame(0, SleepLog::where('profile_id', $profile->id)->count(), 'no lossy duration-only row on a transient');
        $this->assertSame(0, DeviceIngestion::where('profile_id', $profile->id)
            ->where('status', DeviceIngestion::STATUS_SEALED)->count(), 'windows stay unsealed for the retry');
    }

    public function test_gap_clustering_keeps_a_charge_split_night_as_one_session_but_separates_a_nap(): void
    {
        $profile = User::factory()->create()->ensureProfile();
        $mk = function (int $start, int $end) use ($profile) {
            return DeviceIngestion::create([
                'batch_uid' => substr(hash('sha256', $start.'-'.mt_rand()), 0, 40),
                'profile_id' => $profile->id, 'source' => 'titan_band', 'kind' => 'ppg_raw',
                'status' => DeviceIngestion::STATUS_PROCESSED,
                'window_start' => Carbon::createFromTimestamp($start),
                'window_end' => Carbon::createFromTimestamp($end),
            ]);
        };
        // A 23:00→07:00 night with a 90-min charge gap in the middle, then a 14:00 nap next day.
        $base = Carbon::parse('2026-07-08 23:00:00', 'UTC')->timestamp;
        $windows = collect();
        for ($t = $base; $t < $base + 2 * 3600; $t += 180) { $windows->push($mk($t, $t + 30)); }        // 23:00–01:00
        $charge = $base + 2 * 3600 + 90 * 60;                                                            // 90-min charge gap
        for ($t = $charge; $t < $charge + 6 * 3600; $t += 180) { $windows->push($mk($t, $t + 30)); }     // 02:30–08:30
        $napAt = $base + 15 * 3600;                                                                      // ~14:00 the next day
        for ($t = $napAt; $t < $napAt + 25 * 60; $t += 180) { $windows->push($mk($t, $t + 30)); }        // a 25-min nap

        $job = new SealNightJob($profile->id, null, false);
        $m = new \ReflectionMethod(SealNightJob::class, 'clusterSessions');
        $m->setAccessible(true);
        $sessions = $m->invoke($job, $windows);

        $this->assertCount(2, $sessions, 'the charge gap stays inside the night; the nap is its own session');
        $this->assertGreaterThan(100, $sessions[0]->count(), 'the night is one cluster across the charge gap');
        $this->assertLessThan(15, $sessions[1]->count(), 'the afternoon nap is a separate, small cluster');
    }

    public function test_an_evening_cluster_writes_no_recovery_row(): void
    {
        // An evening post-workout ppg_raw cluster (elevated HR, not resting recovery) must NOT write a
        // readiness row — the bug where an evening workout poisoned the dashboard. It's short and daytime,
        // so it isn't a night: no recovery, and (staging all-awake) no sleep row either.
        config(['services.biosignal.url' => 'http://biosignal:8000', 'services.biosignal.token' => 't']);
        Http::fake([
            '*/process/hrv' => Http::response(['metrics' => ['valid' => true, 'hrv_ms' => 40, 'resting_hr' => 72]]),
            '*/process/sleep' => Http::response(['metrics' => ['awake_min' => 60, 'deep_min' => 0, 'rem_min' => 0, 'light_min' => 0, 'coverage' => 0.9, 'hypnogram_30s' => array_fill(0, 120, 'wake')]]),
            '*' => Http::response([]),
        ]);

        $profile = User::factory()->create()->ensureProfile();
        $evening = Carbon::parse('today 19:00', 'UTC')->timestamp;
        for ($t = $evening; $t < $evening + 3600; $t += 180) {   // a 1-hour evening cluster
            DeviceIngestion::create([
                'batch_uid' => substr(hash('sha256', $t.'-'.mt_rand()), 0, 40),
                'profile_id' => $profile->id, 'source' => 'titan_band', 'kind' => 'ppg_raw',
                'status' => DeviceIngestion::STATUS_PROCESSED,
                'window_start' => Carbon::createFromTimestamp($t),
                'window_end' => Carbon::createFromTimestamp($t + 30),
                'result_refs' => ['epoch_motion' => [50.0], 'epoch_hr' => [130.0], 'ibi_ms' => [460, 470, 465, 455, 460, 470, 465, 455, 460, 470, 465, 455], 'rmssd' => 20],
            ]);
        }

        dispatch_sync(new SealNightJob($profile->id, Carbon::parse('today', 'UTC')->toDateString()));

        $this->assertSame(0, \App\Models\RecoveryLog::where('profile_id', $profile->id)->count(),
            'an evening cluster is not a night — no recovery/readiness row');
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/process/hrv'));   // recovery aggregate never runs
    }

    public function test_forgot_to_mark_awake_caps_at_the_last_sample_not_a_15_hour_night(): void
    {
        // Marker says a 15-hour session (forgot to mark awake), but the band only recorded the first ~7h.
        // The hole-fold must stop at the last sample — never paint 8h of band-off daytime as light sleep.
        $this->fakeSleep([
            'duration_min' => 300, 'deep_min' => 60, 'rem_min' => 60, 'light_min' => 180, 'awake_min' => 20,
            'bedtime' => '23:00:00', 'wake_time' => '06:00:00', 'quality' => 80, 'coverage' => 0.9,
            'hypnogram_30s' => array_fill(0, 840, 'light'),
        ]);

        $profile = User::factory()->create()->ensureProfile();
        $bed = time() - 15 * 3600;               // marker: a 15-hour "sleep"
        $wake = time();
        $lastData = $bed + 7 * 3600;             // but the band stopped 7h in
        $this->dutyCycleNight($profile->id, $bed, $lastData);

        Queue::fake();
        (new SealNightJob($profile->id, null, true, $bed, $wake, 0))->handle(app(BiosignalClient::class));

        $log = SleepLog::where('profile_id', $profile->id)->first();
        $this->assertNotNull($log);
        $this->assertLessThanOrEqual(7 * 60 + 10, (int) $log->duration_min, 'capped at the last sample (~7h), not the 15h marker');
    }

    public function test_absurd_span_is_clamped_not_written_as_a_17_hour_night(): void
    {
        $this->fakeSleep(['coverage' => 0.0, 'hypnogram_30s' => []]);   // no real staging

        $profile = User::factory()->create()->ensureProfile();
        $wake = time();
        $bed = $wake - 20 * 3600;   // 20 h — forgot to mark awake

        Queue::fake();
        (new SealNightJob($profile->id, null, true, $bed, $wake, 0))->handle(app(BiosignalClient::class));

        $log = SleepLog::where('profile_id', $profile->id)->first();
        $this->assertNotNull($log);
        $this->assertLessThanOrEqual(16 * 60, (int) $log->duration_min, 'a >16h session is clamped, never a 17h night');
    }
}
