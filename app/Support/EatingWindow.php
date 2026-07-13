<?php

namespace App\Support;

use App\Models\Profile;
use Illuminate\Support\Carbon;

/**
 * The recurring daily EATING WINDOW (time-restricted eating) — the sustainable, evidence-backed proxy for
 * calorie restriction. The research is clear (docs/research/DAVID_SINCLAIR_LONGEVITY.md): a consistent
 * window mostly helps by making it easier to eat less and by keeping eating earlier — NOT clock magic and
 * not a longevity guarantee. So we track *consistency* (a window-adherence streak, like sleep/training),
 * frame it honestly, and note meals eaten outside the window gently — never shaming. (FASTING_EVIDENCE T2.)
 *
 * @see Fasting (the one-off timer) @see WorkoutStreak (the streak this mirrors)
 */
class EatingWindow
{
    /** plan => the number of hours you're ALLOWED to eat (the eating window; the rest is the fast). */
    public const PLANS = ['12:12' => 12, '14:10' => 10, '16:8' => 8, '18:6' => 6, 'omad' => 1];

    private const LOOKBACK_DAYS = 30;   // how far back the adherence streak looks

    /** The configured window {plan, start('HH:MM')} for a profile, or null when unset/invalid. */
    public static function config(Profile $profile): ?array
    {
        $w = $profile->settings['eating_window'] ?? null;
        if (! is_array($w) || ! isset($w['plan'], $w['start']) || ! isset(self::PLANS[$w['plan']])) {
            return null;
        }
        $start = self::normalizeHHMM((string) $w['start']);

        return $start === null ? null : ['plan' => $w['plan'], 'start' => $start];
    }

    /** Set the recurring window. Returns the resolved status, or null if the plan/start is invalid. */
    public static function set(Profile $profile, string $plan, string $start, ?string $tz = null): ?array
    {
        $plan = strtolower(trim($plan));
        $start = self::normalizeHHMM($start);
        if (! isset(self::PLANS[$plan]) || $start === null) {
            return null;
        }
        $settings = $profile->settings ?? [];
        $settings['eating_window'] = ['plan' => $plan, 'start' => $start];
        $profile->update(['settings' => $settings]);

        return self::forProfile($profile->fresh(), $tz);
    }

    /** Turn the window off (back to a plain timer / no window). */
    public static function clear(Profile $profile): void
    {
        $settings = $profile->settings ?? [];
        unset($settings['eating_window']);
        $profile->update(['settings' => $settings]);
    }

    // ---- Pure window math (unit-tested; no DB) --------------------------------

