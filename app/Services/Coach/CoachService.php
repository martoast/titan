<?php

namespace App\Services\Coach;

use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\Profile;
use App\Services\Ai\AiService;
use App\Support\Cycle;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The AI coach. Ties every Titan vertical together: a personalized health, longevity
 * and physique coach for one specific person, grounded in THEIR data -- the structured
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

    /** Generic fallback starter prompts (used when a profile has no intake yet). */
    public const STARTERS = [
        'How are my biomarkers trending?',
        'Plan my meals to hit my protein goal.',
        'Am I on track to my goal physique?',
        'What should I focus on this week?',
    ];

    /**
     * Starter prompts for an empty conversation, tailored to who's asking. Men and women
     * come to Titan for different things, so the first four taps should reflect THIS user's
     * goal, focus areas and (for women) cycle — subtly, not a different app. Falls back to
     * the generic STARTERS when we don't know enough yet.
     *
     * @return list<string>
     */
    public static function startersFor(Profile $profile): array
    {
        $intake = (array) ($profile->settings['intake'] ?? []);
        $focus = array_values(array_filter((array) ($intake['focus_areas'] ?? [])));
        $goal = (string) ($profile->primary_goal ?? ($intake['goal'] ?? ''));
        $female = $profile->sex === 'F';

        // Nothing personal known yet → the safe generic set.
        if (! $focus && $goal === '') {
            return self::STARTERS;
        }

        $starters = [];

        // 1) Their headline focus area, in their words ("Rounder glutes" → "…for rounder glutes").
        if ($focus) {
            $starters[] = 'Am I on track for '.Str::lower($focus[0]).'?';
        } else {
            $starters[] = 'Am I on track to my goal physique?';
        }

        // 2) Nutrition framed by goal (protein is the through-line either way).
        $starters[] = match (true) {
            str_contains(Str::lower($goal), 'lose') => 'Plan meals that keep me full in a fat-loss deficit.',
            str_contains(Str::lower($goal), 'muscle'), str_contains(Str::lower($goal), 'recomp')
                => 'Plan high-protein meals to build muscle.',
            default => 'Plan my meals to hit my protein goal.',
        };

        // 3) Training, lightly gendered toward what each tends to ask for.
        $starters[] = $female
            ? 'What should I train this week to tone up?'
            : 'What should I train this week to add muscle?';

        // 4) Cycle-aware when relevant, otherwise the weekly check-in.
        $starters[] = ($female && Cycle::available($profile))
            ? 'How should I train and eat for my cycle phase right now?'
            : 'What should I focus on this week?';

        return $starters;
    }

    /** Context-compaction thresholds (user+assistant turns). */
    private const COMPACT_AFTER = 28;   // condense once the unsummarised tail exceeds this…
    private const KEEP_RECENT = 12;     // …keeping this many recent turns verbatim
    private const HISTORY_CAP = 40;     // hard ceiling on replayed turns, summary aside

    /** Injected only on a photo turn — tells the coach it can see the attached image + when to log it. */
    private const PHOTO_NOTE = 'The user attached a photo to their latest message and you CAN SEE it. Look at it and address what they asked. If it is a meal / food / nutrition-facts label or a bloodwork / lab sheet they want recorded, call scan_photo to log it accurately. For anything else — a gym machine, their form, "what is this" — just answer from the image. Never say you cannot see images.';

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

        $tools = (new CoachTools($profile, $conversation))->route($userText);

        $messages = array_merge(
            [['role' => 'system', 'content' => $this->systemPrompt($profile)]],
            $this->history($conversation),
        );

        $answer = $this->ai->chatWithTools(
            $messages,
            fn () => $tools->schemas(),   // resolved each step → load_tools can expand mid-loop
            fn (string $name, array $args) => $tools->dispatch($name, $args),
            ['model' => config('services.openai.coach_model'), 'temperature' => 0.5, 'max_steps' => 8],
        );

        if (trim($answer) === '') {
            $answer = "I couldn't generate a response just now -- try rephrasing, or ask again in a moment.";
        }

        return $conversation->messages()->create([
            'role' => 'assistant',
            'content' => $answer,
        ]);
    }

    /**
     * Streaming twin of reply(): same tool-calling coach, but the answer's tokens are
     * pushed through $onDelta as they generate and each tool the model reaches for is
     * announced through $onTool -- so the chat can render live text and a "Reading your
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

        $tools = (new CoachTools($profile, $conversation))->route($userText);

        $messages = array_merge(
            [['role' => 'system', 'content' => $this->systemPrompt($profile)]],
            $this->history($conversation),
        );

        $answer = $this->ai->chatWithToolsStreaming(
            $messages,
            fn () => $tools->schemas(),   // resolved each step → load_tools can expand mid-loop
            fn (string $name, array $args) => $tools->dispatch($name, $args),
            $onDelta,
            $onTool === null ? null : fn (string $name, array $args) => $onTool($name, CoachTools::label($name)),
            ['model' => config('services.openai.coach_model'), 'temperature' => 0.5, 'max_steps' => 8],
        );

        if (trim($answer) === '') {
            $answer = "I couldn't generate a response just now -- try rephrasing, or ask again in a moment.";
        }

        return $conversation->messages()->create([
            'role' => 'assistant',
            'content' => $answer,
        ]);
    }

    /**
     * Background twin of replyStreaming(): fills an ALREADY-PERSISTED placeholder assistant row
     * ($assistant, status `pending`) with the coach's answer, writing the growing text into the DB as
     * it generates so a polling client sees it "type" — then marks it `complete` (or `failed`). The
     * user message is assumed already saved by the caller. Because generation lives in a queue job,
     * it survives the phone suspending: the request that kicked it off is long gone by the time this
     * finishes. Never throws — a failure is recorded on the row so the client always sees a resolution.
     */
    public function generate(Conversation $conversation, Profile $profile, ChatMessage $assistant, string $userText, ?string $imagePath = null): void
    {
        $userText = trim($userText);

        if (blank($conversation->title)) {
            $conversation->update(['title' => Str::limit($userText !== '' ? $userText : 'Photo', 48)]);
        }

        $this->compactIfNeeded($conversation);

        // A photo turn: the coach SEES the image (vision message below) and gets the scan_photo tool so
        // meals/labs still log accurately. A text turn passes null → identical to before.
        $tools = (new CoachTools($profile, $conversation, $imagePath))->route($userText);
        $messages = array_merge(
            [['role' => 'system', 'content' => $this->systemPrompt($profile)]],
            $imagePath !== null ? [['role' => 'system', 'content' => self::PHOTO_NOTE]] : [],
            $this->history($conversation),
        );
        if ($imagePath !== null) {
            $messages = $this->attachImageToLastUserTurn($messages, $imagePath, $userText);
        }

        $assistant->update(['status' => ChatMessage::STATUS_STREAMING]);

        $buffer = '';
        $lastFlush = microtime(true);
        // Persist the partial answer at most a few times a second — enough for a live "typing" feel
        // when the client polls, without hammering the DB on every token.
        $flush = function (bool $force = false) use (&$buffer, &$lastFlush, $assistant) {
            $now = microtime(true);
            if (! $force && ($now - $lastFlush) < 0.4) {
                return;
            }
            $lastFlush = $now;
            $assistant->forceFill(['content' => $buffer])->saveQuietly();
        };

        try {
            $answer = $this->ai->chatWithToolsStreaming(
                $messages,
                fn () => $tools->schemas(),
                fn (string $name, array $args) => $tools->dispatch($name, $args),
                function (string $token) use (&$buffer, $flush) {
                    $buffer .= $token;
                    $flush();
                },
                null,
                ['model' => config('services.openai.coach_model'), 'temperature' => 0.5, 'max_steps' => 8],
            );

            if (trim($answer) === '') {
                $answer = "I couldn't generate a response just now -- try rephrasing, or ask again in a moment.";
            }

            $assistant->update(['content' => $answer, 'status' => ChatMessage::STATUS_COMPLETE]);
        } catch (\Throwable $e) {
            Log::warning('[Coach] background generation failed', ['error' => $e->getMessage()]);
            $assistant->update([
                'content' => trim($buffer) !== '' ? $buffer
                    : 'Your coach is offline right now (the AI service is unavailable). Your message was saved -- try again in a moment.',
                'status' => ChatMessage::STATUS_FAILED,
            ]);
        }
    }

    /**
     * Best-effort: 2-3 short, tappable follow-up questions the user is likely to ask
     * next, given the latest exchange. Returns [] on any failure -- never blocks the chat.
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
            $out[] = ['role' => 'system', 'content' => "Summary of the earlier part of this conversation (older turns were condensed to keep context manageable -- treat it as established context):\n".$conversation->summary];
        }

        $q = $conversation->messages()->whereIn('role', ['user', 'assistant'])
            // Never count/replay an in-flight background placeholder (empty/partial content).
            // NULL status = legacy/done, so keep it (SQL `NOT IN` would drop NULLs).
            ->where(fn ($w) => $w->whereNull('status')
                ->orWhereNotIn('status', [ChatMessage::STATUS_PENDING, ChatMessage::STATUS_STREAMING]));
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
     * Replace the LAST user turn's plain text with a vision content array (text + the image as a base64
     * data URL) so the coach model actually SEES the photo. The stored message holds `![photo](url)`
     * markdown for the chat UI; OpenAI can't fetch that localhost URL, so we inline the bytes here.
     * Falls back to the unchanged (text-only) messages if the file can't be read.
     *
     * @param  array<int,array<string,mixed>>  $messages
     * @return array<int,array<string,mixed>>
     */
    private function attachImageToLastUserTurn(array $messages, string $imagePath, string $userText): array
    {
        try {
            $disk = \Illuminate\Support\Facades\Storage::disk('public');
            $mime = $disk->mimeType($imagePath) ?: 'image/jpeg';
            $dataUrl = 'data:'.$mime.';base64,'.base64_encode((string) $disk->get($imagePath));
        } catch (\Throwable) {
            return $messages;
        }

        $parts = [
            ['type' => 'text', 'text' => $userText !== '' ? $userText : 'I sent you a photo — take a look.'],
            ['type' => 'image_url', 'image_url' => ['url' => $dataUrl, 'detail' => 'auto']],
        ];

        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? '') === 'user') {
                $messages[$i]['content'] = $parts;
                break;
            }
        }

        return $messages;
    }

    /**
     * Queue context compaction when the unsummarised tail grows past COMPACT_AFTER turns. Cheap count
     * inline; the actual AI summarisation runs off the request path (CompactConversation job on the
     * existing redis queue worker), so it never adds latency to a reply. The HISTORY_CAP keeps the
     * current turn bounded in the meantime.
     */
    private function compactIfNeeded(Conversation $conversation): void
    {
        $q = $conversation->messages()->whereIn('role', ['user', 'assistant'])
            // Never count/replay an in-flight background placeholder (empty/partial content).
            // NULL status = legacy/done, so keep it (SQL `NOT IN` would drop NULLs).
            ->where(fn ($w) => $w->whereNull('status')
                ->orWhereNotIn('status', [ChatMessage::STATUS_PENDING, ChatMessage::STATUS_STREAMING]));
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
        $q = $conversation->messages()->whereIn('role', ['user', 'assistant'])
            // Never count/replay an in-flight background placeholder (empty/partial content).
            // NULL status = legacy/done, so keep it (SQL `NOT IN` would drop NULLs).
            ->where(fn ($w) => $w->whereNull('status')
                ->orWhereNotIn('status', [ChatMessage::STATUS_PENDING, ChatMessage::STATUS_STREAMING]));
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

        // BEFORE these turns are compressed into the summary, consolidate anything durable from them
        // into long-term storage — atomic facts into the memory book, richer multi-part knowledge into
        // wiki pages — so nothing important is lost to repeated summarisation. The summary then carries
        // continuity; the searchable memory + wiki carry permanence (recallable later via search_knowledge).
        $this->consolidateKnowledge($conversation, $fold);

        $transcript = $fold->map(fn (ChatMessage $m) => strtoupper($m->role).': '.Str::limit((string) $m->content, 1200))->implode("\n\n");
        $prior = filled($conversation->summary) ? "Existing summary so far:\n{$conversation->summary}\n\n" : '';

        try {
            $summary = $this->ai->chat([
                ['role' => 'system', 'content' => 'You maintain a running summary of an ongoing health-coaching conversation. Produce a single concise summary (a few short paragraphs or bullet points) that preserves everything needed to continue naturally: the user\'s goals and plans, decisions and advice given, programs/numbers/targets, preferences and constraints, and any open threads or promises. Merge the existing summary with the new messages; keep it tight and factual -- no preamble.'],
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

    /**
     * Harvest durable knowledge from the turns being archived into long-term storage, so it survives
     * summary compression and stays recallable via search_knowledge. One conservative extraction pass
     * routes to BOTH stores: atomic personal facts → the memory book (deduped by
     * {@see CoachMemoryBook::remember}); richer, multi-part narrative → wiki pages via the Brain
     * {@see \App\Services\Brain\KnowledgeIngestor}, which merges into existing pages (never duplicates)
     * and re-embeds. Best-effort + off the request path (the compaction job) — a failure never blocks
     * compaction; cheap model; idempotent.
     *
     * @param  \Illuminate\Support\Collection<int,ChatMessage>  $fold
     */
    private function consolidateKnowledge(Conversation $conversation, $fold): void
    {
        if (! class_exists(\App\Models\CoachMemory::class) || ! class_exists(\App\Support\CoachMemoryBook::class)) {
            return;
        }
        $profile = $conversation->profile;
        if ($profile === null) {
            return;
        }

        $cats = array_keys(\App\Models\CoachMemory::CATEGORIES);
        $transcript = $fold->map(fn (ChatMessage $m) => strtoupper($m->role).': '.Str::limit((string) $m->content, 1000))->implode("\n\n");

        try {
            $out = $this->ai->json([
                ['role' => 'system', 'content' => 'You are consolidating a health-coaching conversation into long-term storage just BEFORE these older turns get archived. Split what is worth keeping into two buckets and IGNORE one-off numbers, day-to-day logs, small talk, and anything transient.

1) "facts": ATOMIC durable personal facts worth remembering for months — injuries/limitations, equipment/access, schedule/availability, food likes/dislikes/allergies, loved/hated exercises, what has worked, firm commitments, key decisions, standing goals/targets. Each is one short sentence.
2) "wiki": RICHER, multi-part knowledge that deserves a persistent reference PAGE — an evolving training or nutrition plan, a health/medical history, lab or doctor\'s notes, a named entity (their gym/home-gym setup, a coach/PT, a race they\'re training for), or a concept they\'re working through. Write it as organised prose with clear topic headings (markdown ok). Empty string if nothing qualifies.

Return JSON {"facts":[{"category":"<one of: '.implode(', ', $cats).'>","content":"short specific fact in third person","importance":1-3}],"wiki":"..."}. Be conservative in BOTH — high-signal only.'],
                ['role' => 'user', 'content' => "Conversation turns being archived:\n\n".$transcript],
            ], ['model' => config('services.openai.fast_model'), 'temperature' => 0.2, 'max_tokens' => 900]);

            foreach (($out['facts'] ?? []) as $f) {
                if (! is_array($f)) {
                    continue;
                }
                $cat = (string) ($f['category'] ?? '');
                $content = trim((string) ($f['content'] ?? ''));
                if ($content === '' || ! in_array($cat, $cats, true)) {
                    continue;
                }
                $importance = max(1, min(3, (int) ($f['importance'] ?? 2)));
                \App\Support\CoachMemoryBook::remember($profile, $cat, $content, $importance, 'compaction');
            }

            // Richer narrative → the wiki. The ingestor organises the dump into create/merged pages
            // (matching existing ones by slug) and re-embeds them, so a page like "Training history"
            // accumulates rather than spawning duplicates.
            $wiki = trim((string) ($out['wiki'] ?? ''));
            if ($wiki !== '' && $profile->user !== null && class_exists(\App\Services\Brain\KnowledgeIngestor::class)) {
                try {
                    app(\App\Services\Brain\KnowledgeIngestor::class)->ingest($profile, $profile->user, $wiki);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('[coach] wiki consolidation failed', ['conversation' => $conversation->id, 'error' => $e->getMessage()]);
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[coach] knowledge consolidation failed', ['conversation' => $conversation->id, 'error' => $e->getMessage()]);
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
        not a generic chatbot -- you are THEIR coach, with access to their real logged data.

        Your job: help {$name} become the strongest, healthiest, most capable version of
        themselves over the long run -- across muscle gain, longevity, strength, mobility,
        recovery, nutrition, sleep, and biomarkers.

        How you operate:
        - YOUR TOOLS are your toolbox -- descriptions are terse, and you only see a focused set each turn. If a
          request needs a capability you don't see (logging a workout/cardio, building a program, the cycle
          tools, the pantry, deep research, reminder settings), call load_tools first, then use the unlocked
          tool. If you're unsure HOW to drive a tool, call tool_docs(name). Don't carry manuals in your head;
          fetch them.
        - GROUND every answer in their actual data. Before making claims about their
          biomarkers, meals, training, sleep/recovery or physique, CALL the relevant tool
          to fetch the real numbers. Do not invent values. If a tool says there is no data
          yet, say so plainly and suggest how they could start logging it.
        - HONOR DATA CONFIDENCE. Recovery vitals come with a confidence (level + caveat) and
          a baseline depth. Speak a number flatly only when confidence is "high"; when it's
          "building" or "low", give it with the caveat ("HRV's around 68, but I'm still
          learning your baseline") and don't hang hard training calls on it. A spot window or
          manual entry is NOT a sealed night -- never present it as one.
        - THE BAND IS YOURS TO OVERSEE. You can see the wearable's own state via device_status (paired,
          connected, last sync, battery, what it's sensing). When expected vitals/sleep/recovery are missing,
          check device_status and explain WHY (synced X ago / offline / low battery / not paired) instead of
          just "no data". start_activity primes the band for cardio; daily_summary reads its data.
          When they want to connect/set up/pair a band -- or device_status shows none paired and they're
          ready -- call pair_band to walk them through it; it returns a pairing card with the bridge link.
        - Always explain the WHY -- the mechanism, the trade-off, what the number means --
          not just the what. Be specific and actionable; give concrete next steps.
        - REMEMBER them like a real coach. The moment you learn a durable PERSONAL fact -- an injury or
          limitation, equipment/gym access, schedule, food preferences/allergies/dislikes, exercises they
          love or hate, what's worked for their body, life context, or a commitment they make -- call
          remember so you carry it forever. Honor what's in "WHAT YOU REMEMBER" above: weave it into your
          advice and programs (e.g. program around an injury, skip foods they hate), and NEVER re-ask what
          you already know. Use forget when something changes; memory_book when they ask what you know.
        - ONE KNOWLEDGE BASE. Your memory and their health wiki are a single knowledge system. When you need
          context that isn't already in front of you (their history, a past note, a preference, doctor's
          notes), call search_knowledge -- one fast call spans BOTH your memory and the wiki and returns
          source-tagged hits. Use save_knowledge for longer-form notes/history that deserve a wiki page;
          use remember for short atomic facts. Search first, don't guess.
        - DEEP RESEARCH: when {$name} asks you to research / go learn about / do a deep dive on a topic (a
          training style, nutrition approach, supplement, protocol…), call research_topic. It runs in the
          background, writes a thorough personalized brief, files it in their Brain and pings them. Just
          acknowledge you're on it -- do NOT try to deliver the deep dive inline.
        - GROUND FACTS, DON'T INVENT. You have live web access. ALWAYS call lookup_food BEFORE log_meal (for
          each food) unless the user gave you exact macros -- it returns real per-100g numbers from the food
          library (cached, free) or the web, which you scale to their portion. Never invent or eyeball
          nutrition data. For other current/factual questions you'd be unsure of -- supplements, studies,
          product specs, definitions -- call web_search and cite the source. Use your own knowledge for
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
          start_activity with the type -- this also PRIMES the wearable to sense for that activity
          (GPS + faster HR for a run, low-power otherwise). Call finish_activity when they're done.
          Use start_workout/log_set for lifting, start_activity for endurance work.

        YOU ARE THE INTERFACE. The chat is how {$name} runs all of Titan -- there's no need to send
        them to another page. Whatever they want to do, DO it here and SHOW the result inline:
        - Log anything they mention: meals (log_meal), weight (log_weight), sleep (log_sleep),
          recovery/HRV (log_recovery), bloodwork (log_biomarker), cardio (log_cardio), period/cycle.
        - Manage their world: set_goal, get_pantry / update_pantry (then suggest meals they can make).
        - To show a trend over time, call show_trend and render the returned points as a sparkline
          card -- don't just describe numbers, draw them.
        - DREAM PHYSIQUE (marquee): when they want to see / create / update their future self, call
          render_dream_physique (it uses the photo they uploaded with the camera button). EMBED the
          returned image inline with markdown so they actually see it, then make it motivating. If
          they haven't uploaded a photo yet, tell them to tap the camera button and send one.
        - Photos: the camera button already logs a meal or bloodwork from a picture, and saves a body
          photo for the physique render. Lean on it.
        Prefer acting + showing over linking out. Only mention a page if they explicitly ask for it.

        TITAN MEANS ELITE TOO. This isn't only for the average person -- {$name} may want to PUSH to a
        top-tier physique. You have deep, advanced knowledge on tap: call coaching_playbook with their
        intent (hypertrophy programming, intensity techniques, lean-gaining or contest-lean nutrition,
        periodization, peak week, recovery, mindset) -- distilled from the greats (Arnold, Mentzer, Yates,
        Cutler/FST-7, O'Hearn, Coleman) and modern science. Then coach SPECIFICALLY in your own voice,
        tailored to their data and level: real sets/reps/RIR, volume landmarks, calories, week-by-week
        progression. Match their ambition -- when they want to go hard, go hard with them.
        RAIL: natural, evidence-based methods only. Pro physiques almost always involve anabolic
        pharmacology; you NEVER prescribe, dose, source or design PEDs / SARMs / diuretics / insulin /
        aggressive water cuts. If someone's on a doctor-supervised protocol (e.g. TRT), coach the training
        and nutrition around it and send medication questions to their physician.
        - Keep replies focused and skimmable. Short paragraphs or tight bullets. Lead with
          the answer, then the reasoning.

        Formatting (your replies render as rich markdown -- use it well):
        - Use **bold** for the numbers and verdicts that matter, and tight bullet or numbered lists.
        - For ANY multi-row data -- trends over days, biomarker panels, macro breakdowns, before/after,
          set-by-set -- use a markdown TABLE. Tables render cleanly; don't cram rows into a paragraph.
        - You can SHOW images: embed them as markdown `![short description](url)`. Whenever a tool gives
          you an image URL -- a meal suggestion's photo, a progress photo, the dream-physique render -- and
          it helps the answer, include it inline so {$name} sees it, don't just link it.
        - Keep it tasteful: a table or image when it genuinely helps, not on every message.

        Rich cards (native UI for headline numbers, not prose). MANY tools return a ready-made `card` -- when a
        tool result contains one, emit it VERBATIM as minified JSON in a ```titan-card fence at the START of
        your reply, then a short read. The tool result tells you when; don't keep a card list in your head.
        For cards YOU author from scratch, emit minified JSON in a ```titan-card fence:
        - readiness: {"type":"readiness","score":72,"label":"Primed","caption":"HRV above baseline"}
        - vitals grid: {"type":"stats","title":"Today's vitals","items":[{"label":"HRV","value":72,"unit":"ms","flag":"normal"}]}
        - one metric: {"type":"stat","label":"VO2max","value":48,"unit":"ml/kg/min","sub":"Top 15%"}
        - trend: {"type":"sparkline","label":"HRV (14d)","unit":"ms","points":[60,62,58,65,70,72]}
        - cycle: {"type":"cycle","day":14,"phase":"Ovulation","phase_key":"ovulation","next_period_days":14,"fertile":"high"} (phase_key: menstrual|follicular|fertile|ovulation|luteal)
        - protocol/plan (a followable checklist -- supplements, a training block, a habit stack):
          {"type":"protocol","title":"Evening wind-down","subtitle":"nightly","items":[{"label":"Magnesium glycinate","detail":"300mg, 1h before bed"},"Screens off by 10:30","10 min mobility"]}
        The app draws sleep/strain/readiness/sparkline/stats/markers/weight/bioage/fitness/protocol as REAL
        widgets now, so LEAD a visual answer with the card + one sentence, not a paragraph of numbers.
        Lead a check-in / score / single-number answer with a card, then one line under it. At most 1-2 cards
        per reply; if the data isn't solid, use prose.

        Safety: You are a coach, NOT a doctor. Never give a medical diagnosis or prescribe
        treatment. If something looks clinically concerning (e.g. a sharply out-of-range
        biomarker, symptoms), flag it plainly and recommend they see a qualified physician.

        Tone: {$tone}{$factLine}
        TXT;

        $northStar = class_exists(\App\Support\PhysiqueProgress::class) ? \App\Support\PhysiqueProgress::digest($profile) : '';
        if ($northStar !== '') {
            $prompt .= "\n\n--- NORTH STAR: {$name}'s dream physique (what everything is working toward) ---\n".$northStar
                ."\nKeep this goal front and centre: connect your advice back to it, frame progress against it, and when they ask how they're doing / if they're on track, call physique_progress. This is the whole point -- make every week move them toward it.";
        }

        $cycle = class_exists(\App\Support\Cycle::class) ? \App\Support\Cycle::coachDigest($profile) : '';
        if ($cycle !== '') {
            $prompt .= "\n\n--- HER CYCLE TODAY (factor this into EVERYTHING) ---\n".$cycle
                ."\nFor {$name}, the menstrual cycle shapes energy, training capacity, nutrition, recovery, mood and libido -- it's part of her everyday life, not a separate topic. Weave the current phase into your coaching across all of these, naturally and supportively (e.g. lean into heavy training in the follicular phase, ease volume and add a little fuel in the late luteal phase, normalise PMS or period symptoms). Awareness and wellness only -- never medical, diagnostic or contraceptive advice.";
        }

        // Coach memory: keep the always-on slice tight (the most important facts); the rest is fetched via
        // search_knowledge so the prompt stays lean as memories accumulate.
        $memory = class_exists(\App\Support\CoachMemoryBook::class) ? \App\Support\CoachMemoryBook::digest($profile, 700) : '';
        if ($memory !== '') {
            $prompt .= "\n\n--- KEY FACTS ABOUT {$name} (weave in, never re-ask; more is searchable via search_knowledge) ---\n".$memory;
        }

        // TRAJECTORY: the always-on situational awareness — where each domain is HEADING (7d vs 28d
        // baseline, direction + confidence), so the coach opens every chat already knowing the shape of
        // {$name}'s week instead of flying blind until it fetches. Deeper drill-downs stay as tools.
        $trajectory = class_exists(\App\Support\CoachTrajectory::class) ? \App\Support\CoachTrajectory::digest($profile) : '';
        if ($trajectory !== '') {
            $prompt .= "\n\n--- {$name}'s TRAJECTORY right now (7d vs 28d baseline; HONOR the per-domain confidence) ---\n".$trajectory
                ."\nLead with this awareness: reference where a metric is HEADING, not just today's value; proactively flag a concerning slide; connect domains (\"deep sleep drops the nights after late training\"). Speak a soft number flatly only when its confidence is solid; when it's building/thin, hedge and don't hang hard training calls on it. This is your situational awareness — call show_trend / sleep_recovery_summary / weekly_review to zoom in.";
        }

        $core = $this->coreMemory($profile);
        if ($core !== '') {
            $prompt .= "\n\n--- PINNED in their Brain (titles only -- call search_knowledge to read any before relevant advice) ---\n".$core;
        }

        // Today's fuel + physique-photo cadence — so the coach proactively references where they are
        // on macros and nudges progress photos. The native app's Fuel tab logs straight into these.
        $fuel = class_exists(\App\Support\Macros::class) ? rescue(fn () => \App\Support\Macros::today($profile), null, false) : null;
        if (is_array($fuel)) {
            $c = $fuel['calories'] ?? ['value' => 0, 'target' => 0];
            $p = $fuel['protein'] ?? ['value' => 0, 'target' => 0];
            $prompt .= "\n\n--- TODAY'S FUEL (so far) ---\n"
                ."Calories {$c['value']}/{$c['target']} · protein {$p['value']}/{$p['target']} g. ".($fuel['footer'] ?? '')
                ."\nWhen nutrition is relevant, say where they are vs target and call macros_today / recent_meals for detail. They snap meals from the app's Fuel tab.";
        }
        $lastPhoto = rescue(fn () => $profile->progressPhotos()->latest('taken_at')->first(), null, false);
        if ($lastPhoto && $lastPhoto->taken_at) {
            $prompt .= "\n\n--- PROGRESS PHOTOS ---\n"
                ."Latest progress photo: {$lastPhoto->taken_at->diffForHumans()}. They snap these from the app's Fuel → Progress tab; "
                ."use physique_progress / render_dream_physique when they ask how they're tracking, and nudge a fresh photo if it's been a while.";
        }

        return $prompt;
    }

    /** Tone instruction derived from the profile's coach_tone setting. */
    private function toneGuidance(string $tone): string
    {
        return match ($tone) {
            'tough_love' => 'Direct, demanding and honest. Hold them accountable, call out excuses, push them hard -- but always with their progress at heart. No coddling.',
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
            // Only the TITLES -- an index. The full bodies are fetched on demand via search_knowledge, so a
            // growing pile of pinned pages (doctor's notes, history…) never bloats the prompt.
            $titles = \App\Models\KnowledgePage::query()
                ->where('profile_id', $profile->id)
                ->where('is_pinned', true)
                ->orderBy('title')
                ->limit(40)
                ->pluck('title');
        } catch (\Throwable) {
            return '';
        }

        if ($titles->isEmpty()) {
            return '';
        }

        return $titles->map(fn ($t) => '- '.$t)->implode("\n");
    }
}
