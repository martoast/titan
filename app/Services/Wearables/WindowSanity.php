<?php

namespace App\Services\Wearables;

use Carbon\CarbonImmutable;

/**
 * A cheap signal-sanity gate at the ingestion boundary.
 *
 * The HMAC proves WHO sent a batch -- not that the SIGNAL is real. A misconfigured or buggy
 * band can stream flatlined PPG, an impossible sample rate, NaNs, or a window whose clock is
 * wrong, and (before this gate) all of it flowed straight to MinIO and the processing queue,
 * where a garbage window could masquerade as data or misfile a night.
 *
 * This rejects clearly-corrupt RAW windows at the door. It is deliberately CONSERVATIVE:
 * it only drops windows that are unambiguously broken (no signal, impossible values, an
 * impossible clock) and leaves real-but-noisy signal to the DSP's own quality gates, which
 * are the authority on "is this HRV trustworthy". Provider summaries (Shape C) are computed
 * upstream and pass through untouched.
 */
class WindowSanity
{
    public const SR_MIN = 10;            // Hz -- below this, PPG can't resolve beats at all
    public const SR_MAX = 1000;          // Hz -- above this is a misreport, not a wrist sensor
    public const IBI_MIN_MS = 250;       // ~240 bpm ceiling -- tighter is the DSP's job
    public const IBI_MAX_MS = 2500;      // ~24 bpm floor
    public const MIN_PPG_SAMPLES = 8;    // a window with fewer samples carries no usable beat
    public const MAX_WINDOW_SEC = 21600; // 6h -- a raw "window" longer than this is nonsense
    public const FUTURE_TOLERANCE_SEC = 86400; // 1 day of clock-drift grace before we call it impossible

    /**
     * @param  array<string,mixed>  $window
     * @return array{ok:bool, reason:string|null}
     */
    public static function check(array $window, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $kind = (string) ($window['kind'] ?? 'ibi');

        // --- Timestamps (all kinds): an impossible clock can misfile a whole night ---
        $start = self::ts($window['start'] ?? null);
        $end = self::ts($window['end'] ?? null);
        if ($start && $end && $end->lessThan($start)) {
            return self::bad('end_before_start');
        }
        $ref = $end ?? $start;
        if ($ref && $ref->greaterThan($now->addSeconds(self::FUTURE_TOLERANCE_SEC))) {
            return self::bad('timestamp_in_future');
        }
        if ($start && $end && $start->diffInSeconds($end) > self::MAX_WINDOW_SEC) {
            return self::bad('window_too_long');
        }

        return match ($kind) {
            'ppg_raw' => self::checkPpg($window, $start, $end),
            'ibi' => self::checkIbi($window),
            default => self::ok(),   // sleep / other raw kinds: timestamp sanity only
        };
    }

    /** @param array<string,mixed> $window */
    private static function checkPpg(array $window, ?CarbonImmutable $start, ?CarbonImmutable $end): array
    {
        $ppg = $window['ppg'] ?? null;
        if (! is_array($ppg) || count($ppg) < self::MIN_PPG_SAMPLES) {
            return self::bad('ppg_too_short');
        }

        $min = null;
        $max = null;
        foreach ($ppg as $v) {
            if (! is_numeric($v) || ! is_finite((float) $v)) {
                return self::bad('ppg_non_finite');   // NaN / Inf / garbage sample
            }
            $f = (float) $v;
            $min = $min === null ? $f : min($min, $f);
            $max = $max === null ? $f : max($max, $f);
        }
        if ($min === $max) {
            return self::bad('ppg_flatline');   // sensor saturated / detached -- no pulse at all
        }

        $rate = $window['sample_rate_hz'] ?? null;
        if (! is_numeric($rate) || (float) $rate < self::SR_MIN || (float) $rate > self::SR_MAX) {
            return self::bad('bad_sample_rate');
        }

        // Cross-check the claimed rate against what the timestamps imply. Only when the
        // window is long enough for the ratio to be meaningful (short live frames have too
        // little duration). Generous 2× tolerance -- we catch gross misreports (a 5× wrong
        // clock that poisons beat timing), not fine calibration the DSP can ride out.
        if ($start && $end) {
            $sec = $start->diffInSeconds($end);
            if ($sec >= 2) {
                $implied = count($ppg) / $sec;
                $claimed = (float) $rate;
                if ($implied < $claimed / 2 || $implied > $claimed * 2) {
                    return self::bad('sample_rate_mismatch');
                }
            }
        }

        return self::ok();
    }

    /** @param array<string,mixed> $window */
    private static function checkIbi(array $window): array
    {
        $ibi = $window['ibi_ms'] ?? null;
        if (! is_array($ibi) || $ibi === []) {
            return self::bad('ibi_empty');
        }

        $plausible = 0;
        $first = null;
        $allSame = true;
        foreach ($ibi as $v) {
            if (! is_numeric($v) || ! is_finite((float) $v)) {
                return self::bad('ibi_non_finite');
            }
            $f = (float) $v;
            if ($f >= self::IBI_MIN_MS && $f <= self::IBI_MAX_MS) {
                $plausible++;
            }
            $first ??= $f;
            if ($f !== $first) {
                $allSame = false;
            }
        }
        if ($plausible === 0) {
            return self::bad('ibi_implausible');   // not a single beat in human range
        }
        if ($allSame && count($ibi) >= 6) {
            return self::bad('ibi_flatline');       // identical intervals = synthetic/stuck, not a real heart
        }

        return self::ok();
    }

    private static function ts(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function ok(): array
    {
        return ['ok' => true, 'reason' => null];
    }

    private static function bad(string $reason): array
    {
        return ['ok' => false, 'reason' => $reason];
    }
}
