<?php

namespace App\Support;

use App\Models\BrandedFood;
use App\Models\FoodFact;
use App\Models\MealTemplate;
use App\Models\Profile;

/**
 * Native food search (MEAL_LOGGING_REVISION 3.1) over Titan's OWN caches — the user's logged dishes
 * (MealTemplate), the shared/personal per-basis nutrition cache (FoodFact) and branded products
 * (BrandedFood). Instant + free (no AI, no network); one ranked list the Fuel tab turns into
 * "pick a portion → log". Each row carries its BASIS so the client scales correctly: per_100g takes a
 * gram amount, per_serving/branded take a serving multiplier.
 */
class FoodSearch
{
    /**
     * Pure: how well a candidate food name matches the query. Exact = 1.0, prefix strong, whole-word
     * contains next, then token overlap; 0 when nothing matches. Case/space-insensitive.
     */
    public static function matchScore(string $name, string $query): float
    {
        $n = self::norm($name);
        $q = self::norm($query);
        if ($n === '' || $q === '') {
            return 0.0;
        }
        if ($n === $q) {
            return 1.0;
        }
        if (str_starts_with($n, $q)) {
            return 0.85;
        }
        if (str_contains($n, $q)) {
            return 0.7;
        }
        // Token overlap — every query token present somewhere scores partial by coverage.
        $qt = array_filter(explode(' ', $q));
        $nt = array_filter(explode(' ', $n));
        if ($qt === [] || $nt === []) {
            return 0.0;
        }
        $hit = 0;
        foreach ($qt as $t) {
            foreach ($nt as $w) {
                if ($w === $t || str_starts_with($w, $t)) {
                    $hit++;
                    break;
                }
            }
        }
        return $hit === 0 ? 0.0 : 0.5 * ($hit / count($qt));
    }

    /**
     * Pure: rank unified candidate rows against the query. Score = name match + a small popularity nudge
     * (log-scaled hits/uses, capped) so a common food edges out a rare same-name one. Drops non-matches.
     *
     * @param  array<int,array<string,mixed>>  $rows  each with 'name' (+ optional 'popularity')
     * @return array<int,array<string,mixed>>  sorted best-first, each with a 'score'
     */
    public static function rank(array $rows, string $query, int $limit = 20): array
    {
        $scored = [];
        foreach ($rows as $r) {
            $s = self::matchScore((string) ($r['name'] ?? ''), $query);
            if ($s <= 0.0) {
                continue;
            }
            $pop = max(0, (int) ($r['popularity'] ?? 0));
            $r['score'] = round($s + min(0.15, 0.03 * log($pop + 1)), 4);
            $scored[] = $r;
        }
        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, $limit);
    }

    /**
     * Search the local caches for a profile and return ranked, client-ready rows.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function forProfile(Profile $profile, string $query, int $limit = 20): array
    {
        $q = trim($query);
        if (mb_strlen($q) < 2) {
            return [];   // too short to search meaningfully
        }
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
        $rows = [];

        // 1. The user's own logged dishes — their reality is the best source.
        foreach ($profile->mealTemplates()->where('name', 'like', $like)->limit(40)->get() as $t) {
            $rows[] = [
                'name' => $t->name, 'brand' => null, 'basis' => 'serving', 'serving_label' => null,
                'calories' => (int) $t->calories, 'protein_g' => (float) $t->protein_g,
                'carbs_g' => (float) $t->carbs_g, 'fat_g' => (float) $t->fat_g,
                'fiber_g' => $t->fiber_g !== null ? (float) $t->fiber_g : null,
                'source' => 'your_meals', 'popularity' => (int) $t->times_logged,
            ];
        }

        // 2. Nutrition cache (per-100g / per-serving) — the user's own corrections + the shared cache.
        foreach (FoodFact::query()
            ->where('name', 'like', $like)
            ->where(fn ($w) => $w->whereNull('profile_id')->orWhere('profile_id', $profile->id))
            ->limit(60)->get() as $f) {
            $rows[] = [
                'name' => $f->name, 'brand' => null,
                'basis' => $f->basis === 'serving' ? 'per_serving' : 'per_100g',
                'serving_label' => null,
                'calories' => (int) $f->calories, 'protein_g' => (float) $f->protein_g,
                'carbs_g' => (float) $f->carbs_g, 'fat_g' => (float) $f->fat_g, 'fiber_g' => null,
                'source' => $f->profile_id ? 'your_correction' : 'library', 'popularity' => (int) $f->hits,
            ];
        }

        // 3. Branded products (per serving).
        foreach (BrandedFood::query()
            ->where(fn ($w) => $w->where('product', 'like', $like)->orWhere('brand', 'like', $like))
            ->limit(40)->get() as $b) {
            $label = trim((string) $b->brand.' '.(string) $b->product);
            $rows[] = [
                'name' => $label !== '' ? $label : (string) $b->product, 'brand' => $b->brand ?: null,
                'basis' => 'per_serving', 'serving_label' => $b->serving ?: null,
                'calories' => (int) $b->calories, 'protein_g' => (float) $b->protein_g,
                'carbs_g' => (float) $b->carbs_g, 'fat_g' => (float) $b->fat_g, 'fiber_g' => null,
                'source' => 'brand', 'popularity' => (int) $b->hits,
            ];
        }

        return self::rank($rows, $q, $limit);
    }

    private static function norm(string $s): string
    {
        $s = mb_strtolower(trim($s));

        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $s)));
    }
}
