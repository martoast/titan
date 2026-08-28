<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Ai\AiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Reasoning models (gpt-5.x / o-series) bill their HIDDEN reasoning tokens against
 * `max_completion_tokens` — the same budget the caller means as "visible answer length".
 * With a tight cap (the briefings pass 320) the model can spend the whole budget thinking
 * and return `content: ""` with `finish_reason: "length"`, which used to surface as
 * "OpenAI returned an empty response" and silently drop the evening nudge (issue #46).
 */
class AiReasoningBudgetTest extends TestCase
{
    use RefreshDatabase;

    private function openAiIsConfiguredWith(string $model): void
    {
        config([
            'services.openai.key' => 'test-key',
            'services.openai.base_url' => 'https://api.openai.com/v1',
            'services.openai.chat_model' => $model,
        ]);
    }

    private function emptyLengthResponse(): array
    {
        return [
            'model' => 'gpt-5.6-luna',
            'choices' => [['message' => ['role' => 'assistant', 'content' => ''], 'finish_reason' => 'length']],
            'usage' => [
                'prompt_tokens' => 500, 'completion_tokens' => 320,
                'completion_tokens_details' => ['reasoning_tokens' => 320],
            ],
        ];
    }

    private function goodResponse(string $text): array
    {
        return [
            'model' => 'gpt-5.6-luna',
            'choices' => [['message' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 500, 'completion_tokens' => 60],
        ];
    }

    public function test_chat_retries_once_when_reasoning_exhausts_the_token_cap(): void
    {
        $this->openAiIsConfiguredWith('gpt-5.6-luna');
        Http::fake(['*/chat/completions' => Http::sequence()
            ->push($this->emptyLengthResponse())
            ->push($this->goodResponse("You're 40g protein short — a cup of Greek yogurt closes it."))]);

        $answer = app(AiService::class)->chat(
            [['role' => 'user', 'content' => 'evening nudge please']],
            ['max_tokens' => 320],
        );

        $this->assertSame("You're 40g protein short — a cup of Greek yogurt closes it.", $answer);
        Http::assertSentCount(2);

        // The retry must actually raise the completion budget, or it would just starve again.
        $caps = collect(Http::recorded())->map(fn ($pair) => $pair[0]['max_completion_tokens'] ?? null);
        $this->assertGreaterThan($caps[0], $caps[1], 'the retry should raise max_completion_tokens');
    }

    public function test_chat_gives_reasoning_models_headroom_above_the_callers_cap(): void
    {
        $this->openAiIsConfiguredWith('gpt-5.6-luna');
        Http::fake(['*/chat/completions' => Http::response($this->goodResponse('ok'))]);

        app(AiService::class)->chat([['role' => 'user', 'content' => 'hi']], ['max_tokens' => 320]);

        Http::assertSent(function ($request) {
            $cap = $request->data()['max_completion_tokens'] ?? null;

            // The caller's 320 means "visible answer"; the budget must also cover hidden reasoning.
            return $cap !== null && $cap > 320 && ! isset($request->data()['max_tokens']);
        });
    }

    public function test_chat_keeps_the_exact_cap_for_non_reasoning_models(): void
    {
        $this->openAiIsConfiguredWith('gpt-4o');
        Http::fake(['*/chat/completions' => Http::response($this->goodResponse('ok'))]);

        app(AiService::class)->chat([['role' => 'user', 'content' => 'hi']], ['max_tokens' => 320]);

        Http::assertSent(fn ($request) => ($request->data()['max_tokens'] ?? null) === 320
            && ! isset($request->data()['max_completion_tokens']));
    }

    public function test_a_persistently_empty_response_still_fails_but_names_the_finish_reason(): void
    {
        $this->openAiIsConfiguredWith('gpt-5.6-luna');
        Http::fake(['*/chat/completions' => Http::sequence()
            ->push($this->emptyLengthResponse())
            ->push($this->emptyLengthResponse())]);

        try {
            app(AiService::class)->chat([['role' => 'user', 'content' => 'hi']], ['max_tokens' => 320]);
            $this->fail('an empty response on every attempt should raise AiException');
        } catch (\App\Exceptions\AiException $e) {
            // The warning must carry the real detail, not just the category (issue #46 intake).
            $this->assertStringContainsString('length', $e->getMessage());
        }
    }

    public function test_evening_nudge_survives_one_reasoning_starved_response(): void
    {
        // The incident shape: a profile with NO meals logged today (so the deterministic
        // fallback is empty) used to lose its nudge entirely on one empty completion.
        $this->openAiIsConfiguredWith('gpt-5.6-luna');
        Http::fake(['*/chat/completions' => Http::sequence()
            ->push($this->emptyLengthResponse())
            ->push($this->goodResponse('Wind down early tonight — lights out by 22:30 keeps your streak.'))]);

        $profile = User::factory()->create()->ensureProfile();

        $message = app(\App\Services\Coach\CoachBriefingService::class)->eveningNudge($profile);

        $this->assertSame('Wind down early tonight — lights out by 22:30 keeps your streak.', $message);
        $this->assertDatabaseHas('chat_messages', ['content' => $message]);
    }
}
