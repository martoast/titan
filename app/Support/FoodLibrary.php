<?php

namespace App\Support;

use App\Models\FoodFact;
use App\Services\Ai\AiService;
use App\Services\Web\WebSearch;

/**
 * Cache-first food nutrition lookup. Tries the food-knowledge cache first; only on a miss does it hit
 * the web (and one AI call to extract per-100g macros), then stores the result so the same food is
 * never researched twice. This is what stops Titan from burning tokens re-looking-up the same foods.
 */
class FoodLibrary
{
    public function __construct(protected WebSearch $web, protected AiService $ai) {}

    /**
     * @return array{ok:bool,food?:string,basis?:string,calories?:int,protein_g?:float,carbs_g?:float,fat_g?:float,source?:?string,cached?:bool,reason?:string}
     */
    public function lookup(string $food): array
    {
        $base = self::normalize($food);
        if ($base === '') {
            return ['ok' => false, 'reason' => 'empty'];
        }

        // 1. Cache hit → zero web/AI cost.
        $fact = FoodFact::findFuzzy($base);
        if ($fact) {
            $fact->increment('hits');

            return $this->payload($fact, true);
        }

        // 2. Miss → look it up on the web once, extract structured macros, and cache it.
        if (! $this->web->configured()) {
            return ['ok' => false, 'reason' => 'no_web'];
        }
        $facts = $this->web->facts("calories protein carbs fat per 100g {$base}");
        if ($facts === '') {
            return ['ok' => false, 'reason' => 'no_data'];
        }

        try {
            $p = $this->ai->json([
                ['role' => 'system', 'content' => 'Extract a food\'s nutrition PER 100g from web snippets. Return JSON {"calories":int,"protein_g":number,"carbs_g":number,"fat_g":number,"source":string}. Choose the most authoritative/consistent figures; if snippets are per-serving, convert to per-100g. Numbers only, no ranges.'],
                ['role' => 'user', 'content' => "Food: {$base}\n\nWeb data:\n{$facts}"],
            ], ['temperature' => 0.1, 'max_tokens' => 200]);
        } catch (\Throwable) {
            return ['ok' => false, 'reason' => 'parse_failed'];
        }

        if (! isset($p['calories']) || ! is_numeric($p['calories'])) {
            return ['ok' => false, 'reason' => 'parse_failed'];
        }

        $fact = FoodFact::create([
            'name' => $base,
            'basis' => '100g',
            'calories' => (int) round((float) $p['calories']),
            'protein_g' => round((float) ($p['protein_g'] ?? 0), 1),
            'carbs_g' => round((float) ($p['carbs_g'] ?? 0), 1),
            'fat_g' => round((float) ($p['fat_g'] ?? 0), 1),
            'source' => isset($p['source']) ? \Illuminate\Support\Str::limit((string) $p['source'], 120, '') : null,
            'hits' => 1,
        ]);

        return $this->payload($fact, false);
    }

    private function payload(FoodFact $f, bool $cached): array
    {
        return [
            'ok' => true,
            'food' => $f->name,
            'basis' => $f->basis,
            'calories' => $f->calories,
            'protein_g' => $f->protein_g,
            'carbs_g' => $f->carbs_g,
            'fat_g' => $f->fat_g,
            'source' => $f->source,
            'cached' => $cached,
        ];
    }

    /** Strip quantities, units, sizes and filler so "8 oz of grilled chicken breast" → "grilled chicken breast". */
    public static function normalize(string $food): string
    {
        $s = mb_strtolower(trim($food));
        $s = preg_replace('#\b\d+([.,/]\d+)?\b#', ' ', $s) ?? $s;                       // quantities
        $s = preg_replace('/\b(g|kg|gram|grams|oz|ounce|ounces|lb|lbs|pound|pounds|cup|cups|tbsp|tablespoons?|tsp|teaspoons?|ml|l|liters?|litres?|slices?|servings?|portions?|pieces?|scoops?|cans?|bowls?|plates?|handful|large|medium|small|big|whole|a|an|of|the|my|some|with|and)\b/', ' ', $s) ?? $s;

        return trim(preg_replace('/\s+/', ' ', $s) ?? $s);
    }
}
