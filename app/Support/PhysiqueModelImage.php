<?php

namespace App\Support;

/**
 * Maps sex + angle to the pre-generated static model physique images stored in
 * public/images/physique/models/. These replace on-the-fly AI generation which
 * was blocked by Gemini IMAGE_SAFETY for person-photo transformation requests.
 *
 * The user's own photo is still uploaded and stored for progress tracking and
 * coach analysis -- it just isn't used as AI input anymore.
 */
class PhysiqueModelImage
{
    /** Return the filename (not a full path) for the given sex + angle. */
    public static function filename(?string $sex, string $angle = 'front'): string
    {
        $angle = in_array($angle, ['front', 'back', 'side'], true) ? $angle : 'front';
        $prefix = $sex === 'F' ? 'female' : 'male';

        return "{$prefix}-{$angle}.jpg";
    }

    /** Return the path relative to public/ so it can be served as a static asset. */
    public static function path(?string $sex, string $angle = 'front'): string
    {
        return 'images/physique/models/'.self::filename($sex, $angle);
    }

    /** Return the full public URL. */
    public static function url(?string $sex, string $angle = 'front'): string
    {
        return asset('images/physique/models/'.self::filename($sex, $angle));
    }
}
