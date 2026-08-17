<?php

namespace Tests\Feature;

use App\Jobs\FlagTruncatedNightJob;
use App\Models\SleepLog;
use App\Models\User;
use App\Support\NightTruncation;
use App\Support\SleepDebt;
use App\Support\SleepStory;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A dead band battery must not be reported as a short night.
 *
 * The seal cannot tell "the user woke" from "the band stopped" — both are just "samples stop arriving" —
 * so Tester B's 2026-08-16 sealed as a believed 4.8h night. Duration was trusted (only the stages were
 * caveated), which made that single artifact her ENTIRE sleep debt (0.2h → 3.3h "moderate") and earned
 * her a coaching line telling her to go to bed earlier. She had slept normally.
 *
 * The evidence is a REBOOT: a band only reboots on power loss or a reflash, and the ingest's clock guard
 * makes one visible. Note what is deliberately NOT used — "the band never contacted us again after the
 * night" — because measured against real nights on this box those gaps ran from +1 minute to +6.5 days on
 * ordinary complete nights, and would have condemned most of the history.
 *
 * These pin both orderings (reboot before the seal, reboot long after it), every condition that keeps a
 * good night out of it, and that the number never reaches the user.
 */
class TruncatedNightTest extends TestCase
{
    use RefreshDatabase;

    private function profile(): \App\Models\Profile
    {
        $user = User::factory()->create();
        $user->ensureProfile();
        $p = $user->profile;
        $p->update(['settings' => array_merge($p->settings ?? [], ['timezone' => 'America/Tijuana'])]);

        return $p->fresh();
    }

    /**
     * Dates are RELATIVE (last night, rebooting this morning) rather than the literal 2026-08-16.
     *
     * Not cosmetic: SQLite has no real DATE type, so the `slept_at` date cast round-trips as
     * "2026-08-16 00:00:00" and a row dated exactly the ledger's upper bound fails `slept_at <= '2026-08-16'`
     * — today's night is invisible to SleepDebt under SQLite. MySQL coerces the column and production is
     * unaffected (this is the date-cast trap in docs/SEAL_ARCHITECTURE). Keeping the night in the past
     * tests the real behaviour instead of that artifact.
     */
    private function nightDate(): \Illuminate\Support\Carbon
    {
        return \Illuminate\Support\Carbon::today('America/Tijuana')->subDay();
    }

    /** A night that stops dead at 05:53 after 4h45m — the exact shape of the one that started this. */
    private function night(\App\Models\Profile $p, array $overrides = []): SleepLog
    {
        return SleepLog::create(array_merge([
            'profile_id' => $p->id,
            'slept_at' => $this->nightDate()->toDateString(),
            'is_nap' => false,
            'duration_min' => 285,
            'deep_min' => 145, 'rem_min' => 0, 'light_min' => 141, 'awake_min' => 15,
            'bedtime' => '01:08:53', 'wake_time' => '05:53:53',
            'quality' => 77, 'coverage' => 1.0,
            'stage_status' => 'final',
            'updated_via' => 'biosignal:sealed-ppg',
            'hypnogram' => array_merge(array_fill(0, 300, 'deep'), array_fill(0, 270, 'light')),
        ], $overrides));
    }

    /** @param string $clock local wall-clock; $dayOffset is days from the night's date (0 = same day) */
    private function reboot(\App\Models\Profile $p, string $clock, int $dayOffset = 1): CarbonImmutable
    {
        $at = CarbonImmutable::parse(
            $this->nightDate()->copy()->addDays($dayOffset)->toDateString().' '.$clock, 'America/Tijuana');
        $p->wearableConnections()->create([
            'provider' => 'device', 'source' => 'titan_band', 'status' => 'connected',
            'device_id' => 'band-1', 'timezone' => 'America/Tijuana',
            'last_sync_at' => $at, 'last_reboot_at' => $at,
        ]);

        return $at;
    }

    // ---- the core case -------------------------------------------------------------------------

    public function test_a_reboot_after_the_night_marks_it_truncated_and_unmeasured(): void
    {
        $p = $this->profile();
        $night = $this->night($p);
        // The band reconnected the NEXT morning — fourteen hours after the row was written, which is the
        // ordering that actually happened and the one a seal-time-only check would miss entirely.
        $this->reboot($p, '00:19:00');

        (new FlagTruncatedNightJob($p->id))->handle();

        $night->refresh();
        $this->assertTrue($night->truncated, 'the dead battery was not recognised');
        $this->assertTrue($night->low_confidence, 'a truncated night must also be unmeasured, or debt still eats it');
    }

    public function test_the_false_debt_disappears(): void
    {
        $p = $this->profile();
        $night = $this->night($p);
        $this->reboot($p, '00:19:00');

        $before = SleepDebt::forProfile($p, $this->nightDate()->copy()->addDay())['balance_h'];
        $this->assertGreaterThan(1.0, $before, 'fixture should start with the artifact debt this exists to remove');

        (new FlagTruncatedNightJob($p->id))->handle();

        $after = SleepDebt::forProfile($p->fresh(), $this->nightDate()->copy()->addDay())['balance_h'];
        $this->assertSame(0.0, $after, 'a night the band did not finish measuring must not build debt');
    }

