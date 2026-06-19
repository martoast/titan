<?php

namespace App\Support;

/**
 * Dream-physique prompt for gpt-image-1. Deliberately TINY — the model overshoots when you
 * over-describe, so we say one short thing ("add ~10 lb of lean muscle and show abs") plus a single
 * line to keep their face. Gender-aware, lightly angle-aware. The user's own text layers on.
 */
class PhysiquePrompt
{
    public static function build(?string $sex, ?string $description = null, string $angle = 'front'): string
    {
        $female = $sex === 'F';
        $male = $sex === 'M';
        $angle = in_array($angle, ['front', 'back', 'side'], true) ? $angle : 'front';

        $change = self::changeFor($female, $male, $angle);
        $desc = trim((string) $description);
        $extra = $desc !== '' ? ' '.$desc.'.' : '';

        return "Edit this photo of this person ({$angle} view): {$change}.{$extra} "
            ."Keep their exact same face and identity so it still clearly looks like the same person.";
    }

    /** One short phrase for the change — by sex and angle. */
    private static function changeFor(bool $female, bool $male, string $angle): string
    {
        if ($female) {
            return match ($angle) {
                'back' => 'make her more toned with firmer, rounder, lifted glutes and toned legs',
                'side' => 'make her more toned and lean with a flatter stomach and firmer, lifted glutes',
                default => 'make her leaner and more toned with a flatter, defined stomach, toned arms and a firmer figure',
            };
        }
        if ($male) {
            return match ($angle) {
                'back' => 'add about 10 lbs of lean muscle — a fuller, more defined back, shoulders and glutes',
                'side' => 'add about 10 lbs of lean muscle and show defined abs, with a flatter waist',
                default => 'add about 10 lbs of lean muscle and show defined abs',
            };
        }

        return 'add a bit of lean muscle and tone, leaner and more defined';
    }
}
