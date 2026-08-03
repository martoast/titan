<?php

namespace App\Services\Coach;

use App\Exceptions\AiException;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\Profile;
use App\Services\Ai\AiService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The PROACTIVE side of the coach. Where CoachService answers questions the user asks,
 * this service speaks first: a short, specific, grounded morning briefing and an evening
 * nudge -- the "behavior-triggered push" the research report calls out as one of the
 * highest-leverage retention mechanics.
 *
 * Every line is grounded in the profile's REAL data (last night's recovery + sleep,
 * yesterday's meals + training) and their pinned brain pages. We never invent numbers,
 * never make medical claims, and degrade to a plain data summary if the AI is offline.
 * Tone follows $profile->coach_tone.
 *
 * Each generated briefing is persisted as an assistant ChatMessage in a dedicated
 * "Daily Briefings" conversation so it surfaces in the coach UI alongside chat history.
 */
class CoachBriefingService
{
    /**
     * The title of the pre-day-chats thread that used to hold every proactive briefing. Briefings
     * now land in the day's own chat; this survives only so the backfill command can recognise the
     * old thread.
     *
     * @deprecated Use {@see \App\Models\Conversation::forDay()}.
     */
    public const BRIEFINGS_TITLE = 'Daily Briefings';

    public function __construct(protected AiService $ai) {}

    /**
     * Generate, persist and return this profile's morning briefing -- readiness/HRV/RHR
     * from last night, sleep, yesterday's nutrition + training, grounded against the brain.
     *
     * @throws AiException when the AI service fails (caller decides how to surface it).
     */
    public function morningBriefing(Profile $profile): string
    {
        $facts = $this->gather($profile);
        $message = $this->compose($profile, 'morning', $facts);
        $this->persist($profile, $message);

        return $message;
    }

    /**
     * Generate, persist and return this profile's evening nudge -- what's still open today
     * (e.g. a protein gap), framed as one concrete, easy closing action.
     *
     * @throws AiException when the AI service fails.
     */
    public function eveningNudge(Profile $profile): string
    {
        $facts = $this->gather($profile);
        $message = $this->compose($profile, 'evening', $facts);
        $this->persist($profile, $message);

        return $message;
    }

    // ---- Composition ----------------------------------------------------------

