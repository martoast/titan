<?php

namespace App\Support;

use App\Models\CycleLog;
use App\Models\MenstrualCycle;
use App\Models\Profile;
use Illuminate\Support\Carbon;

/**
 * The menstrual-cycle engine -- Titan's cycle-aware lens.
 *
 * It answers, from logged period starts + each person's own averages: what cycle day is it,
 * which phase (menstrual → follicular → fertile → ovulation → luteal), when the next period and
 * ovulation are likely, where the fertile window sits, and a plain conception-likelihood label.
 * It also ties the cycle to the rest of Titan: resting-HR / HRV shift by phase (so a luteal-phase
 * RHR bump reads as "expected", not "poor recovery"), iron/nutrition needs change with bleeding,
 * and hormone bloodwork is only interpretable against the cycle day it was drawn.
 *
 * --- The two rails (never crossed) ----------------------------------------------------------
 *  1. WELLNESS, NOT MEDICAL. The fertile window / conception likelihood is AWARENESS only -- it is
 *     NOT a contraceptive method and must never be presented as one (see DISCLAIMER). We don't
 *     diagnose (PCOS, endometriosis, pregnancy); at most we flag a pattern "worth a doctor's eyes".
 *  2. Estimates are honest. Predictions need real data; with <2 logged cycles we say so and lean on
 *     the person's configured averages rather than pretending precision.
 *
 * Physiology basis: the luteal phase is the stable part of the cycle (~12-14 d); cycle-length
 * variation is mostly follicular. So ovulation is estimated as next_period − luteal_length, and the
 * fertile window as the ~5 days before ovulation through ~1 day after (sperm ~5 d, egg ~24 h).
 */
class Cycle
{
    public const DEFAULT_LENGTH = 28;
    public const DEFAULT_PERIOD = 5;
    public const DEFAULT_LUTEAL = 14;
    public const FERTILE_PRE = 5;    // fertile days before ovulation
    public const FERTILE_POST = 1;   // fertile days after ovulation

    public const DISCLAIMER = 'These are estimates for awareness, not a contraceptive method or medical advice. Cycles vary -- for preventing or planning pregnancy, talk to a healthcare provider.';

    /** Symptoms we recognise (for the UI + the coach's vocabulary). */
    public const SYMPTOMS = [
        'cramps', 'headache', 'bloating', 'fatigue', 'mood_swings', 'irritability', 'anxiety',
        'tender_breasts', 'acne', 'nausea', 'back_pain', 'insomnia', 'cravings', 'low_libido', 'high_libido',
    ];

    public const FLOWS = ['none', 'spotting', 'light', 'medium', 'heavy'];

    /** Per-request memo for available() — it's called many times per page (nav + controller + support classes). */
    private static array $availableMemo = [];

    /** Should the cycle features even be offered to this profile? (female, or explicitly enabled, or has data). */
    public static function available(Profile $profile): bool
    {
        $key = $profile->id ?? spl_object_id($profile);
        if (isset(self::$availableMemo[$key])) {
            return self::$availableMemo[$key];
        }

        $available = self::config($profile)['enabled']
            || in_array(strtolower((string) ($profile->sex ?? '')), ['f', 'female', 'woman', 'w'], true)
            || $profile->menstrualCycles()->exists();   // only this branch touches the DB

        return self::$availableMemo[$key] = $available;
    }

    /** Merged cycle settings (profile.settings['cycle']) over sane defaults. */
    public static function config(Profile $profile): array
    {
        $c = $profile->settings['cycle'] ?? [];

        return [
            'enabled' => (bool) ($c['enabled'] ?? false),
            'avg_length' => self::clampInt($c['avg_length'] ?? self::DEFAULT_LENGTH, 21, 45),
            'avg_period' => self::clampInt($c['avg_period'] ?? self::DEFAULT_PERIOD, 1, 10),
            'luteal_length' => self::clampInt($c['luteal_length'] ?? self::DEFAULT_LUTEAL, 9, 17),
            'birth_control' => (string) ($c['birth_control'] ?? 'none'),   // none|pill|patch|ring|hormonal_iud|copper_iud|implant|injection|other
            'intent' => (string) ($c['intent'] ?? 'tracking'),             // tracking|conceiving|avoiding
        ];
    }

