<?php

namespace Database\Seeders;

use App\Models\Meal;
use App\Models\Profile;
use Carbon\Carbon;
use Database\Seeders\Concerns\SeedsProfile;
use Illuminate\Database\Seeder;

/**
 * A few days of sample meals for profile 1 (Alex). Each meal is built from items and
 * its macros recalculated from them, mirroring the real save flow. Idempotent-ish:
 * skips if Alex already has meals, so re-running the seeder doesn't duplicate.
 */
class MealsSeeder extends Seeder
{
    use SeedsProfile;

    public function run(): void
    {
        $profile = $this->targetProfile();
        if (! $profile) {
            return;
        }
        if ($profile->meals()->exists()) {
            return;
        }

        // Sensible bulking targets for Alex if none set yet.
        $settings = $profile->settings ?? [];
        $settings['macro_targets'] ??= ['calories' => 2900, 'protein_g' => 210, 'carbs_g' => 300, 'fat_g' => 85];
        $profile->settings = $settings;
        $profile->save();

        $days = [
            // day offset (0 = today), meals
            [0, [
                ['08:15', 'photo', 'Steak & eggs breakfast', [
                    ['Sirloin steak', '180 g', 360, 50, 0, 16],
                    ['Whole eggs', '3 large', 215, 18, 1, 15],
                    ['Sourdough toast', '2 slices', 180, 6, 34, 2],
                    ['Black coffee', '1 cup', 5, 0, 1, 0],
                ]],
                ['13:00', 'text', 'Chicken rice bowl', [
                    ['Grilled chicken breast', '200 g', 330, 62, 0, 7],
                    ['White rice', '1.5 cup', 300, 6, 66, 1],
                    ['Avocado', '1/2', 120, 1, 6, 11],
                    ['Mixed greens', '1 cup', 20, 1, 4, 0],
                ]],
                ['19:30', 'photo', 'Salmon & sweet potato', [
                    ['Baked salmon', '170 g', 350, 39, 0, 21],
                    ['Sweet potato', '1 medium', 130, 2, 30, 0],
                    ['Broccoli', '1.5 cup', 75, 5, 14, 1],
                ]],
            ]],
            [1, [
                ['09:00', 'text', 'Oatmeal & protein', [
                    ['Rolled oats', '90 g', 340, 12, 60, 6],
                    ['Whey protein', '1 scoop', 120, 25, 3, 1],
                    ['Banana', '1 medium', 105, 1, 27, 0],
                    ['Peanut butter', '1 tbsp', 95, 4, 3, 8],
                ]],
                ['14:00', 'photo', 'Burrito bowl', [
                    ['Ground beef 90/10', '150 g', 280, 35, 0, 15],
                    ['Black beans', '1 cup', 220, 15, 40, 1],
                    ['Brown rice', '1 cup', 215, 5, 45, 2],
                    ['Cheese & salsa', '-', 140, 8, 4, 10],
                ]],
                ['20:00', 'manual', 'Greek yogurt & berries', [
                    ['Greek yogurt 0%', '250 g', 150, 26, 9, 0],
                    ['Mixed berries', '1 cup', 70, 1, 17, 0],
                    ['Honey', '1 tbsp', 64, 0, 17, 0],
                ]],
            ]],
            [2, [
                ['08:30', 'photo', 'Egg white scramble', [
                    ['Egg whites', '6', 100, 22, 1, 0],
                    ['Spinach & mushrooms', '-', 40, 3, 6, 0],
                    ['Turkey bacon', '3 strips', 90, 12, 0, 5],
                    ['Whole grain toast', '1 slice', 90, 4, 16, 1],
                ]],
                ['13:30', 'text', 'Tuna pasta', [
                    ['Tuna', '1 can', 180, 40, 0, 2],
                    ['Whole wheat pasta', '100 g dry', 350, 14, 70, 2],
                    ['Olive oil', '1 tbsp', 120, 0, 0, 14],
                ]],
                ['18:45', 'photo', 'Bunless burger & fries', [
                    ['Beef patty', '200 g', 450, 40, 0, 32],
                    ['Sweet potato fries', '150 g', 220, 2, 38, 7],
                    ['Side salad', '-', 60, 2, 8, 3],
                ]],
            ]],
            [4, [
                ['12:00', 'manual', 'Post-workout shake', [
                    ['Whey protein', '2 scoops', 240, 50, 6, 2],
                    ['Banana', '1 medium', 105, 1, 27, 0],
                    ['Oat milk', '300 ml', 120, 3, 19, 5],
                ]],
                ['19:00', 'photo', 'Pad thai', [
                    ['Chicken pad thai', '1 plate', 650, 35, 75, 22],
                    ['Spring roll', '1', 130, 4, 16, 6],
                ]],
            ]],
        ];

        foreach ($days as [$offset, $meals]) {
            $date = Carbon::today()->subDays($offset);
            foreach ($meals as [$time, $source, $name, $items]) {
                [$h, $m] = explode(':', $time);
                $meal = $profile->meals()->create([
                    'eaten_at' => $date->copy()->setTime((int) $h, (int) $m),
                    'name' => $name,
                    'source' => $source,
                    'calories' => 0, 'protein_g' => 0, 'carbs_g' => 0, 'fat_g' => 0,
                ]);

                foreach ($items as [$iname, $qty, $cal, $p, $c, $f]) {
                    $meal->items()->create([
                        'name' => $iname,
                        'quantity' => $qty === '-' ? null : $qty,
                        'calories' => $cal,
                        'protein_g' => $p,
                        'carbs_g' => $c,
                        'fat_g' => $f,
                    ]);
                }

                $meal->recalcFromItems();
            }
        }
    }
}
