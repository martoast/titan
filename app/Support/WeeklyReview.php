<?php

namespace App\Support;

use App\Models\Profile;
use App\Models\WeeklySnapshot;
use Illuminate\Support\Carbon;

/**
 * The week in review -- the longitudinal coaching arc. Synthesizes the last 7 days across training
 * (adherence vs the program + whether the lifts moved), nutrition, recovery/sleep and body comp into
 * one scorecard with an overall week score, week-over-week deltas, a multi-week trend and a streak of
 * consistent weeks, plus what to change next week.
 *
 * measure() = the raw numbers (also what gets frozen into a WeeklySnapshot); compile() = the display
 * review built on top, comparing against the previous stored snapshot. Pure synthesis -- the coach
 * narrates. Degrades gracefully; a blank week yields null.
 */
class WeeklyReview
{
    private const CONSISTENT = 60;   // a week scoring ≥ this counts toward the streak

    /** @return array<string,mixed>|null the display review */
    public static function compile(Profile $profile, ?Carbon $asOf = null): ?array
    {
        $tz = self::tz($profile);
        $end = $asOf ? $asOf->copy()->setTimezone($tz) : Carbon::now($tz);
        $m = self::measure($profile, $end);
        if (! $m['have']) {
            return null;
        }

        $weekStart = $end->copy()->startOfWeek();
        $prev = class_exists(WeeklySnapshot::class)
            ? $profile->weeklySnapshots()->whereDate('week_start', '<', $weekStart->toDateString())->orderByDesc('week_start')->first()
            : null;
        $prevM = $prev?->metrics;

        $score = self::score($m);
        [$wins, $watch] = self::winsWatch($m);
        $metrics = self::displayMetrics($m, $prevM);

        $next = null;
        if (class_exists(\App\Support\Autoregulator::class)) {
            $a = rescue(fn () => \App\Support\Autoregulator::assess($profile), null, false);
            if ($a && ($a['verdict'] ?? null) && $a['verdict'] !== 'insufficient') {
                $next = ['verdict' => $a['verdict'], 'text' => $a['adjustment']['note'] ?? ''];
            }
        }

        // Progress toward the dream physique, with the step gained THIS week vs last week's snapshot.
        $physique = null;
        if ($p = $m['physique']) {
            $prevStep = $prevM['physique']['step_pct'] ?? null;
            $physique = [
                'step_pct' => $p['step_pct'],
                'step_delta' => $prevStep !== null ? $p['step_pct'] - $prevStep : null,
                'verdict' => $p['verdict'],
                'verdict_label' => $p['verdict_label'],
                'eta_weeks' => $p['eta_weeks'],
                'description' => $p['description'],
            ];
        }

        return [
            'range' => self::range($weekStart, $end),
            'headline' => self::headline($score),
            'physique' => $physique,
            'score' => $score,
            'score_delta' => ($prev && $prev->score !== null && $score !== null) ? $score - $prev->score : null,
            'streak' => self::streak($profile, $weekStart, $score),
            'trend' => self::trend($profile, $weekStart, $score),
            'metrics' => $metrics,
            'wins' => array_slice($wins, 0, 4),
            'watch' => array_slice($watch, 0, 3),
            'next' => $next,
        ];
    }

    /** Freeze the week into a snapshot (idempotent per profile+week). Call at week's end. */
    public static function snapshot(Profile $profile, ?Carbon $asOf = null): ?WeeklySnapshot
    {
        if (! class_exists(WeeklySnapshot::class)) {
            return null;
        }
        $tz = self::tz($profile);
        $end = $asOf ? $asOf->copy()->setTimezone($tz) : Carbon::now($tz);
        $m = self::measure($profile, $end);
        if (! $m['have']) {
            return null;
        }
        $score = self::score($m);

        return $profile->weeklySnapshots()->updateOrCreate(
            ['week_start' => $end->copy()->startOfWeek()->toDateString()],
            ['score' => $score, 'metrics' => $m, 'headline' => self::headline($score)],
        );
    }