    public static function hormonalBirthControl(string $bc): bool
    {
        return in_array($bc, ['pill', 'patch', 'ring', 'hormonal_iud', 'implant', 'injection'], true);
    }

    /**
     * The full cycle snapshot for a day (default today). The one method the UI, coach and daily
     * summary all read. Returns has_data=false (with a gentle prompt) when nothing is logged yet.
     */
    public static function status(Profile $profile, ?Carbon $on = null): array
    {
        $cfg = self::config($profile);
        $tz = $profile->settings['timezone'] ?? config('app.timezone', 'UTC');
        $today = ($on ?? Carbon::now($tz))->copy()->startOfDay();

        $current = $profile->menstrualCycles()
            ->whereDate('start_date', '<=', $today->toDateString())
            ->orderByDesc('start_date')->first();

        if (! $current) {
            return [
                'tracking' => true,
                'has_data' => false,
                'config' => $cfg,
                'note' => 'No cycle logged yet. Log the first day of your last period and I can map your phases, predict your next one, and factor your cycle into recovery and nutrition.',
                'disclaimer' => self::DISCLAIMER,
            ];
        }

        $closed = $profile->menstrualCycles()->whereNotNull('length_days')
            ->orderByDesc('start_date')->take(6)->pluck('length_days')->map(fn ($v) => (int) $v)->all();
        $avgLen = self::averageLength($closed, $cfg['avg_length']);
        $periodLen = self::averagePeriod($profile, $cfg['avg_period']);

        $startDate = $current->start_date->copy()->startOfDay();
        $cycleDay = self::daysBetween($startDate, $today) + 1;        // day 1 = the start date

        $ovDay = max(1, $avgLen - $cfg['luteal_length']);            // e.g. 28 − 14 = 14
        $fertileStartDay = max(1, $ovDay - self::FERTILE_PRE);
        $fertileEndDay = $ovDay + self::FERTILE_POST;

        $nextPeriod = $startDate->copy()->addDays($avgLen);
        $ovDate = $startDate->copy()->addDays($ovDay - 1);
        $fertileStart = $startDate->copy()->addDays($fertileStartDay - 1);
        $fertileEnd = $startDate->copy()->addDays($fertileEndDay - 1);
        $inDaysNext = self::daysBetween($today, $nextPeriod);

        [$phase, $phaseLabel] = self::phaseFor($cycleDay, $periodLen, $ovDay, $fertileStartDay, $fertileEndDay, $avgLen);
        [$likelihood, $conNote] = self::conception($cycleDay, $ovDay, $fertileStartDay, $fertileEndDay, $cfg['birth_control']);

        $hormonalBc = self::hormonalBirthControl($cfg['birth_control']);
        $late = $inDaysNext < 0;

        return [
            'tracking' => true,
            'has_data' => true,
            'config' => $cfg,
            'today' => $today->toDateString(),
            'last_period_start' => $startDate->toDateString(),
            'cycle_day' => $cycleDay,
            'phase' => $phase,
            'phase_label' => $phaseLabel,
            'phase_blurb' => self::phaseBlurb($phase),
            'avg_length' => $avgLen,
            'period_length' => $periodLen,
            'cycles_tracked' => $profile->menstrualCycles()->count(),
            'regularity' => self::regularity($closed),
            'next_period' => [
                'date' => $nextPeriod->toDateString(),
                'in_days' => $inDaysNext,
                'late_days' => $late ? abs($inDaysNext) : 0,
            ],
            'ovulation' => [
                'date' => $ovDate->toDateString(),
                'in_days' => self::daysBetween($today, $ovDate),
                'day' => $ovDay,
                'estimated' => true,
            ],
            'fertile_window' => [
                'start' => $fertileStart->toDateString(),
                'end' => $fertileEnd->toDateString(),
                'active' => ! $hormonalBc && $cycleDay >= $fertileStartDay && $cycleDay <= $fertileEndDay,
                'applicable' => ! $hormonalBc,
            ],
            'conception' => [
                'likelihood' => $hormonalBc ? 'low' : $likelihood,
                'note' => $conNote,
                'disclaimer' => self::DISCLAIMER,
            ],
            'birth_control' => $cfg['birth_control'],
            'intent' => $cfg['intent'],
            'late' => $late,
            'today_log' => self::todayLog($profile, $today),
            'note' => self::summaryLine($phaseLabel, $cycleDay, $inDaysNext, $late, $hormonalBc, $likelihood),
            'disclaimer' => self::DISCLAIMER,
        ];
    }

