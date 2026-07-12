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

        return $last ? self::detailForLog($last, $profile, $day) : null;
    }

    /**
     * Every sleep SESSION for the user's local day — the overnight AND each nap — as its own full detail,
     * plus a daily aggregate (total asleep + combined stages). Sleep is modelled like workouts: a list of
     * sessions above a day total, not one "last night". Grouped on the PROFILE timezone: both `slept_at`
     * (night wake-date) and `session_start` (nap start) are stored profile-tz-local, so a nap taken "today"
     * in Tijuana lands on today even when the server's app tz differs (the recurring tz trap).
     *
     * @return array{date:string,sessions:array<int,array<string,mixed>>,aggregate:array<string,mixed>}
     */
    public static function sessionsForDay(Profile $profile, ?Carbon $day = null): array
    {
        $tz = self::tz($profile);
        $day = $day ?? Carbon::now($tz);
        $dayStr = $day->toDateString();

        // A night belongs to the day by its wake-date (slept_at); a nap by its start-date (session_start).
        // whereDate on either compares the stored profile-tz-local string's date — exactly the local day.
        $logs = SleepLog::where('profile_id', $profile->id)
            ->where(function ($q) use ($dayStr) {
                $q->where(fn ($n) => $n->where('is_nap', false)->whereDate('slept_at', $dayStr))
                    ->orWhere(fn ($n) => $n->where('is_nap', true)->whereDate('session_start', $dayStr));
            })
            ->get()
            ->sortByDesc(fn (SleepLog $l) => self::sessionStartTs($l, $tz))   // newest session first
            ->values();

        return [
            'date' => $dayStr,
            'sessions' => $logs->map(fn (SleepLog $l) => self::detailForLog($l, $profile, $day))->all(),
            'aggregate' => self::aggregate($logs, $profile, $day),
        ];
    }

    /**
     * The Whoop-style breakdown for ONE session (night or nap). A nap is not a scored night: its
     * performance/need/debt/consistency are null (those are night-level; a nap ADDS to the day total but
     * never masquerades as a night), while its stages/hypnogram/movement/HR timeline are exposed in full
     * so it opens to its own v2 timeline just like the overnight.
     *
     * @return array<string,mixed>
     */
    public static function detailForLog(SleepLog $log, Profile $profile, ?Carbon $day = null): array
    {
        $day = $day ?? Carbon::today();
        $isNap = (bool) $log->is_nap;

        // Night-level read (need/debt/performance) — only meaningful for the overnight.
        $assess = $isNap ? null : rescue(fn () => SleepCoach::assess($profile, $day), null, false);

        $deep = (int) ($log->deep_min ?? 0);
        $rem = (int) ($log->rem_min ?? 0);
        $light = (int) ($log->light_min ?? 0);
        $awake = (int) ($log->awake_min ?? 0);
        $asleep = $deep + $rem + $light;
        $total = $asleep + $awake;                 // time in bed (staged)
        $duration = (int) ($log->duration_min ?? $asleep);
        $last = $log;                              // keep the rest of the body's references terse

        // Stages as % of the STAGED night (deep/rem/light/awake). Whoop shows minutes + a bar.
        $den = max(1, $total);
        // No `color` here: iOS re-derives the swatch from `key` and web reads SleepStages.php — a per-stage
        // color string in the payload was a stray divergent copy (spec v2 §4), so it's dropped.
        $stages = [];
        foreach ([
            ['deep', 'Deep (SWS)', $deep],
            ['rem', 'REM', $rem],
            ['light', 'Light', $light],
            ['awake', 'Awake', $awake],
        ] as [$key, $label, $min]) {
            $stages[] = ['key' => $key, 'label' => $label, 'min' => $min,
                'pct' => (int) round($min / $den * 100)];
        }

        // Efficiency = asleep / in-bed (only when awake is tracked, else null rather than a fake 100%).
        $efficiency = $total > 0 && ($deep + $rem + $light) > 0 ? (int) round($asleep / $den * 100) : null;
        $restorative = ($deep + $rem) > 0 ? $deep + $rem : null;   // deep + REM = the recovery stages

        $resp = RecoveryLog::where('profile_id', $profile->id)
            ->whereNotNull('resp_rate')->orderByDesc('logged_at')->value('resp_rate');

        // The session's START as a Unix timestamp (the timeline's t0 — epoch i's clock = startEpoch + i×30).
        // Read tz-correctly from the profile-tz-local stored values (see sessionStartTs).
        $startEpoch = self::sessionStartTs($last, self::tz($profile));

        // A `computing` row is still being staged: its stages/quality are NULL and `$assess` reflects the last
        // FINAL night — so don't pair yesterday's performance ring with tonight's placeholder card (it would
        // visibly jump on finalize). The app renders the loading state off `stage_status` instead.
        $computing = $last->stage_status === \App\Models\SleepLog::STATUS_COMPUTING;

        return [
            'id' => $last->id,
            'is_nap' => $isNap,
            'date' => $last->slept_at?->toDateString(),
            // A nap is NOT a scored night: performance/need/debt/consistency stay null so it can't
            // masquerade as one (it still adds to the day total via the aggregate).
            'performance_pct' => ($isNap || $computing) ? null : ($assess['performance_pct'] ?? $last->quality),
            'duration_min' => $duration,
            'need_h' => $isNap ? null : ($assess['need_h'] ?? null),
            'debt_h' => $isNap ? null : ($assess['debt_h'] ?? null),
            'in_bed_min' => $total > 0 ? $total : null,
            'asleep_min' => $asleep > 0 ? $asleep : $duration,
            'efficiency_pct' => $efficiency,
            'restorative_min' => $restorative,
            'respiratory_rate' => $resp !== null ? round((float) $resp, 1) : null,
            'consistency_pct' => $isNap ? null : self::consistency($profile, $day),
            'quality' => $last->quality,
            'stages' => $stages,
            // The classic Whoop hypnogram: the per-30s stage sequence + its clock span, so the app can
            // draw the wavy stage timeline from bedtime to wake.
            'bedtime' => $last->bedtime ? Carbon::parse($last->bedtime)->format('H:i') : null,
            'wake_time' => $last->wake_time ? Carbon::parse($last->wake_time)->format('H:i') : null,
            'hypnogram' => is_array($last->hypnogram) && count($last->hypnogram) ? $last->hypnogram : null,
            // v2 movement/peaks overlays: sparse, gap-honest {i,v} series on the SAME epoch_sec+30s grid as
            // the hypnogram (epoch i's clock = epoch_sec + i×30). Only MEASURED epochs — a band-off/charge gap
            // is a hole, never interpolated. Persisted downsampled (≤180 pts, max-pool) at seal time.
            'hr_series' => $last->hr_series ?: null,
            'motion_series' => $last->motion_series ?: null,
            'epoch_sec' => $startEpoch,
            // Progressive summary: expose the compute state so any consumer can tell a still-`computing`
            // placeholder (real duration/times, but stages/quality NULL → 0% here) from a settled `final` night.
            'stage_status' => $last->stage_status,
            // Low-signal night → the app shows the read as an estimate + a fit-check hint, not a hard number.
            'low_confidence' => (bool) $last->low_confidence,
            'finalized_at' => $last->finalized_at?->toIso8601String(),
        ];
    }

    private static function tz(Profile $profile): string
    {
        return $profile->settings['timezone'] ?? config('app.timezone', 'UTC');
    }

    /** A session's true start instant, from the profile-tz-local stored values (NOT the app-tz cast). */
    private static function sessionStartTs(SleepLog $log, string $tz): int
    {
        // session_start (naps, + any session that stored it) is a PROFILE-tz wall-clock string: parse the
        // RAW value in the profile tz, or a Tijuana session reads hours off on a Mexico_City server.
        $raw = $log->getRawOriginal('session_start');
        if ($raw) {
            return Carbon::parse((string) $raw, $tz)->timestamp;
        }
        // A night stores slept_at (local date) + bedtime/wake_time (bare H:i:s).
        if (! $log->bedtime) {
            return Carbon::parse($log->slept_at->toDateString(), $tz)->startOfDay()->timestamp;
        }
        $bt = Carbon::parse($log->slept_at->toDateString().' '.$log->bedtime, $tz);
        if ($log->wake_time && (string) $log->bedtime > (string) $log->wake_time) {
            $bt = $bt->subDay();   // bedtime clock later than wake clock ⇒ went to bed the previous day
        }

        return $bt->timestamp;
    }

    /**
     * The day's roll-up across all sessions: total asleep + combined stage minutes, session counts, and a
     * night-anchored performance/need/debt. Naps ADD to total sleep and REDUCE debt (real recovery), but
     * the scored performance stays the overnight's — a nap can't inflate it.
     *
     * @param  \Illuminate\Support\Collection<int,SleepLog>  $logs
     * @return array<string,mixed>
     */
    private static function aggregate($logs, Profile $profile, Carbon $day): array
    {
        $deep = (int) $logs->sum('deep_min');
        $rem = (int) $logs->sum('rem_min');
        $light = (int) $logs->sum('light_min');
        $awake = (int) $logs->sum('awake_min');
        $asleep = $deep + $rem + $light;

        $nights = $logs->where('is_nap', false);
        $naps = $logs->where('is_nap', true);
        $napAsleep = (int) $naps->sum(fn (SleepLog $l) => (int) $l->deep_min + (int) $l->rem_min + (int) $l->light_min);

        $assess = rescue(fn () => SleepCoach::assess($profile, $day), null, false);
        $debt = $assess['debt_h'] ?? null;
        if ($debt !== null && $napAsleep > 0) {
            $debt = max(0.0, round($debt - $napAsleep / 60.0, 1));   // a nap chips into the debt
        }

        return [
            'total_asleep_min' => $asleep,
            'deep_min' => $deep,
            'rem_min' => $rem,
            'light_min' => $light,
            'awake_min' => $awake,
            'session_count' => $logs->count(),
            'night_count' => $nights->count(),
            'nap_count' => $naps->count(),
            // Night-anchored: naps add minutes + cut debt but never score as a night.
            'performance_pct' => $assess['performance_pct'] ?? null,
            'need_h' => $assess['need_h'] ?? null,
            'debt_h' => $debt,
            'label' => $naps->count() > 0
                ? $nights->count().' night'.($nights->count() === 1 ? '' : 's').' + '.$naps->count().' nap'.($naps->count() === 1 ? '' : 's')
                : null,
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
