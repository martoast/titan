<?php

namespace Tests\Unit;

use App\Support\MealType;
use PHPUnit\Framework\TestCase;

/**
 * Meal-type inference (MEAL_LOGGING_REVISION 3.5) — the time-of-day → breakfast/lunch/dinner/snack map
 * that sections the day list. Pure, no DB.
 */
class MealTypeTest extends TestCase
{
    public function test_main_meal_windows(): void
    {
        $this->assertSame(MealType::BREAKFAST, MealType::inferFromHour(7));
        $this->assertSame(MealType::LUNCH, MealType::inferFromHour(12));
        $this->assertSame(MealType::DINNER, MealType::inferFromHour(19));
    }

    public function test_boundaries(): void
    {
        $this->assertSame(MealType::BREAKFAST, MealType::inferFromHour(5));   // window opens
        $this->assertSame(MealType::LUNCH, MealType::inferFromHour(11));      // breakfast→lunch
        $this->assertSame(MealType::SNACK, MealType::inferFromHour(15));      // mid-afternoon gap
        $this->assertSame(MealType::DINNER, MealType::inferFromHour(17));     // dinner opens
    }

    public function test_late_night_and_early_hours_are_snacks(): void
    {
        $this->assertSame(MealType::SNACK, MealType::inferFromHour(23));
        $this->assertSame(MealType::SNACK, MealType::inferFromHour(2));
        $this->assertSame(MealType::SNACK, MealType::inferFromHour(4));
    }

    public function test_valid_gate(): void
    {
        $this->assertTrue(MealType::valid('breakfast'));
        $this->assertTrue(MealType::valid('snack'));
        $this->assertFalse(MealType::valid('brunch'));
        $this->assertFalse(MealType::valid(null));
    }
}