    /** Minute-of-day (0..1439) for an 'HH:MM' string. */
    public static function startMinute(string $hhmm): int
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm));

        return ($h % 24) * 60 + ($m % 60);
    }

    /** The eating window's length in minutes for a plan. */
    public static function windowLengthMin(string $plan): int
    {
        return (self::PLANS[$plan] ?? 8) * 60;
    }

    /**
     * Is a minute-of-day inside the window [start, start+len)? Wrap-aware, so a window that crosses
     * midnight (e.g. 18:00 for 8h → 18:00–02:00) is handled correctly.
     */
    public static function contains(int $minuteOfDay, int $startMin, int $lenMin): bool
    {
        $offset = (($minuteOfDay - $startMin) % 1440 + 1440) % 1440;

        return $offset < $lenMin;
    }

    /**
     * Was a day adherent? A day with logged meals is adherent iff EVERY meal fell inside the window. A day
     * with no meals is not counted (returns null) — it doesn't help or hurt the streak (you may have just
     * not logged), same as SleepWeek skips unlogged nights.
     *
     * @param  array<int,int>  $mealMinutes  minute-of-day for each meal that day
     */
    public static function dayAdherent(array $mealMinutes, int $startMin, int $lenMin): ?bool
    {
        if ($mealMinutes === []) {
            return null;
        }
        foreach ($mealMinutes as $m) {
            if (! self::contains($m, $startMin, $lenMin)) {
                return false;
            }
        }

        return true;
    }

    // ---- Resolved status (window + today + streak) ----------------------------

    /** @return array<string,mixed>|null the window status, or null when no window is configured. */
    public static function forProfile(Profile $profile, ?string $tz = null): ?array
    {
        $cfg = self::config($profile);
        if ($cfg === null) {
            return null;
        }
        $tz = $tz ?: ($profile->settings['timezone'] ?? config('app.timezone', 'UTC'));
        $startMin = self::startMinute($cfg['start']);
        $lenMin = self::windowLengthMin($cfg['plan']);
        $eatH = self::PLANS[$cfg['plan']];

        $now = Carbon::now($tz);
        $open = self::contains($now->hour * 60 + $now->minute, $startMin, $lenMin);

        // Meals grouped by local day (last LOOKBACK_DAYS) for adherence + streak.
        $appTz = config('app.timezone', 'UTC');
        $since = $now->copy()->subDays(self::LOOKBACK_DAYS)->startOfDay()->setTimezone($appTz);
        $byDay = [];   // 'Y-m-d' (local) => [minuteOfDay, …]
        foreach ($profile->meals()->where('eaten_at', '>=', $since)->get() as $meal) {
            $local = $meal->eaten_at->copy()->setTimezone($tz);
            $byDay[$local->toDateString()][] = $local->hour * 60 + $local->minute;
        }

        $todayKey = $now->toDateString();
        $todayOutside = 0;
        foreach ($byDay[$todayKey] ?? [] as $m) {
            if (! self::contains($m, $startMin, $lenMin)) {
                $todayOutside++;
            }
        }

        [$current, $longest] = self::streak($byDay, $now, $startMin, $lenMin);

        return [
            'plan' => $cfg['plan'],
            'start' => $cfg['start'],
            'end' => self::hhmm(($startMin + $lenMin) % 1440),
            'eat_hours' => $eatH,
            'fast_hours' => 24 - $eatH,
            'open' => $open,
            'today_outside' => $todayOutside,
            'streak' => ['current' => $current, 'longest' => $longest],
            'note' => self::honestNote(),
        ];
    }

    /**
     * @return array{0:int,1:int} [current, longest]. Current = consecutive most-recent LOGGED adherent
     * days (an outside-window day or a past gap ends it; today-not-yet-logged is grace, not a break).
     * Longest = the best consecutive-adherent run over the lookback window.
     */
    private static function streak(array $byDay, Carbon $now, int $startMin, int $lenMin): array
    {
        $adherentOn = fn (int $i) => self::dayAdherent($byDay[$now->copy()->subDays($i)->toDateString()] ?? [], $startMin, $lenMin);

        // Current: walk back from today.
        $current = 0;
        for ($i = 0; $i <= self::LOOKBACK_DAYS; $i++) {
            $a = $adherentOn($i);
            if ($a === null) {
                if ($i === 0) {
                    continue;   // today just isn't logged yet → keep the streak alive off yesterday
                }
                break;          // a past day with no meals is a gap → ends the current run
            }
            if ($a === false) {
                break;          // ate outside the window → breaks it
            }
            $current++;
        }

        // Longest: best consecutive adherent run (a gap or an outside day resets it).
        $longest = 0;
        $run = 0;
        for ($i = self::LOOKBACK_DAYS; $i >= 0; $i--) {
            $run = $adherentOn($i) === true ? $run + 1 : 0;
            $longest = max($longest, $run);
        }

        return [$current, max($longest, $current)];
    }

    /** Was a meal eaten OUTSIDE the configured window? null when no window is set (nothing to judge). */
    public static function mealOutside(Profile $profile, Carbon $eatenAt, ?string $tz = null): ?bool
    {
        $cfg = self::config($profile);
        if ($cfg === null) {
            return null;
        }
        $tz = $tz ?: ($profile->settings['timezone'] ?? config('app.timezone', 'UTC'));
        $local = $eatenAt->copy()->setTimezone($tz);

        return ! self::contains($local->hour * 60 + $local->minute, self::startMinute($cfg['start']), self::windowLengthMin($cfg['plan']));
    }

    /** The honest one-liner shown wherever the window appears. */
    public static function honestNote(): string
    {
        return 'A consistent window mostly helps by making it easier to eat less and to eat earlier — that\'s the evidence, not a longevity guarantee.';
    }

    private static function hhmm(int $minuteOfDay): string
    {
        return sprintf('%02d:%02d', intdiv($minuteOfDay, 60), $minuteOfDay % 60);
    }

    /** Accept 'H:MM' / 'HH:MM' (and 'HHMM'); return canonical 'HH:MM' or null. */
    private static function normalizeHHMM(string $s): ?string
    {
        $s = trim($s);
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $s, $m)) {
            $h = (int) $m[1];
            $min = (int) $m[2];
        } elseif (preg_match('/^(\d{2})(\d{2})$/', $s, $m)) {
            $h = (int) $m[1];
            $min = (int) $m[2];
        } else {
            return null;
        }

        return ($h >= 0 && $h < 24 && $min >= 0 && $min < 60) ? sprintf('%02d:%02d', $h, $min) : null;
    }
}
