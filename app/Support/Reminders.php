<?php

namespace App\Support;

use App\Models\Profile;

/**
 * Proactive-coach preferences. The user picks how PRESENT they want their coach (coaching_intensity),
 * which sets the default mix of nudges; individual types can still be toggled. This is what makes Titan
 * feel like a real coach who's with you all day -- reminding you to eat, train, move/stretch and sleep --
 * without being a nag for people who want a lighter touch.
 */
class Reminders
{
    /** type => human label (for settings + the coach). */
    public const TYPES = [
        'briefing' => 'Daily briefing',
        'review' => 'Weekly review',
        'meals' => 'Meal timing',
        'sleep' => 'Sleep wind-down',
        'cycle' => 'Cycle heads-up',
        'move' => 'Move & stretch breaks',
        'training' => 'Training nudges',
        'strain' => 'Strain coach',
        'stress' => 'Stress check-ins',
        'glucose' => 'Glucose spikes',
    ];

    /** intensity => [label, blurb, default-on types]. */
    public const INTENSITIES = [
        'minimal' => ['label' => 'Light touch', 'blurb' => 'Just a morning briefing and a weekly review -- I stay out of your way.', 'types' => ['briefing', 'review']],
        'balanced' => ['label' => 'Balanced', 'blurb' => 'Daily briefing, weekly review, meal timing, a nightly wind-down, and cycle heads-ups.', 'types' => ['briefing', 'review', 'meals', 'sleep', 'cycle']],
        'intense' => ['label' => 'All-in', 'blurb' => "I'm on you all day -- eat, train, move, stretch, sleep, plus a weekly review. Like a coach in your pocket.", 'types' => ['briefing', 'review', 'meals', 'sleep', 'cycle', 'move', 'training', 'strain', 'stress', 'glucose']],
    ];

    public static function intensity(Profile $profile): string
    {
        $level = data_get($profile->settings, 'coaching_intensity', 'balanced');

        return isset(self::INTENSITIES[$level]) ? $level : 'balanced';
    }

    /** Is this nudge type on for the profile? Explicit per-type override wins, else the intensity default. */
    public static function enabled(Profile $profile, string $type): bool
    {
        // Honour the legacy flags so existing opt-outs keep working.
        if ($type === 'briefing' && data_get($profile->settings, 'briefings') === false) {
            return false;
        }
        if ($type === 'meals' && data_get($profile->settings, 'meal_reminders') === false) {
            return false;
        }

        $explicit = data_get($profile->settings, "reminders.{$type}");
        if (is_bool($explicit)) {
            return $explicit;
        }

        return in_array($type, self::INTENSITIES[self::intensity($profile)]['types'], true);
    }

    public static function setIntensity(Profile $profile, string $level): string
    {
        $level = isset(self::INTENSITIES[$level]) ? $level : 'balanced';
        $settings = $profile->settings ?? [];
        $settings['coaching_intensity'] = $level;
        // A fresh intensity choice clears per-type overrides so the tier takes effect cleanly.
        unset($settings['reminders']);
        $profile->update(['settings' => $settings]);

        return $level;
    }

    public static function setType(Profile $profile, string $type, bool $on): void
    {
        if (! isset(self::TYPES[$type])) {
            return;
        }
        $settings = $profile->settings ?? [];
        $settings['reminders'][$type] = $on;
        $profile->update(['settings' => $settings]);
    }

    /** A snapshot for the coach / settings UI: intensity + each type's on/off. */
    public static function summary(Profile $profile): array
    {
        $out = ['intensity' => self::intensity($profile), 'types' => []];
        foreach (self::TYPES as $type => $label) {
            $out['types'][$type] = ['label' => $label, 'on' => self::enabled($profile, $type)];
        }

        return $out;
    }
}
