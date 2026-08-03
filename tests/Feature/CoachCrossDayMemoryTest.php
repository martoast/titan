<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\Profile;
use App\Models\User;
use App\Services\Ai\AiService;
use App\Services\Coach\CoachService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Splitting the chat by day must NOT reset the coach's memory at midnight.
 *
 * `CoachService::history()` is private, so these drive it the way the real reply path does — through
 * a fake AiService that captures the exact message array handed to the model. That's the contract
 * that matters: what the coach can actually see.
 */
class CoachCrossDayMemoryTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int,array<string,mixed>> */
    private array $captured = [];

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function freezeLocal(string $localDateTime): void
    {
        Carbon::setTestNow(Carbon::parse($localDateTime, Conversation::tz())->utc());
    }

    /** Swap in an AiService that records the prompt and answers with a fixed string. */
    private function captureCoach(): CoachService
    {
        $fake = new class($this) extends AiService
        {
            public function __construct(private CoachCrossDayMemoryTest $test) {}

            public function configured(): bool
            {
                return true;
            }

            public function chatWithTools(array $messages, array|\Closure $tools, callable $dispatch, array $opts = []): string
            {
                $this->test->record($messages);

                return 'ack';
            }
        };

        return new CoachService($fake);
    }

    /** @param array<int,array<string,mixed>> $messages */
    public function record(array $messages): void
    {
        $this->captured = $messages;
    }

    /** The replayed turns, as "role: content" strings (the system prompt itself dropped). */
    private function replayed(): array
    {
        return collect($this->captured)
            ->slice(1)   // [0] is the system prompt
            ->map(fn ($m) => $m['role'].': '.(is_string($m['content']) ? $m['content'] : ''))
            ->values()
            ->all();
    }

    private function seedDay(Profile $profile, string $day, string $userText, string $reply): void
    {
        $convo = Conversation::forDay($profile, $day);
        $convo->messages()->create(['role' => 'user', 'content' => $userText]);
        $convo->messages()->create(['role' => 'assistant', 'content' => $reply]);
    }

    public function test_the_coach_still_sees_previous_days_after_midnight(): void
    {
        $this->freezeLocal('2026-08-01 09:00');
        $profile = User::factory()->create()->ensureProfile();

        $this->seedDay($profile, '2026-07-30', 'knee felt off on squats', 'drop to 60% next session');
        $this->seedDay($profile, '2026-07-31', 'did the 60%, felt fine', 'good — hold there one more session');

        $this->captureCoach()->reply(Conversation::forDay($profile), $profile, 'back to normal load?');

        $replay = implode("\n", $this->replayed());

        $this->assertStringContainsString('knee felt off on squats', $replay, "yesterday's context must survive the day split");
        $this->assertStringContainsString('drop to 60% next session', $replay);
        $this->assertStringContainsString('back to normal load?', $replay);
    }

    public function test_each_days_turns_are_introduced_by_a_dated_divider(): void
    {
        $this->freezeLocal('2026-08-01 09:00');
        $profile = User::factory()->create()->ensureProfile();

        $this->seedDay($profile, '2026-07-30', 'older question', 'older answer');

        $this->captureCoach()->reply(Conversation::forDay($profile), $profile, 'today question');

        $replay = implode("\n", $this->replayed());

        $this->assertStringContainsString('Thursday, July 30, 2026', $replay, 'a past day must be dated in context');
        $this->assertStringContainsString('TODAY — Saturday, August 1, 2026', $replay, 'today must be marked as today');
    }

    public function test_days_beyond_the_window_are_not_replayed(): void
    {
        $this->freezeLocal('2026-08-01 09:00');
        $profile = User::factory()->create()->ensureProfile();

        $this->seedDay($profile, '2026-06-01', 'ancient history', 'ancient answer');   // > 14 days back
        $this->seedDay($profile, '2026-07-31', 'recent thing', 'recent answer');

        $this->captureCoach()->reply(Conversation::forDay($profile), $profile, 'hello');

        $replay = implode("\n", $this->replayed());

        $this->assertStringNotContainsString('ancient history', $replay, 'the window must be bounded');
        $this->assertStringContainsString('recent thing', $replay);
    }

    public function test_a_proactive_message_is_replayed_as_unprompted(): void
    {
        $this->freezeLocal('2026-08-01 09:00');
        $profile = User::factory()->create()->ensureProfile();

        Conversation::forDay($profile)->messages()->create([
            'role' => 'assistant',
            'kind' => ChatMessage::KIND_BRIEFING,
            'content' => 'Readiness is 71 today.',
        ]);

        $this->captureCoach()->reply(Conversation::forDay($profile), $profile, 'why?');

        $replay = implode("\n", $this->replayed());

        $this->assertStringContainsString('unprompted', $replay, 'the coach must know it spoke first');
        $this->assertStringContainsString('Readiness is 71 today.', $replay);
    }

    public function test_another_profiles_days_are_never_replayed(): void
    {
        $this->freezeLocal('2026-08-01 09:00');
        $mine = User::factory()->create()->ensureProfile();
        $theirs = User::factory()->create()->ensureProfile();

        $this->seedDay($theirs, '2026-07-31', 'their private thing', 'their private answer');

        $this->captureCoach()->reply(Conversation::forDay($mine), $mine, 'hello');

        $replay = implode("\n", $this->replayed());

        $this->assertStringNotContainsString('their private thing', $replay);
    }
}