    /**
     * A per-day calendar projected forward (and back) from the last logged period — for the app's
     * calendar view, so you can plan ahead. Each day is classified by the same phase model as status(),
     * repeating every avg_length. Past logged-period accuracy is a future refinement; this is the
     * standard period-app prediction.
     *
     * @return array<int,array{date:string,cycle_day:int,phase:string,period:bool,fertile:bool,ovulation:bool}>
     */
    public static function calendar(Profile $profile, Carbon $from, int $days): array
    {
        $start = $profile->menstrualCycles()->orderByDesc('start_date')->value('start_date');
        if (! $start) {
            return [];
        }
        $cfg = self::config($profile);
        $anchor = Carbon::parse($start)->startOfDay();
        $len = $cfg['avg_length'];
        $period = $cfg['avg_period'];
        $ovDay = max(1, $len - $cfg['luteal_length']);
        $fStart = max(1, $ovDay - self::FERTILE_PRE);
        $fEnd = $ovDay + self::FERTILE_POST;
        $hormonalBc = self::hormonalBirthControl($cfg['birth_control']);

        $out = [];
        for ($i = 0; $i < $days; $i++) {
            $d = $from->copy()->addDays($i)->startOfDay();
            $delta = (int) $anchor->diffInDays($d, false);           // signed days from the anchor
            $cd = ((($delta % $len) + $len) % $len) + 1;             // 1..len, wrapping each cycle
            [$phase] = self::phaseFor($cd, $period, $ovDay, $fStart, $fEnd, $len);
            $out[] = [
                'date' => $d->toDateString(),
                'cycle_day' => $cd,
                'phase' => $phase,
                'period' => $cd <= $period,
                'fertile' => ! $hormonalBc && $cd >= $fStart && $cd <= $fEnd,
                'ovulation' => ! $hormonalBc && $cd === $ovDay,
            ];
        }

        return $out;
    }

    /**
     * Wellness guidance for a phase -- how it tends to shape training, nutrition, body/energy and intimacy.
     * Supportive and evidence-informed, never clinical. @return array{training:string,nutrition:string,body:string,vibe:string}
     */
    public static function guidanceFor(string $phase): array
    {
        return match ($phase) {
            'menstrual' => [
                'training' => 'Energy can be low on heavier-flow days -- train to feel; lighter is fine and it often lifts as the period eases.',
                'nutrition' => 'Lean on iron-rich foods (red meat, leafy greens, lentils) to offset menstrual losses.',
                'body' => 'Cramps and fatigue are normal -- warmth, hydration and rest help.',
                'vibe' => 'menstrual -- train to feel, refuel iron',
            ],
            'follicular' => [
                'training' => 'Rising estrogen means strength and energy are climbing -- your best window to PUSH: PRs, heavy loads, higher volume.',
                'nutrition' => 'Insulin sensitivity is great here -- carbs are well used, so fuel the harder training.',
                'body' => 'Mood and motivation usually run high -- capitalise on it.',
                'vibe' => 'follicular -- prime to push hard',
            ],
            'fertile', 'ovulation' => [
                'training' => 'Peak strength and power -- go for PRs. Joints are a touch laxer near ovulation, so keep form tight on heavy lifts.',
                'nutrition' => 'Appetite is usually steady -- keep protein up around the hard sessions.',
                'body' => 'Energy and libido typically peak. This is the fertile window -- pregnancy is most likely now if not using contraception (awareness only).',
                'vibe' => 'ovulation -- peak power, fertile window',
            ],
            'luteal' => [
                'training' => 'Energy may dip later in this phase and resting HR runs a little higher -- favour moderate volume over max intensity, especially the few days before your period.',
                'nutrition' => 'Metabolism and hunger rise -- a small calorie bump (~5-10%) is normal; lean on protein and fibre for cravings, and ease off salt for bloating.',
                'body' => 'PMS -- mood swings, poorer sleep, bloating -- can show up premenstrually; extra sleep and self-compassion go a long way.',
                'vibe' => 'luteal -- recover well, fuel a little more',
            ],
            default => ['training' => '', 'nutrition' => '', 'body' => '', 'vibe' => ''],
        };
    }