    /**
     * The raw measures for the 7 days ending at $end. This is the snapshot payload and the basis for
     * every display metric + delta.
     *
     * @return array<string,mixed>
     */
    public static function measure(Profile $profile, Carbon $end): array
    {
        $start = $end->copy()->subDays(7);
        $prevStart = $start->copy()->subDays(7);
        $m = ['have' => false, 'training' => null, 'nutrition' => null, 'sleep' => null, 'recovery' => null, 'weight' => null, 'physique' => null, 'lifts_improving' => false];

        if (class_exists(\App\Models\Workout::class)) {
            $sessions = $profile->workouts()->whereBetween('performed_at', [$start, $end])->count();
            $program = class_exists(\App\Models\TrainingProgram::class)
                ? $profile->trainingPrograms()->where('is_active', true)->latest('id')->first() : null;
            if ($sessions > 0 || $program) {
                $m['have'] = true;
                $target = $program?->days_per_week;
                $sets = self::setsBetween($profile, $start, $end);
                $state = $target
                    ? ($sessions >= $target ? 'good' : ($sessions >= (int) ceil($target * 0.6) ? 'ok' : 'low'))
                    : ($sessions >= 3 ? 'good' : ($sessions >= 1 ? 'ok' : 'low'));
                $m['training'] = ['sessions' => $sessions, 'sets' => $sets, 'target' => $target, 'state' => $state];
                $m['lifts_improving'] = self::liftsImproving($profile, $start, $end, $prevStart);
            }
        }

        if (class_exists(\App\Models\Meal::class)) {
            $meals = $profile->meals()->whereBetween('eaten_at', [$start, $end])->get(['eaten_at', 'calories', 'protein_g']);
            $days = $meals->groupBy(fn ($x) => Carbon::parse($x->eaten_at)->toDateString())->count();
            if ($days > 0) {
                $m['have'] = true;
                $avgCal = (int) round($meals->sum('calories') / $days);
                $avgPro = (int) round((float) $meals->sum('protein_g') / $days);
                $calT = (int) data_get($profile->settings, 'macro_targets.calories', 0);
                // Protein target from current bodyweight (same evidence-based ~1 g/lb the meal card uses),
                // not the frozen onboarding value. @see App\Support\MacroTargets
                $proT = \App\Support\MacroTargets::proteinTarget($profile)
                    ?? (int) data_get($profile->settings, 'macro_targets.protein_g', 0);
                $state = 'ok';
                if ($days >= 5 && (! $proT || $avgPro >= $proT * 0.9) && (! $calT || abs($avgCal - $calT) <= $calT * 0.12)) {
                    $state = 'good';
                } elseif ($days < 3) {
                    $state = 'low';
                }
                $m['nutrition'] = ['avg_cal' => $avgCal, 'avg_pro' => $avgPro, 'days' => $days, 'cal_t' => $calT, 'pro_t' => $proT, 'state' => $state];
            }
        }

        if (class_exists(\App\Models\SleepLog::class)) {
            $nights = $profile->sleepLogs()->nights()->whereBetween('slept_at', [$start->toDateString(), $end->toDateString()])->get(['duration_min']);
            if ($nights->count() > 0) {
                $m['have'] = true;
                $avgH = round($nights->avg('duration_min') / 60, 1);
                $need = class_exists(\App\Support\SleepCoach::class) ? rescue(fn () => \App\Support\SleepCoach::assess($profile)['need_h'] ?? 8.0, 8.0, false) : 8.0;
                $m['sleep'] = ['avg_h' => $avgH, 'need' => round($need, 1), 'state' => $avgH >= $need - 0.5 ? 'good' : ($avgH >= $need - 1.5 ? 'ok' : 'low')];
            }
        }

        if (class_exists(\App\Support\Readiness::class)) {
            $score = rescue(fn () => \App\Support\Readiness::compute($profile)['score'] ?? null, null, false);
            if ($score !== null) {
                $m['have'] = true;
                $m['recovery'] = ['readiness' => (int) $score, 'state' => $score >= 67 ? 'good' : ($score >= 34 ? 'ok' : 'low')];
            }
        }

        if (class_exists(\App\Models\BodyMetric::class)) {
            $latest = $profile->bodyMetrics()->whereNotNull('weight_kg')->where('taken_at', '<=', $end)->orderByDesc('taken_at')->first();
            $prior = $profile->bodyMetrics()->whereNotNull('weight_kg')->where('taken_at', '<', $start)->orderByDesc('taken_at')->first();
            if ($latest) {
                $m['have'] = true;
                $kg = round((float) $latest->weight_kg, 1);
                $m['weight'] = ['kg' => $kg, 'prior' => $prior ? round((float) $prior->weight_kg, 1) : null, 'delta' => $prior ? round($kg - (float) $prior->weight_kg, 1) : null];
            }
        }

        // Progress toward the dream physique -- the north star. Enriches the review; doesn't gate it.
        if (class_exists(\App\Support\PhysiqueProgress::class)) {
            $pp = rescue(fn () => \App\Support\PhysiqueProgress::assess($profile), null, false);
            if ($pp) {
                $m['physique'] = ['step_pct' => $pp['step_pct'], 'verdict' => $pp['verdict'], 'verdict_label' => $pp['verdict_label'], 'eta_weeks' => $pp['eta_weeks'], 'description' => $pp['description']];
            }
        }

        return $m;
    }

