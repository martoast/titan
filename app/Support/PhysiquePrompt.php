<?php

namespace App\Support;

/**
 * Builds the Nano-Banana prompt for a dream-physique render — gender-aware AND angle-aware.
 *
 * This is a DREAM physique: a bold, inspirational image of an elite athlete that represents
 * what the user is working toward. Generated from text only (no input photo) because Gemini's
 * IMAGE_SAFETY policy blocks person-photo body-transformation requests.
 *
 * Gender and angle steer the physique description so each render shows exactly what matters:
 * front = overall silhouette, back = glutes/lats/V-taper, side = waist taper + glute projection.
 */
class PhysiquePrompt
{
    public static function build(?string $sex, ?string $description = null, string $angle = 'front'): string
    {
        $female = $sex === 'F';
        $male = $sex === 'M';
        $angle = in_array($angle, ['front', 'back', 'side'], true) ? $angle : 'front';

        $body = self::bodyFor($female, $male, $angle);
        $sexLabel = $female ? 'woman' : ($male ? 'man' : 'person');
        $angleLabel = match ($angle) {
            'back' => 'rear-facing',
            'side' => 'side-profile',
            default => 'front-facing',
        };

        $desc = trim((string) $description);
        $wants = $desc !== '' ? " Specific goals: {$desc}." : '';

        return <<<PROMPT
        Create a photorealistic fitness inspiration photo of an elite athletic {$sexLabel} in a
        {$angleLabel} pose, fully clothed in tasteful well-fitted athletic wear (shorts/leggings
        and a fitted top), in a clean gym or neutral studio setting with good natural lighting.

        Physique: {$body}.{$wants}

        Style: cinematic, high-resolution, motivational — the kind of photo you'd see on a premium
        fitness app. The physique should be STRIKING and clearly defined — bold enough to genuinely
        inspire. Natural, believable muscle and skin tone. NOT a bodybuilder, NOT objectifying, NO
        excessive vascularity or cartoonish proportions. Just an elite, peak-condition athlete.
        PROMPT;
    }

    /** The dream-physique clause for a given sex + camera angle. */
    private static function bodyFor(bool $female, bool $male, string $angle): string
    {
        if ($female) {
            return match ($angle) {
                'back' => 'full, round, lifted glutes with a clear shelf and filled-out side glutes, strong defined hamstrings and quads, a tight snatched waist flaring into the hips, a toned V-shaped back and capped shoulders, low body fat with healthy feminine curves',
                'side' => 'flat toned stomach, dramatically slim snatched waist, full projected round glutes with a strong upward shelf, lean toned legs, upright confident posture, low body fat with healthy feminine curves',
                default => 'lean sculpted fitness-model physique — sharply defined waist creating a strong natural hourglass, toned sculpted arms and shoulders, full round lifted glutes, lean defined legs, flat toned midsection with subtle ab definition, low body fat with healthy feminine curves',
            };
        }
        if ($male) {
            return match ($angle) {
                'back' => 'wide flaring lats with a dramatic V-taper down to a tight waist, thick defined upper back and capped rear delts, full glutes and strong hamstrings, very low body fat and shredded conditioning',
                'side' => 'flat tight midsection with visible abs, full thick chest, capped 3D shoulders and arms, upright confident posture, very low body fat and shredded conditioning',
                default => 'broad capped shoulders, full defined chest, clearly visible six-pack abs, sculpted muscular arms, wide V-taper down to a tight waist, very low body fat and shredded conditioning',
            };
        }

        return match ($angle) {
            'back' => 'defined muscular back and shoulders, firm shapely glutes and toned legs, low body fat and athletic conditioning',
            'side' => 'flat defined midsection, upright posture, lean athletic build with low body fat',
            default => 'visibly sculpted muscle, defined athletic tone, low body fat and peak conditioning',
        };
    }
}
