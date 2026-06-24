<?php

namespace App\Support;

use App\Models\Profile;
use Illuminate\Support\Carbon;

/**
 * Meal-timing coach -- WHEN to eat, not just what. Built for people who overwork and forget to eat:
 * by the time you're hungry it's already too late, and you can't build a physique on a body you keep
 * starving. So we space the day's fuel across an eating window and push you to the next meal BEFORE
 * hunger -- with the protein/calories that meal should carry.
 *
 * The plan: N meals evenly spaced across a waking eating window (defaults: 4 meals, 08:00-21:00),
 * each carrying its share of the daily macro target (protein-forward). The next meal is the slot you
 * haven't filled yet; once its time passes with nothing logged, it's OVERDUE → the "eat now" signal
 * (and the reminder). Honest scope: a behavioural nudge, not medical nutrition advice.
 */
class MealCoach
{
    private const DEFAULT_PLAN = ['meals' => 4, 'start' => '08:00', 'end' => '21:00'];
    private const DEFAULT_TARGETS = ['calories' => 2800, 'protein_g' => 200];
    private const OVERDUE_GRACE_MIN = 20;   // a slot is "due" at its time, "overdue" past this grace

    public static function plan(Profile $profile): array
    {
        $p = $profile->settings['meal_plan'] ?? [];

        return [
            'meals' => (int) ($p['meals'] ?? self::DEFAULT_PLAN['meals']),
            'start' => (string) ($p['start'] ?? self::DEFAULT_PLAN['start']),
            'end' => (string) ($p['end'] ?? self::DEFAULT_PLAN['end']),
        ];
    }

    public static function targets(Profile $profile): array
    {
        $set = $profile->settings['macro_targets'] ?? [];

        // User-set targets are authoritative — return them verbatim (no bodyweight recompute, no
        // cycle bump). They customise via the app's Targets sheet or the coach's set_targets tool.
        if (($set['source'] ?? null) === 'custom') {
            return [
                'calories' => (int) ($set['calories'] ?? self::DEFAULT_TARGETS['calories']),
                'protein_g' => (int) ($set['protein_g'] ?? self::DEFAULT_TARGETS['protein_g']),
            ];
        }

        $calories = (int) ($set['calories'] ?? self::DEFAULT_TARGETS['calories']);

        // Protein is recomputed from CURRENT bodyweight + goal every time (evidence-based: ~1 g/lb
        // for muscle building) so it's never a stale onboarding number and always hits the real
        // target. Falls back to the stored/default value only when we have no bodyweight on file.
        $protein = MacroTargets::proteinTarget($profile)
            ?? (int) ($set['protein_g'] ?? self::DEFAULT_TARGETS['protein_g']);

        // Cycle-aware: the luteal phase raises BMR ~5-10%, so nudge calories up (protein need is
        // bodyweight-driven, so it holds steady). Only when she tracks her cycle.
        $calories = (int) round($calories * self::cycleCalorieMultiplier($profile));

        return ['calories' => $calories, 'protein_g' => $protein];
    }

    /** Luteal-phase energy bump (mid-range of the 5-10% literature); 1.0 otherwise. */
    private static function cycleCalorieMultiplier(Profile $profile): float
    {
        $s = self::cyclePhase($profile);

        return $s === 'luteal' ? 1.08 : 1.0;
    }

    /** Current cycle phase, or null when she doesn't track a cycle / has no data. */
    private static function cyclePhase(Profile $profile): ?string
    {
        if (! class_exists(Cycle::class) || ! Cycle::available($profile)) {
            return null;
        }
        try {
            $s = Cycle::status($profile);
        } catch (\Throwable) {
            return null;
        }

        return ($s['has_data'] ?? false) ? $s['phase'] : null;
    }

    /** Phase-specific nutrition guidance (iron on the period, more fuel in luteal, etc.). */
    public static function cycleNote(Profile $profile): ?string
    {
        return match (self::cyclePhase($profile)) {
            'menstrual' => 'On your period: iron draws down with bleeding -- favour iron-rich foods (red meat, lentils, spinach) with a little vitamin C to absorb it, and keep protein steady.',
            'luteal' => "Luteal phase: your body burns a bit more now, so I've nudged your calorie target up ~8% -- eating a touch more is normal. Cravings are physiological; lean into protein and complex carbs.",
            'follicular', 'fertile', 'ovulation' => 'Follicular phase: insulin sensitivity and energy are high -- a great window to fuel harder training.',
            default => null,
        };
    }