    /** A concise always-on digest of where she is in her cycle + what it means today (for the coach context). */
    public static function coachDigest(Profile $profile): string
    {
        if (! self::available($profile)) {
            return '';
        }
        $s = rescue(fn () => self::status($profile), null, false);
        if (! $s || empty($s['phase'])) {
            return '';
        }
        $g = self::guidanceFor($s['phase']);
        $line = "Day {$s['cycle_day']}, {$s['phase_label']} phase";
        if (isset($s['next_period']['in_days']) && $s['next_period']['in_days'] !== null) {
            $line .= " (~{$s['next_period']['in_days']}d to next period)";
        }
        $line .= ". Training: {$g['training']} Nutrition: {$g['nutrition']} Body: {$g['body']}";
        if (! empty($s['fertile_window']['active'])) {
            $line .= ' She is in her fertile window now.';
        }

        return $line;
    }

    /** A short phase tag for cards/summaries, e.g. "Day 8 · follicular -- prime to push hard". */
    public static function shortLine(Profile $profile): ?string
    {
        if (! self::available($profile)) {
            return null;
        }
        $s = rescue(fn () => self::status($profile), null, false);
        if (! $s || empty($s['phase'])) {
            return null;
        }
        $vibe = self::guidanceFor($s['phase'])['vibe'] ?: $s['phase_label'];

        return "Day {$s['cycle_day']} · {$vibe}";
    }

    /** Phase + cycle day for ANY date, by finding the cycle that contains it. Powers per-phase insights. */
    public static function phaseOn(Profile $profile, Carbon $date): ?array
    {
        $cfg = self::config($profile);
        $date = $date->copy()->startOfDay();
        $cycle = $profile->menstrualCycles()
            ->whereDate('start_date', '<=', $date->toDateString())
            ->orderByDesc('start_date')->first();
        if (! $cycle) {
            return null;
        }
        $avgLen = self::averageLength(
            $profile->menstrualCycles()->whereNotNull('length_days')->orderByDesc('start_date')->take(6)->pluck('length_days')->map(fn ($v) => (int) $v)->all(),
            $cfg['avg_length'],
        );
        // Don't attribute a date to a cycle it can't belong to (a big gap with no logging).
        $cycleDay = self::daysBetween($cycle->start_date->copy()->startOfDay(), $date) + 1;
        if ($cycleDay < 1 || $cycleDay > $avgLen + 10) {
            return null;
        }
        $periodLen = self::averagePeriod($profile, $cfg['avg_period']);
        $ovDay = max(1, $avgLen - $cfg['luteal_length']);
        [$phase, $label] = self::phaseFor($cycleDay, $periodLen, $ovDay, max(1, $ovDay - self::FERTILE_PRE), $ovDay + self::FERTILE_POST, $avgLen);

        return ['phase' => $phase, 'phase_label' => $label, 'cycle_day' => $cycleDay];
    }

