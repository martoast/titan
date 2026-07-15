<?php

namespace App\Support;

use App\Models\Meal;
use App\Models\MealTemplate;
use App\Models\Profile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The user's meal memory: turns every logged {@see Meal} into a reusable, deduped {@see MealTemplate}
 * so a dish they eat often can be re-logged in one tap instead of re-scanned. Auto-remembers on every
 * log (called from Meal::created); ranks "your usuals" by how often + how recently they're eaten.
 */
class MealMemory
{
    /** Ignore trivially-named or empty meals — they'd pollute the library with a "Meal" catch-all. */
    private const SKIP_NAMES = ['', 'meal', 'food', 'snack'];

    /** When paused, the Meal::created hook's remember() is a no-op — see withoutRemembering(). */
    private static bool $paused = false;

    /**
     * Run a create-with-items sequence WITHOUT the per-insert auto-remember firing early. Item-based
     * meals are inserted with zero totals, then their items + recalcFromItems set the real numbers; the
     * created-hook would otherwise remember the empty intermediate state. Callers remember() once after.
     *
     * @template T
     * @param  \Closure():T  $fn
     * @return T
     */
    public static function withoutRemembering(\Closure $fn): mixed
    {
        $prev = self::$paused;
        self::$paused = true;
        try {
            return $fn();
        } finally {
            self::$paused = $prev;
        }
    }

    /**
     * Fold a just-logged meal into the profile's library: upsert on the normalized name, bump the
     * frequency + recency, and refresh macros/photo to this (latest, i.e. most-corrected) instance.
     */
    public function remember(Meal $meal): void
    {
        if (self::$paused) {
            return;   // mid create-with-items; the caller will remember() the finished meal
        }
        $name = trim((string) $meal->name);
        $key = self::normalize($name);
        if ($key === '' || in_array($key, self::SKIP_NAMES, true)) {
            return;   // nothing worth remembering
        }

        $tpl = MealTemplate::firstOrNew(['profile_id' => $meal->profile_id, 'key' => $key]);
        $tpl->name = $name ?: ($tpl->name ?? 'Meal');
        $tpl->calories = (int) $meal->calories;
        $tpl->protein_g = (float) $meal->protein_g;
        $tpl->carbs_g = (float) $meal->carbs_g;
        $tpl->fat_g = (float) $meal->fat_g;
        // Remember the ingredient breakdown too (MEAL_LOGGING_REVISION 3.4), so "my usual breakfast"
        // re-logs as the eggs + oats + coffee it was. IMPORTANT: only OVERWRITE when this instance
        // actually has data — a later LUMPED log of the same dish (copy-day, manual, coach) must not
        // erase a breakdown/fibre we already learned (unknown never clobbers known).
        $items = $meal->relationLoaded('items') ? $meal->items : $meal->items()->get();
        if ($items->isNotEmpty()) {
            $tpl->items = $items->map(fn (\App\Models\MealItem $i) => [
                'name' => $i->name,
                'quantity' => $i->quantity,
                'calories' => (int) $i->calories,
                'protein_g' => (float) $i->protein_g,
                'carbs_g' => (float) $i->carbs_g,
                'fat_g' => (float) $i->fat_g,
                'fiber_g' => $i->fiber_g !== null ? (float) $i->fiber_g : null,
            ])->values()->all();
        }
        if ($meal->fiber_g !== null) {
            $tpl->fiber_g = (float) $meal->fiber_g;
        }
        if ($meal->photo_path) {
            $tpl->photo_path = $meal->photo_path;   // keep the most recent real photo
        }
        $tpl->times_logged = (int) ($tpl->times_logged ?? 0) + 1;
        // A meal can be back-dated; never let an older instance rewind the recency clock.
        $eaten = $meal->eaten_at ?? now();
        if ($tpl->last_eaten_at === null || $eaten->greaterThan($tpl->last_eaten_at)) {
            $tpl->last_eaten_at = $eaten;
        }
        $tpl->source = $tpl->source ?: ($meal->source ?? 'manual');
        $tpl->save();
    }

    /**
     * The "your meals" list: favorites first, then ranked by a recency-weighted frequency score so a
     * daily staple outranks a one-off from last week. Newest-eaten breaks ties.
     *
     * @return Collection<int,MealTemplate>
     */
    public function library(Profile $profile, int $limit = 24): Collection
    {
        return $profile->mealTemplates()
            ->orderByDesc('favorite')
            ->orderByDesc('last_eaten_at')
            ->limit(200)
            ->get()
            ->sortByDesc(fn (MealTemplate $t) => [$t->favorite ? 1 : 0, $this->score($t)])
            ->take($limit)
            ->values();
    }

    /** Recency-weighted frequency: frequent AND recent beats frequent-but-stale. */
    private function score(MealTemplate $t): float
    {
        $days = $t->last_eaten_at ? max(0, now()->diffInDays($t->last_eaten_at)) : 60;
        $recency = 1.0 / (1.0 + $days / 7.0);   // ~1 today, ~0.5 a week ago, decays gently
        return $t->times_logged * (0.4 + 0.6 * $recency);
    }

    /** Normalize a meal name to a dedup key: lowercase, strip punctuation, collapse whitespace. */
    public static function normalize(string $name): string
    {
        return Str::of($name)->lower()->ascii()
            ->replace('&', ' and ')                    // "Chicken & Rice" == "chicken and rice"
            ->replaceMatches('/[^a-z0-9 ]+/', ' ')     // drop remaining punctuation
            ->replaceMatches('/\s+/', ' ')             // collapse whitespace
            ->trim()
            ->value();
    }
}