    /** Overall 0-100 week score from the domain states (weight is goal-dependent, so excluded). */
    public static function score(array $m): ?int
    {
        $map = ['good' => 100, 'ok' => 60, 'low' => 20];
        $vals = [];
        foreach (['training', 'nutrition', 'sleep', 'recovery'] as $k) {
            if (isset($m[$k]['state'], $map[$m[$k]['state']])) {
                $vals[] = $map[$m[$k]['state']];
            }
        }

        return $vals === [] ? null : (int) round(array_sum($vals) / count($vals));
    }

    /** Build the display rows, attaching a week-over-week delta where the prior snapshot has the number. */
    private static function displayMetrics(array $m, ?array $prev): array
    {
        $rows = [];

        if ($t = $m['training']) {
            $value = trans_choice(':count session|:count sessions', $t['sessions'], ['count' => $t['sessions']]);
            if ($t['sets'] > 0) {
                $value .= " · {$t['sets']} ".__('sets');
            }
            $rows[] = [
                'key' => 'training', 'label' => __('Training'),
                'value' => $value,
                'sub' => $t['target'] ? __('of :count/wk plan', ['count' => $t['target']]) : '',
                'state' => $t['state'],
                'delta' => self::delta($t['sets'], $prev['training']['sets'] ?? null, __('sets')),
            ];
        }
        if ($n = $m['nutrition']) {
            $rows[] = [
                'key' => 'nutrition', 'label' => __('Nutrition'),
                'value' => __(':cal kcal · :pro g protein', ['cal' => number_format($n['avg_cal']), 'pro' => $n['avg_pro']]),
                'sub' => __('avg/day · logged :days/7', ['days' => $n['days']]),
                'state' => $n['state'],
                'delta' => self::delta($n['avg_pro'], $prev['nutrition']['avg_pro'] ?? null, __('g protein')),
            ];
        }
        if ($s = $m['sleep']) {
            $rows[] = [
                'key' => 'sleep', 'label' => __('Sleep'),
                'value' => __(':hours h avg', ['hours' => $s['avg_h']]),
                'sub' => __('need ~:hours h', ['hours' => rtrim(rtrim(number_format($s['need'], 1), '0'), '.')]),
                'state' => $s['state'],
                'delta' => self::delta($s['avg_h'], $prev['sleep']['avg_h'] ?? null, __('h'), 1),
            ];
        }
        if ($r = $m['recovery']) {
            $rows[] = [
                'key' => 'recovery', 'label' => __('Recovery'),
                'value' => __('readiness :score', ['score' => $r['readiness']]), 'sub' => __('right now'),
                'state' => $r['state'],
                'delta' => self::delta($r['readiness'], $prev['recovery']['readiness'] ?? null, ''),
            ];
        }
        if ($w = $m['weight']) {
            $rows[] = [
                'key' => 'weight', 'label' => __('Bodyweight'),
                'value' => $w['kg'].' kg',
                'sub' => $w['delta'] !== null ? __(':delta kg vs last wk', ['delta' => sprintf('%+.1f', $w['delta'])]) : __('first reading'),
                'state' => 'neutral', 'delta' => null,
            ];
        }

        return $rows;
    }