    public function test_the_user_is_never_shown_the_truncated_duration_as_her_sleep(): void
    {
        $p = $this->profile();
        $night = $this->night($p);
        $this->reboot($p, '00:19:00');
        (new FlagTruncatedNightJob($p->id))->handle();

        $story = SleepStory::forNight($night->fresh());

        $this->assertTrue($story['truncated']);
        $this->assertNull($story['short_by_h'], 'a truncated night must not claim a shortfall — that IS the fake short night');
        $this->assertStringNotContainsString('4.8h', $story['text']);
        $this->assertStringNotContainsString('band fit', $story['text'], 'do not blame her fit for a flat battery');
        $this->assertMatchesRegularExpression('/batter/i', $story['text'], 'say what actually happened');
    }

    // ---- everything that must NOT be flagged ---------------------------------------------------

    public function test_a_confirmed_wake_marker_outranks_the_inference(): void
    {
        $p = $this->profile();
        $night = $this->night($p, ['updated_via' => 'biosignal:sealed-session']);
        $this->reboot($p, '00:19:00');
        (new FlagTruncatedNightJob($p->id))->handle();

        $this->assertFalse($night->fresh()->truncated, 'she tapped "I am awake" — her word beats our guess');
    }

    public function test_a_band_that_died_after_a_normal_morning_wake_is_left_alone(): void
    {
        // Taken off at 09:30, left on a nightstand, dies, reboots when charged. The night was complete.
        $p = $this->profile();
        $night = $this->night($p, ['wake_time' => '09:30:00', 'duration_min' => 285]);
        $this->reboot($p, '00:19:00');
        (new FlagTruncatedNightJob($p->id))->handle();

        $this->assertFalse($night->fresh()->truncated, '09:30 is a wake, not a power loss mid-sleep');
    }

    public function test_a_long_night_is_believed_even_if_the_band_died_at_the_end(): void
    {
        // Battery died twenty minutes before she woke: the reading is fine and discarding it would lose
        // good data for nothing.
        $p = $this->profile();
        $night = $this->night($p, ['duration_min' => 470, 'wake_time' => '08:58:00']);
        $this->reboot($p, '00:19:00');
        (new FlagTruncatedNightJob($p->id))->handle();

        $this->assertFalse($night->fresh()->truncated, 'a full-length night loses nothing by being believed');
    }

    public function test_a_reboot_BEFORE_the_night_says_nothing_about_how_it_ended(): void
    {
        $p = $this->profile();
        $night = $this->night($p);
        // Charged and rebooted the evening before — that is why the band was running, not how it stopped.
        $this->reboot($p, '21:00:00', -1);
        (new FlagTruncatedNightJob($p->id))->handle();

        $this->assertFalse($night->fresh()->truncated);
    }

    public function test_with_no_reboot_on_record_nothing_is_flagged(): void
    {
        $p = $this->profile();
        $night = $this->night($p);
        (new FlagTruncatedNightJob($p->id))->handle();

        $this->assertFalse($night->fresh()->truncated, 'without evidence of a power loss we must not invent one');
    }

    public function test_a_later_session_means_the_night_was_not_what_the_power_loss_interrupted(): void
    {
        $p = $this->profile();
        $night = $this->night($p);
        // She napped that afternoon, so the band was still recording after the night — whatever the reboot
        // interrupted, it was not this night.
        SleepLog::create([
            'profile_id' => $p->id, 'slept_at' => $this->nightDate()->toDateString(), 'is_nap' => true,
            'session_start' => $this->nightDate()->toDateString().' 15:00:00', 'duration_min' => 40,
            'bedtime' => '15:00:00', 'wake_time' => '15:40:00',
            'stage_status' => 'final', 'updated_via' => 'biosignal:sealed-ppg',
        ]);
        $this->reboot($p, '00:19:00');
        (new FlagTruncatedNightJob($p->id))->handle();

        $this->assertFalse($night->fresh()->truncated);
    }

    public function test_a_nap_is_never_truncated(): void
    {
        $p = $this->profile();
        $nap = SleepLog::create([
            'profile_id' => $p->id, 'slept_at' => $this->nightDate()->toDateString(), 'is_nap' => true,
            'session_start' => $this->nightDate()->toDateString().' 02:00:00', 'duration_min' => 40,
            'bedtime' => '02:00:00', 'wake_time' => '02:40:00',
            'stage_status' => 'final', 'updated_via' => 'biosignal:sealed-ppg',
        ]);
        $this->reboot($p, '00:19:00');
        (new FlagTruncatedNightJob($p->id))->handle();

        $this->assertFalse($nap->fresh()->truncated, 'naps are short by definition — the rule cannot apply');
    }

    public function test_running_twice_is_idempotent(): void
    {
        $p = $this->profile();
        $night = $this->night($p);
        $this->reboot($p, '00:19:00');

        (new FlagTruncatedNightJob($p->id))->handle();
        $first = $night->fresh()->updated_at;
        (new FlagTruncatedNightJob($p->id))->handle();

        $this->assertTrue($night->fresh()->truncated);
        $this->assertEquals($first, $night->fresh()->updated_at, 'a still-un-synced band re-reports the same power loss');
    }

    // ---- the predicate's own edges -------------------------------------------------------------

    public function test_core_sleep_hours_wrap_midnight(): void
    {
        $tz = 'America/Tijuana';
        foreach (['23:30' => true, '02:00' => true, '05:53' => true, '08:59' => true,
            '09:00' => false, '13:00' => false, '21:59' => false] as $t => $expected) {
            $this->assertSame($expected,
                NightTruncation::insideCoreSleepHours(CarbonImmutable::parse($this->nightDate()->toDateString()." {$t}", $tz)),
                "{$t} classified wrong");
        }
    }
}
