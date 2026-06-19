<?php

namespace App\Services\Web;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Live web access for the coach -- Google search (SerpAPI) + page scraping (ScraperAPI). This is how
 * Titan grounds claims in REAL data instead of the model inventing them: looking up a food's actual
 * calories/macros, checking current facts, and sourcing the deep-research briefs.
 *
 * Best-effort and defensive: every method degrades to empty on a missing key, a non-OK response, or a
 * timeout, so the coach simply falls back to its own knowledge rather than erroring.
 */
class WebSearch
{
    public function configured(): bool
    {
        return filled(config('services.serpapi.key'));
    }

    /**
     * Google search via SerpAPI. Returns the featured answer (when Google has one) + organic results.
     *
     * @return array{answer:?string,results:array<int,array{title:?string,link:?string,snippet:?string}>}
     */
    public function search(string $query, int $num = 5): array
    {
        $empty = ['answer' => null, 'results' => []];
        if (! $this->configured() || trim($query) === '') {
            return $empty;
        }

        try {
            $resp = Http::timeout(15)->get('https://serpapi.com/search.json', [
                'q' => $query,
                'api_key' => config('services.serpapi.key'),
                'engine' => 'google',
                'num' => max(1, min(10, $num)),
                'hl' => 'en',
                'gl' => 'us',
            ]);
            if (! $resp->ok()) {
                Log::warning('[web] serpapi non-ok', ['status' => $resp->status()]);

                return $empty;
            }
            $j = $resp->json();

            $answer = data_get($j, 'answer_box.answer')
                ?? data_get($j, 'answer_box.snippet')
                ?? data_get($j, 'answer_box.result')
                ?? data_get($j, 'knowledge_graph.description');

            $results = collect(data_get($j, 'organic_results', []))
                ->take($num)
                ->map(fn ($r) => [
                    'title' => $r['title'] ?? null,
                    'link' => $r['link'] ?? null,
                    'snippet' => $r['snippet'] ?? null,
                ])
                ->filter(fn ($r) => $r['snippet'] || $r['title'])
                ->values()->all();

            return ['answer' => $answer ? trim((string) $answer) : null, 'results' => $results];
        } catch (\Throwable $e) {
            Log::warning('[web] search failed', ['error' => $e->getMessage()]);

            return $empty;
        }
    }

    /** Fetch a page's readable text via ScraperAPI (handles JS-heavy / blocked sites). '' on failure. */
    public function scrape(string $url, int $maxChars = 6000): string
    {
        if (blank(config('services.scraperapi.key')) || ! Str::startsWith($url, ['http://', 'https://'])) {
            return '';
        }

        try {
            $resp = Http::timeout(30)->get('https://api.scraperapi.com/', [
                'api_key' => config('services.scraperapi.key'),
                'url' => $url,
            ]);
            if (! $resp->ok()) {
                return '';
            }

            return self::htmlToText($resp->body(), $maxChars);
        } catch (\Throwable $e) {
            Log::warning('[web] scrape failed', ['error' => $e->getMessage()]);

            return '';
        }
    }

    /** A compact, model-ready grounding block for a factual query (answer + sourced snippets). */
    public function facts(string $query, int $num = 4): string
    {
        $r = $this->search($query, $num);
        $lines = [];
        if ($r['answer']) {
            $lines[] = 'Top answer: '.$r['answer'];
        }
        foreach ($r['results'] as $res) {
            if ($res['snippet']) {
                $host = $res['link'] ? (parse_url($res['link'], PHP_URL_HOST) ?: '') : '';
                $lines[] = '- '.$res['snippet'].($host ? " [{$host}]" : '');
            }
        }

        return implode("\n", $lines);
    }

    private static function htmlToText(string $html, int $maxChars): string
    {
        $text = preg_replace('/<(script|style|noscript)\b[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html;
        $text = preg_replace('/<[^>]+>/', ' ', $text) ?? $text;
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);

        return Str::limit($text, $maxChars, '');
    }
}