    /**
     * Cross-signal insight: average resting-HR and HRV by phase, from recovery logs over the
     * window. This is the differentiator -- it lets the coach say "your RHR runs ~4 bpm higher in
     * the luteal phase, which is expected" instead of flagging false alarms. Needs both data sets.
     *
     * @return array{by_phase:array<string,array{rhr:?float,hrv:?float,n:int}>, luteal_rhr_delta:?float, note:?string}
     */
    public static function recoveryByPhase(Profile $profile, int $days = 120): array
    {
        $by = [];
        if (! class_exists(\App\Models\RecoveryLog::class)) {
            return ['by_phase' => [], 'luteal_rhr_delta' => null, 'note' => null];
        }

        $since = Carbon::now()->subDays($days)->toDateString();
        $logs = \App\Models\RecoveryLog::query()
            ->where('profile_id', $profile->id)
            ->whereDate('logged_at', '>=', $since)
            ->get(['logged_at', 'resting_hr', 'hrv_ms']);

        $buckets = [];
        $allRhr = [];
        foreach ($logs as $log) {
            $p = self::phaseOn($profile, Carbon::parse($log->logged_at));
            if (! $p) {
                continue;
            }
            $key = $p['phase'] === 'ovulation' || $p['phase'] === 'fertile' ? 'follicular' : $p['phase'];   // fold sparse phases
            $buckets[$key]['rhr'][] = $log->resting_hr;
            $buckets[$key]['hrv'][] = $log->hrv_ms;
            if (is_numeric($log->resting_hr)) {
                $allRhr[] = $log->resting_hr;
            }
        }
        $overallRhr = $allRhr ? round(array_sum($allRhr) / count($allRhr), 1) : null;

        foreach ($buckets as $phase => $vals) {
            $rhr = array_values(array_filter($vals['rhr'] ?? [], 'is_numeric'));
            $hrv = array_values(array_filter($vals['hrv'] ?? [], 'is_numeric'));
            $by[$phase] = [
                'rhr' => $rhr ? round(array_sum($rhr) / count($rhr), 1) : null,
                'hrv' => $hrv ? round(array_sum($hrv) / count($hrv), 1) : null,
                'n' => max(count($rhr), count($hrv)),
            ];
        }

        // The headline: how much higher is resting HR in the luteal phase vs the follicular?
        $delta = null;
        $note = null;
        if (isset($by['luteal']['rhr'], $by['follicular']['rhr']) && $by['luteal']['rhr'] && $by['follicular']['rhr']) {
            $delta = round($by['luteal']['rhr'] - $by['follicular']['rhr'], 1);
            if ($delta >= 1.5) {
                $note = "Your resting HR runs about {$delta} bpm higher in your luteal phase than your follicular -- a normal hormonal shift, so a small recovery dip premenstrually is expected, not a red flag.";
            }
        }

        return ['by_phase' => $by, 'overall_rhr' => $overallRhr, 'luteal_rhr_delta' => $delta, 'note' => $note];
    }

    /**
     * Phase-aware readiness correction. The luteal phase raises resting HR by a few bpm for
     * normal hormonal reasons -- so without this, a woman's readiness would dip every luteal
     * phase as a FALSE alarm. We return how much today's phase elevates her resting HR above
     * her own all-phase average (from her own logged data), so Readiness can normalise today's
     * RHR before scoring. We only ever NEUTRALISE an elevation (offset ≥ 0) -- never inflate a
     * naturally well-recovered phase. Returns null until there's enough same-phase history.
     *
     * @return array{phase:string,offset:float,note:?string}|null
     */
    public static function readinessRhrOffset(Profile $profile, ?Carbon $date = null): ?array
    {
        $status = self::status($profile, $date);
        if (empty($status['has_data'])) {
            return null;
        }
        $phase = $status['phase'];
        $bucket = in_array($phase, ['ovulation', 'fertile'], true) ? 'follicular' : $phase;

        $rec = self::recoveryByPhase($profile);
        $overall = $rec['overall_rhr'] ?? null;
        $phaseRhr = $rec['by_phase'][$bucket]['rhr'] ?? null;
        $n = $rec['by_phase'][$bucket]['n'] ?? 0;

        // Need a real same-phase sample before trusting the correction.
        if ($overall === null || $phaseRhr === null || $n < 3) {
            return null;
        }

        $offset = max(0.0, round($phaseRhr - $overall, 1));   // only neutralise elevations
        if ($offset < 1.0) {
            return ['phase' => $phase, 'offset' => 0.0, 'note' => null];
        }

        return [
            'phase' => $phase,
            'offset' => $offset,
            'note' => "Adjusted for your {$status['phase_label']} phase, where your resting HR naturally runs about {$offset} bpm higher -- so this isn't read as poor recovery.",
        ];
    }

    // ---- Logging helpers (shared by web + coach + MCP) ------------------------

