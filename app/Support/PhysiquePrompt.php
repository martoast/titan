<?php

namespace App\Support;

/**
 * Builds the Nano-Banana prompt for a dream-physique render — gender-aware AND angle-aware.
 *
 * This is a DREAM physique: the aspirational, peak version of the person after YEARS of dedicated
 * training — not a modest "what you could do in two months." The render should be bold and striking
 * so it actually inspires, while staying photorealistic and unmistakably the SAME person.
 *
 * Gender, because the dream differs by sex (a man wants powerful, lean, muscular; a woman typically
 * wants a sculpted hourglass, full lifted glutes and toned athletic legs with low body fat and curves).
 *
 * Angle, because a single front photo can't show a glute, leg or back goal at all. We capture up to
 * three shots — front / back / side — and each render emphasises what THAT view reveals: the back is
 * where glutes, hamstrings, lats and the V-taper live; the side is where waist taper, posture and the
 * glute projection read. The user's own free-text always layers on top.
 *
 * Always TASTEFUL and identity-preserving: same face, fully clothed in athletic wear, no objectification.
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
            ? 'keep the SAME person — same hair, skin tone, body frame, pose, camera framing, lighting, clothing style and background'
            : 'keep their EXACT face, identity, skin tone, hair and bone structure, plus the same pose, camera framing, lighting, clothing style and background — this must be unmistakably the SAME person, just at their physical peak';

        $desc = trim((string) $description);
        $wants = $desc !== '' ? " The person specifically wants: {$desc} — make sure this clearly comes through." : '';

        return <<<PROMPT
        Take this exact person from the photo (a {$angle} view) and render their DREAM PHYSIQUE — the
        aspirational, peak version of themselves after YEARS of dedicated training and disciplined
        nutrition: {$body}.{$wants}

        Make the transformation BOLD and clearly visible — this is a dream to inspire them, not a small
        change, so push it well beyond their current shape. Still fully photorealistic.

        Constraints: {$identity}. Keep them fully clothed in tasteful, well-fitted athletic wear (the
        same style they're wearing). Natural, believable skin and muscle — defined and athletic, NOT a
        grotesque or cartoonish over-muscled bodybuilder, no objectification, no filters, no facial
        distortion. The result should look like a real, elite, in-shape version of this exact person.
        PROMPT;
    }

    /** The dream-physique clause for a given sex + camera angle. */
    private static function bodyFor(bool $female, bool $male, string $angle): string
    {
        if ($female) {
            return match ($angle) {
                'back' => 'a stunning, sculpted athletic fitness-model back — full, round, lifted glutes with a clear shelf and the side gluteus-medius filled out, strong defined hamstrings and quads, a tight snatched waist flaring into the hips, a toned V-shaped back and capped shoulders, and low body fat with healthy feminine curves',
                'side' => 'a stunning athletic fitness-model profile — a flat, toned stomach, a dramatically slim snatched waist, full projected round glutes with a strong upward shelf, lean toned legs, an upright confident posture, and low body fat with healthy feminine curves',
                default => 'a stunning, lean and sculpted athletic fitness-model physique — a dramatically slimmer, sharply defined waist creating a strong natural hourglass, toned sculpted arms and shoulders, full round lifted glutes, lean defined legs, a flat toned midsection with subtle ab definition, and low body fat with healthy feminine curves',
            };
        }
        if ($male) {
            return match ($angle) {
                'back' => 'a powerful, muscular and shredded athletic back — wide flaring lats with a dramatic V-taper down to a tight waist, a thick defined upper back and capped rear delts, full glutes and strong hamstrings, and very low body fat',
                'side' => 'a powerful, muscular and shredded athletic profile — a flat, tight midsection with visible abs, a full thick chest, capped 3D shoulders and arms, an upright confident posture, and very low body fat',
                default => 'a powerful, muscular and shredded athletic build — broad capped shoulders, a full defined chest, clearly visible six-pack abs, sculpted muscular arms, a wide V-taper down to a tight waist, and very low body fat',
            };
        }

        return match ($angle) {
            'back' => 'a strong, lean and impressively athletic back view — a defined muscular back and shoulders, firm shapely glutes and toned legs, and low body fat',
            'side' => 'a strong, lean and impressively athletic profile — a flat defined midsection, an upright posture and low body fat',
            default => 'a strong, lean and impressively athletic version of themselves — visibly sculpted muscle, defined tone and low body fat',
        };
    }
}
