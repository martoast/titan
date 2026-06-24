<?php

namespace App\Support;

use App\Models\ActivitySession;

/**
 * The coach's voice when a workout completes. Turns a sealed {@see ActivitySession} into a warm,
 * specific celebration: a one-line push summary + a chat message that congratulates, gives recovery
 * guidance scaled to how hard it was, and asks ONE useful follow-up. Mirrors {@see SleepCoach}.
 */
class WorkoutCoach
{
    /**
     * @return array{title:string, push:string, body:string}
     */
    public static function celebrate(ActivitySession $s): array
    {
        $name = $s->profile?->display_name ? ' '.$s->profile->display_name : '';
        $type = $s->title();                       // Run / Ride / Walk / Stairs / Strength / Workout
        $emoji = match ($s->activity_type) {
            'run' => '🏃', 'cycle' => '🚴', 'walk' => '🚶', 'stairs' => '🪜', 'strength' => '💪',
            default => '🔥',
        };

        // One-line summary. HR leads with the PEAK (not just the average), and the minutes in the hard
        // zones — because on a lifting day the average is dragged down by inter-set rest and hides the
        // real intensity. Peak + time-in-red is what makes a brutal session legible.
        $hard = $s->hardZoneMin();
        $bits = [];
        if ($s->duration_min) {
            $bits[] = "{$s->duration_min} min";
        }
        if ($s->distance_km) {
            $bits[] = "{$s->distance_km} km";
        }
        if ($s->max_hr) {
            $bits[] = 'peak '.$s->max_hr.($s->avg_hr ? ' · avg '.$s->avg_hr : '').' bpm';
        } elseif ($s->avg_hr) {
            $bits[] = 'avg '.$s->avg_hr.' bpm';
        }
        if ($hard >= 0.5) {
            $bits[] = self::mins($hard).' in the red (Z4–5)';
        }
        if ($s->calories_kcal) {
            $bits[] = $s->calories_kcal.' kcal';
        }
        $summary = implode(' · ', $bits);

        // The lifting insight: when the average sits well below the peak AND there was real time in the
        // hard zones, call it out — that gap IS the story a plain average would bury.
        $liftInsight = '';
        if ($s->max_hr && $s->avg_hr && ($s->max_hr - $s->avg_hr) >= 25 && $hard >= 1) {
            $liftInsight = " Your average ({$s->avg_hr}) looks easy, but you spiked to {$s->max_hr} with "
                .self::mins($hard)." in the red — that\'s the hard sets your average alone would hide.";
        }

        $intense = ($s->trimp ?? 0) >= 100
            || ($s->max_hr ?? 0) >= 160
            || ($s->duration_min ?? 0) >= 60;

        // Recovery guidance, scaled to the load.
        $recovery = $intense
            ? 'That was a real load. Refuel within the hour — protein plus some carbs — drink up, and protect your sleep tonight. Your recovery may dip tomorrow, so if it reads low, keep it easy.'
            : 'Nice and steady. Get some protein in and hydrate and you\'ll bounce back fast.';

        // One follow-up that actually moves things forward.
        $followup = match ($s->activity_type) {
            'strength' => 'What did you train, and roughly how heavy? Tell me and I\'ll log it so we can progress it next time.',
            'run', 'cycle', 'walk', 'stairs' => 'How did it feel — easy, moderate, or all-out? I\'ll tune your HR zones to match.',
            default => 'How\'d it go? Tell me what you did and I\'ll log the details.',
        };

        $hr = ($s->hr_source === 'ppg_inmotion') ? ' (HR motion-corrected from your raw signal)' : '';

        return [
            'title' => "{$emoji} {$type} done",
            'push' => ($summary !== '' ? $summary : 'Nice work').'. Tap — your coach has a note.',
            'body' => "{$emoji} **Strong work{$name} — {$type} logged.**".($summary !== '' ? " {$summary}.{$hr}" : '')
                .$liftInsight." {$recovery} {$followup}",
        ];
    }

    /** "8 min" / "8.5 min" — trim a trailing .0. */
    private static function mins(float $m): string
    {
        return rtrim(rtrim(number_format($m, 1), '0'), '.').' min';
    }
}

