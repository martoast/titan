<?php

namespace App\Support;

/**
 * Builds the Nano-Banana prompt for a dream-physique render — gender-aware, because the
 * goal physique is fundamentally different by sex (a man typically wants more muscle and
 * leanness; a woman typically wants a defined waist, stronger glutes/legs and lower body
 * fat with natural curves). The user's own free-text description always layers on top, so
 * anyone can steer it (a woman who wants more muscle, a man who wants a specific look).
 *
 * Deliberately TASTEFUL + identity-preserving: same person, fully clothed, realistic and
 * achievable — never exaggerated, never a fantasy/objectifying render. Keeps the image
 * generator inside its content policy and keeps the result believable as "future you".
 */
class PhysiquePrompt
{
    public static function build(?string $sex, ?string $description = null): string
    {
        $female = $sex === 'F';
        $male = $sex === 'M';

        $body = $female
            ? 'a lean, toned and athletic feminine physique — a visibly slimmer, defined waist (a natural hourglass shape), toned arms and shoulders, and shapely, stronger glutes and legs, with noticeably lower body fat while keeping healthy, natural curves'
            : ($male
                ? 'a leaner, more muscular and athletic build — broader shoulders, a fuller more defined chest and arms, visible core and ab definition, and noticeably lower body fat'
                : 'a leaner, stronger and more athletic version of themselves — visibly toned muscle and noticeably lower body fat');

        $desc = trim((string) $description);
        $wants = $desc !== '' ? " The person specifically wants: {$desc} — honour this within a realistic, healthy result." : '';

        return <<<PROMPT
        Take this exact person from the photo and render their realistic FUTURE SELF after a
        dedicated period of consistent training and good nutrition: {$body}.{$wants}

        Critical constraints: keep their EXACT face, identity, skin tone, hair, bone structure,
        pose, camera framing, lighting, clothing style and background unchanged — this must look
        unmistakably like the SAME person, only fitter. Keep them fully clothed in tasteful
        athletic or everyday wear (the same kind they're wearing). Photorealistic, natural and
        believable — an achievable, healthy transformation. NOT an exaggerated bodybuilder, NOT
        a fashion-model or fantasy render, no objectification, no filters, no facial distortion.
        PROMPT;
    }
}