    /** A signed delta vs last week, flagged good/bad (more is better for all of these). */
    private static function delta($cur, $prev, string $unit, int $decimals = 0): ?array
    {
        if ($prev === null || $cur === null) {
            return null;
        }
        $diff = round($cur - $prev, $decimals);
        if ($diff == 0.0) {
            return null;
        }
        $text = ($diff > 0 ? '+' : '').($decimals > 0 ? number_format($diff, $decimals) : (string) (int) $diff).($unit ? ' '.$unit : '');

        return ['text' => $text, 'good' => $diff > 0];
    }

    /** @return array{0:array<int,string>,1:array<int,string>} [wins, watch] */
    private static function winsWatch(array $m): array
    {
        $wins = [];
        $watch = [];
        if ($t = $m['training']) {
            if ($t['target'] && $t['sessions'] >= $t['target']) {
                $wins[] = __('Hit all :count training sessions', ['count' => $t['target']]);
            } elseif ($t['target'] && $t['sessions'] < ceil($t['target'] * 0.6)) {
                $watch[] = __('Only :sessions of :target planned sessions', ['sessions' => $t['sessions'], 'target' => $t['target']]);
            }
            if ($m['lifts_improving']) {
                $wins[] = __('Lifts trending up -- real progressive overload');
            }
        }
        if ($n = $m['nutrition']) {
            if ($n['pro_t'] && $n['avg_pro'] >= $n['pro_t'] * 0.95) {
                $wins[] = __('Protein dialed in (~:grams g/day)', ['grams' => $n['avg_pro']]);
            }
            if ($n['days'] < 4) {
                $watch[] = __('Only logged food :days of 7 days', ['days' => $n['days']]);
            } elseif ($n['cal_t'] && $n['avg_cal'] > $n['cal_t'] * 1.15) {
                $watch[] = __('Calories ran above target most days');
            }
        }
        if ($s = $m['sleep']) {
            if ($s['state'] === 'good') {
                $wins[] = __('Sleep held strong all week');
            } elseif ($s['state'] === 'low') {
                $watch[] = __('Sleep ran short -- it drags everything');
            }
        }

        return [$wins, $watch];
    }

    /** Consecutive weeks (ending this one) scoring ≥ CONSISTENT. */
    private static function streak(Profile $profile, Carbon $weekStart, ?int $currentScore): int
    {
        if (! class_exists(WeeklySnapshot::class)) {
            return ($currentScore !== null && $currentScore >= self::CONSISTENT) ? 1 : 0;
        }
        $scores = $profile->weeklySnapshots()
            ->whereDate('week_start', '<', $weekStart->toDateString())
            ->orderByDesc('week_start')->limit(52)->pluck('score', 'week_start');

        $streak = 0;
        $cursor = $weekStart->copy()->subWeek();
        foreach ($scores as $wk => $sc) {
            // Only count an unbroken chain of immediately-preceding weeks.
            if (Carbon::parse($wk)->toDateString() !== $cursor->toDateString() || $sc === null || $sc < self::CONSISTENT) {
                break;
            }
            $streak++;
            $cursor->subWeek();
        }
        if ($currentScore !== null && $currentScore >= self::CONSISTENT) {
            $streak++;   // this week (in progress) counts too
        }

        return $streak;
    }

