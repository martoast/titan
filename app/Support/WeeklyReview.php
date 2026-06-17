<?php

namespace App\Support;

use App\Models\Profile;
use Illuminate\Support\Carbon;

/**
 * The week in review — the longitudinal coaching arc. Synthesizes the last 7 days across training
 * (adherence vs the program + whether the lifts moved), nutrition, recovery/sleep, and body comp into
 * one scorecard with wins, things to watch, and what to change next week. Point-in-time tools answer
 * "how am I today"; this answers "is this working, and what do we do about it".
 *
 * Pure data synthesis — the coach narrates the story around it. Degrades gracefully: domains with no
 * data are simply dropped, and a brand-new profile yields null.
 */
class WeeklyReview
{
    /** @return array<string,mixed>|null */
    public static function compile(Profile $profile, ?Carbon $asOf = null): ?array
    {
        $tz = $profile->settings['timezone'] ?? config('app.timezone', 'UTC');
        $end = ($asOf ? $asOf->copy()->setTimezone($tz) : Carbon::now($tz));
        $start = $end->copy()->subDays(7);
        $prevStart = $start->copy()->subDays(7);

        $metrics = [];
        $wins = [];
        $watch = [];
        $have = false;

        // --- Training: adherence + did the lifts move? ---
        if (class_exists(\App\Models\Workout::class)) {
            $sessions = $profile->workouts()->whereBetween('performed_at', [$start, $end])->count();
            $sets = self::setsBetween($profile, $start, $end);
            $program = class_exists(\App\Models\TrainingProgram::class)
                ? $profile->trainingPrograms()->where('is_active', true)->latest('id')->first() : null;

            if ($sessions > 0 || $program) {
                $have = true;
                $target = $program?->days_per_week;
                $value = $sessions.' session'.($sessions === 1 ? '' : 's').($sets > 0 ? " · {$sets} sets" : '');
                $state = $target
                    ? ($sessions >= $target ? 'good' : ($sessions >= (int) ceil($target * 0.6) ? 'ok' : 'low'))
                    : ($sessions >= 3 ? 'good' : ($sessions >= 1 ? 'ok' : 'low'));
                $metrics[] = ['key' => 'training', 'label' => 'Training', 'value' => $value, 'sub' => $target ? "of {$target}/wk plan" : '', 'state' => $state];

                if ($target && $sessions >= $target) {
                    $wins[] = "Hit all {$target} training sessions";
                } elseif ($target && $sessions < ceil($target * 0.6)) {
                    $watch[] = "Only {$sessions} of {$target} planned sessions";
                }
                if (self::liftsImproving($profile, $start, $end, $prevStart)) {
                    $wins[] = 'Lifts trending up — real progressive overload';
                }
            }
        }

        // --- Nutrition: average day vs targets ---
        if (class_exists(\App\Models\Meal::class)) {
            $meals = $profile->meals()->whereBetween('eaten_at', [$start, $end])->get(['eaten_at', 'calories', 'protein_g']);
            $byDay = $meals->groupBy(fn ($m) => Carbon::parse($m->eaten_at)->toDateString());
            $daysLogged = $byDay->count();
            if ($daysLogged > 0) {
                $have = true;
                $avgCal = (int) round($meals->sum('calories') / $daysLogged);
                $avgPro = (int) round((float) $meals->sum('protein_g') / $daysLogged);
                $calT = (int) data_get($profile->settings, 'macro_targets.calories', 0);
                $proT = (int) data_get($profile->settings, 'macro_targets.protein_g', 0);

                $state = 'ok';
                if ($daysLogged >= 5 && (! $proT || $avgPro >= $proT * 0.9) && (! $calT || abs($avgCal - $calT) <= $calT * 0.12)) {
                    $state = 'good';
                } elseif ($daysLogged < 3) {
                    $state = 'low';
                }
                $metrics[] = ['key' => 'nutrition', 'label' => 'Nutrition', 'value' => number_format($avgCal).' kcal · '.$avgPro.'g protein', 'sub' => "avg/day · logged {$daysLogged}/7", 'state' => $state];

                if ($proT && $avgPro >= $proT * 0.95) {
                    $wins[] = "Protein dialed in (~{$avgPro}g/day)";
                }
                if ($daysLogged < 4) {
                    $watch[] = "Only logged food {$daysLogged} of 7 days";
                } elseif ($calT && $avgCal > $calT * 1.15) {
                    $watch[] = 'Calories ran above target most days';
                }
            }
        }

        // --- Sleep ---
        if (class_exists(\App\Models\SleepLog::class)) {
            $nights = $profile->sleepLogs()->whereBetween('slept_at', [$start->toDateString(), $end->toDateString()])->get(['duration_min']);
            if ($nights->count() > 0) {
                $have = true;
                $avgH = round($nights->avg('duration_min') / 60, 1);
                $need = class_exists(\App\Support\SleepCoach::class) ? rescue(fn () => \App\Support\SleepCoach::assess($profile)['need_h'] ?? 8.0, 8.0, false) : 8.0;
                $state = $avgH >= $need - 0.5 ? 'good' : ($avgH >= $need - 1.5 ? 'ok' : 'low');
                $metrics[] = ['key' => 'sleep', 'label' => 'Sleep', 'value' => $avgH.'h avg', 'sub' => 'need ~'.rtrim(rtrim(number_format($need, 1), '0'), '.').'h', 'state' => $state];
                if ($state === 'good') {
                    $wins[] = 'Sleep held strong all week';
                } elseif ($state === 'low') {
                    $watch[] = 'Sleep ran short — it drags everything';
                }
            }
        }

        // --- Recovery (readiness now as the headline) ---
        if (class_exists(\App\Support\Readiness::class)) {
            $score = rescue(fn () => \App\Support\Readiness::compute($profile)['score'] ?? null, null, false);
            if ($score !== null) {
                $have = true;
                $state = $score >= 67 ? 'good' : ($score >= 34 ? 'ok' : 'low');
                $metrics[] = ['key' => 'recovery', 'label' => 'Recovery', 'value' => 'readiness '.$score, 'sub' => 'right now', 'state' => $state];
            }
        }

        // --- Body composition: weight trend ---
        if (class_exists(\App\Models\BodyMetric::class)) {
            $latest = $profile->bodyMetrics()->whereNotNull('weight_kg')->where('taken_at', '<=', $end)->orderByDesc('taken_at')->first();
            $prior = $profile->bodyMetrics()->whereNotNull('weight_kg')->where('taken_at', '<', $start)->orderByDesc('taken_at')->first();
            if ($latest) {
                $have = true;
                $w = round((float) $latest->weight_kg, 1);
                $delta = $prior ? round($w - (float) $prior->weight_kg, 1) : null;
                $metrics[] = ['key' => 'weight', 'label' => 'Bodyweight', 'value' => $w.' kg', 'sub' => $delta !== null ? sprintf('%+.1f kg vs last wk', $delta) : 'first reading', 'state' => 'neutral'];
            }
        }

        if (! $have) {
            return null;
        }

        // --- Next week: the autoregulation call ---
        $next = null;
        if (class_exists(\App\Support\Autoregulator::class)) {
            $a = rescue(fn () => \App\Support\Autoregulator::assess($profile), null, false);
            if ($a && ($a['verdict'] ?? null) && $a['verdict'] !== 'insufficient') {
                $next = ['verdict' => $a['verdict'], 'text' => $a['adjustment']['note'] ?? ''];
            }
        }

        $goods = count(array_filter($metrics, fn ($m) => $m['state'] === 'good'));
        $lows = count(array_filter($metrics, fn ($m) => $m['state'] === 'low'));
        $headline = $lows === 0 && $goods >= 2
            ? "Strong week — momentum's with you"
            : ($lows >= 2 ? "A tougher week — let's reset and rebuild" : 'Solid week, with room to sharpen');

        return [
            'range' => self::range($start, $end),
            'headline' => $headline,
            'metrics' => $metrics,
            'wins' => array_slice($wins, 0, 4),
            'watch' => array_slice($watch, 0, 3),
            'next' => $next,
        ];
    }

    private static function range(Carbon $start, Carbon $end): string
    {
        return $start->format('M j').'–'.($start->month === $end->month ? $end->format('j') : $end->format('M j'));
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

    /** Did more lifts go up than down, this week vs the prior week? */
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
