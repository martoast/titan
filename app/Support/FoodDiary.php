<?php

namespace App\Support;

use App\Models\Profile;

/**
 * The user's eating patterns -- their most-eaten foods, computed on demand from logged meals (grouped by
 * the same normalization the food cache uses). This is a reference the coach FETCHES when it needs it
 * (meal planning, "what do I usually eat"), not something kept in the system prompt -- so it never clogs
 * context yet is always current.
 */
class FoodDiary
{
    /**
     * @return array<int,array{food:string,count:int,avg_calories:int,avg_protein_g:float,last_eaten:?string}>
     */
    public static function topFoods(Profile $profile, int $limit = 15): array
    {
        if (! class_exists(\App\Models\Meal::class)) {
            return [];
        }

        $meals = $profile->meals()->whereNotNull('name')
            ->orderByDesc('eaten_at')->limit(800)
            ->get(['name', 'calories', 'protein_g', 'eaten_at']);

        $groups = [];
        foreach ($meals as $m) {
            $key = FoodLibrary::normalize((string) $m->name);
            if ($key === '') {
                $key = mb_strtolower(trim((string) $m->name));
            }
            if ($key === '') {
                continue;
            }
            $groups[$key] ??= ['names' => [], 'count' => 0, 'cal' => 0, 'pro' => 0.0, 'last' => null];
            $groups[$key]['count']++;
            $groups[$key]['cal'] += (int) $m->calories;
            $groups[$key]['pro'] += (float) $m->protein_g;
            $name = (string) $m->name;
            $groups[$key]['names'][$name] = ($groups[$key]['names'][$name] ?? 0) + 1;
            if ($m->eaten_at && (! $groups[$key]['last'] || $m->eaten_at->gt($groups[$key]['last']))) {
                $groups[$key]['last'] = $m->eaten_at;
            }
        }

        return collect($groups)
            ->sortByDesc('count')
            ->take($limit)
            ->map(function ($g) {
                // Display the most-common spelling, tie-broken by the shortest (least portion cruft).
                $best = null;
                $bestCount = -1;
                $bestLen = PHP_INT_MAX;
                foreach ($g['names'] as $nm => $cnt) {
                    $len = mb_strlen($nm);
                    if ($cnt > $bestCount || ($cnt === $bestCount && $len < $bestLen)) {
                        $best = $nm;
                        $bestCount = $cnt;
                        $bestLen = $len;
                    }
                }

                return [
                    'food' => (string) $best,
                    'count' => $g['count'],
                    'avg_calories' => (int) round($g['cal'] / max(1, $g['count'])),
                    'avg_protein_g' => round($g['pro'] / max(1, $g['count']), 1),
                    'last_eaten' => $g['last']?->diffForHumans(),
                ];
            })
            ->values()->all();
    }
}
