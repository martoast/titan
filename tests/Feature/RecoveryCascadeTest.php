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
}
