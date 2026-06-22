<?php

namespace App\Support;

use App\Models\Profile;

/**
 * One place for weight/length unit handling. The database always stores metric
 * (weight in kg, circumferences in cm); this converts to/from the user's chosen
 * display units (settings.units = 'metric' | 'imperial') so every surface reads the
 * same — no more "82.6 kg here, 182 lb there".
 *
 * Convention: *Out = stored metric → display; *In = submitted display → stored metric.
 */
class Units
{
    public const KG_PER_LB = 0.45359237;
    public const LB_PER_KG = 2.2046226218;
    public const CM_PER_IN = 2.54;
    public const IN_PER_CM = 0.39370078740;

    public static function imperial(?Profile $profile): bool
    {
        return $profile && ($profile->settings['units'] ?? 'metric') === 'imperial';
    }

    public static function weightUnit(?Profile $profile): string
    {
        return self::imperial($profile) ? 'lb' : 'kg';
    }

    public static function lengthUnit(?Profile $profile): string
    {
        return self::imperial($profile) ? 'in' : 'cm';
    }

    /** Stored kg → display weight (rounded). Null passes through. */
    public static function weightOut(?float $kg, ?Profile $profile, int $dec = 1): ?float
    {
        if ($kg === null) {
            return null;
        }

        return round(self::imperial($profile) ? $kg * self::LB_PER_KG : $kg, $dec);
    }

    /** Submitted display weight → stored kg (rounded). Null passes through. */
    public static function weightIn(?float $value, ?Profile $profile, int $dec = 2): ?float
    {
        if ($value === null) {
            return null;
        }

        return round(self::imperial($profile) ? $value * self::KG_PER_LB : $value, $dec);
    }

    /** Stored cm → display length (rounded). Null passes through. */
    public static function lengthOut(?float $cm, ?Profile $profile, int $dec = 1): ?float
    {
        if ($cm === null) {
            return null;
        }

        return round(self::imperial($profile) ? $cm * self::IN_PER_CM : $cm, $dec);
    }

    /** Submitted display length → stored cm (rounded). Null passes through. */
    public static function lengthIn(?float $value, ?Profile $profile, int $dec = 1): ?float
    {
        if ($value === null) {
            return null;
        }

        return round(self::imperial($profile) ? $value * self::CM_PER_IN : $value, $dec);
    }

    /** "182.1 lb" / "82.6 kg" / "—" for a stored kg value. */
    public static function weight(?float $kg, ?Profile $profile, int $dec = 1): string
    {
        $v = self::weightOut($kg, $profile, $dec);

        return $v === null ? '—' : self::num($v).' '.self::weightUnit($profile);
    }

    /** "32 in" / "81 cm" / "—" for a stored cm value. */
    public static function length(?float $cm, ?Profile $profile, int $dec = 1): string
    {
        $v = self::lengthOut($cm, $profile, $dec);

        return $v === null ? '—' : self::num($v).' '.self::lengthUnit($profile);
    }

    /** Trim trailing zeros: 182.10 → "182.1", 80.0 → "80". */
    public static function num(float $value, int $dec = 2): string
    {
        return rtrim(rtrim(number_format($value, $dec, '.', ''), '0'), '.');
    }
}