    /** Today's meal slot times (Carbon), evenly spaced across the eating window. */
    public static function schedule(Profile $profile, Carbon $day): array
    {
        $plan = self::plan($profile);
        $tz = $day->timezone;
        $start = Carbon::parse($day->toDateString().' '.$plan['start'], $tz);
        $end = Carbon::parse($day->toDateString().' '.$plan['end'], $tz);
        $n = max(1, $plan['meals']);
        if ($n === 1) {
            return [$start];
        }
        $stepSec = $start->diffInSeconds($end) / ($n - 1);

        return array_map(fn ($i) => $start->copy()->addSeconds((int) round($i * $stepSec)), range(0, $n - 1));
    }

    /**
     * @return array{status:string,label:string,next_at:?string,next_in_min:?int,overdue_min:?int,
     *   meals_logged:int,meals_planned:int,consumed:array{calories:int,protein_g:int},
     *   target:array{calories:int,protein_g:int},this_meal:array{calories:int,protein_g:int},advice:string}
     */
    public static function assess(Profile $profile, ?Carbon $now = null): array
    {
        $tz = $profile->settings['timezone'] ?? config('app.timezone', 'UTC');
        $now = ($now ?? Carbon::now($tz))->copy()->setTimezone($tz);
        $day = $now->copy()->startOfDay();

        $meals = $profile->meals()
            ->whereBetween('eaten_at', [$day, $day->copy()->endOfDay()])
            ->get();
        $logged = $meals->count();
        $consumed = [
            'calories' => (int) $meals->sum('calories'),
            'protein_g' => (int) round((float) $meals->sum('protein_g')),
        ];
        $targets = self::targets($profile);
        $plan = self::plan($profile);
        $schedule = self::schedule($profile, $day);
        $cycleNote = self::cycleNote($profile);

        // Per-meal share = what's LEFT of the daily target, split over the meals still to come.
        $remainingSlots = max(1, $plan['meals'] - $logged);
        $thisMeal = [
            'calories' => (int) round(max(0, $targets['calories'] - $consumed['calories']) / $remainingSlots),
            'protein_g' => (int) round(max(0, $targets['protein_g'] - $consumed['protein_g']) / $remainingSlots),
        ];

        // The next meal is the slot you haven't filled. (Eat 1 → next is slot[1], even if its time passed.)
        $nextAt = $logged < count($schedule) ? $schedule[$logged] : null;

        if ($nextAt === null) {
            return self::pack('done', 'All meals in', null, null, null, $logged, $plan['meals'], $consumed, $targets, $thisMeal,
                "You've hit your meals for today -- nicely fuelled. Keep this rhythm tomorrow.", $cycleNote);
        }

        $diffMin = (int) round($now->diffInSeconds($nextAt, false) / 60);   // negative = past due
        if ($diffMin > 30) {
            $status = 'upcoming';
            $label = 'Next meal';
            $advice = sprintf('Next fuel in %s -- about %dg protein. Have it ready before you get heads-down.',
                self::human($diffMin), $thisMeal['protein_g']);
        } elseif ($diffMin >= -self::OVERDUE_GRACE_MIN) {
            $status = 'soon';
            $label = 'Time to eat';
            $advice = sprintf("It's meal time -- grab ~%d kcal / %dg protein now, even if you're not hungry yet.",
                $thisMeal['calories'], $thisMeal['protein_g']);
        } else {
            $status = 'overdue';
            $label = 'Time to fuel up';
            $advice = sprintf("Let's get some fuel in -- about %dg protein, ~%d kcal. Even something quick counts; eating before you're starving keeps your energy and your build on track.",
                $thisMeal['protein_g'], $thisMeal['calories']);
        }

        return self::pack($status, $label, $nextAt->toIso8601String(), $diffMin > 0 ? $diffMin : 0,
            $diffMin < 0 ? -$diffMin : null, $logged, $plan['meals'], $consumed, $targets, $thisMeal, $advice, $cycleNote);
    }

    private static function pack($status, $label, $nextAt, $inMin, $overdue, $logged, $planned, $consumed, $target, $thisMeal, $advice, $cycleNote = null): array
    {
        return [
            'status' => $status, 'label' => $label,
            'next_at' => $nextAt, 'next_in_min' => $inMin, 'overdue_min' => $overdue,
            'meals_logged' => $logged, 'meals_planned' => $planned,
            'consumed' => $consumed, 'target' => $target, 'this_meal' => $thisMeal,
            'advice' => $advice, 'cycle_note' => $cycleNote,
        ];
    }

    private static function human(int $min): string
    {
        if ($min < 60) {
            return $min.' min';
        }
        $h = intdiv($min, 60);
        $m = $min % 60;

        return $m ? "{$h}h {$m}m" : "{$h}h";
    }
}
