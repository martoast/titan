<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Meal-type grouping (MEAL_LOGGING_REVISION 3.5) — breakfast / lunch / dinner / snack. Inferred from the
 * time of day on create (in the app-tz wall-clock frame meals are stored in — see [[titan-meal-timezone-fault]])
 * and user-overridable. Sections the day list, unlocks "add to breakfast", and makes templates cleaner.
 */
class MealType
{
    public const BREAKFAST = 'breakfast';
    public const LUNCH = 'lunch';
    public const DINNER = 'dinner';
    public const SNACK = 'snack';

    /** type => display label, in day order. */
    public const TYPES = [
        self::BREAKFAST => 'Breakfast',
        self::LUNCH => 'Lunch',
        self::DINNER => 'Dinner',
        self::SNACK => 'Snack',
    ];

    /** Is this a real meal type we accept from the client? */
    public static function valid(?string $type): bool
    {
        return $type !== null && isset(self::TYPES[$type]);
    }

    /**
     * Pure: infer a meal type from the hour of day (0–23). Main-meal windows map to breakfast/lunch/dinner;
     * the late-night / mid-afternoon gaps fall to snack (a bare hour can't distinguish a late dinner from a
     * snack, so we keep it honest and let the user re-file).
     */
    public static function inferFromHour(int $hour): string
    {
        return match (true) {
            $hour >= 5 && $hour < 11 => self::BREAKFAST,
            $hour >= 11 && $hour < 15 => self::LUNCH,
            $hour >= 17 && $hour < 22 => self::DINNER,
            default => self::SNACK,   // 15–17 (afternoon) and 22–05 (late) read as snacks
        };
    }

    /** Infer from a stored eaten_at (already in the app-tz frame — read its hour directly, no tz shift). */
    public static function infer(Carbon $eatenAt): string
    {
        return self::inferFromHour((int) $eatenAt->format('G'));
    }
}