    /** Record a period start = a new cycle. Closes out the previous cycle's length (start→start). */
    public static function startPeriod(Profile $profile, Carbon $date, string $source = 'manual'): MenstrualCycle
    {
        $date = $date->copy()->startOfDay();

        $cycle = $profile->menstrualCycles()->updateOrCreate(
            ['start_date' => $date->toDateString()],
            ['source' => $source],
        );

        // Set the PRIOR cycle's length now that we know when the next one began.
        $prev = $profile->menstrualCycles()
            ->whereDate('start_date', '<', $date->toDateString())
            ->orderByDesc('start_date')->first();
        if ($prev) {
            $prev->update(['length_days' => self::daysBetween($prev->start_date->copy()->startOfDay(), $date)]);
        }

        // If a LATER cycle exists (back-filling), fix this new one's length too.
        $next = $profile->menstrualCycles()
            ->whereDate('start_date', '>', $date->toDateString())
            ->orderBy('start_date')->first();
        if ($next) {
            $cycle->update(['length_days' => self::daysBetween($date, $next->start_date->copy()->startOfDay())]);
        }

        return $cycle->refresh();
    }

    /** Mark the last day of bleeding for the cycle that contains $date. */
    public static function endPeriod(Profile $profile, Carbon $date): ?MenstrualCycle
    {
        $date = $date->copy()->startOfDay();
        $cycle = $profile->menstrualCycles()
            ->whereDate('start_date', '<=', $date->toDateString())
            ->orderByDesc('start_date')->first();
        if (! $cycle) {
            return null;
        }
        $cycle->update(['period_end_date' => $date->toDateString()]);

        return $cycle;
    }

    /** Upsert a daily log (flow, symptoms, mood/energy, BBT). */
    public static function logDay(Profile $profile, Carbon $date, array $data, string $source = 'manual'): CycleLog
    {
        $clean = array_filter([
            'flow' => isset($data['flow']) && in_array($data['flow'], self::FLOWS, true) ? $data['flow'] : null,
            'symptoms' => isset($data['symptoms']) && is_array($data['symptoms'])
                ? array_values(array_intersect(array_map('strval', $data['symptoms']), self::SYMPTOMS)) : null,
            'mood' => isset($data['mood']) ? self::clampInt($data['mood'], 1, 5) : null,
            'energy' => isset($data['energy']) ? self::clampInt($data['energy'], 1, 5) : null,
            'bbt_c' => isset($data['bbt_c']) ? round((float) $data['bbt_c'], 2) : null,
            'intimacy' => array_key_exists('intimacy', $data) ? (bool) $data['intimacy'] : null,
            'notes' => isset($data['notes']) ? (string) $data['notes'] : null,
        ], fn ($v) => $v !== null);

        return $profile->cycleLogs()->updateOrCreate(
            ['logged_on' => $date->copy()->startOfDay()->toDateString()],
            $clean + ['source' => $source],
        );
    }

    // ---- Internals ------------------------------------------------------------

    /** @return array{0:string,1:string} [phase, label] */
    private static function phaseFor(int $cycleDay, int $periodLen, int $ovDay, int $fertileStart, int $fertileEnd, int $avgLen): array
    {
        return match (true) {
            $cycleDay <= $periodLen => ['menstrual', 'Menstrual'],
            $cycleDay === $ovDay => ['ovulation', 'Ovulation'],
            $cycleDay >= $fertileStart && $cycleDay <= $fertileEnd => ['fertile', 'Fertile window'],
            $cycleDay < $fertileStart => ['follicular', 'Follicular'],
            default => ['luteal', 'Luteal'],
        };
    }

    /** @return array{0:string,1:string} [likelihood, note] */
    private static function conception(int $cycleDay, int $ovDay, int $fertileStart, int $fertileEnd, string $bc): array
    {
        if (self::hormonalBirthControl($bc)) {
            return ['low', 'Hormonal birth control typically suppresses ovulation, so a fertile window doesn\'t apply in the usual way.'];
        }
        if ($cycleDay >= $ovDay - 2 && $cycleDay <= $ovDay) {
            return ['high', 'Peak fertility -- the two days before and the day of estimated ovulation.'];
        }
        if ($cycleDay >= $fertileStart && $cycleDay <= $fertileEnd) {
            return ['medium', 'Inside your estimated fertile window.'];
        }

        return ['low', 'Outside your estimated fertile window.'];
    }

