<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * THE ONE web sleep-stage vocabulary — the single source of truth the Blade views read, mirroring the
 * iOS `SleepStage` (TitanCore) and the biosignal stager (`biosignal/app/staging.py`: wake / light /
 * deep / rem / nodata). Before this existed the color/label map was copied inline in
 * `sleep/index.blade.php` and diverged (light was wrongly indigo, nodata was missing).
 *
 * Lanes, top→bottom: Awake 0 / REM 1 / Light 2 / Deep 3 (depth reads intuitively as depth). `awake` is
 * a defensive alias of `wake`. `nodata` is a coverage HOLE — an epoch the band never sampled — with NO
 * lane: it renders as an honest full-height hatched gap, never painted as sleep.
 *
 * A new stage added server-side fails loudly in exactly ONE place — {@see self::for()} — instead of
 * silently mis-rendering across the bar, legend, and timeline.
 */
class SleepStages
{
    /** Number of hypnogram lanes (Awake / REM / Light / Deep). */
    public const LANES = 4;

    /**
     * code → [label, tailwind bg class, hex (for SVG), lane (null = hole), hole].
     *
     * @var array<string,array{label:string,class:string,hex:string,lane:int|null,hole:bool}>
     */
    public const MAP = [
        'wake'   => ['label' => 'Awake',   'class' => 'bg-titan-amber',  'hex' => '#FFB020', 'lane' => 0,    'hole' => false],
        'rem'    => ['label' => 'REM',     'class' => 'bg-titan-violet', 'hex' => '#A78BFA', 'lane' => 1,    'hole' => false],
        'light'  => ['label' => 'Light',   'class' => 'bg-titan-cyan',   'hex' => '#22D3EE', 'lane' => 2,    'hole' => false],
        'deep'   => ['label' => 'Deep',    'class' => 'bg-titan-indigo', 'hex' => '#6D6BF6', 'lane' => 3,    'hole' => false],
        'nodata' => ['label' => 'No data', 'class' => 'bg-white/10',     'hex' => '#4A4A55', 'lane' => null, 'hole' => true],
    ];

    /** The four sleep lanes, top→bottom, for legends/axis (excludes the `nodata` hole). */
    public const LANE_CODES = ['wake', 'rem', 'light', 'deep'];

    /**
     * Resolve a raw stage code to its style, treating `awake` as an alias of `wake`. An unknown code
     * (e.g. a stage added server-side) fails loudly in debug — the ONE place — and degrades to a
     * `nodata` hole in production rather than mis-rendering as sleep.
     *
     * @return array{label:string,class:string,hex:string,lane:int|null,hole:bool,code:string}
     */
    public static function for(string $code): array
    {
        $code = strtolower(trim($code));
        if ($code === 'awake') {
            $code = 'wake';
        }
        if (isset(self::MAP[$code])) {
            return self::MAP[$code] + ['code' => $code];
        }
        if (config('app.debug')) {
            throw new \InvalidArgumentException(
                "Unknown sleep-stage code '{$code}' — add it to ".self::class." (the staging.py vocabulary changed)."
            );
        }
        Log::warning("Unknown sleep-stage code '{$code}' — rendering as a nodata hole.");

        return self::MAP['nodata'] + ['code' => 'nodata'];
    }
}