    /** Last few weeks' scores (oldest → newest), with this week appended. @return array<int,int> */
    private static function trend(Profile $profile, Carbon $weekStart, ?int $currentScore, int $weeks = 6): array
    {
        $points = [];
        if (class_exists(WeeklySnapshot::class)) {
            $points = $profile->weeklySnapshots()
                ->whereDate('week_start', '<', $weekStart->toDateString())
                ->whereNotNull('score')
                ->orderByDesc('week_start')->limit($weeks - 1)->pluck('score')->reverse()->values()->all();
        }
        if ($currentScore !== null) {
            $points[] = $currentScore;
        }

        return array_map('intval', $points);
    }

    private static function headline(?int $score): string
    {
        return match (true) {
            $score === null => __('Your week so far'),
            $score >= 75 => __("Strong week -- momentum's with you"),
            $score >= 50 => __('Solid week, with room to sharpen'),
            default => __("A tougher week -- let's reset and rebuild"),
        };
    }

    private static function tz(Profile $profile): string
    {
        return $profile->settings['timezone'] ?? config('app.timezone', 'UTC');
    }

    private static function range(Carbon $start, Carbon $end): string
    {
        return $start->format('M j').'-'.($start->month === $end->month ? $end->format('j') : $end->format('M j'));
    }

    private static function setsBetween(Profile $profile, Carbon $start, Carbon $end): int
    {
        if (! class_exists(\App\Models\WorkoutSet::class) || ! class_exists(\App\Models\WorkoutExercise::class)) {
            return 0;
        }
        $workoutIds = $profile->workouts()->whereBetween('performed_at', [$start, $end])->pluck('id');
        if ($workoutIds->isEmpty()) {
            return 0;
        }
        $weIds = \App\Models\WorkoutExercise::whereIn('workout_id', $workoutIds)->pluck('id');

        return $weIds->isEmpty() ? 0 : \App\Models\WorkoutSet::whereIn('workout_exercise_id', $weIds)->count();
    }

    private static function liftsImproving(Profile $profile, Carbon $start, Carbon $end, Carbon $prevStart): bool
    {
        $recent = self::bestE1rm($profile, $start, $end);
        $prior = self::bestE1rm($profile, $prevStart, $start);
        $common = array_intersect_key($recent, $prior);
        if (count($common) < 1) {
            return false;
        }
        $up = $down = 0;
        foreach ($common as $ex => $e) {
            if ($e > $prior[$ex] * 1.01) {
                $up++;
            } elseif ($e < $prior[$ex] * 0.99) {
                $down++;
            }
        }

        return $up > $down;
    }

    /** @return array<int,float> exercise_id => best estimated 1RM in the window */
    private static function bestE1rm(Profile $profile, Carbon $from, Carbon $to): array
    {
        if (! class_exists(\App\Models\WorkoutSet::class)) {
            return [];
        }
        $workoutIds = $profile->workouts()->whereBetween('performed_at', [$from, $to])->pluck('id');
        if ($workoutIds->isEmpty()) {
            return [];
        }
        $we = \App\Models\WorkoutExercise::whereIn('workout_id', $workoutIds)->get(['id', 'exercise_id']);
        $exByWe = $we->pluck('exercise_id', 'id');
        $best = [];
        foreach (\App\Models\WorkoutSet::whereIn('workout_exercise_id', $we->pluck('id'))->where('weight_kg', '>', 0)->where('reps', '>', 0)->get(['workout_exercise_id', 'weight_kg', 'reps']) as $s) {
            $exId = $exByWe[$s->workout_exercise_id] ?? null;
            if ($exId !== null) {
                $best[$exId] = max($best[$exId] ?? 0, (float) $s->weight_kg * (1 + min(12, (int) $s->reps) / 30));
            }
        }

        return $best;
    }
}