    private static function phaseBlurb(string $phase): string
    {
        return match ($phase) {
            'menstrual' => 'Your period. Estrogen and progesterone are at their lowest; iron stores draw down with bleeding. Energy can be low early, often lifting by day 3-4.',
            'follicular' => 'Post-period, estrogen rising. Often the highest-energy stretch -- frequently the best window for harder training and PRs.',
            'fertile' => 'The days around ovulation when conception is most likely. Estrogen peaks; many feel strong and social.',
            'ovulation' => 'An egg is released (estimated). A small temperature rise follows; libido often peaks.',
            'luteal' => 'Post-ovulation, progesterone rising. Resting HR ticks up and HRV dips a little (normal), appetite and PMS symptoms can build toward the period.',
            default => '',
        };
    }

    private static function summaryLine(string $phaseLabel, int $cycleDay, int $inDays, bool $late, bool $hormonalBc, string $likelihood): string
    {
        $base = "Day {$cycleDay} · {$phaseLabel} phase.";
        if ($late) {
            return $base.' Your period is '.abs($inDays).' day'.(abs($inDays) === 1 ? '' : 's').' later than predicted -- cycles vary, but worth noting.';
        }
        $next = $inDays === 0 ? 'Your period is predicted today.' : "Next period in ~{$inDays} day".($inDays === 1 ? '' : 's').'.';
        if (! $hormonalBc && $likelihood === 'high') {
            $next .= ' You\'re in your peak fertility days.';
        }

        return $base.' '.$next;
    }

    private static function averageLength(array $closedLengths, int $fallback): int
    {
        $valid = array_values(array_filter($closedLengths, fn ($v) => $v >= 21 && $v <= 45));
        if (count($valid) < 2) {
            return self::clampInt($fallback, 21, 45);
        }

        return self::clampInt((int) round(array_sum($valid) / count($valid)), 21, 45);
    }

    private static function averagePeriod(Profile $profile, int $fallback): int
    {
        $rows = $profile->menstrualCycles()->whereNotNull('period_end_date')
            ->orderByDesc('start_date')->take(6)->get(['start_date', 'period_end_date']);
        $lens = [];
        foreach ($rows as $r) {
            $len = self::daysBetween($r->start_date->copy()->startOfDay(), $r->period_end_date->copy()->startOfDay()) + 1;
            if ($len >= 1 && $len <= 10) {
                $lens[] = $len;
            }
        }
        if (count($lens) < 2) {
            return self::clampInt($fallback, 1, 10);
        }

        return self::clampInt((int) round(array_sum($lens) / count($lens)), 1, 10);
    }

    private static function regularity(array $closedLengths): string
    {
        $valid = array_values(array_filter($closedLengths, fn ($v) => $v >= 21 && $v <= 45));
        if (count($valid) < 3) {
            return 'unknown';
        }
        $mean = array_sum($valid) / count($valid);
        $sd = sqrt(array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $valid)) / count($valid));

        return $sd <= 3.5 ? 'regular' : 'irregular';
    }

    private static function todayLog(Profile $profile, Carbon $today): ?array
    {
        $log = $profile->cycleLogs()->whereDate('logged_on', $today->toDateString())->first();
        if (! $log) {
            return null;
        }

        return array_filter([
            'flow' => $log->flow,
            'symptoms' => $log->symptoms ?: null,
            'mood' => $log->mood,
            'energy' => $log->energy,
            'bbt_c' => $log->bbt_c,
        ], fn ($v) => $v !== null && $v !== []);
    }

    /** Whole-day difference $to − $from (positive if $to is later). Avoids Carbon sign ambiguity. */
    private static function daysBetween(Carbon $from, Carbon $to): int
    {
        return (int) round(($to->copy()->startOfDay()->timestamp - $from->copy()->startOfDay()->timestamp) / 86400);
    }

    private static function clampInt($v, int $min, int $max): int
    {
        $v = (int) $v;

        return max($min, min($max, $v));
    }
}
