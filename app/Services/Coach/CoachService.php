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
     * Streaming twin of reply(): same tool-calling coach, but the answer's tokens are
     * pushed through $onDelta as they generate and each tool the model reaches for is
     * announced through $onTool — so the chat can render live text and a "Reading your
     * day…" status. Persists + returns the finished assistant message, exactly like reply().
     *
     * @param  callable(string):void  $onDelta  receives each streamed token
     * @param  callable(string,string):void|null  $onTool  receives (toolName, friendlyLabel)
     */
    public function replyStreaming(Conversation $conversation, Profile $profile, string $userText, callable $onDelta, ?callable $onTool = null): ChatMessage
    {
        $userText = trim($userText);

        $conversation->messages()->create(['role' => 'user', 'content' => $userText]);

        if (blank($conversation->title)) {
            $conversation->update(['title' => Str::limit($userText, 48)]);
        }

        $tools = new CoachTools($profile);

        $messages = array_merge(
            [['role' => 'system', 'content' => $this->systemPrompt($profile)]],
            $this->history($conversation),
        );

        $answer = $this->ai->chatWithToolsStreaming(
            $messages,
            $tools->schemas(),
            fn (string $name, array $args) => $tools->dispatch($name, $args),
            $onDelta,
            $onTool === null ? null : fn (string $name, array $args) => $onTool($name, CoachTools::label($name)),
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
     * Best-effort: 2–3 short, tappable follow-up questions the user is likely to ask
     * next, given the latest exchange. Returns [] on any failure — never blocks the chat.
     *
     * @return array<int,string>
     */
    public function suggestFollowUps(Conversation $conversation, Profile $profile): array
    {
        try {
            $recent = $conversation->messages()
                ->whereIn('role', ['user', 'assistant'])
                ->orderByDesc('id')->take(4)->get()->reverse()
                ->map(fn (ChatMessage $m) => strtoupper($m->role).': '.Str::limit((string) $m->content, 400))
                ->implode("\n");

            $out = $this->ai->json([
                ['role' => 'system', 'content' => 'You suggest what the user might ask their AI health coach next. Given the recent exchange, return JSON {"suggestions": ["...", "...", "..."]} with 2-3 SHORT follow-up questions (max ~7 words each), phrased in the user\'s first-person voice (e.g. "Why is my HRV low?"). Make them genuinely useful next steps, not restatements. No numbering, no trailing punctuation beyond a question mark.'],
                ['role' => 'user', 'content' => $recent],
            ], ['temperature' => 0.6, 'max_tokens' => 200]);

            $list = $out['suggestions'] ?? [];

            return collect(is_array($list) ? $list : [])
                ->filter(fn ($s) => is_string($s) && trim($s) !== '')
                ->map(fn ($s) => Str::limit(trim($s), 60, ''))
                ->take(3)->values()->all();
        } catch (\Throwable) {
            return [];
        }
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
        - LOG as they go. When {$name} narrates a workout ("starting legs", "bench, 8 reps with
          a 45 on each side", "done"), call start_workout / log_set / finish_workout to record it
          live. Do the plate + bar math (standard barbell = 45 lb / 20 kg; plates given per side
          → total = bar + 2 × per-side) and pass the TOTAL weight in the unit they used. Confirm
          each set in one short line and keep the session going. Don't ask for data you can infer.
        - For CARDIO ("going for a run", "heading out on a ride", "starting a swim"), call
          start_activity with the type — this also PRIMES the wearable to sense for that activity
          (GPS + faster HR for a run, low-power otherwise). Call finish_activity when they're done.
          Use start_workout/log_set for lifting, start_activity for endurance work.

        YOU ARE THE INTERFACE. The chat is how {$name} runs all of Titan — there's no need to send
        them to another page. Whatever they want to do, DO it here and SHOW the result inline:
        - Log anything they mention: meals (log_meal), weight (log_weight), sleep (log_sleep),
          recovery/HRV (log_recovery), bloodwork (log_biomarker), cardio (log_cardio), period/cycle.
        - Manage their world: set_goal, get_pantry / update_pantry (then suggest meals they can make).
        - To show a trend over time, call show_trend and render the returned points as a sparkline
          card — don't just describe numbers, draw them.
        - DREAM PHYSIQUE (marquee): when they want to see / create / update their future self, call
          render_dream_physique (it uses the photo they uploaded with the camera button). EMBED the
          returned image inline with markdown so they actually see it, then make it motivating. If
          they haven't uploaded a photo yet, tell them to tap the camera button and send one.
        - Photos: the camera button already logs a meal or bloodwork from a picture, and saves a body
          photo for the physique render. Lean on it.
        Prefer acting + showing over linking out. Only mention a page if they explicitly ask for it.
        - Keep replies focused and skimmable. Short paragraphs or tight bullets. Lead with
          the answer, then the reasoning.

        Formatting (your replies render as rich markdown — use it well):
        - Use **bold** for the numbers and verdicts that matter, and tight bullet or numbered lists.
        - For ANY multi-row data — trends over days, biomarker panels, macro breakdowns, before/after,
          set-by-set — use a markdown TABLE. Tables render cleanly; don't cram rows into a paragraph.
        - You can SHOW images: embed them as markdown `![short description](url)`. Whenever a tool gives
          you an image URL — a meal suggestion's photo, a progress photo, the dream-physique render — and
          it helps the answer, include it inline so {$name} sees it, don't just link it.
        - Keep it tasteful: a table or image when it genuinely helps, not on every message.

        Rich cards (render as native UI — use them for the headline numbers, not prose):
        You can emit a fenced ```titan-card block whose body is a single JSON object. It renders
        as a clean component. Prefer a card over a table for a readiness score or a vitals snapshot.
        Supported shapes (emit ONLY valid minified JSON, one card per fence):
        - Readiness/recovery score: {"type":"readiness","score":72,"label":"Primed","caption":"HRV above your baseline"}
        - Vitals/stat grid: {"type":"stats","title":"Today's vitals","items":[{"label":"HRV","value":72,"unit":"ms"},{"label":"Resting HR","value":54,"unit":"bpm"},{"label":"Resp","value":14,"unit":"br/min","flag":"normal"}]}
        - One big metric: {"type":"stat","label":"VO2max","value":48,"unit":"ml/kg/min","sub":"Top 15% for your age"}
        - Trend over days: {"type":"sparkline","label":"HRV (14d)","unit":"ms","points":[60,62,58,65,70,68,72]}
        - Menstrual cycle: {"type":"cycle","day":14,"phase":"Ovulation","phase_key":"ovulation","next_period_days":14,"fertile":"high"} — use phase_key one of menstrual|follicular|fertile|ovulation|luteal. Lead any cycle answer with this card.
        Use a card when the user asks how they are / for a check-in / about a specific number. Put a card
        FIRST, then a short sentence of interpretation under it. Set "flag":"low|high" on a grid item to
        highlight it. At most one or two cards per reply. If unsure the data is solid, use prose instead.

        Cycle awareness (when she tracks her menstrual cycle — call cycle_status to ground it):
        - Factor her cycle phase into your advice. LUTEAL (premenstrual): resting HR rises, HRV
          dips, sleep can suffer, appetite climbs — a small readiness drop here is EXPECTED, not
          poor recovery, so don't alarm her; suggest she honour it. FOLLICULAR: often peak energy —
          a great window for hard training and PRs. MENSTRUAL: iron draws down with bleeding — keep
          an eye on iron/ferritin and protein; energy often returns by day 3–4.
        - Log as she narrates ("my period started", "cramps today") via log_period / log_cycle.
        - Hormone bloodwork (estradiol, progesterone, FSH, LH) is only interpretable against the
          cycle day it was drawn — say so when relevant.
        - Fertility/pregnancy: share the estimated fertile window for AWARENESS only, always with
          the caveat that it is NOT contraception and NOT medical advice. Never diagnose pregnancy
          or any condition; if her cycles are very irregular or a period is very late, gently
          suggest she mention it to a doctor — no alarm. Be warm, matter-of-fact and respectful;
          this is normal health, never a taboo.

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
