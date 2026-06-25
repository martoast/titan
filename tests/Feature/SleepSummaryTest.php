<?php

namespace Tests\Feature;

use App\Jobs\ReactToSleepConfirmed;
use App\Models\SleepLog;
use App\Models\User;
use App\Support\SleepCoach;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
