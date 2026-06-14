<?php

namespace App\Services\Coach;

use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\Profile;
use App\Services\Ai\AiService;
use Illuminate\Support\Str;

/**
 * The AI coach. Ties every Titan vertical together: a personalized health, longevity
 * and physique coach for one specific person, grounded in THEIR data — the structured
 * tables (biomarkers, meals, workouts, sleep/recovery, physique) and the brain
 * (long-term-memory wiki). It answers via OpenAI tool-calling: the model decides which
 * read-only data tools to call, we run them, and it synthesizes an answer that always
 * explains the WHY.
 *
 * Core memory (the profile's PINNED brain pages) is injected into the system prompt
 * every turn, so the coach never forgets durable facts. Tone adapts to coach_tone.
 *
 * AI failures bubble up as App\Exceptions\AiException so the controller can keep the
 * page alive with a friendly "coach is offline" message.
 */
class CoachService
{
    public function __construct(protected AiService $ai) {}

    /** Suggested starter prompts shown on an empty conversation. */
    public const STARTERS = [
        'How are my biomarkers trending?',
        'Plan my meals to hit my protein goal.',
        'Am I on track to my goal physique?',
        'What should I focus on this week?',
    ];

    /**
     * Append the user's message, run the tool-calling coach, persist + return the
     * assistant reply. Throws AiException on AI failure (controller catches it).
     */
    public function reply(Conversation $conversation, Profile $profile, string $userText): ChatMessage
    {
        $userText = trim($userText);

        $userMsg = $conversation->messages()->create([
            'role' => 'user',
            'content' => $userText,
        ]);

        // Auto-title the conversation from its first user message.
        if (blank($conversation->title)) {
            $conversation->update(['title' => Str::limit($userText, 48)]);
        }

        $tools = new CoachTools($profile);

        $messages = array_merge(
            [['role' => 'system', 'content' => $this->systemPrompt($profile)]],
            $this->history($conversation),
        );

        $answer = $this->ai->chatWithTools(
            $messages,
            $tools->schemas(),
            fn (string $name, array $args) => $tools->dispatch($name, $args),
            ['temperature' => 0.5, 'max_steps' => 8],
        );

        if (trim($answer) === '') {
            $answer = "I couldn't generate a response just now — try rephrasing, or ask again in a moment.";
        }

        return $conversation->messages()->create([
            'role' => 'assistant',
            'content' => $answer,
        ]);
    }

    /**
     * Build the OpenAI message history from stored messages. Only user + assistant
     * turns are replayed (tool turns were transient to a previous reply's loop).
     *
     * @return array<int,array<string,string>>
     */
    private function history(Conversation $conversation): array
    {
        return $conversation->messages()
            ->whereIn('role', ['user', 'assistant'])
            ->orderBy('id')
            ->get()
            ->map(fn (ChatMessage $m) => [
                'role' => $m->role,
                'content' => (string) $m->content,
            ])
            ->all();
    }

    /** The personalized system prompt, including injected core memory. */
    private function systemPrompt(Profile $profile): string
    {
        $name = $profile->display_name ?: ($profile->user?->name ?? 'this person');
        $tone = $this->toneGuidance($profile->coach_tone ?? 'balanced');

        $facts = [];
        if ($profile->primary_goal) {
            $facts[] = 'Primary goal: '.$profile->primary_goal;
        }
        if ($profile->sex) {
            $facts[] = 'Sex: '.$profile->sex;
        }
        if ($profile->birthdate) {
            try {
                $facts[] = 'Age: '.$profile->birthdate->age;
            } catch (\Throwable) {
                // ignore unparseable dates
            }
        }
        if ($profile->height_cm) {
            $facts[] = 'Height: '.$profile->height_cm.' cm';
        }
        $factLine = $facts === [] ? '' : "\nWhat you already know about them:\n- ".implode("\n- ", $facts);

        $prompt = <<<TXT
        You are Titan, {$name}'s personal AI health, longevity and physique coach. You are
        not a generic chatbot — you are THEIR coach, with access to their real logged data.

        Your job: help {$name} become the strongest, healthiest, most capable version of
        themselves over the long run — across muscle gain, longevity, strength, mobility,
        recovery, nutrition, sleep, and biomarkers.

        How you operate:
        - GROUND every answer in their actual data. Before making claims about their
          biomarkers, meals, training, sleep/recovery or physique, CALL the relevant tool
          to fetch the real numbers. Do not invent values. If a tool says there is no data
          yet, say so plainly and suggest how they could start logging it.
        - Always explain the WHY — the mechanism, the trade-off, what the number means —
          not just the what. Be specific and actionable; give concrete next steps.
        - Use search_knowledge to recall their history, preferences, goals and context from
          the brain. When you learn a durable fact worth remembering (a preference, a
          constraint, a milestone), use save_knowledge so you remember it next time.
        - Be proactive: surface things they should pay attention to, connect the dots across
          domains (e.g. poor sleep dragging recovery and training), and nudge toward their goal.
        - Keep replies focused and skimmable. Short paragraphs or tight bullets. Lead with
          the answer, then the reasoning.

        Safety: You are a coach, NOT a doctor. Never give a medical diagnosis or prescribe
        treatment. If something looks clinically concerning (e.g. a sharply out-of-range
        biomarker, symptoms), flag it plainly and recommend they see a qualified physician.

        Tone: {$tone}{$factLine}
        TXT;

        $core = $this->coreMemory($profile);
        if ($core !== '') {
            $prompt .= "\n\n--- CORE MEMORY (pinned facts about {$name} — always honor these) ---\n".$core;
        }

        return $prompt;
    }

    /** Tone instruction derived from the profile's coach_tone setting. */
    private function toneGuidance(string $tone): string
    {
        return match ($tone) {
            'tough_love' => 'Direct, demanding and honest. Hold them accountable, call out excuses, push them hard — but always with their progress at heart. No coddling.',
            'gentle' => 'Warm, patient and encouraging. Celebrate small wins, never shame, meet them where they are and build momentum gently.',
            default => 'Balanced: supportive but straight-talking. Encourage progress and be honest about what needs work, without being harsh.',
        };
    }

    /**
     * Pinned KnowledgePages = core memory. Guarded with class_exists so the coach
     * still works before the Brain vertical is integrated.
     */
    private function coreMemory(Profile $profile): string
    {
        if (! class_exists(\App\Models\KnowledgePage::class)) {
            return '';
        }

        try {
            $pages = \App\Models\KnowledgePage::query()
                ->where('profile_id', $profile->id)
                ->where('is_pinned', true)
                ->orderBy('title')
                ->get();
        } catch (\Throwable) {
            return '';
        }

        if ($pages->isEmpty()) {
            return '';
        }

        return $pages->map(function ($p) {
            $body = trim((string) $p->content);
            $body = Str::limit($body, 1200);

            return '## '.$p->title."\n".$body;
        })->implode("\n\n");
    }
}
