<?php

namespace App\Support;

use App\Models\Profile;
use App\Models\RecoveryLog;
use App\Models\SleepLog;
use Illuminate\Support\Carbon;

/**
 * The Whoop-style sleep breakdown for the native Sleep tab: a performance %, hours-vs-need, the four
 * stages (with minutes + % of the night), and the derived metrics Whoop headlines — efficiency,
 * restorative sleep (deep+REM), sleep debt, respiratory rate, and consistency. Reuses SleepCoach for
 * need/debt/performance so this is presentation, not a second source of truth.
 */
class SleepDetail
{
    /** @return array<string,mixed>|null */
    public static function forProfile(Profile $profile, ?Carbon $day = null): ?array
    {
        $day = $day ?? Carbon::today();
        $last = SleepLog::where('profile_id', $profile->id)->nights()
            ->whereDate('slept_at', '<=', $day)
            ->orderByDesc('slept_at')->orderByDesc('id')->first();
        if (! $last) {
            return null;
        }

        $assess = null;
        try {
            $assess = SleepCoach::assess($profile, $day);
        } catch (\Throwable) {
            // leave null
        }

        $deep = (int) ($last->deep_min ?? 0);
        $rem = (int) ($last->rem_min ?? 0);
        $light = (int) ($last->light_min ?? 0);
        $awake = (int) ($last->awake_min ?? 0);
        $asleep = $deep + $rem + $light;
        $total = $asleep + $awake;                 // time in bed (staged)
        $duration = (int) ($last->duration_min ?? $asleep);

        // Stages as % of the STAGED night (deep/rem/light/awake). Whoop shows minutes + a bar.
        $den = max(1, $total);
        $stages = [];
        foreach ([
            ['deep', 'Deep (SWS)', $deep, 'indigo'],
            ['rem', 'REM', $rem, 'violet'],
            ['light', 'Light', $light, 'cyan'],
            ['awake', 'Awake', $awake, 'faint'],
        ] as [$key, $label, $min, $color]) {
            $stages[] = ['key' => $key, 'label' => $label, 'min' => $min,
                'pct' => (int) round($min / $den * 100), 'color' => $color];
        }

        // Efficiency = asleep / in-bed (only when awake is tracked, else null rather than a fake 100%).
        $efficiency = $total > 0 && ($deep + $rem + $light) > 0 ? (int) round($asleep / $den * 100) : null;
        $restorative = ($deep + $rem) > 0 ? $deep + $rem : null;   // deep + REM = the recovery stages

        $resp = RecoveryLog::where('profile_id', $profile->id)
            ->whereNotNull('resp_rate')->orderByDesc('logged_at')->value('resp_rate');

        // The night's START as a Unix timestamp. The app compares this to the bedtime of the session it
        // just ended, to be sure it's showing THIS night's summary (not yesterday's, which is what
        // sleepDetail() still returns until the new night seals). Was hardcoded to 30 — the constant
        // epoch length — which made every match fail, so the post-sleep summary never enriched.
        $tz = $profile->settings['timezone'] ?? config('app.timezone', 'UTC');
        $startEpoch = $last->session_start
            ? $last->session_start->timestamp
            : (function () use ($last, $tz) {
                if (! $last->bedtime) {
                    return Carbon::parse($last->slept_at->toDateString(), $tz)->startOfDay()->timestamp;
                }
                $bt = Carbon::parse($last->slept_at->toDateString().' '.$last->bedtime, $tz);
                // Bedtime clock later than wake clock ⇒ went to bed the previous calendar day.
                if ($last->wake_time && (string) $last->bedtime > (string) $last->wake_time) {
                    $bt = $bt->subDay();
                }
                return $bt->timestamp;
            })();

        // A `computing` row is still being staged: its stages/quality are NULL and `$assess` reflects the last
        // FINAL night — so don't pair yesterday's performance ring with tonight's placeholder card (it would
        // visibly jump on finalize). The app renders the loading state off `stage_status` instead.
        $computing = $last->stage_status === \App\Models\SleepLog::STATUS_COMPUTING;

        return [
            'date' => $last->slept_at?->toDateString(),
            'performance_pct' => $computing ? null : ($assess['performance_pct'] ?? $last->quality),
            'duration_min' => $duration,
            'need_h' => $assess['need_h'] ?? null,
            'debt_h' => $assess['debt_h'] ?? null,
            'in_bed_min' => $total > 0 ? $total : null,
            'asleep_min' => $asleep > 0 ? $asleep : $duration,
            'efficiency_pct' => $efficiency,
            'restorative_min' => $restorative,
            'respiratory_rate' => $resp !== null ? round((float) $resp, 1) : null,
            'consistency_pct' => self::consistency($profile, $day),
            'quality' => $last->quality,
            'stages' => $stages,
            // The classic Whoop hypnogram: the per-30s stage sequence + its clock span, so the app can
            // draw the wavy stage timeline from bedtime to wake.
            'bedtime' => $last->bedtime ? Carbon::parse($last->bedtime)->format('H:i') : null,
            'wake_time' => $last->wake_time ? Carbon::parse($last->wake_time)->format('H:i') : null,
            'hypnogram' => is_array($last->hypnogram) && count($last->hypnogram) ? $last->hypnogram : null,
            'epoch_sec' => $startEpoch,
            // Progressive summary: expose the compute state so any consumer can tell a still-`computing`
            // placeholder (real duration/times, but stages/quality NULL → 0% here) from a settled `final` night.
            'stage_status' => $last->stage_status,
            'finalized_at' => $last->finalized_at?->toIso8601String(),
        ];
    }

    /**
     * Sleep consistency (0-100): how regular your bed/wake times have been over recent nights — the
     * lower the spread of bed + wake clock-times, the higher the score. Whoop weights consistency
     * heavily because a steady schedule drives recovery. Null until there are ≥3 nights with times.
     */
    private static function consistency(Profile $profile, Carbon $day): ?int
    {
        $nights = SleepLog::where('profile_id', $profile->id)->nights()
            ->whereDate('slept_at', '>=', $day->copy()->subDays(7))
            ->whereNotNull('bedtime')->whereNotNull('wake_time')
            ->get();
        if ($nights->count() < 3) {
            return null;
        }
        $minsOfDay = fn ($t) => $t ? (Carbon::parse($t)->hour * 60 + Carbon::parse($t)->minute) : null;
        // Bed/wake as minutes-of-day, unwrapped around midnight for bedtime (23:30 and 00:30 are close).
        $bed = $nights->map(fn ($n) => self::unwrap((int) $minsOfDay($n->bedtime)))->filter()->values();
        $wake = $nights->map(fn ($n) => (int) $minsOfDay($n->wake_time))->filter()->values();
        if ($bed->count() < 3 || $wake->count() < 3) {
            return null;
        }
        $spread = (self::std($bed) + self::std($wake)) / 2.0;   // avg minutes of jitter
        // 0 min jitter → 100; ~120 min → ~0. A gentle linear map, clamped.
        return (int) round(max(0.0, min(100.0, 100.0 - $spread / 1.2)));
    }

    /** Shift late-evening bedtimes (>18:00) negative so they cluster with just-after-midnight ones. */
    private static function unwrap(int $mins): int { return $mins > 18 * 60 ? $mins - 1440 : $mins; }

    private static function std($series): float
    {
        $vals = collect($series)->map(fn ($v) => (float) $v);
        $n = $vals->count();
        if ($n < 2) {
            return 0.0;
        }
        $mean = $vals->avg();

        return sqrt($vals->reduce(fn ($c, $v) => $c + ($v - $mean) ** 2, 0.0) / $n);
    }
}
