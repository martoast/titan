<?php

namespace App\Support;

/**
 * Builds the Nano-Banana prompt for a dream-physique render — gender-aware AND angle-aware.
 *
 * Gender, because the goal physique differs by sex (a man typically wants more muscle and
 * leanness; a woman typically wants a defined waist, stronger/rounder glutes and legs, and
 * lower body fat with natural curves).
 *
 * Angle, because a single front photo can't show a glute or leg goal at all. We capture up
 * to three shots — front / back / side — and each render emphasises what THAT view reveals:
 * the back shot is where glutes, hamstrings, lats and the V-taper live; the side shot is where
 * waist taper, posture and the glute profile read. The user's own free-text always layers on top.
 *
 * Deliberately TASTEFUL + identity-preserving: same person, fully clothed, realistic and
 * achievable — never exaggerated, never objectifying. Keeps the generator inside its content
 * policy and the result believable as "future you".
 */
class PhysiquePrompt
{
    public static function build(?string $sex, ?string $description = null, string $angle = 'front'): string
    {
        $female = $sex === 'F';
        $male = $sex === 'M';
        $angle = in_array($angle, ['front', 'back', 'side'], true) ? $angle : 'front';

        $body = self::bodyFor($female, $male, $angle);

        // The face only needs preserving when it's actually in frame.
        $identity = $angle === 'back'
            ? 'keep the SAME person — same hair, skin tone, body proportions, pose, camera framing, lighting, clothing style and background unchanged'
            : 'keep their EXACT face, identity, skin tone, hair, bone structure, pose, camera framing, lighting, clothing style and background unchanged — this must look unmistakably like the SAME person, only fitter';

        $desc = trim((string) $description);
        $wants = $desc !== '' ? " The person specifically wants: {$desc} — honour this within a realistic, healthy result." : '';

        return <<<PROMPT
        Take this exact person from the photo (a {$angle} view) and render their realistic FUTURE
        SELF after a dedicated period of consistent training and good nutrition: {$body}.{$wants}

        Critical constraints: {$identity}. Keep them fully clothed in tasteful athletic or everyday
        wear (the same kind they're wearing). Photorealistic, natural and believable — an achievable,
        healthy transformation. NOT an exaggerated bodybuilder, NOT a fashion-model or fantasy render,
        no objectification, no filters, no facial distortion.
        PROMPT;
    }

    /** The body-change clause for a given sex + camera angle. */
    private static function bodyFor(bool $female, bool $male, string $angle): string
    {
        if ($female) {
            return match ($angle) {
                'back' => 'a toned, athletic feminine back view — fuller, rounder, lifted glutes with a clear shelf, stronger sculpted hamstrings and legs, a defined lower back and toned upper back and shoulders, with noticeably lower body fat while keeping healthy natural curves',
                'side' => 'a lean, athletic feminine profile — a flatter stomach and slimmer, defined waist, a lifted rounder glute profile, toned legs and an upright confident posture, with noticeably lower body fat while keeping healthy natural curves',
                default => 'a lean, toned and athletic feminine physique — a visibly slimmer, defined waist (a natural hourglass shape), toned arms and shoulders, and shapely, stronger glutes and legs, with noticeably lower body fat while keeping healthy natural curves',
            };
        }
        if ($male) {
            return match ($angle) {
                'back' => 'a leaner, more muscular athletic back — wider lats and a clear V-taper down to a tighter waist, defined upper back and rear delts, fuller glutes and stronger hamstrings, and noticeably lower body fat',
                'side' => 'a leaner, more muscular athletic profile — a flatter, tighter midsection, a fuller more defined chest, capped shoulders and arms, an upright posture, and noticeably lower body fat',
                default => 'a leaner, more muscular and athletic build — broader shoulders, a fuller more defined chest and arms, visible core and ab definition, and noticeably lower body fat',
            };
        }

        return match ($angle) {
            'back' => 'a leaner, stronger and more athletic back view — a more defined back and shoulders, firmer glutes and legs, and noticeably lower body fat',
            'side' => 'a leaner, stronger and more athletic profile — a flatter midsection, an upright posture and noticeably lower body fat',
            default => 'a leaner, stronger and more athletic version of themselves — visibly toned muscle and noticeably lower body fat',
        };
    }
}
