<?php

namespace App\Support;

/**
 * The biomarker catalog -- the single source of truth for which bloodwork markers
 * Titan understands, their units, and the optimal range we flag against.
 *
 * Ranges are pragmatic adult-male targets (optimisation-leaning, not just the wide
 * lab "normal" reference). They are NOT medical advice -- they exist to colour a card
 * and prompt a conversation with the coach / a doctor.
 *
 * Each entry:
 *   - label      human name
 *   - unit       canonical unit we store + display
 *   - low/high   the optimal band [low, high]
 *   - direction  how to read a value relative to the band:
 *                 'mid'    in-band = optimal, below = low, above = high (e.g. testosterone)
 *                 'lower'  lower is better; at/under `high` = optimal, over = high (e.g. ApoB, LDL)
 *                 'higher' higher is better; at/over `low` = optimal, under = low (e.g. HDL, eGFR)
 *   - aliases    substrings the lab-parser/LLM may use to recognise this marker
 */
class Biomarkers
{
    /**
     * @return array<string,array{label:string,unit:string,low:float|null,high:float|null,direction:string,aliases:array<int,string>}>
     */
    public static function all(): array
    {
        return [
            'testosterone' => [
                'label' => 'Testosterone (Total)', 'unit' => 'ng/dL',
                'low' => 600, 'high' => 900, 'direction' => 'mid',
                'aliases' => ['total testosterone', 'testosterone total', 'testosterone, total', 'test total'],
            ],
            'free_testosterone' => [
                'label' => 'Free Testosterone', 'unit' => 'pg/mL',
                'low' => 15, 'high' => 25, 'direction' => 'mid',
                'aliases' => ['free testosterone', 'free t', 'testosterone, free'],
            ],
            'apob' => [
                'label' => 'ApoB', 'unit' => 'mg/dL',
                'low' => null, 'high' => 80, 'direction' => 'lower',
                'aliases' => ['apolipoprotein b', 'apo b', 'apob'],
            ],
            'ldl' => [
                'label' => 'LDL Cholesterol', 'unit' => 'mg/dL',
                'low' => null, 'high' => 100, 'direction' => 'lower',
                'aliases' => ['ldl', 'ldl-c', 'ldl cholesterol', 'low density lipoprotein'],
            ],
            'hdl' => [
                'label' => 'HDL Cholesterol', 'unit' => 'mg/dL',
                'low' => 50, 'high' => null, 'direction' => 'higher',
                'aliases' => ['hdl', 'hdl-c', 'hdl cholesterol', 'high density lipoprotein'],
            ],
            'triglycerides' => [
                'label' => 'Triglycerides', 'unit' => 'mg/dL',
                'low' => null, 'high' => 90, 'direction' => 'lower',
                'aliases' => ['triglycerides', 'trig', 'tg'],
            ],
            'hba1c' => [
                'label' => 'HbA1c', 'unit' => '%',
                'low' => null, 'high' => 5.3, 'direction' => 'lower',
                'aliases' => ['hba1c', 'a1c', 'hemoglobin a1c', 'glycated hemoglobin'],
            ],
            'fasting_glucose' => [
                'label' => 'Fasting Glucose', 'unit' => 'mg/dL',
                'low' => 70, 'high' => 90, 'direction' => 'mid',
                'aliases' => ['fasting glucose', 'glucose', 'glucose fasting', 'blood sugar'],
            ],
            'insulin' => [
                'label' => 'Fasting Insulin', 'unit' => 'uIU/mL',
                'low' => null, 'high' => 6, 'direction' => 'lower',
                'aliases' => ['insulin', 'fasting insulin'],
            ],
            'vitamin_d' => [
                'label' => 'Vitamin D (25-OH)', 'unit' => 'ng/mL',
                'low' => 40, 'high' => 60, 'direction' => 'mid',
                'aliases' => ['vitamin d', '25-hydroxyvitamin d', '25-oh vitamin d', 'vit d', '25(oh)d'],
            ],
            'ferritin' => [
                'label' => 'Ferritin', 'unit' => 'ng/mL',
                'low' => 50, 'high' => 150, 'direction' => 'mid',
                'aliases' => ['ferritin'],
            ],
            'hs_crp' => [
                'label' => 'hs-CRP', 'unit' => 'mg/L',
                'low' => null, 'high' => 1.0, 'direction' => 'lower',
                'aliases' => ['hs-crp', 'hscrp', 'high sensitivity crp', 'c-reactive protein', 'crp'],
            ],
            'alt' => [
                'label' => 'ALT', 'unit' => 'U/L',
                'low' => null, 'high' => 30, 'direction' => 'lower',
                'aliases' => ['alt', 'sgpt', 'alanine aminotransferase'],
            ],
            'ast' => [
                'label' => 'AST', 'unit' => 'U/L',
                'low' => null, 'high' => 30, 'direction' => 'lower',
                'aliases' => ['ast', 'sgot', 'aspartate aminotransferase'],
            ],
            'ggt' => [
                'label' => 'GGT', 'unit' => 'U/L',
                'low' => null, 'high' => 30, 'direction' => 'lower',
                'aliases' => ['ggt', 'gamma-glutamyl transferase', 'gamma gt'],
            ],
            'creatinine' => [
                'label' => 'Creatinine', 'unit' => 'mg/dL',
                'low' => 0.7, 'high' => 1.2, 'direction' => 'mid',
                'aliases' => ['creatinine', 'creat'],
            ],
            'egfr' => [
                'label' => 'eGFR', 'unit' => 'mL/min/1.73m²',
                'low' => 90, 'high' => null, 'direction' => 'higher',
                'aliases' => ['egfr', 'gfr', 'estimated gfr', 'estimated glomerular filtration rate'],
            ],
            'bun' => [
                'label' => 'BUN', 'unit' => 'mg/dL',
                'low' => 8, 'high' => 20, 'direction' => 'mid',
                'aliases' => ['bun', 'urea nitrogen', 'blood urea nitrogen'],
            ],

            // --- CBC + CMP markers that complete the PhenoAge panel (Levine/Liu 2018) ---
            'albumin' => [
                'label' => 'Albumin', 'unit' => 'g/dL',
                'low' => 4.0, 'high' => 5.0, 'direction' => 'mid',
                'aliases' => ['albumin', 'serum albumin', 'alb'],
            ],
            'alkaline_phosphatase' => [
                'label' => 'Alkaline Phosphatase', 'unit' => 'U/L',
                'low' => 30, 'high' => 100, 'direction' => 'mid',
                'aliases' => ['alkaline phosphatase', 'alp', 'alk phos', 'alkp'],
            ],
            'wbc' => [
                'label' => 'White Blood Cell Count', 'unit' => '10^3/uL',
                'low' => 3.5, 'high' => 7.0, 'direction' => 'mid',
                'aliases' => ['wbc', 'white blood cell count', 'white blood cells', 'leukocytes', 'leukocyte count'],
            ],
            'lymphocyte_percent' => [
                'label' => 'Lymphocyte %', 'unit' => '%',
                'low' => 30, 'high' => 45, 'direction' => 'mid',
                'aliases' => ['lymphocyte percent', 'lymphocyte %', 'lymphocytes %', 'lymph %', 'lymphs', 'lymphocyte percentage'],
            ],
            'mcv' => [
                'label' => 'Mean Cell Volume', 'unit' => 'fL',
                'low' => 80, 'high' => 95, 'direction' => 'mid',
                'aliases' => ['mcv', 'mean cell volume', 'mean corpuscular volume'],
            ],
            'rdw' => [
                'label' => 'Red Cell Distribution Width', 'unit' => '%',
                'low' => null, 'high' => 13.0, 'direction' => 'lower',
                'aliases' => ['rdw', 'red cell distribution width', 'red blood cell distribution width', 'rdw-cv'],
            ],
        ];
    }