    /**
     * Ask the model for the briefing, grounded in the gathered data. On AI failure this
     * bubbles AiException; for an empty/blank answer we fall back to a deterministic,
     * data-only summary so the user always gets something real.
     *
     * @param  array<string,mixed>  $facts
     */
    private function compose(Profile $profile, string $kind, array $facts): string
    {
        $name = $profile->display_name ?: ($profile->user?->name ?? 'there');
        $tone = $this->toneGuidance($profile->coach_tone ?? 'balanced');
        $core = $this->coreMemory($profile);

        $intent = $kind === 'morning'
            ? <<<'TXT'
            Write a MORNING briefing (2-4 short sentences). Open with how they are TODAY
            (readiness/HRV/resting HR vs their own baseline and last night's sleep), connect
            it to yesterday if the data supports it (e.g. a late or low-protein dinner, a hard
            session), then give ONE clear, specific focus for today (intensity guidance + a
            concrete nutrition target like a protein number). Be supportive and motivating.
            TXT
            : <<<'TXT'
            Write an EVENING nudge (1-3 short sentences). Look at where today stands so far --
            especially any gap to their nutrition target (e.g. protein remaining) or a missed
            session -- and suggest ONE concrete, easy action to close it tonight
            (e.g. "you're 40g protein short -- a cup of Greek yogurt closes it"). Encouraging,
            never nagging.
            TXT;

        $system = <<<TXT
        You are Titan, {$name}'s personal AI health and physique coach, writing a PROACTIVE
        push notification / briefing -- they did NOT ask a question; you are reaching out.

        Hard rules:
        - GROUND every statement in the DATA provided below. Quote their real numbers.
          Never invent a value. If a metric is missing, simply don't mention it.
        - RESPECT DATA CONFIDENCE. A recovery read carries a `confidence` (level + caveat) and
          the baseline carries `sufficient`. Only state a number flatly when confidence is
          "high". When it's "building" or "low", give the number WITH its caveat in plain words
          (e.g. "HRV's around 68 -- still learning your baseline, so don't read too much into it")
          and soften any "vs baseline" comparison when `sufficient` is false. Never present a
          manual estimate or a single spot window as if it were a sealed night's recovery.
        - Be SPECIFIC and actionable, not generic. No filler, no "good morning champion!"
          fluff -- lead with a real number that matters.
        - Keep it SHORT -- this is a glanceable message, not an essay. No headings, no bullet
          lists, no markdown. Plain sentences. Address them by name at most once.
        - You are a coach, NOT a doctor. Never diagnose or make medical claims. If a number
          looks clinically concerning, gently suggest they check with a physician -- don't alarm.

        Tone: {$tone}

        {$intent}
        TXT;

        if ($core !== '') {
            $system .= "\n\n--- PINNED FACTS about {$name} (honor these) ---\n".$core;
        }

        $cycle = class_exists(\App\Support\Cycle::class) ? \App\Support\Cycle::coachDigest($profile) : '';
        if ($cycle !== '') {
            $system .= "\n\n--- HER CYCLE TODAY (work it into the briefing -- phase shapes energy, training & nutrition) ---\n".$cycle;
        }

        if (($profile->primary_language ?? 'en') === 'es-MX') {
            $system .= "\n\n--- LANGUAGE: WRITE IN MEXICAN SPANISH ---\n"
                ."{$name} uses Titan in Spanish. Write this ENTIRE briefing in natural MEXICAN Spanish "
                ."(español de México) — warm, direct, Tijuana-friendly, Mexican vocabulary and the informal "
                ."\"tú\". Everything they read must be Spanish. Keep it plain (no markdown). Units and metric "
                ."names stay as-is (HRV, bpm, g de proteína), but every sentence around them is Spanish.";
        }

        $dataBlock = "Here is {$name}'s real data right now:\n".json_encode(
            $facts,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        try {
            $answer = $this->ai->chat(
                [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $dataBlock],
                ],
                ['temperature' => 0.5, 'max_tokens' => 320],
            );
        } catch (AiException $e) {
            // Last resort: a real, data-only summary so the push is never empty.
            $fallback = $this->dataOnlySummary($name, $kind, $facts);
            if ($fallback !== '') {
                return $fallback;
            }
            throw $e;
        }

        $answer = trim($answer);
        if ($answer === '') {
            return $this->dataOnlySummary($name, $kind, $facts)
                ?: "Morning {$name} -- log today's recovery, meals and training so I can give you a sharper briefing tomorrow.";
        }

        return $answer;
    }

    /**
     * Deterministic, grounded fallback if the model is unavailable or silent. Plain data --
     * no invented coaching, just the facts we have.
     *
     * @param  array<string,mixed>  $facts
     */
    private function dataOnlySummary(string $name, string $kind, array $facts): string
    {
        $bits = [];
        $rec = $facts['last_night']['recovery'] ?? null;
        $sleep = $facts['last_night']['sleep'] ?? null;
        $meals = $facts['yesterday']['nutrition'] ?? null;

        if ($kind === 'morning') {
            if (is_array($rec)) {
                $parts = [];
                if (isset($rec['hrv_ms'])) {
                    $parts[] = "HRV {$rec['hrv_ms']}ms";
                }
                if (isset($rec['resting_hr'])) {
                    $parts[] = "resting HR {$rec['resting_hr']}bpm";
                }
                if ($parts) {
                    // Honour confidence even in the deterministic fallback -- never overstate a shaky read.
                    $caveat = $rec['confidence']['caveat'] ?? null;
                    $level = $rec['confidence']['level'] ?? 'high';
                    $line = 'Last night: '.implode(', ', $parts).'.';
                    if ($caveat && $level !== 'high') {
                        $line .= ' ('.rtrim($caveat, '.').'.)';
                    }
                    $bits[] = $line;
                }
            }
            if (is_array($sleep) && isset($sleep['duration_label'])) {
                $bits[] = "You slept {$sleep['duration_label']}.";
            }
            if (is_array($meals) && isset($meals['protein_g'])) {
                $bits[] = "Yesterday you hit {$meals['protein_g']}g protein.";
            }
        } else {
            if (is_array($facts['today']['nutrition'] ?? null) && isset($facts['today']['nutrition']['protein_g'])) {
                $bits[] = "So far today: {$facts['today']['nutrition']['protein_g']}g protein, {$facts['today']['nutrition']['calories']} kcal.";
            }
        }

        if ($bits === []) {
            return '';
        }

        return implode(' ', $bits);
    }

    // ---- Data gathering -------------------------------------------------------

    /**
     * Assemble the grounding payload: last night's recovery + sleep, yesterday's and
     * today's nutrition, recent training, and which pinned brain pages exist. Every read
     * is class_exists / try-guarded so the briefing works before every vertical is wired.
     *
     * @return array<string,mixed>
     */
    private function gather(Profile $profile): array
    {
        $tz = config('app.timezone', 'UTC');
        $today = Carbon::now($tz)->startOfDay();
        $yesterday = $today->copy()->subDay();

        return [
            'generated_at' => Carbon::now($tz)->toDateTimeString(),
            'profile' => array_filter([
                'name' => $profile->display_name ?: $profile->user?->name,
                'primary_goal' => $profile->primary_goal,
                'sex' => $profile->sex,
            ]),
            'last_night' => [
                'recovery' => $this->latestRecovery($profile),
                'sleep' => $this->latestSleep($profile),
            ],
            'yesterday' => [
                'nutrition' => $this->nutritionForDay($profile, $yesterday),
                'training' => $this->trainingForDay($profile, $yesterday),
            ],
            'today' => [
                'nutrition' => $this->nutritionForDay($profile, $today),
            ],
            'recovery_baseline_14d' => $this->recoveryBaseline($profile),
        ];
    }

    /** Most recent recovery snapshot (HRV, resting HR, stress, soreness, etc.) + a confidence read. */
    private function latestRecovery(Profile $profile): ?array
    {
        if (! class_exists(\App\Models\RecoveryLog::class)) {
            return null;
        }
        try {
            // Prefer a real sealed overnight read (or a provider summary) from the last few days
            // over a noisier daytime window that merely happens to be the newest row. Fall back
            // to whatever is latest so a fresh user still gets something -- with low confidence.
            $base = \App\Models\RecoveryLog::query()->where('profile_id', $profile->id);
            $r = (clone $base)
                ->where(fn ($q) => $q->where('updated_via', 'like', 'biosignal:sealed%')->orWhere('updated_via', 'like', 'device:summary%'))
                ->whereDate('logged_at', '>=', Carbon::today()->subDays(3))
                ->orderByDesc('logged_at')->orderByDesc('id')->first()
                ?? (clone $base)->orderByDesc('logged_at')->orderByDesc('id')->first();
        } catch (\Throwable) {
            return null;
        }
        if (! $r) {
            return null;
        }

        $confidence = class_exists(\App\Support\RecoveryConfidence::class)
            ? \App\Support\RecoveryConfidence::assess($profile, $r)
            : null;

        return array_filter([
            'date' => optional($r->logged_at)->toDateString(),
            'hrv_ms' => $r->hrv_ms,
            'resting_hr' => $r->resting_hr,
            'stress' => $r->stress,
            'soreness' => $r->soreness,
            'mood' => $r->mood,
            'energy' => $r->energy,
            // The coach must phrase numbers according to this -- see the CONFIDENCE rule in the prompt.
            'confidence' => $confidence ? array_filter([
                'level' => $confidence['level'],
                'source' => $confidence['source'],
                'nights_of_data' => $confidence['nights'],
                'caveat' => $confidence['note'],
            ], fn ($v) => $v !== null) : null,
        ], fn ($v) => $v !== null);
    }

    /** Most recent sleep night (duration, quality, stage split). */
    private function latestSleep(Profile $profile): ?array
    {
        if (! class_exists(\App\Models\SleepLog::class)) {
            return null;
        }
        try {
            $s = \App\Models\SleepLog::query()->nights()->final()
                ->where('profile_id', $profile->id)
                ->orderByDesc('slept_at')->orderByDesc('id')->first();
        } catch (\Throwable) {
            return null;
        }
        if (! $s) {
            return null;
        }

        return array_filter([
            'date' => optional($s->slept_at)->toDateString(),
            'duration_min' => $s->duration_min,
            'duration_label' => method_exists($s, 'durationLabel') ? $s->durationLabel() : null,
            'quality' => $s->quality,
            'deep_min' => $s->deep_min,
            'rem_min' => $s->rem_min,
            'bedtime' => $s->bedtime,
            'wake_time' => $s->wake_time,
            // A low-signal night (poor band contact → mostly NODATA): the briefing must caveat it as an
            // estimate and nudge a fit check, not lead with a confident-but-wrong short duration.
            'low_confidence' => (bool) $s->low_confidence ?: null,
            'coverage_pct' => $s->low_confidence && $s->coverage !== null ? round($s->coverage * 100) : null,
        ], fn ($v) => $v !== null);
    }

    /** Macro totals for a single day, or null when nothing was logged. */
    private function nutritionForDay(Profile $profile, Carbon $day): ?array
    {
        if (! class_exists(\App\Models\Meal::class)) {
            return null;
        }
        try {
            $meals = \App\Models\Meal::query()
                ->where('profile_id', $profile->id)
                ->whereBetween('eaten_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
                ->get();
        } catch (\Throwable) {
            return null;
        }
        if ($meals->isEmpty()) {
            return null;
        }

        return [
            'date' => $day->toDateString(),
            'meals' => $meals->count(),
            'calories' => (int) $meals->sum('calories'),
            'protein_g' => round((float) $meals->sum('protein_g'), 1),
            'carbs_g' => round((float) $meals->sum('carbs_g'), 1),
            'fat_g' => round((float) $meals->sum('fat_g'), 1),
            'last_meal_at' => optional($meals->sortByDesc('eaten_at')->first()?->eaten_at)->toDateTimeString(),
        ];
    }

    /** Training summary for a single day, or null when none was logged. */
    private function trainingForDay(Profile $profile, Carbon $day): ?array
    {
        if (! class_exists(\App\Models\Workout::class)) {
            return null;
        }
        try {
            $workouts = \App\Models\Workout::query()
                ->where('profile_id', $profile->id)
                ->whereBetween('performed_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
                ->get();
        } catch (\Throwable) {
            return null;
        }
        if ($workouts->isEmpty()) {
            return null;
        }

        return [
            'date' => $day->toDateString(),
            'sessions' => $workouts->count(),
            'names' => $workouts->pluck('name')->filter()->values()->all(),
            'total_duration_min' => (int) $workouts->sum('duration_min'),
        ];
    }

    /** 14-day averages so the model can say "vs your baseline" without guessing. */
    private function recoveryBaseline(Profile $profile): ?array
    {
        if (! class_exists(\App\Models\RecoveryLog::class)) {
            return null;
        }
        try {
            $rows = \App\Models\RecoveryLog::query()
                ->where('profile_id', $profile->id)
                ->orderByDesc('logged_at')->take(14)->get();
        } catch (\Throwable) {
            return null;
        }
        if ($rows->count() < 2) {
            return null;
        }

        $avg = function (string $key) use ($rows): ?float {
            $vals = $rows->pluck($key)->filter(fn ($v) => is_numeric($v));

            return $vals->isNotEmpty() ? round((float) $vals->avg(), 1) : null;
        };

        return array_filter([
            'avg_hrv_ms' => $avg('hrv_ms'),
            'avg_resting_hr' => $avg('resting_hr'),
            'nights' => $rows->count(),
            // Below ~2 weeks the baseline is still settling -- the coach should hedge "vs baseline" claims.
            'sufficient' => $rows->count() >= (class_exists(\App\Support\RecoveryConfidence::class) ? \App\Support\RecoveryConfidence::FULL_BASELINE : 14),
        ], fn ($v) => $v !== null);
    }

    // ---- Persistence ----------------------------------------------------------

    /** Append the briefing as an assistant message in the day's chat, where the user will see it. */
    private function persist(Profile $profile, string $content): ChatMessage
    {
        return $this->briefingsConversation($profile)
            ->messages()
            ->create([
                'role' => 'assistant',
                'kind' => ChatMessage::KIND_BRIEFING,
                'content' => $content,
            ]);
    }

    /** The conversation a briefing belongs in: this profile's chat for the current local day. */
    public function briefingsConversation(Profile $profile): Conversation
    {
        return Conversation::forDay($profile);
    }

    /** The latest stored briefing for the coach UI card, or null if none yet. */
    public function latestBriefing(Profile $profile): ?ChatMessage
    {
        return ChatMessage::query()
            ->whereIn('conversation_id', $profile->conversations()->select('id'))
            ->where('role', 'assistant')
            ->where('kind', ChatMessage::KIND_BRIEFING)
            ->latest('id')
            ->first();
    }

    // ---- Shared tone + core-memory (mirrors CoachService) ----------------------

    private function toneGuidance(string $tone): string
    {
        return match ($tone) {
            'tough_love' => 'Direct, demanding and honest. Hold them accountable and push them -- but always with their progress at heart. No coddling, no fluff.',
            'gentle' => 'Warm, patient and encouraging. Celebrate small wins, never shame, build momentum gently.',
            default => 'Balanced: supportive but straight-talking. Encourage progress and be honest, without being harsh.',
        };
    }

    /** Pinned KnowledgePages = core memory; guarded so it works before the Brain ships. */
    private function coreMemory(Profile $profile): string
    {
        if (! class_exists(\App\Models\KnowledgePage::class)) {
            return '';
        }
        try {
            $pages = \App\Models\KnowledgePage::query()
                ->where('profile_id', $profile->id)
                ->where('is_pinned', true)
                ->orderBy('title')->get();
        } catch (\Throwable) {
            return '';
        }
        if ($pages->isEmpty()) {
            return '';
        }

        return $pages->map(fn ($p) => '## '.$p->title."\n".Str::limit(trim((string) $p->content), 800))
            ->implode("\n\n");
    }
}
