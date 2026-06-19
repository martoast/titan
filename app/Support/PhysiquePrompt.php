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

        // Back shots don't show the face — only the body frame needs anchoring.
        $styleAnchor = $angle === 'back'
            ? 'same hair colour, skin tone, body frame, pose, camera framing, lighting, clothing style and background'
            : 'same skin tone, hair colour, body frame, pose, camera framing, lighting, clothing style and background';

        $desc = trim((string) $description);
        $wants = $desc !== '' ? " The user specifically wants: {$desc} — make sure this clearly comes through." : '';

        return <<<PROMPT
        This is a fitness visualisation. Using the reference photo ({$angle} view), create a
        photorealistic image showing what this person's body could look like at their absolute
        athletic peak after years of dedicated training and disciplined nutrition: {$body}.{$wants}

        Make the transformation BOLD and clearly visible — this should inspire, not a subtle change,
        so push the physique well beyond the current shape. Fully photorealistic.

        Style constraints: {$styleAnchor}. Keep the person fully clothed in tasteful, well-fitted
        athletic wear (same style as in the photo). Natural, believable muscle and skin — defined
        and athletic, NOT a grotesque or cartoonish over-muscled bodybuilder, no objectification,
        no filters, no distortion. The result should look like a real, elite, in-shape athlete.
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
