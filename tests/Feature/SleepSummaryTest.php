<?php

namespace Tests\Feature;

use App\Jobs\ReactToSleepConfirmed;
use App\Jobs\SealNightJob;
use App\Models\DeviceIngestion;
use App\Models\SleepLog;
use App\Models\User;
use App\Services\Wearables\DeviceIngestionService;
use App\Support\SleepCoach;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * The morning sleep summary — fired only when the user marked awake on the band (confirmed). A full
 * breakdown (duration, deep, REM) in the chat + a push, mirroring the workout. See ReactToSleepConfirmed.
 */
class SleepSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmed_wake_posts_a_summary_and_is_idempotent(): void
    {
        $this->travelTo(Carbon::parse('2026-06-24 07:30:00', 'UTC'));
        $user = User::factory()->create();
        $profile = $user->ensureProfile();
        $profile->update(['settings' => ['timezone' => 'UTC']]);
        $log = $profile->sleepLogs()->create([
            'slept_at' => '2026-06-24', 'duration_min' => 462, 'quality' => 88,
            'deep_min' => 95, 'rem_min' => 110, 'light_min' => 240, 'awake_min' => 17, 'updated_via' => 'test',
        ]);

        ReactToSleepConfirmed::dispatchSync($log->id);

        $convo = $profile->conversations()->where('title', 'Daily Briefings')->first();
        $this->assertNotNull($convo);
        $msg = $convo->messages()->where('role', 'assistant')->get();
        $this->assertCount(1, $msg);
        $body = $msg->first()->content;
        $this->assertStringContainsString('Good morning', $body);
        $this->assertStringContainsString('95 min deep', $body);   // the breakdown, not just duration
        $this->assertStringContainsString('110 min REM', $body);

        // Re-running must not post a second summary.
        ReactToSleepConfirmed::dispatchSync($log->id);
        $this->assertSame(1, $convo->messages()->where('role', 'assistant')->count());
    }

    public function test_confirmed_summary_keys_the_night_by_wake_date_not_bedtime(): void
    {
        // Regression: a sleep crossing midnight (bed 23:00 Jun24 → wake 07:00 Jun25) was keyed by the
        // BEDTIME date (Jun24), which never matched SealNightJob's window_end grouping (Jun25), so the
        // user's confirmed morning summary never fired. The night must be keyed by the wake date.
        Bus::fake();
        $user = User::factory()->create();
        $profile = $user->ensureProfile();
        $profile->update(['settings' => ['timezone' => 'UTC']]);
        $conn = $profile->wearableConnections()->create([
            'provider' => 'TITAN_BAND', 'source' => 'titan_band', 'status' => 'connected', 'timezone' => 'UTC',
        ]);

        DeviceIngestion::create([
            'batch_uid' => 'night-1', 'profile_id' => $profile->id, 'source' => 'titan_band', 'kind' => 'ppg_raw',
            'window_start' => '2026-06-24 23:00:00', 'window_end' => '2026-06-25 06:55:00',
            'status' => DeviceIngestion::STATUS_QUEUED,
        ]);

        $svc = app(DeviceIngestionService::class);
        $trigger = new \ReflectionMethod($svc, 'triggerSleepSummary');
        $trigger->setAccessible(true);
        $ok = $trigger->invoke($svc, $conn, ['confirmed' => true, 'bedtime' => strtotime('2026-06-24 23:00:00 UTC')], 'UTC');

        $this->assertTrue($ok);
        Bus::assertDispatched(SealNightJob::class, fn (SealNightJob $j) => $j->night === '2026-06-25' && $j->confirmed === true);
    }

    public function test_confirmed_seal_bypasses_the_quiescence_gate(): void
    {
        // A confirmed seal fires the instant the user wakes, so the last window ended seconds ago.
        // nightIsComplete must treat the user's explicit "awake" as complete instead of "still streaming".
        $job = new SealNightJob(1, '2026-06-25', confirmed: true);
        $complete = new \ReflectionMethod($job, 'sessionIsComplete');
        $complete->setAccessible(true);

        // Use the date IN THE SAME TZ we pass ('UTC') — mixing now()->toDateString() (the app tz) with a
        // 'UTC' tz arg made "today" read as a past night whenever the two calendars differ (near the
        // UTC/local midnight boundary), spuriously failing the fresh-night assertion.
        $fresh = collect([new DeviceIngestion(['window_end' => now()])]); // ended just now
        $this->assertTrue($complete->invoke($job, $fresh, now('UTC')->toDateString(), 'UTC'));

        // The unconfirmed (cron) path still waits for quiescence on a fresh same-day night.
        $cron = new SealNightJob(1, null, confirmed: false);
        $this->assertFalse($complete->invoke($cron, $fresh, now('UTC')->toDateString(), 'UTC'));
    }

    public function test_summary_breakdown_includes_stages(): void
    {
        $user = User::factory()->create();
        $profile = $user->ensureProfile();
        $log = new SleepLog(['duration_min' => 300, 'deep_min' => 40, 'rem_min' => 55, 'quality' => 70]);
        $log->setRelation('profile', $profile);

        $out = SleepCoach::summary($profile, $log);
        $this->assertStringContainsString('5h asleep', $out['body']);
        $this->assertStringContainsString('40 min deep', $out['body']);
        $this->assertStringContainsString('Good morning', $out['title']);
    }
}