    /** Ordered list of catalog keys. */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function has(string $key): bool
    {
        return isset(self::all()[$key]);
    }

    /** A single catalog entry, or null if the key isn't recognised. */
    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    public static function label(string $key): string
    {
        return self::get($key)['label'] ?? ucwords(str_replace('_', ' ', $key));
    }

    public static function unit(string $key): ?string
    {
        return self::get($key)['unit'] ?? null;
    }

    /**
     * Resolve a free-text marker name (e.g. from a lab PDF / LLM) to a catalog key.
     * Tries exact key, then label, then alias substring match. Null if unknown.
     */
    public static function resolveKey(string $name): ?string
    {
        $needle = strtolower(trim($name));
        if ($needle === '') {
            return null;
        }
        if (self::has($needle)) {
            return $needle;
        }

        foreach (self::all() as $key => $def) {
            if (strtolower($def['label']) === $needle) {
                return $key;
            }
            foreach ($def['aliases'] as $alias) {
                if ($needle === $alias || str_contains($needle, $alias) || str_contains($alias, $needle)) {
                    return $key;
                }
            }
        }

        return null;
    }

    /**
     * Compute the flag for a value against a marker's optimal band:
     * 'low' | 'normal' | 'high' | 'optimal'. Returns null for unknown markers.
     *
     * "optimal" = inside the target band; "normal" = present but mildly off in the
     * better-direction tolerance; "low"/"high" = out of range the wrong way.
     */
    public static function flag(string $key, float $value): ?string
    {
        $def = self::get($key);
        if ($def === null) {
            return null;
        }

        $low = $def['low'];
        $high = $def['high'];

        return match ($def['direction']) {
            // Lower is better: at/under high = optimal; mild overage = normal; well over = high.
            'lower' => $high === null ? 'normal'
                : ($value <= $high ? 'optimal'
                    : ($value <= $high * 1.25 ? 'normal' : 'high')),

            // Higher is better: at/over low = optimal; mild shortfall = normal; well under = low.
            'higher' => $low === null ? 'normal'
                : ($value >= $low ? 'optimal'
                    : ($value >= $low * 0.85 ? 'normal' : 'low')),

            // Mid: inside band = optimal; just outside = normal; far outside = low/high.
            default => self::flagMid($value, $low, $high),
        };
    }

