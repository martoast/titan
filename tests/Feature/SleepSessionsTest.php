<?php

namespace Tests\Feature;

use App\Models\SleepLog;
use App\Models\User;
use App\Support\SleepDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * SLEEP-AS-SESSIONS — the overnight AND each nap surface as their own revisitable session, above a daily
 * aggregate, grouped on the USER's local day. Before this, /api/me/sleep dropped naps entirely and only
 * detailed the latest overnight (Alex's 86-min nap was captured, staged, then hidden).
 */
class SleepSessionsTest extends TestCase
{
    use RefreshDatabase;

    private function profileInTijuana(): \App\Models\Profile
    {
        $profile = User::factory()->create()->ensureProfile();
        // Deliberately NOT the app tz (Mexico_City) — a nap taken "today" in Tijuana must still land on
        // today's list (the recurring tz trap the spec calls out).
        $profile->update(['settings' => array_merge((array) $profile->settings, ['timezone' => 'America/Tijuana'])]);

        return $profile->fresh();
    }

    public function test_a_day_lists_the_overnight_and_each_nap_with_a_summed_aggregate(): void
    {
        $profile = $this->profileInTijuana();
        $day = Carbon::parse('2026-07-11', 'America/Tijuana');

        // Overnight #54: 269 min, staged.
        SleepLog::create([
            'profile_id' => $profile->id, 'slept_at' => '2026-07-11', 'is_nap' => false,
            'stage_status' => SleepLog::STATUS_FINAL, 'duration_min' => 269, 'quality' => 80,
            'deep_min' => 50, 'rem_min' => 60, 'light_min' => 159, 'awake_min' => 10,
            'bedtime' => '23:30:00', 'wake_time' => '06:59:00',
            'hypnogram' => array_fill(0, 552, 'light'),
        ]);
        // Nap #55: 86 min in the afternoon, staged. session_start is a Tijuana wall-clock string.
        SleepLog::create([
            'profile_id' => $profile->id, 'slept_at' => '2026-07-11', 'is_nap' => true,
            'session_start' => '2026-07-11 14:30:00', 'stage_status' => SleepLog::STATUS_FINAL,
            'duration_min' => 86, 'quality' => 70,
            'deep_min' => 28, 'rem_min' => 10, 'light_min' => 43, 'awake_min' => 5,
            'hypnogram' => array_fill(0, 172, 'light'),
        ]);

        $out = SleepDetail::sessionsForDay($profile, $day);

        // Both sessions present, newest first (the afternoon nap is later than last night's overnight).
        $this->assertCount(2, $out['sessions']);
        $this->assertTrue($out['sessions'][0]['is_nap'], 'the afternoon nap sorts newest');
        $this->assertFalse($out['sessions'][1]['is_nap']);
        // Each carries its own timeline.
        foreach ($out['sessions'] as $s) {
            $this->assertIsArray($s['hypnogram']);
            $this->assertNotEmpty($s['hypnogram']);
        }

        // Daily aggregate = night + nap (the 355m total Alex was missing, not 269m).
        $agg = $out['aggregate'];
        // Σ(deep+rem+light): night 50+60+159=269, nap 28+10+43=81 → 350 (the total Alex was missing).
        $this->assertSame(350, $agg['total_asleep_min'], 'asleep = Σ(deep+rem+light) across sessions');
        $this->assertSame(50 + 28, $agg['deep_min']);
        $this->assertSame(2, $agg['session_count']);
        $this->assertSame(1, $agg['night_count']);
        $this->assertSame(1, $agg['nap_count']);
        $this->assertSame('1 night + 1 nap', $agg['label']);

        // A nap is NOT scored as a night: no performance/need/debt on the nap session.
        $nap = $out['sessions'][0];
        $this->assertNull($nap['performance_pct']);
        $this->assertNull($nap['need_h']);
    }

    public function test_nap_groups_on_the_user_local_day_not_the_app_tz(): void
    {
        // 2026-07-12 01:00 in Tijuana is 2026-07-12 03:00 in Mexico_City (app tz) — same local DAY here,
        // but the point is it must group by the USER's day. A late-evening Tijuana nap that crosses into
        // the next app-tz day must still land on the Tijuana day.
        $profile = $this->profileInTijuana();
        SleepLog::create([
            'profile_id' => $profile->id, 'slept_at' => '2026-07-11', 'is_nap' => true,
            'session_start' => '2026-07-11 23:30:00', 'stage_status' => SleepLog::STATUS_FINAL,
            'duration_min' => 40, 'deep_min' => 10, 'rem_min' => 5, 'light_min' => 25, 'awake_min' => 0,
            'hypnogram' => array_fill(0, 80, 'light'),
        ]);

        $out = SleepDetail::sessionsForDay($profile, Carbon::parse('2026-07-11', 'America/Tijuana'));
        $this->assertCount(1, $out['sessions'], 'the 11:30pm Tijuana nap belongs to the Tijuana day');
        $this->assertSame(40, $out['aggregate']['total_asleep_min']);
    }
}
