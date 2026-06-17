<?php

namespace App\Services\Brain;

use App\Models\KnowledgePage;
use App\Models\Profile;
use App\Services\Ai\AiService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * Hybrid (semantic + keyword) search over a profile's health wiki. Embeddings are
 * cached per page and refreshed lazily — the first search after a page edit
 * re-embeds just the changed pages (matched by content hash), so there's no
 * separate index to keep in sync. Brute-force cosine in PHP is fine at one
 * profile's scale. Degrades to keyword-only when OpenAI isn't configured.
 */
class KnowledgeSearch
{
    /** Blend weights: meaning matters most, but exact-term hits still count. */
    private const W_SEMANTIC = 0.65;

    private const W_KEYWORD = 0.35;

    public function __construct(protected AiService $ai) {}

    public function configured(): bool
    {
        return $this->ai->configured();
    }

    /** All of a profile's pages. */
    private function pagesFor(Profile $profile): Collection
    {
        return KnowledgePage::query()
            ->where('profile_id', $profile->id)
            ->get();
    }

    /**
     * Make sure the given pages have a current embedding. Re-embeds only those
     * whose content changed (hash mismatch). Best-effort — never throws.
     */
    public function ensureIndexed(Collection $pages): void
    {
        if (! $this->configured()) {
            return;
        }
        $stale = $pages->filter(fn (KnowledgePage $p) => empty($p->embedding) || $p->embed_hash !== $p->contentHash())->values();
        if ($stale->isEmpty()) {
            return;
        }

        try {
            foreach ($stale->chunk(96) as $batch) {
                $vectors = $this->ai->embed($batch->map->embedText()->all());
                foreach ($batch->values() as $i => $page) {
                    if (! empty($vectors[$i])) {
                        $page->forceFill(['embedding' => $vectors[$i], 'embed_hash' => $page->contentHash()])->saveQuietly();
                    }
                }
            }
        } catch (Throwable) {
            // Embedding unavailable (rate limit / outage) — search falls back to keyword.
        }
    }

    /** Embed a single page immediately (called right after a save/ingest). */
    public function embedPage(KnowledgePage $page): void
    {
        $this->ensureIndexed(collect([$page]));
    }

    /**
     * Rank a profile's pages against a query. Returns rows of
     * `['page' => KnowledgePage, 'score' => float, 'snippet' => string]`,
     * best first.
     *
     * @return array<int,array{page:KnowledgePage,score:float,snippet:string}>
     */
    public function search(Profile $profile, string $query, int $limit = 20, bool $semantic = true): array
    {
        $query = trim($query);
        $pages = $this->pagesFor($profile);
        if ($pages->isEmpty()) {
            return [];
        }
        if ($query === '') {
            // No query → pinned/recent first (browse mode).
            return $pages->sortByDesc('is_pinned')->sortByDesc('updated_at')
                ->take($limit)
                ->map(fn ($p) => ['page' => $p, 'score' => 0.0, 'snippet' => $this->snippet($p, '')])
                ->values()->all();
        }

        // Fast mode ($semantic = false): keyword-only, no embedding round-trip or re-indexing.
        if ($semantic) {
            $this->ensureIndexed($pages);
        }

        $terms = $this->terms($query);
        $queryVec = ($semantic && $this->configured()) ? $this->safeQueryVector($query) : [];

        $scored = $pages->map(function (KnowledgePage $p) use ($terms, $queryVec) {
            $keyword = $this->keywordScore($p, $terms);
            $semantic = ($queryVec !== [] && ! empty($p->embedding)) ? $this->cosine($queryVec, $p->embedding) : 0.0;
            // Cosine on 3-small is ~0.1–0.7; stretch into 0–1 so it's comparable.
            $semantic = max(0.0, min(1.0, ($semantic - 0.15) / 0.5));

            $score = $queryVec !== []
                ? self::W_SEMANTIC * $semantic + self::W_KEYWORD * $keyword
                : $keyword;
            // A pinned page is a slight tiebreaker, not a thumb on the scale.
            $score += $p->is_pinned ? 0.02 : 0.0;

            return ['page' => $p, 'score' => $score, 'keyword' => $keyword, 'snippet' => $this->snippet($p, $terms)];
        });

        // Keep only plausibly-relevant hits: any keyword match, or a real semantic signal.
        return $scored
            ->filter(fn ($r) => $r['score'] > 0.18 || $r['keyword'] > 0)
            ->sortByDesc('score')
            ->take($limit)
            ->map(fn ($r) => ['page' => $r['page'], 'score' => round($r['score'], 4), 'snippet' => $r['snippet']])
            ->values()->all();
    }

    private function safeQueryVector(string $query): array
    {
        try {
            return $this->ai->embedOne($query);
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<int,string> lowercased query terms (3+ chars) */
    private function terms(string $query): array
    {
        preg_match_all('/[\p{L}\p{N}]{3,}/u', mb_strtolower($query), $m);

        return array_values(array_unique($m[0] ?? []));
    }

    /** 0–1 keyword score: title hits weigh more than body hits. */
    private function keywordScore(KnowledgePage $page, array $terms): float
    {
        if ($terms === []) {
            return 0.0;
        }
        $title = mb_strtolower((string) $page->title);
        $content = mb_strtolower((string) $page->content);
        $hits = 0.0;
        foreach ($terms as $t) {
            if (str_contains($title, $t)) {
                $hits += 1.0;
            } elseif (str_contains($content, $t)) {
                $hits += 0.5;
            }
        }

        return min(1.0, $hits / count($terms));
    }

    /** A snippet centered on the first term match, else the page's opening. */
    private function snippet(KnowledgePage $page, array|string $terms): string
    {
        $content = trim(preg_replace('/\s+/', ' ', (string) $page->content) ?? '');
        $terms = is_array($terms) ? $terms : $this->terms($terms);
        if ($content === '') {
            return '';
        }
        foreach ($terms as $t) {
            $pos = mb_stripos($content, $t);
            if ($pos !== false) {
                $start = max(0, $pos - 80);
                $piece = mb_substr($content, $start, 240);

                return ($start > 0 ? '…' : '').trim($piece).(mb_strlen($content) > $start + 240 ? '…' : '');
            }
        }

        return Str::limit($content, 220);
    }

    /** Cosine similarity of two equal-length vectors. */
    private function cosine(array $a, array $b): float
    {
        $dot = $na = $nb = 0.0;
        $n = min(count($a), count($b));
        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $na += $a[$i] * $a[$i];
            $nb += $b[$i] * $b[$i];
        }
        if ($na <= 0 || $nb <= 0) {
            return 0.0;
        }

        return $dot / (sqrt($na) * sqrt($nb));
    }
}
