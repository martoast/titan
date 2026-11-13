<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\DailyFocus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Daily Focus answers "what should I do today?" and is read in four places: the web hero, the
 * coach's daily check-in card, the assistant's context and the post-sync nudge. Its `default` arm
 * used to tell a brand-new App Store user that "recovery, strain and sleep are in balance" with
 * nothing measured at all -- an empty state dressed as a healthy one, and the coach repeats
 * whatever this hands it.
 */
class DailyFocusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Prod runs UTC; pin it so the strain day window and `now()` agree near a day boundary.
        config(['app.timezone' => 'UTC']);
        date_default_timezone_set('UTC');
    }

    public function test_a_brand_new_account_is_never_told_things_are_in_balance(): void
    {
        $focus = DailyFocus::compute(User::factory()->create()->ensureProfile());

        $this->assertSame('measure', $focus['focus']);
        $this->assertStringNotContainsStringIgnoringCase('in balance', $focus['detail']);
        $this->assertStringNotContainsStringIgnoringCase('stay the course', $focus['headline']);
        $this->assertNull($focus['readiness']);
        // It must say what actually starts the loop, or it is just a different empty state.
        $this->assertStringContainsStringIgnoringCase('band', $focus['detail']);
    }

    public function test_a_new_account_is_not_told_to_pick_the_thread_back_up(): void
    {
        // "Pick the thread back up" is nonsense on day one; the two states are told apart by
        // whether any reading has ever landed.
        $focus = DailyFocus::compute(User::factory()->create()->ensureProfile());

        $this->assertSame('Start with one night', $focus['headline']);
    }

    public function test_a_lapsed_account_is_not_greeted_as_a_beginner(): void
    {
        $p = User::factory()->create()->ensureProfile();
        // A night well outside SleepCoach's window: history exists, nothing recent does.
        $p->sleepLogs()->create([
            'slept_at' => now()->subDays(90)->toDateString(),
            'duration_min' => 430,
            'status' => 'final',
        ]);

        $focus = DailyFocus::compute($p);

        $this->assertSame('measure', $focus['focus']);
        $this->assertSame('Pick the thread back up', $focus['headline']);
        $this->assertStringNotContainsStringIgnoringCase('first recovery score', $focus['detail']);
    }

    public function test_a_recovery_reading_with_no_night_asks_for_a_night(): void
    {
        // The reachable half-measured state. (The mirror -- a night but no reading -- is almost
        // always caught by the sleep-debt arm first, because a missing last night IS debt, so it
        // is a defensive fallback rather than something this can honestly construct.)
        $p = User::factory()->create()->ensureProfile();
        for ($d = 0; $d <= 3; $d++) {
            $p->recoveryLogs()->create([
                'logged_at' => now()->subDays($d)->toDateString(),
                'hrv_ms' => 62 + $d,
                'resting_hr' => 54,
            ]);
        }

        $focus = DailyFocus::compute($p);

        $this->assertNotNull($focus['readiness'], 'fixture is wrong: readiness should be scoring here');
        $this->assertSame('measure', $focus['focus']);
        $this->assertStringNotContainsStringIgnoringCase('in balance', $focus['detail']);
        $this->assertStringContainsStringIgnoringCase('night', $focus['detail']);
    }

    public function test_a_real_sleep_debt_still_beats_the_missing_reading_message(): void
    {
        // Ordering guard: a measured problem is a better answer to "what should I do today?" than
        // "something is still missing". This is what keeps the new arm last before the default.
        $p = User::factory()->create()->ensureProfile();
        for ($d = 1; $d <= 4; $d++) {
            $p->sleepLogs()->create([
                'slept_at' => now()->subDays($d)->toDateString(),
                'duration_min' => 290,
                'status' => 'final',
            ]);
        }

        $this->assertSame('sleep', DailyFocus::compute($p)['focus']);
    }
}
