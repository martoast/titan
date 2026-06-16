<?php

namespace App\Support;

use App\Models\Profile;
use Illuminate\Support\Carbon;

/**
 * The kitchen — what food the user actually has on hand. So the meal coach suggests things they can
 * make right now ("you bought ground beef, eggs, tuna → here's what to cook"), not stuff they'd have
 * to shop for. Stored on the profile settings as a simple item list; editable from the Meals page and,
 * crucially, conversationally through the agent ("I just bought X, Y, Z" → update_pantry).
 */
class Pantry
{
    private const MAX_ITEMS = 80;

    /** @return array<int,string> the current pantry items */
    public static function get(Profile $profile): array
    {
        return array_values((array) ($profile->settings['pantry'] ?? []));
    }

    public static function updatedAt(Profile $profile): ?Carbon
    {
        $ts = $profile->settings['pantry_updated_at'] ?? null;

        return $ts ? Carbon::parse($ts) : null;
    }

    /** Replace the whole list. */
    public static function set(Profile $profile, array $items): array
    {
        return self::save($profile, self::clean($items));
    }

    /** Add items (deduped, case-insensitive). Accepts an array or a comma/newline string. */
    public static function add(Profile $profile, array|string $items): array
    {
        $existing = self::get($profile);
        $merged = array_merge($existing, self::clean(self::parse($items)));

        return self::save($profile, self::dedupe($merged));
    }

    /** Remove items (case-insensitive match). */
    public static function remove(Profile $profile, array|string $items): array
    {
        $drop = array_map('mb_strtolower', self::clean(self::parse($items)));
        $kept = array_filter(self::get($profile), fn ($i) => ! in_array(mb_strtolower($i), $drop, true));

        return self::save($profile, array_values($kept));
    }

    /** Split a comma/newline string into items (a list passes through). */
    public static function parse(array|string $items): array
    {
        if (is_array($items)) {
            return $items;
        }

        return preg_split('/[,\n;]+/', $items) ?: [];
    }

    private static function clean(array $items): array
    {
        $out = [];
        foreach ($items as $i) {
            $v = trim(preg_replace('/\s+/', ' ', (string) $i));
            if ($v !== '' && mb_strlen($v) <= 60) {
                $out[] = $v;
            }
        }

        return self::dedupe($out);
    }

    private static function dedupe(array $items): array
    {
        $seen = [];
        $out = [];
        foreach ($items as $i) {
            $k = mb_strtolower($i);
            if (! isset($seen[$k])) {
                $seen[$k] = true;
                $out[] = $i;
            }
        }

        return array_slice($out, 0, self::MAX_ITEMS);
    }

    private static function save(Profile $profile, array $items): array
    {
        $profile->settings = array_merge($profile->settings ?? [], [
            'pantry' => $items,
            'pantry_updated_at' => now()->toIso8601String(),
        ]);
        $profile->save();

        return $items;
    }
}
