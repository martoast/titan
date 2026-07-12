<?php

namespace App\Support;

use App\Models\Profile;
use App\Services\Ai\AiService;

/**
 * COACH v2 · Phase 4 — PROACTIVE INTELLIGENCE. The ReactTo* moments (morning sleep note, workout
 * celebration…) used to post hand-written templated copy — a rules engine, not a coach. This grounds
 * a reaction in the user's TRAJECTORY (the Phase-1 digest): it reasons over where recovery/sleep/
 * training/nutrition are HEADING plus the moment's facts, and writes a short, specific note in the
 * coach's voice. Card-led (the caller passes the moment's card, rendered above the prose by the Phase-2
 * widgets) and always safe: if the model is unconfigured or errors, it falls back to the caller's
 * template, so a reaction is never dropped. Rate-limiting stays with each ReactTo* job's own per-event
 * dedup — this only shapes the copy.
 */
class CoachReaction
{
    /**
     * A trajectory-grounded reaction for `$moment` (e.g. "last night's sleep"), composed card-first.
     * `$facts` is the moment's key data; `$fallback` is the templated copy used verbatim if the model
     * is unavailable. Returns the fenced card (if any) + the grounded prose (or the fallback).
     *
     * @param  array<string,mixed>|null  $card
     */
    public static function ground(Profile $profile, string $moment, string $facts, string $fallback, ?array $card = null): string
    {
        $prose = trim((string) rescue(fn () => self::generate($profile, $moment, $facts), '', false));

        return self::compose($card, $prose !== '' ? $prose : $fallback);
    }

    private static function generate(Profile $profile, string $moment, string $facts): string
    {
        $ai = app(AiService::class);
        if (! $ai->configured()) {
            return '';
        }
        $trajectory = trim((string) rescue(fn () => CoachTrajectory::digest($profile, 900), '', false));
        $name = $profile->display_name ?: 'them';

        $system = <<<TXT
        You are {$name}'s personal health coach writing a SHORT, proactive check-in about their {$moment}.
        Ground it in their TRAJECTORY below — reference where a metric is HEADING (not just today's value),
        connect domains when the link is real (e.g. "your deep sleep dips the nights after late training"),
        and give ONE specific, useful insight or nudge toward their goal. HONOR the per-domain confidence:
        hedge soft numbers, and never hang a hard training call on "building"/"thin" data. 2–4 sentences,
        warm and direct — their coach, not a bot. A card with the numbers is already shown ABOVE your text,
        so interpret them, don't restate them. End by inviting a quick reply.
        TXT;

        if (($profile->primary_language ?? 'en') === 'es-MX') {
            $system .= "\n\nLANGUAGE: Write ENTIRELY in natural MEXICAN Spanish (español de México) — "
                ."warm, direct, Tijuana-friendly, using the informal \"tú\". Metric names/units stay as-is "
                ."(HRV, bpm), but every sentence is Spanish.";
        }

        $user = "MOMENT: {$facts}\n\nTRAJECTORY (7d vs 28d):\n"
            .($trajectory !== '' ? $trajectory : '(not much history yet — keep it light and encouraging)');

        return $ai->chat([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ], ['model' => config('services.openai.fast_model'), 'max_tokens' => 280]);
    }

    /** @param array<string,mixed>|null $card */
    private static function compose(?array $card, string $body): string
    {
        if (! empty($card)) {
            return "```titan-card\n".json_encode($card, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n```\n\n".$body;
        }

        return $body;
    }
}
