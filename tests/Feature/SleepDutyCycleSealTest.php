<?php

namespace Tests\Feature;

use App\Jobs\SealNightJob;
use App\Models\DeviceIngestion;
use App\Models\SleepLog;
use App\Models\User;
use App\Services\Wearables\BiosignalClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
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
        // Progressive summary: a fully-staged seal finalizes the row.
        $this->assertSame('final', $log->stage_status);
        $this->assertNotNull($log->finalized_at);
        $this->assertEqualsWithDelta(0.99, (float) $log->coverage, 0.001);
    }

    public function test_confirmed_seal_writes_a_computing_placeholder_before_staging_finishes(): void
    {
        // Phase 1: open the app right after ending on the watch, before staging finishes. A COMPUTING row
        // must already exist (duration/bed/wake real, stages null) so the app shows a loading card — and the
        // seal defers (doesn't finalize) while the raw windows are still processing.
        config(['services.biosignal.url' => 'http://biosignal:8000', 'services.biosignal.token' => 't']);
        $profile = User::factory()->create()->ensureProfile();
        $wake = time();
        $bed = $wake - 420 * 60;
        foreach ($this->dutyCycleNight($profile->id, $bed, $wake) as $w) {
            $w->update(['status' => DeviceIngestion::STATUS_PROCESSING]);   // still in flight → the seal defers
        }

        Queue::fake();   // capture the deferred re-dispatch instead of running it
        (new SealNightJob($profile->id, null, true, $bed, $wake, 0))->handle(app(BiosignalClient::class));

        $log = SleepLog::where('profile_id', $profile->id)->first();
        $this->assertNotNull($log, 'a placeholder row exists immediately for the loading card');
        $this->assertSame('computing', $log->stage_status);
        $this->assertGreaterThan(0, (int) $log->duration_min, 'duration is real, from the envelope');
        $this->assertNull($log->deep_min, 'no fabricated stages while computing');
        $this->assertNull($log->hypnogram);
        $this->assertNull($log->finalized_at);
        Queue::assertPushed(SealNightJob::class);   // it deferred to finish staging
        $this->assertSame(0, DeviceIngestion::where('profile_id', $profile->id)
            ->where('status', DeviceIngestion::STATUS_SEALED)->count(), 'nothing sealed yet');
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
        // Phase 1: a COMPUTING placeholder (envelope only, stages null) is expected — the app shows a loading
        // card during the outage and the retry finalizes it. What must NOT happen is a lossy FINAL row that
        // hides the real stages, nor sealing the windows away.
        $this->assertSame(0, SleepLog::where('profile_id', $profile->id)->where('stage_status', 'final')->count(),
            'no lossy FINAL row on a transient');
        $placeholder = SleepLog::where('profile_id', $profile->id)->first();
        $this->assertNotNull($placeholder, 'a computing placeholder exists for the loading card');
        $this->assertSame('computing', $placeholder->stage_status);
        $this->assertNull($placeholder->deep_min, 'the placeholder carries no fabricated stages');
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
        $evening = Carbon::parse('yesterday 19:00', 'UTC')->timestamp;   // past + quiescent so the cron seals it
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

        dispatch_sync(new SealNightJob($profile->id, Carbon::parse('yesterday', 'UTC')->toDateString()));

        $this->assertSame(0, \App\Models\RecoveryLog::where('profile_id', $profile->id)->count(),
            'an evening cluster is not a night — no recovery/readiness row');
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/process/hrv'));   // recovery aggregate never runs
    }

    public function test_forgot_to_mark_awake_caps_at_the_last_sample_not_a_15_hour_night(): void
    {
        // Marker says a 15-hour session (forgot to mark awake), but the band only recorded the first ~7h.
        // The hole-fold must stop a few hours past the last sample — never paint the full band-off daytime
        // as light sleep (a 900-min night). ~7h data + 3h grace ≈ 10h, NOT 15h.
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
        $this->assertLessThanOrEqual(10 * 60 + 10, (int) $log->duration_min, 'bounded to ~last sample + grace (~10h), NOT the 15h marker');
        $this->assertGreaterThan(7 * 60, (int) $log->duration_min, 'the ~7h of real data plus a little grace still counts');
    }

    public function test_a_mid_night_charge_pause_is_not_sealed_as_a_half_night(): void
    {
        // Finding 1: the first half of a night with a 90-min charge gap must NOT seal while the band is
        // paused (it would resume and rejoin the session). sessionIsComplete waits a full cluster gap.
        $profile = User::factory()->create()->ensureProfile();
        $firstHalfEnd = time() - 60 * 60;   // last window 60 min ago — inside the charge pause, not quiescent yet
        for ($t = $firstHalfEnd - 2 * 3600; $t < $firstHalfEnd; $t += 180) {
            DeviceIngestion::create([
                'batch_uid' => substr(hash('sha256', $t.'-'.mt_rand()), 0, 40),
                'profile_id' => $profile->id, 'source' => 'titan_band', 'kind' => 'ppg_raw',
                'status' => DeviceIngestion::STATUS_PROCESSED,
                'window_start' => Carbon::createFromTimestamp($t), 'window_end' => Carbon::createFromTimestamp($t + 30),
                'result_refs' => ['epoch_motion' => [2.0]],
            ]);
        }

        $job = new SealNightJob($profile->id, null, false);
        $m = new \ReflectionMethod(SealNightJob::class, 'sessionIsComplete');
        $m->setAccessible(true);
        $windows = DeviceIngestion::where('profile_id', $profile->id)->get();
        $this->assertFalse($m->invoke($job, $windows, Carbon::now('UTC')->toDateString(), 'UTC'),
            'a session quiet for only 60 min (a charge pause) is NOT complete — the band may still resume');
    }

    public function test_an_evening_doze_does_not_replace_the_mornings_real_night(): void
    {
        // Finding 2: a real staged night exists; a thinner seal on the same (profile, slept_at) key must
        // not clobber it (the richer row wins).
        $profile = User::factory()->create()->ensureProfile();
        $date = Carbon::parse('yesterday', 'UTC')->toDateString();
        $night = SleepLog::create([
            'profile_id' => $profile->id, 'slept_at' => $date, 'is_nap' => false,
            'duration_min' => 470, 'deep_min' => 90, 'rem_min' => 100, 'light_min' => 280,
            'hypnogram' => array_fill(0, 940, 'light'), 'updated_via' => 'biosignal:sealed-ppg',
        ]);

        $m = new \ReflectionMethod(SealNightJob::class, 'upsertSleep');
        $m->setAccessible(true);
        $job = new SealNightJob($profile->id, null, false);
        $result = $m->invoke($job, ['profile_id' => $profile->id, 'slept_at' => $date, 'is_nap' => false],
            ['slept_at' => $date, 'duration_min' => 240, 'light_min' => 240, 'hypnogram' => array_fill(0, 480, 'light'), 'updated_via' => 'biosignal:sealed-ppg']);

        $this->assertSame($night->id, $result->id, 'the richer 470-min night is kept, not replaced');
        $this->assertSame(470, (int) $result->fresh()->duration_min, 'the thinner evening seal did not overwrite it');
    }

    public function test_a_poison_session_is_released_after_the_attempt_cap(): void
    {
        // Finding 3: a deterministic staging failure must not re-aggregate forever. After MAX_SEAL_ATTEMPTS
        // the windows are released (with an error marker), unblocking the profile's later sessions.
        $profile = User::factory()->create()->ensureProfile();
        $win = DeviceIngestion::create([
            'batch_uid' => substr(hash('sha256', (string) mt_rand()), 0, 40),
            'profile_id' => $profile->id, 'source' => 'titan_band', 'kind' => 'ppg_raw',
            'status' => DeviceIngestion::STATUS_PROCESSED,
            'window_start' => Carbon::createFromTimestamp(time() - 3600),
            'window_end' => Carbon::createFromTimestamp(time() - 3570),
            'result_refs' => ['epoch_motion' => [2.0], 'seal_attempts' => SealNightJob::MAX_SEAL_ATTEMPTS - 1],
        ]);

        $m = new \ReflectionMethod(SealNightJob::class, 'handleSessionFailure');
        $m->setAccessible(true);
        $job = new SealNightJob($profile->id, null, false);
        $m->invoke($job, $profile, collect([$win]), new \RuntimeException('poison payload'));

        $win->refresh();
        $this->assertSame(DeviceIngestion::STATUS_SEALED, $win->status, 'released at the attempt cap so it stops looping');
        $this->assertArrayHasKey('seal_error', (array) $win->result_refs);
    }

    public function test_an_authoritative_reseal_overrides_a_stale_richer_row(): void
    {
        // S-1: the richer-row guard protects against a thinner AUTO seal — but a user-CONFIRMED seal or an
        // operator --night reseal is a correction and must ALWAYS write, or an inflated stale row (a
        // 900-min-bug-era or grace-inflated night) is permanently unrepairable and the coach narrates it.
        $profile = User::factory()->create()->ensureProfile();
        $date = Carbon::parse('yesterday', 'UTC')->toDateString();
        $stale = SleepLog::create([
            'profile_id' => $profile->id, 'slept_at' => $date, 'is_nap' => false,
            'duration_min' => 900, 'light_min' => 900,
            'hypnogram' => array_fill(0, 1800, 'light'), 'updated_via' => 'biosignal:sealed-ppg',
        ]);

        $m = new \ReflectionMethod(SealNightJob::class, 'upsertSleep');
        $m->setAccessible(true);
        $job = new SealNightJob($profile->id, null, true);   // confirmed = authoritative
        $honest = ['slept_at' => $date, 'duration_min' => 430, 'light_min' => 430,
            'hypnogram' => array_fill(0, 860, 'light'), 'updated_via' => 'biosignal:sealed-session-marker'];
        $result = $m->invoke($job, ['profile_id' => $profile->id, 'slept_at' => $date, 'is_nap' => false], $honest, true);

        $this->assertSame($stale->id, $result->id, 'same night row, corrected in place');
        $this->assertSame(430, (int) $result->fresh()->duration_min, 'the authoritative reseal overwrote the inflated 900-min night');

        $written = new \ReflectionMethod(SealNightJob::class, 'sleepRowWritten');
        $written->setAccessible(true);
        $this->assertTrue($written->invoke($job, $result), 'a real correction signals a write, so the coach summary fires');
    }

    public function test_confirmed_force_is_scoped_to_the_same_night_not_an_evening_doze(): void
    {
        // A-2: a confirmed marker forces the write only when it OVERLAPS the existing night's span (a
        // correction). A distinct >=4h evening doze sharing the wake-date key must NOT clobber the real night.
        $profile = User::factory()->create()->ensureProfile();
        $date = Carbon::parse('2026-07-08', 'UTC');
        SleepLog::create([
            'profile_id' => $profile->id, 'slept_at' => $date->toDateString(), 'is_nap' => false,
            'duration_min' => 470, 'bedtime' => '23:00:00', 'wake_time' => '07:00:00',
            'hypnogram' => array_fill(0, 940, 'light'), 'updated_via' => 'biosignal:sealed-ppg',
        ]);
        $key = ['profile_id' => $profile->id, 'slept_at' => $date->toDateString(), 'is_nap' => false];

        $m = new \ReflectionMethod(SealNightJob::class, 'confirmedOverwriteAllowed');
        $m->setAccessible(true);
        $job = new SealNightJob($profile->id, null, true);

        // Re-confirming the SAME night (prev-evening 23:00 -> 07:00) overlaps → allowed to correct it.
        $this->assertTrue($m->invoke($job, $key,
            Carbon::parse('2026-07-07 23:00', 'UTC')->timestamp,
            Carbon::parse('2026-07-08 07:00', 'UTC')->timestamp, 'UTC'), 'same-night correction is allowed');

        // A distinct evening doze (20:00 -> 23:00 same day) does NOT overlap → must not clobber the night.
        $this->assertFalse($m->invoke($job, $key,
            Carbon::parse('2026-07-08 20:00', 'UTC')->timestamp,
            Carbon::parse('2026-07-08 23:00', 'UTC')->timestamp, 'UTC'), 'a distinct evening doze is refused');
    }

    public function test_a_force_duration_only_reseal_clears_stale_stages(): void
    {
        // A-3: a forced duration-only correction must not leave the old row's stages under the new duration
        // (a 430-min duration over a 15h hypnogram is a chimera). upsertSleep nulls the omitted stage columns.
        $profile = User::factory()->create()->ensureProfile();
        $date = Carbon::parse('yesterday', 'UTC')->toDateString();
        SleepLog::create([
            'profile_id' => $profile->id, 'slept_at' => $date, 'is_nap' => false,
            'duration_min' => 900, 'deep_min' => 120, 'rem_min' => 150, 'light_min' => 630, 'awake_min' => 0,
            'quality' => 80, 'hypnogram' => array_fill(0, 1800, 'light'), 'updated_via' => 'biosignal:sealed-ppg',
        ]);

        $m = new \ReflectionMethod(SealNightJob::class, 'upsertSleep');
        $m->setAccessible(true);
        $job = new SealNightJob($profile->id, null, true);
        $result = $m->invoke($job, ['profile_id' => $profile->id, 'slept_at' => $date, 'is_nap' => false],
            ['slept_at' => $date, 'duration_min' => 430, 'updated_via' => 'biosignal:sealed-session-marker'], true);

        $fresh = $result->fresh();
        $this->assertSame(430, (int) $fresh->duration_min, 'the honest duration is written');
        $this->assertNull($fresh->deep_min, 'stale deep stage cleared');
        $this->assertNull($fresh->rem_min, 'stale rem stage cleared');
        $this->assertNull($fresh->quality, 'stale quality cleared');
        $this->assertNull($fresh->hypnogram, 'stale 15h hypnogram cleared — no chimera');
    }

    public function test_a_transient_outage_does_not_burn_an_attempt_or_seal_the_night(): void
    {
        // S-2: a biosignal ops failure (service unreachable / 5xx) is transient, not a data verdict. It must
        // NOT count toward the attempt cap nor terminal-seal the night — a multi-hour outage would otherwise
        // destroy it. The window stays OPEN (unsealed, attempt count untouched) for the next cron.
        $profile = User::factory()->create()->ensureProfile();
        $win = DeviceIngestion::create([
            'batch_uid' => substr(hash('sha256', (string) mt_rand()), 0, 40),
            'profile_id' => $profile->id, 'source' => 'titan_band', 'kind' => 'ppg_raw',
            'status' => DeviceIngestion::STATUS_PROCESSED,
            'window_start' => Carbon::createFromTimestamp(time() - 3600),
            'window_end' => Carbon::createFromTimestamp(time() - 3570),
            'result_refs' => ['epoch_motion' => [2.0], 'seal_attempts' => SealNightJob::MAX_SEAL_ATTEMPTS - 1],
        ]);

        $m = new \ReflectionMethod(SealNightJob::class, 'handleSessionFailure');
        $m->setAccessible(true);
        $job = new SealNightJob($profile->id, null, false);
        // At the cap-minus-one: a DETERMINISTIC error would seal here. A transient one must not.
        $m->invoke($job, $profile, collect([$win]), new ConnectionException('biosignal unreachable'));

        $win->refresh();
        $this->assertNotSame(DeviceIngestion::STATUS_SEALED, $win->status, 'a transient outage never seals the night away');
        $this->assertArrayNotHasKey('seal_error', (array) $win->result_refs);
        $this->assertSame(SealNightJob::MAX_SEAL_ATTEMPTS - 1, (int) ($win->result_refs['seal_attempts'] ?? 0), 'no attempt was burned');
    }

    public function test_a_nap_never_surfaces_as_last_night(): void
    {
        // Finding 4: with a nap and a night sharing a date, the PRODUCTION "last night" reader
        // (SleepDetail::forProfile — what the app renders) must return the NIGHT, not the later-id nap.
        $profile = User::factory()->create()->ensureProfile();
        $date = Carbon::parse('today', 'UTC')->toDateString();
        SleepLog::create(['profile_id' => $profile->id, 'slept_at' => $date, 'is_nap' => false, 'duration_min' => 460, 'quality' => 88]);
        SleepLog::create(['profile_id' => $profile->id, 'slept_at' => $date, 'is_nap' => true, 'session_start' => Carbon::parse('today 14:00', 'UTC'), 'duration_min' => 25, 'quality' => 30]);   // later id

        $detail = \App\Support\SleepDetail::forProfile($profile->fresh());
        $this->assertNotNull($detail);
        $this->assertSame(460, (int) $detail['duration_min'], 'the reader surfaces the night, not the 25-min nap');
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
