<?php

namespace Tests\Feature;

use App\Jobs\ReactToDeviceSync;
use App\Models\Notification;
use App\Models\SleepLog;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Support\Readiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Progressive summary Phase 2 (docs/PROGRESSIVE_SUMMARY.md): recovery must reflect the FINAL night, and the
 * morning "your recovery is in" greeting must wait for the night to finalize rather than firing off the
 * incomplete `computing` placeholder.
 */
class RecoveryCascadeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC']);
        date_default_timezone_set('UTC');
    }

    public function test_readiness_uses_the_last_final_night_not_the_computing_placeholder(): void
    {
        $profile = User::factory()->create()->ensureProfile();
        // A complete FINAL night (8h, quality 90) → a high sleep component.
        SleepLog::create([
            'profile_id' => $profile->id, 'slept_at' => Carbon::today()->subDay()->toDateString(),
            'is_nap' => false, 'duration_min' => 480, 'quality' => 90, 'stage_status' => 'final',
        ]);
        // Tonight's COMPUTING placeholder: a short duration and NO quality yet. If readiness counted it, the
        // sleep component would crater (~4h, no quality).
        SleepLog::create([
            'profile_id' => $profile->id, 'slept_at' => Carbon::today()->toDateString(),
            'is_nap' => false, 'duration_min' => 240, 'stage_status' => 'computing',
        ]);

        $sleep = Readiness::compute($profile)['components']['sleep'] ?? null;
        $this->assertNotNull($sleep, 'the last FINAL night still contributes a sleep component');
        $this->assertGreaterThan(80, $sleep, 'readiness uses the complete 8h night, not the 4h placeholder');
    }

    public function test_readiness_ignores_a_stale_recovery_reading(): void
    {
        $profile = User::factory()->create()->ensureProfile();
        // The only recovery reading is 3 days old — no reading today or yesterday. It must NOT be scored as
        // today's recovery (that would show a "recovered" number from a days-old HRV/RHR reading).
        $profile->recoveryLogs()->create([
            'logged_at' => Carbon::today()->subDays(3)->toDateString(), 'hrv_ms' => 120, 'resting_hr' => 50,
        ]);

        $components = Readiness::compute($profile)['components'] ?? [];
        $this->assertArrayNotHasKey('hrv', $components, 'a 3-day-old reading is not today\'s recovery');
        $this->assertArrayNotHasKey('rhr', $components, 'a 3-day-old reading is not today\'s recovery');
    }

    public function test_readiness_still_uses_a_yesterday_recovery_reading(): void
    {
        $profile = User::factory()->create()->ensureProfile();
        // Baseline history + the most recent reading dated YESTERDAY — fresh enough to stand in for today
        // (grace = today or yesterday), so HRV/RHR still contribute.
        for ($d = 2; $d <= 20; $d++) {
            $profile->recoveryLogs()->create([
                'logged_at' => Carbon::today()->subDays($d)->toDateString(),
                'hrv_ms' => 120 + ($d % 5), 'resting_hr' => 50 + ($d % 3),
            ]);
        }
        $profile->recoveryLogs()->create([
            'logged_at' => Carbon::today()->subDay()->toDateString(), 'hrv_ms' => 130, 'resting_hr' => 48,
        ]);

        $components = Readiness::compute($profile)['components'] ?? [];
        $this->assertArrayHasKey('hrv', $components, "yesterday's reading still stands in for today");
        $this->assertArrayHasKey('rhr', $components, "yesterday's reading still stands in for today");
    }

    public function test_readiness_is_provisional_when_hrv_is_stale_but_sleep_is_fresh(): void
    {
        $profile = User::factory()->create()->ensureProfile();
        // Fresh sleep last night, but the most recent HRV/RHR reading is days old.
        SleepLog::create([
            'profile_id' => $profile->id, 'slept_at' => Carbon::today()->toDateString(),
            'is_nap' => false, 'duration_min' => 470, 'quality' => 88, 'stage_status' => 'final',
        ]);
        $profile->recoveryLogs()->create([
            'logged_at' => Carbon::today()->subDays(4)->toDateString(), 'hrv_ms' => 120, 'resting_hr' => 50,
        ]);

        $r = Readiness::compute($profile);
        $this->assertNotNull($r['score'], 'fresh sleep still yields a score');
        $this->assertArrayNotHasKey('hrv', $r['components'], 'the stale HRV reading is not scored');
        $this->assertTrue($r['provisional'], 'a sleep-only score is provisional');
        $this->assertStringContainsString('no recent HRV read', $r['note'], 'the note names the HRV gap');
    }

    public function test_recovery_greeting_waits_for_the_night_to_finalize(): void
    {
        $profile = User::factory()->create()->ensureProfile();
        // A prior final night makes readiness computable (non-null score).
        SleepLog::create([
            'profile_id' => $profile->id, 'slept_at' => Carbon::today()->subDay()->toDateString(),
            'is_nap' => false, 'duration_min' => 420, 'quality' => 80, 'stage_status' => 'final',
        ]);
        // Tonight's night is still being staged.
        $tonight = SleepLog::create([
            'profile_id' => $profile->id, 'slept_at' => Carbon::today()->toDateString(),
            'is_nap' => false, 'duration_min' => 300, 'stage_status' => 'computing',
        ]);

        $svc = app(NotificationService::class);

        // While computing → the greeting holds off (no push, and NOT marked greeted so it can fire later).
        (new ReactToDeviceSync($profile->id))->handle($svc);
        $this->assertSame(0, Notification::where('profile_id', $profile->id)->where('type', 'sync')->count(),
            'held while the night is still staging');
        $this->assertNull(data_get($profile->fresh()->settings, 'device_greeted'));

        // Night finalizes → the greeting fires once, now reflecting the complete night.
        $tonight->update(['stage_status' => 'final', 'quality' => 82]);
        (new ReactToDeviceSync($profile->id))->handle($svc);
        $this->assertSame(1, Notification::where('profile_id', $profile->id)->where('type', 'sync')->count(),
            'fires after finalize');

        // Idempotent: a re-dispatch (the seal also fires it) does not double-greet.
        (new ReactToDeviceSync($profile->id))->handle($svc);
        $this->assertSame(1, Notification::where('profile_id', $profile->id)->where('type', 'sync')->count(),
            'once per day');
    }

    public function test_the_greeting_hold_works_under_a_non_utc_app_timezone(): void
    {
        // Finding 1: Eloquent stores updated_at as a NAIVE app-tz string, so the still-staging hold must
        // compare with Carbon::now() (app tz), NOT now('UTC') — the latter read every fresh row as 6h stale
        // under a non-UTC app tz and silently disabled the hold in prod. CI missed it because setUp pins UTC.
        config(['app.timezone' => 'America/Mexico_City']);
        date_default_timezone_set('America/Mexico_City');

        $profile = User::factory()->create()->ensureProfile();
        SleepLog::create([
            'profile_id' => $profile->id, 'slept_at' => Carbon::today()->subDay()->toDateString(),
            'is_nap' => false, 'duration_min' => 420, 'quality' => 80, 'stage_status' => 'final',
        ]);
        SleepLog::create([   // tonight, freshly written, still staging
            'profile_id' => $profile->id, 'slept_at' => Carbon::today()->toDateString(),
            'is_nap' => false, 'duration_min' => 300, 'stage_status' => 'computing',
        ]);

        (new ReactToDeviceSync($profile->id))->handle(app(NotificationService::class));

        $this->assertSame(0, Notification::where('profile_id', $profile->id)->where('type', 'sync')->count(),
            'the greeting holds while computing even under a non-UTC app timezone');
    }

    public function test_a_computing_nap_does_not_suppress_the_morning_greeting(): void
    {
        // Audit F3: the still-staging hold is NIGHTS only. A mid-staging afternoon nap must not block the
        // morning recovery greeting — the nap finalize (night-only re-dispatch) would never re-fire it.
        $profile = User::factory()->create()->ensureProfile();
        SleepLog::create([
            'profile_id' => $profile->id, 'slept_at' => Carbon::today()->subDay()->toDateString(),
            'is_nap' => false, 'duration_min' => 430, 'quality' => 78, 'stage_status' => 'final',
        ]);
        SleepLog::create([   // a nap still being staged right now
            'profile_id' => $profile->id, 'slept_at' => Carbon::today()->toDateString(),
            'session_start' => Carbon::today()->setTime(14, 0), 'is_nap' => true,
            'duration_min' => 25, 'stage_status' => 'computing',
        ]);

        (new ReactToDeviceSync($profile->id))->handle(app(NotificationService::class));

        $this->assertSame(1, Notification::where('profile_id', $profile->id)->where('type', 'sync')->count(),
            'a computing nap does not hold the morning greeting');
    }
}
