<?php

namespace App\Support;

use App\Models\ActivitySession;
use App\Models\Profile;
use App\Services\Ai\AiService;
use Illuminate\Support\Carbon;

/**
 * COACH v2 · Phase 3 — LIVING KNOWLEDGE. The wiki used to grow only from CHAT (consolidateKnowledge at
 * conversation compaction), so between long chats it stayed sparse. This makes it grow from the user's
 * DATA: once a week it reads the trajectory + the week's notable events and asks the model to distill
 * only the DURABLE, high-signal knowledge — patterns ("deep sleep −22% the nights after 9pm+ workouts"),
 * milestones (PRs, streaks, goals hit), meaningful biomarker/body shifts, what's working — as wiki-ready
 * prose. The command files it through the existing KnowledgeIngestor (append-safe, slug-deduped,
 * re-embedded) tagged as auto-synthesis, so over weeks the coach accumulates a real model of the user
 * without depending on it remembering to call `remember`.
 */
class KnowledgeEnricher
{
    public function __construct(protected AiService $ai) {}

    /**
     * The DATA brief handed to the model: the always-on trajectory (P1) plus a compact "this week"
     * events line (sessions, nights logged, weight move, fresh bloodwork). Pure — no AI, no writes — so
     * it's cheap to test and safe to log. Empty string when there's nothing worth synthesizing.
     */
    public static function brief(Profile $profile): string
    {
        $parts = [];

        $trajectory = rescue(fn () => CoachTrajectory::digest($profile, 1600), '', false);
        if ($trajectory !== '') {
            $parts[] = "TRAJECTORY (7d vs 28d):\n".$trajectory;
        }

        $events = rescue(fn () => self::weekEvents($profile), [], false);
        if ($events !== []) {
            $parts[] = "THIS WEEK:\n- ".implode("\n- ", $events);
        }

        return implode("\n\n", $parts);
    }

    /**
     * Distil the week's data into durable wiki prose, or null when the model is unconfigured, the brief
     * is too thin, or nothing this week rises to durable knowledge. A provenance line (source + week) is
     * appended so every auto-page is identifiable and dated.
     */
    public function synthesize(Profile $profile): ?string
    {
        if (! $this->ai->configured()) {
            return null;
        }
        $brief = self::brief($profile);
        // Need at least the trajectory AND some events — a single lonely metric isn't a pattern.
        if (strlen($brief) < 80) {
            return null;
        }

        $out = rescue(fn () => $this->ai->json([
            ['role' => 'system', 'content' => self::PROMPT],
            ['role' => 'user', 'content' => "This week's data for the person:\n\n".$brief],
        ], ['model' => config('services.openai.fast_model'), 'temperature' => 0.3, 'max_tokens' => 700]), null, false);

        $knowledge = trim((string) ($out['knowledge'] ?? ''));
        if ($knowledge === '' || strlen($knowledge) < 40) {
            return null;   // the model found nothing durable this week — don't file noise
        }

        $tz = $profile->settings['timezone'] ?? config('app.timezone', 'UTC');
        $week = Carbon::now($tz)->startOfWeek()->toDateString();

        return $knowledge."\n\n_Source: auto-synthesis from your data · week of {$week}._";
    }

    private const PROMPT = <<<'PROMPT'
    You are the analyst for a person's long-term health wiki. From this week's DATA (their trajectory +
    notable events), extract ONLY durable, high-signal knowledge worth a PERMANENT wiki page — the kind of
    thing a great coach would write down about someone. Look for:
    - PATTERNS: a repeatable relationship across domains, WITH the evidence ("deep sleep runs ~20% lower
      the nights after late (9pm+) training, seen 5×").
    - MILESTONES: PRs, streaks, a goal hit or newly on-track, a biomarker moving into range.
    - MEANINGFUL SHIFTS: a real body/recovery/biomarker change (not day-to-day noise).
    - WHAT'S WORKING (or not) toward their goals.
    Rules: be CONSERVATIVE — high-signal only; skip anything transient, obvious, or already day-to-day. Cite
    the actual numbers as evidence. Do NOT invent data not in the brief. Do NOT give medical advice. Write
    as organised markdown prose (a short heading per insight) suitable to file in the wiki. If NOTHING this
    week rises to durable knowledge, return an empty string.
    Return ONLY JSON: {"knowledge": "..."}.
    PROMPT;

    /**
     * A compact list of the week's notable events for the brief.
     *
     * @return array<int,string>
     */
    private static function weekEvents(Profile $profile): array
    {
        $out = [];
        $since = Carbon::now()->subDays(7);

        $sessions = ActivitySession::where('profile_id', $profile->id)->training()
            ->where('started_at', '>=', $since)->get(['activity_type']);
        if ($sessions->isNotEmpty()) {
            $byKind = $sessions->groupBy(fn ($s) => $s->activity_type ?: 'session')->map->count()
                ->map(fn ($n, $k) => "{$n} {$k}")->values()->implode(', ');
            $out[] = "Training sessions: {$sessions->count()} ({$byKind})";
        }

        if (class_exists(\App\Models\SleepLog::class)) {
            $nights = rescue(fn () => \App\Models\SleepLog::where('profile_id', $profile->id)
                ->nights()->final()->where('slept_at', '>=', $since->toDateString())->count(), 0, false);
            if ($nights > 0) {
                $out[] = "Nights of sleep logged: {$nights}";
            }
        }

        if (class_exists(\App\Models\BiomarkerReading::class)) {
            $fresh = rescue(fn () => $profile->biomarkerReadings()
                ->where('taken_at', '>=', Carbon::now()->subDays(30))->count(), 0, false);
            if ($fresh > 0) {
                $out[] = "New bloodwork markers logged (last 30d): {$fresh}";
            }
        }

        if (class_exists(WeightTrend::class)) {
            $rate = rescue(fn () => WeightTrend::weeklyRateKg($profile), null, false);
            if ($rate !== null && abs($rate) >= 0.05) {
                $out[] = 'Weight moving '.($rate < 0 ? '↓' : '↑').abs(round($rate, 2)).' kg/wk';
            }
        }

        return $out;
    }
}
