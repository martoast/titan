<?php

namespace App\Support;

use App\Models\Profile;
use App\Models\RecoveryLog;
use Illuminate\Support\Carbon;

/**
 * How much should we trust a recovery read — and say so out loud.
 *
 * The biosignal service already knows when it's unsure (valid flags, artifact drops) and
 * Readiness knows when the baseline is too thin (provisional). Historically that honesty
 * was computed and then thrown away before it reached the coach, so a number from 3 noisy
 * windows was spoken with the same authority as one from 60 sealed nights. This collapses
 * those signals into a single confidence verdict the coach can phrase honestly:
 *
 *   source  — where the number came from (sealed whole-night > provider > single window > manual)
 *   nights  — how many nights of HRV history back the personal baseline
 *   level   — high | building | low | none
 *   note    — a short plain-language caveat to speak (null when confidence is high)
 */
class RecoveryConfidence
{
    /** Nights of HRV history before a personal baseline is considered solid (mirrors Readiness::MIN_BASELINE). */
    public const FULL_BASELINE = 14;

    /** Classify a recovery row's provenance from its `updated_via` tag. */
    public static function source(?string $updatedVia): string
    {
        $v = strtolower((string) $updatedVia);

        return match (true) {
            str_starts_with($v, 'biosignal:sealed') => 'sealed',   // whole-night aggregate — our gold path
            str_starts_with($v, 'biosignal') => 'window',          // a single processed window, much noisier
            str_starts_with($v, 'device:summary') => 'provider',   // Apple Health / Polar provider summary
            str_starts_with($v, 'manual') => 'manual',             // self-entered estimate
            default => 'unknown',
        };
    }

    /**
     * Assess confidence in a recovery read and the baseline behind it.
     *
     * @param  array<string,mixed>|null  $readiness  optional Readiness::compute() result (for its provisional flag)
     * @return array{level:string, source:string, nights:int, provisional:bool, windows_used:int|null, windows_dropped:int|null, note:string|null}
     */
    public static function assess(Profile $profile, ?RecoveryLog $read, ?array $readiness = null): array
    {
        $source = self::source($read?->updated_via);
        $nights = self::baselineNights($profile, $read?->logged_at);
        $provisional = (bool) ($readiness['provisional'] ?? ($nights < self::FULL_BASELINE));

        $q = is_array($read?->quality) ? $read->quality : [];
        $used = isset($q['windows_used']) ? (int) $q['windows_used'] : null;
        $dropped = isset($q['windows_dropped']) ? (int) $q['windows_dropped'] : null;

        $level = match (true) {
            $read === null => 'none',
            in_array($source, ['manual', 'window'], true) => 'low',  // not a sealed overnight read
            $nights < 4 => 'low',                                     // barely any history to compare against
            $provisional => 'building',                              // real read, baseline still maturing
            default => 'high',
        };

        return [
            'level' => $level,
            'source' => $source,
            'nights' => $nights,
            'provisional' => $provisional,
            'windows_used' => $used,
            'windows_dropped' => $dropped,
            'note' => self::note($level, $source, $nights, $used, $dropped),
        ];
    }

    /** A short caveat to speak alongside the number — null when the read is solid. */
    private static function note(string $level, string $source, int $nights, ?int $used, ?int $dropped): ?string
    {
        $drop = ($dropped && $used !== null && $dropped > 0)
            ? " {$dropped} of ".($used + $dropped).' windows were too noisy and were dropped.'
            : '';

        return match (true) {
            $level === 'none' => 'No recovery read yet — nothing to interpret.',
            $source === 'manual' => 'This is a self-entered estimate, not a sensor read — treat it loosely.'.$drop,
            $source === 'window' => 'This is a single spot window, not a full night — a sealed overnight read is far steadier.'.$drop,
            $nights < 4 => "Only {$nights} night".($nights === 1 ? '' : 's')." of data so far — a rough first read; it sharpens fast.".$drop,
            $level === 'building' => "Still learning your baseline ({$nights} nights) — a sharp read takes ~2 weeks.".$drop,
            default => $drop !== '' ? trim($drop) : null,   // high confidence: only flag if windows were dropped
        };
    }

    /** Distinct nights with a usable HRV value in the 60 days up to (and including) the read's date. */
    private static function baselineNights(Profile $profile, Carbon|string|null $upTo = null): int
    {
        $end = $upTo ? Carbon::parse($upTo)->toDateString() : Carbon::today()->toDateString();

        return (int) $profile->recoveryLogs()
            ->whereNotNull('hrv_ms')->where('hrv_ms', '>', 0)
            ->whereDate('logged_at', '<=', $end)
            ->whereDate('logged_at', '>', Carbon::parse($end)->subDays(60))
            ->distinct()->count('logged_at');
    }
}
