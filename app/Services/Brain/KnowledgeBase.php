<?php

namespace App\Services\Brain;

use App\Models\CoachMemory;
use App\Models\Profile;

/**
 * The coach's ONE knowledge base. A single search spans both stores of what the coach knows about a
 * person — the atomic coach memories (injuries, preferences, what's worked, commitments) AND the
 * health-wiki pages (notes, history, doctor's notes) — and returns one ranked, source-tagged result.
 *
 * Built for speed: a fast keyword pass over both stores runs first with zero network calls; the
 * semantic (embedding) page search is only invoked when the lexical pass comes back thin. For the
 * common exact-term query the coach gets its context instantly.
 */
class KnowledgeBase
{
    public function __construct(protected KnowledgeSearch $pages) {}

    /**
     * @return array<int,array{source:string,title:?string,snippet:string,score:float,ref:string}>
     */
    public function search(Profile $profile, string $query, int $limit = 8): array
    {
        $q = trim($query);

        // 1. Instant lexical pass across BOTH stores (no embeddings).
        $memories = $this->memoryHits($profile, $q, $limit);
        $lexPages = $this->mapPages($this->pages->search($profile, $q, $limit, false));
        $merged = $this->rank(array_merge($memories, $lexPages), $limit);

        // Good enough already → return instantly, paying NO embedding round-trip. We only deepen with the
        // semantic search when the lexical pass is genuinely thin (weak top hit and few results).
        $top = $merged[0]['score'] ?? 0.0;
        $strong = array_filter($merged, fn ($r) => $r['score'] >= 0.5);
        if ($q === '' || ! $this->pages->configured() || $top >= 0.4 || count($strong) >= min($limit, 3)) {
            return array_slice($merged, 0, $limit);
        }

        // 2. Lexical recall was thin → deepen with the semantic page search and re-rank.
        $semPages = $this->mapPages($this->pages->search($profile, $q, $limit, true));

        return $this->rank(array_merge($memories, $semPages, $lexPages), $limit);
    }

    /** Keyword-score the active coach memories (instant — they're short and few). */
    private function memoryHits(Profile $profile, string $query, int $limit): array
    {
        if (! class_exists(CoachMemory::class)) {
            return [];
        }
        $memories = $profile->coachMemories()->active()->get();
        if ($memories->isEmpty()) {
            return [];
        }
        $terms = $this->terms($query);

        $out = [];
        foreach ($memories as $m) {
            $imp = ($m->importance ?? 2) / 3;                 // 0.33–1.0
            if ($terms === []) {
                $score = 0.4 * $imp;                          // browse mode → important facts first
            } else {
                $content = mb_strtolower((string) $m->content);
                $hits = 0;
                foreach ($terms as $t) {
                    if (str_contains($content, $t)) {
                        $hits++;
                    }
                }
                if ($hits === 0) {
                    continue;
                }
                $score = min(1.0, $hits / count($terms)) * (0.85 + 0.15 * $imp) + 0.1;  // memories are high-signal
            }
            $out[] = [
                'source' => 'memory',
                'title' => $m->label(),
                'snippet' => (string) $m->content,
                'score' => round($score, 4),
                'ref' => 'memory:'.$m->id,
            ];
        }

        return $out;
    }

    private function mapPages(array $hits): array
    {
        return array_map(fn ($h) => [
            'source' => 'note',
            'title' => $h['page']->title ?? null,
            'snippet' => $h['snippet'] ?? '',
            'score' => (float) ($h['score'] ?? 0),
            'ref' => 'page:'.($h['page']->id ?? 0),
        ], $hits);
    }

    /** Dedupe by ref, sort by score, take the top N. */
    private function rank(array $items, int $limit): array
    {
        $byRef = [];
        foreach ($items as $it) {
            $ref = $it['ref'];
            if (! isset($byRef[$ref]) || $it['score'] > $byRef[$ref]['score']) {
                $byRef[$ref] = $it;
            }
        }
        $ranked = array_values($byRef);
        usort($ranked, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($ranked, 0, $limit);
    }

    /** @return array<int,string> lowercased query terms (3+ chars) */
    private function terms(string $query): array
    {
        preg_match_all('/[\p{L}\p{N}]{3,}/u', mb_strtolower($query), $m);

        return array_values(array_unique($m[0] ?? []));
    }
}