    private static function flagMid(float $value, ?float $low, ?float $high): string
    {
        if ($low !== null && $value < $low) {
            return $value >= $low * 0.9 ? 'normal' : 'low';
        }
        if ($high !== null && $value > $high) {
            return $value <= $high * 1.1 ? 'normal' : 'high';
        }

        return 'optimal';
    }

    /** Tailwind classes for a flag badge -- keeps the colour logic in one place. */
    public static function flagClasses(?string $flag): string
    {
        return match ($flag) {
            'optimal' => 'bg-emerald-500/15 text-emerald-300 ring-1 ring-emerald-500/30',
            'normal' => 'bg-sky-500/15 text-sky-300 ring-1 ring-sky-500/30',
            'high' => 'bg-rose-500/15 text-rose-300 ring-1 ring-rose-500/30',
            'low' => 'bg-amber-500/15 text-amber-300 ring-1 ring-amber-500/30',
            default => 'bg-gray-500/15 text-gray-400 ring-1 ring-white/10',
        };
    }

    /** Human label for the optimal band, e.g. "600-900 ng/dL", "≤ 80 mg/dL", "≥ 50 mg/dL". */
    public static function rangeLabel(string $key): string
    {
        $def = self::get($key);
        if ($def === null) {
            return '';
        }
        $unit = $def['unit'] ? ' '.$def['unit'] : '';

        return match ($def['direction']) {
            'lower' => $def['high'] !== null ? '≤ '.self::num($def['high']).$unit : '',
            'higher' => $def['low'] !== null ? '≥ '.self::num($def['low']).$unit : '',
            default => $def['low'] !== null && $def['high'] !== null
                ? self::num($def['low']).'-'.self::num($def['high']).$unit
                : '',
        };
    }

    private static function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }
}
