<?php

namespace App\Support;

/**
 * Per-item meal math (MEAL_LOGGING_REVISION 3.2) — the killer photo flow becomes trustworthy when the
 * vision's ingredient breakdown is returned as editable lines whose sum IS the meal total. Pure so the
 * draft preview and any validation read the same numbers the stored meal will (recalcFromItems does the
 * same sum on the DB side). Fiber is summed as a secondary stat, null unless a line reports it.
 */
class MealItems
{
    /**
     * Sum a list of item lines into meal totals.
     *
     * @param  array<int,array<string,mixed>>  $items  each with calories/protein_g/carbs_g/fat_g (+ optional fiber_g)
     * @return array{calories:int,protein_g:float,carbs_g:float,fat_g:float,fiber_g:?float}
     */
    public static function totals(array $items): array
    {
        $cal = 0.0;
        $p = 0.0;
        $c = 0.0;
        $f = 0.0;
        $fiber = 0.0;
        $hasFiber = false;
        foreach ($items as $i) {
            $cal += (float) ($i['calories'] ?? 0);
            $p += (float) ($i['protein_g'] ?? 0);
            $c += (float) ($i['carbs_g'] ?? 0);
            $f += (float) ($i['fat_g'] ?? 0);
            if (isset($i['fiber_g']) && is_numeric($i['fiber_g'])) {
                $fiber += (float) $i['fiber_g'];
                $hasFiber = true;
            }
        }

        return [
            'calories' => (int) round($cal),
            'protein_g' => round($p, 1),
            'carbs_g' => round($c, 1),
            'fat_g' => round($f, 1),
            'fiber_g' => $hasFiber ? round($fiber, 1) : null,
        ];
    }

    /**
     * Normalize a raw items array (from vision or the client) into clean line rows — drop blank-named
     * lines, clamp negatives to 0, round. Returns [] when nothing usable, so callers can fall back to a
     * lumped estimate.
     *
     * @param  mixed  $items
     * @return array<int,array{name:string,quantity:?string,calories:int,protein_g:float,carbs_g:float,fat_g:float,fiber_g:?float}>
     */
    public static function clean(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }
        $out = [];
        foreach ($items as $i) {
            if (! is_array($i)) {
                continue;   // legacy string-only items carry no macros — not editable lines
            }
            $name = trim((string) ($i['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $out[] = [
                'name' => mb_substr($name, 0, 80),
                'quantity' => isset($i['quantity']) && trim((string) $i['quantity']) !== '' ? mb_substr(trim((string) $i['quantity']), 0, 40) : null,
                'calories' => max(0, (int) round((float) ($i['calories'] ?? 0))),
                'protein_g' => max(0.0, round((float) ($i['protein_g'] ?? 0), 1)),
                'carbs_g' => max(0.0, round((float) ($i['carbs_g'] ?? 0), 1)),
                'fat_g' => max(0.0, round((float) ($i['fat_g'] ?? 0), 1)),
                'fiber_g' => isset($i['fiber_g']) && is_numeric($i['fiber_g']) ? max(0.0, round((float) $i['fiber_g'], 1)) : null,
            ];
        }

        return $out;
    }
}
