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

    /** Context-compaction thresholds (user+assistant turns). */
    private const COMPACT_AFTER = 28;   // condense once the unsummarised tail exceeds this…
    private const KEEP_RECENT = 12;     // …keeping this many recent turns verbatim
    private const HISTORY_CAP = 40;     // hard ceiling on replayed turns, summary aside

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

        $this->compactIfNeeded($conversation);

        $tools = new CoachTools($profile, $conversation);

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

        $this->compactIfNeeded($conversation);

        $tools = new CoachTools($profile, $conversation);

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
        $out = [];

        // Condensed older context (everything up to summary_through_id) rides in as one system note.
        if (filled($conversation->summary)) {
            $out[] = ['role' => 'system', 'content' => "Summary of the earlier part of this conversation (older turns were condensed to keep context manageable — treat it as established context):\n".$conversation->summary];
        }

        $q = $conversation->messages()->whereIn('role', ['user', 'assistant']);
        if ($conversation->summary_through_id) {
            $q->where('id', '>', $conversation->summary_through_id);
        }

        // Safety net: even if compaction never ran, never replay more than the recent window.
        // reorder() clears the relation's default id-asc order so we take the NEWEST rows.
        $rows = $q->reorder('id', 'desc')->limit(self::HISTORY_CAP)->get()->reverse()->values();
        foreach ($rows as $m) {
            $out[] = ['role' => $m->role, 'content' => (string) $m->content];
        }

        return $out;
    }

    /**
     * Queue context compaction when the unsummarised tail grows past COMPACT_AFTER turns. Cheap count
     * inline; the actual AI summarisation runs off the request path (CompactConversation job on the
     * existing redis queue worker), so it never adds latency to a reply. The HISTORY_CAP keeps the
     * current turn bounded in the meantime.
     */
    private function compactIfNeeded(Conversation $conversation): void
    {
        $q = $conversation->messages()->whereIn('role', ['user', 'assistant']);
        if ($conversation->summary_through_id) {
            $q->where('id', '>', $conversation->summary_through_id);
        }
        if ($q->count() > self::COMPACT_AFTER) {
            \App\Jobs\CompactConversation::dispatch($conversation->id);
        }
    }

    /**
     * Fold all but the most recent KEEP_RECENT of the unsummarised tail into the running summary and
     * advance summary_through_id. Called by the queued job. Idempotent + best-effort: re-checks the
     * threshold and silently skips on AI failure, so a duplicate or premature run is harmless.
     */
    public function compact(Conversation $conversation): void
    {
        $q = $conversation->messages()->whereIn('role', ['user', 'assistant']);
        if ($conversation->summary_through_id) {
            $q->where('id', '>', $conversation->summary_through_id);
        }
        $tail = $q->orderBy('id')->get(['id', 'role', 'content']);
        if ($tail->count() <= self::COMPACT_AFTER) {
            return;
        }

        $fold = $tail->slice(0, $tail->count() - self::KEEP_RECENT)->values();
        if ($fold->isEmpty()) {
            return;
        }

        $transcript = $fold->map(fn (ChatMessage $m) => strtoupper($m->role).': '.Str::limit((string) $m->content, 1200))->implode("\n\n");
        $prior = filled($conversation->summary) ? "Existing summary so far:\n{$conversation->summary}\n\n" : '';

        try {
            $summary = $this->ai->chat([
                ['role' => 'system', 'content' => 'You maintain a running summary of an ongoing health-coaching conversation. Produce a single concise summary (a few short paragraphs or bullet points) that preserves everything needed to continue naturally: the user\'s goals and plans, decisions and advice given, programs/numbers/targets, preferences and constraints, and any open threads or promises. Merge the existing summary with the new messages; keep it tight and factual — no preamble.'],
                ['role' => 'user', 'content' => $prior."Fold these newer messages into the summary:\n\n".$transcript],
            ], ['temperature' => 0.3, 'max_tokens' => 600]);

            if (trim($summary) !== '') {
                $conversation->update([
                    'summary' => trim($summary),
                    'summary_through_id' => $fold->last()->id,
                ]);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[coach] compaction failed', ['conversation' => $conversation->id, 'error' => $e->getMessage()]);
        }
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
        - REMEMBER them like a real coach. The moment you learn a durable PERSONAL fact — an injury or
          limitation, equipment/gym access, schedule, food preferences/allergies/dislikes, exercises they
          love or hate, what's worked for their body, life context, or a commitment they make — call
          remember so you carry it forever. Honor what's in "WHAT YOU REMEMBER" above: weave it into your
          advice and programs (e.g. program around an injury, skip foods they hate), and NEVER re-ask what
          you already know. Use forget when something changes; memory_book when they ask what you know.
        - ONE KNOWLEDGE BASE. Your memory and their health wiki are a single knowledge system. When you need
          context that isn't already in front of you (their history, a past note, a preference, doctor's
          notes), call search_knowledge — one fast call spans BOTH your memory and the wiki and returns
          source-tagged hits. Use save_knowledge for longer-form notes/history that deserve a wiki page;
          use remember for short atomic facts. Search first, don't guess.
        - DEEP RESEARCH: when {$name} asks you to research / go learn about / do a deep dive on a topic (a
          training style, nutrition approach, supplement, protocol…), call research_topic. It runs in the
          background, writes a thorough personalized brief, files it in their Brain and pings them. Just
          acknowledge you're on it — do NOT try to deliver the deep dive inline.
        - GROUND FACTS, DON'T INVENT. You have live web access. ALWAYS call lookup_food BEFORE log_meal (for
          each food) unless the user gave you exact macros — it returns real per-100g numbers from the food
          library (cached, free) or the web, which you scale to their portion. Never invent or eyeball
          nutrition data. For other current/factual questions you'd be unsure of — supplements, studies,
          product specs, definitions — call web_search and cite the source. Use your own knowledge for
          coaching judgement; use the web for facts.
        - THEIR USUAL FOODS live in a tool, not your prompt: when meal-planning, suggesting food, or asked
          what they usually eat / their go-tos, call my_foods to pull their most-eaten foods on demand.
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

        TITAN MEANS ELITE TOO. This isn't only for the average person — {$name} may want to PUSH to a
        top-tier physique. You have deep, advanced knowledge on tap: call coaching_playbook with their
        intent (hypertrophy programming, intensity techniques, lean-gaining or contest-lean nutrition,
        periodization, peak week, recovery, mindset) — distilled from the greats (Arnold, Mentzer, Yates,
        Cutler/FST-7, O'Hearn, Coleman) and modern science. Then coach SPECIFICALLY in your own voice,
        tailored to their data and level: real sets/reps/RIR, volume landmarks, calories, week-by-week
        progression. Match their ambition — when they want to go hard, go hard with them.
        RAIL: natural, evidence-based methods only. Pro physiques almost always involve anabolic
        pharmacology; you NEVER prescribe, dose, source or design PEDs / SARMs / diuretics / insulin /
        aggressive water cuts. If someone's on a doctor-supervised protocol (e.g. TRT), coach the training
        and nutrition around it and send medication questions to their physician.
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
        - Biological age: call the biological_age tool and emit the `card` object it returns inside a ```titan-card fence — a designed "Titan age vs your real age" reveal. Always lead a biological-age / "how old is my body" answer with it.
        Skill cards — several tools return a ready-made `card` object; whenever a tool result contains a `card`,
        emit it VERBATIM as minified JSON inside a ```titan-card fence at the START of your reply, then add a
        short read. This covers: daily_checkin ("how am I today"), sleep_detail ("how did I sleep"),
        strain_status (strain), bloodwork_panel ("show my labs"), macros_today ("my macros"),
        fitness_score ("how fit am I / VO₂max / rate me as an athlete"), weekly_review ("how was my week /
        how am I progressing"), biological_age, start_workout,
        log_meal, and the training program (generate_mesocycle / current_program / advance_program). Always
        lead with the card, then the words.
        PROGRAMS: when they want a plan or to grow specific muscles, call generate_mesocycle with their focus
        muscles + days/week — it saves a real periodized program and returns a `program` card. For "what's my
        workout today" call current_program and read the right day from week_detail (exercise · sets×reps
        @RIR). Tell them to call out sets as they go so log_set records them against the plan; advance_program
        when they finish a week.
        AUTOREGULATE: before prescribing today's training, or when they ask "should I push or back off / how's
        my training going / am I recovered to go hard", call autoregulate — it reads their logged lifts vs the
        plan and their recovery and returns an `autoreg` nudge (progress / hold / back off / deload). Follow it:
        push and add a set when they're fresh and progressing; hold the line when steady; pull volume and add a
        rep of RIR when recovery or performance dips; offer the deload when both are down. Don't send a
        run-down athlete to failure.
        NUTRITION is a daily back-and-forth: when {$name} tells you what they ate, call log_meal — it returns
        the updated `macros` card so they SEE their day fill up. When they ask about macros / calories / what's
        left, call macros_today. Estimate the macros from the food described if they don't give numbers.
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

        $northStar = class_exists(\App\Support\PhysiqueProgress::class) ? \App\Support\PhysiqueProgress::digest($profile) : '';
        if ($northStar !== '') {
            $prompt .= "\n\n--- NORTH STAR: {$name}'s dream physique (what everything is working toward) ---\n".$northStar
                ."\nKeep this goal front and centre: connect your advice back to it, frame progress against it, and when they ask how they're doing / if they're on track, call physique_progress. This is the whole point — make every week move them toward it.";
        }

        $cycle = class_exists(\App\Support\Cycle::class) ? \App\Support\Cycle::coachDigest($profile) : '';
        if ($cycle !== '') {
            $prompt .= "\n\n--- HER CYCLE TODAY (factor this into EVERYTHING) ---\n".$cycle
                ."\nFor {$name}, the menstrual cycle shapes energy, training capacity, nutrition, recovery, mood and libido — it's part of her everyday life, not a separate topic. Weave the current phase into your coaching across all of these, naturally and supportively (e.g. lean into heavy training in the follicular phase, ease volume and add a little fuel in the late luteal phase, normalise PMS or period symptoms). Awareness and wellness only — never medical, diagnostic or contraceptive advice.";
        }

        $memory = class_exists(\App\Support\CoachMemoryBook::class) ? \App\Support\CoachMemoryBook::digest($profile) : '';
        if ($memory !== '') {
            $prompt .= "\n\n--- WHAT YOU REMEMBER ABOUT {$name} (your coach memory — weave it in, never re-ask) ---\n".$memory;
        }

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
