<?php

namespace App\Support;

use App\Models\CoachMemory;
use App\Models\Profile;
use Illuminate\Support\Carbon;

/**
 * The coach's working memory of a person. Captures durable facts (deduping near-duplicates so the
 * book stays tight), serves an always-on digest into the system prompt so the coach never re-asks,
 * and powers the "what do you remember about me?" card. This is what makes Titan feel like a coach
 * who actually knows you, not a chatbot meeting you for the first time every session.
 */
class CoachMemoryBook
{
    /** Remember a durable fact. Updates a near-duplicate instead of piling on. */
    public static function remember(Profile $profile, string $category, string $content, int $importance = 2, string $source = 'coach'): ?CoachMemory
    {
        $content = trim(preg_replace('/\s+/', ' ', $content) ?? '');
        if ($content === '') {
            return null;
        }
        $category = isset(CoachMemory::CATEGORIES[$category]) ? $category : 'misc';
        $importance = max(1, min(3, $importance));
        $norm = self::norm($content);

        foreach ($profile->coachMemories()->active()->where('category', $category)->get() as $existing) {
            $eNorm = self::norm($existing->content);
            $pct = 0.0;
            similar_text($eNorm, $norm, $pct);
            if ($pct >= 80 || str_contains($eNorm, $norm) || str_contains($norm, $eNorm)) {
                $existing->update([
                    'content' => strlen($content) >= strlen($existing->content) ? $content : $existing->content,
                    'importance' => max($existing->importance, $importance),
                    'last_referenced_at' => Carbon::now(),
                ]);

                return $existing;
            }
        }

        return $profile->coachMemories()->create([
            'category' => $category,
            'content' => $content,
            'importance' => $importance,
            'source' => $source,
            'last_referenced_at' => Carbon::now(),
        ]);
    }

    /** Archive ("forget") memories matching a free-text description. Returns how many were removed. */
    public static function forget(Profile $profile, string $query): int
    {
        $q = self::norm($query);
        if ($q === '') {
            return 0;
        }
        $removed = 0;
        foreach ($profile->coachMemories()->active()->get() as $m) {
            $mNorm = self::norm($m->content);
            $pct = 0.0;
            similar_text($mNorm, $q, $pct);
            if ($pct >= 55 || str_contains($mNorm, $q) || str_contains($q, $mNorm)) {
                $m->update(['archived_at' => Carbon::now()]);
                $removed++;
            }
        }

        return $removed;
    }

    /** Active memories, most important + most recently relevant first. */
    public static function active(Profile $profile)
    {
        return $profile->coachMemories()->active()
            ->orderByDesc('importance')
            ->orderByDesc('last_referenced_at')
            ->orderByDesc('id')
            ->get();
    }

    /** Always-on digest for the system prompt -- grouped by category, capped so it never bloats. */
    public static function digest(Profile $profile, int $maxChars = 1600): string
    {
        $memories = self::active($profile);
        if ($memories->isEmpty()) {
            return '';
        }

        $lines = [];
        foreach (CoachMemory::CATEGORIES as $key => [$label, $emoji]) {
            $items = $memories->where('category', $key)->pluck('content')->all();
            if ($items === []) {
                continue;
            }
            $lines[] = "{$emoji} {$label}: ".implode('; ', $items);
        }
        $text = implode("\n", $lines);

        if (strlen($text) > $maxChars) {
            $text = rtrim(substr($text, 0, $maxChars))."\n(…more in their memory book)";
        }

        return $text;
    }

    /** Card payload for "what do you remember about me?". */
    public static function card(Profile $profile): array
    {
        $memories = self::active($profile);
        $groups = [];
        foreach (CoachMemory::CATEGORIES as $key => [$label, $emoji]) {
            $items = $memories->where('category', $key)->pluck('content')->values()->all();
            if ($items !== []) {
                $groups[] = ['label' => $label, 'emoji' => $emoji, 'items' => $items];
            }
        }

        return ['type' => 'memory', 'count' => $memories->count(), 'groups' => $groups];
    }

    private static function norm(string $s): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtolower($s)) ?? '');
    }
}
