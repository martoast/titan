<?php

namespace App\Support;

use App\Models\Profile;
use Illuminate\Support\Carbon;

/**
 * Progress-aware autoregulation: reads the athlete's LOGGED training (are the lifts going up?) against
 * their plan and their RECOVERY, and nudges volume / proximity-to-failure accordingly -- push when fresh
 * and progressing, hold when steady, back off (or deload) when performance slips or recovery tanks.
 *
 * This is the playbook's "manage fatigue toward MRV, progress when you've earned it" turned into a read on
 * the actual data. Natural, evidence-based -- no pharmacology assumed.
 */
class Autoregulator
{
    /** @return array<string,mixed> */
    public static function assess(Profile $profile): array
    {
        $program = class_exists(\App\Models\TrainingProgram::class)
            ? $profile->trainingPrograms()->where('is_active', true)->latest('id')->first()
            : null;

        [$recState, $recDetail] = self::recovery($profile);
        [$perfState, $perfDetail] = self::performance($profile);
        [$adhState, $adhDetail] = self::adherence($profile, $program);

        // Nothing to read yet.
        if (! $program && $perfState === 'unknown' && $recState === 'unknown') {
            return [
                'verdict' => 'insufficient',
                'headline' => "Let's get some data flowing",
                'signals' => [],
                'adjustment' => ['note' => 'Log a few training sessions (call out your sets) and connect recovery, and I can autoregulate -- push you when you\'re fresh and progressing, ease off when you\'re not. Want me to build you a program to follow?'],
                'program' => null,
            ];
        }

        $poorRecovery = $recState === 'poor';
        $declining = $perfState === 'declining';

        if ($poorRecovery && $declining) {
            $verdict = 'deload';
            $headline = 'Time to deload';
            $adj = ['volume' => 'cut ~40-50% this week', 'rir' => '3-4 RIR, no failure', 'note' => 'Recovery and performance are both down -- that\'s your body at its ceiling (MRV). Take the deload; you\'ll come back stronger.'];
        } elseif ($poorRecovery || $declining) {
            $verdict = 'back_off';
            $headline = 'Ease off a touch';
            $adj = ['volume' => 'hold -- don\'t add sets', 'rir' => '+1 RIR, skip the intensity techniques today', 'note' => $poorRecovery
                ? 'Recovery is low -- protect sleep, keep effort 2-3 reps shy of failure, and we\'ll push again once you bounce back.'
                : 'Reps are slipping at the same loads -- hold volume, sharpen recovery, then attack it again next week.'];
        } elseif ($adhState === 'behind') {
            $verdict = 'adhere';
            $headline = 'Just keep showing up';
            $adj = ['volume' => 'hit the planned sets', 'rir' => 'as prescribed', 'note' => 'You\'re under your planned volume this week -- the fix is consistency, not changing the plan. Let\'s log the sessions.'];
        } elseif ($recState === 'good' && $perfState === 'improving') {
            $verdict = 'progress';
            $headline = 'Green light -- push';
            $adj = ['volume' => 'add ~1 set to your focus muscles', 'rir' => 'chase 1 RIR (0 on isolations)', 'note' => 'You\'re recovered and beating the logbook -- earn another set and a little more intensity. This is how the focus muscles grow.'];
        } else {
            $verdict = 'hold';
            $headline = 'Stay the course';
            $adj = ['volume' => 'keep volume', 'rir' => 'as prescribed', 'note' => 'Solid and steady -- just beat last week\'s reps or load somewhere today and let the volume accumulate.'];
        }

        return [
            'verdict' => $verdict,
            'headline' => $headline,
            'signals' => [
                ['label' => 'Recovery', 'state' => $recState, 'detail' => $recDetail],
                ['label' => 'Performance', 'state' => $perfState, 'detail' => $perfDetail],
                ['label' => 'On plan', 'state' => $adhState, 'detail' => $adhDetail],
            ],
            'adjustment' => $adj,
            'program' => $program ? [
                'name' => $program->name,
                'week' => $program->current_week,
                'of' => $program->weeks,
                'phase' => $program->currentWeek()['phase'] ?? '',
            ] : null,
        ];
    }

    /** @return array{0:string,1:string} [state, detail] */
    private static function recovery(Profile $profile): array
    {
        if (class_exists(\App\Support\Readiness::class)) {
            $score = rescue(fn () => \App\Support\Readiness::compute($profile)['score'] ?? null, null, false);
            if ($score !== null) {
                $state = $score >= 67 ? 'good' : ($score >= 34 ? 'moderate' : 'poor');

                return [$state, "readiness {$score}"];
            }
        }
        // Fall back to the latest subjective recovery log.
        if (class_exists(\App\Models\RecoveryLog::class)) {
            $r = $profile->recoveryLogs()->orderByDesc('logged_at')->orderByDesc('id')->first();
            if ($r && ($r->energy || $r->soreness)) {
                $e = $r->energy ?? 6;
                $s = $r->soreness ?? 4;
                $net = $e - ($s - 5);                       // high energy, low soreness = good
                $state = $net >= 7 ? 'good' : ($net >= 4 ? 'moderate' : 'poor');

                return [$state, 'self-reported'];
            }
        }

        return ['unknown', 'no recovery data'];
    }

    /** @return array{0:string,1:string} [state, detail] */
    private static function performance(Profile $profile): array
    {
        if (! class_exists(\App\Models\WorkoutSet::class)) {
            return ['unknown', 'no training logged'];
        }
        $now = Carbon::now();
        $recent = self::bestE1rmByExercise($profile, $now->copy()->subDays(10), $now);
        $prior = self::bestE1rmByExercise($profile, $now->copy()->subDays(24), $now->copy()->subDays(10));
        $common = array_intersect_key($recent, $prior);

        if (count($common) < 2) {
            return ['unknown', $recent === [] ? 'no training logged' : 'building history'];
        }
        $up = 0;
        $down = 0;
        foreach ($common as $ex => $e1rm) {
            if ($e1rm > $prior[$ex] * 1.01) {
                $up++;
            } elseif ($e1rm < $prior[$ex] * 0.99) {
                $down++;
            }
        }
        $state = $up > $down ? 'improving' : ($down > $up ? 'declining' : 'flat');

        return [$state, "{$up} lift".($up === 1 ? '' : 's')." up, {$down} down (10d vs prior)"];
    }

    /** Best estimated 1RM per exercise from sets logged in [$from,$to]. @return array<int,float> exercise_id => e1RM */
    private static function bestE1rmByExercise(Profile $profile, Carbon $from, Carbon $to): array
    {
        $workoutIds = $profile->workouts()->whereBetween('performed_at', [$from, $to])->pluck('id');
        if ($workoutIds->isEmpty()) {
            return [];
        }
        $we = \App\Models\WorkoutExercise::whereIn('workout_id', $workoutIds)->get(['id', 'exercise_id']);
        if ($we->isEmpty()) {
            return [];
        }
        $exByWe = $we->pluck('exercise_id', 'id');

        $best = [];
        foreach (\App\Models\WorkoutSet::whereIn('workout_exercise_id', $we->pluck('id'))->where('weight_kg', '>', 0)->where('reps', '>', 0)->get(['workout_exercise_id', 'weight_kg', 'reps']) as $s) {
            $exId = $exByWe[$s->workout_exercise_id] ?? null;
            if ($exId === null) {
                continue;
            }
            $e1rm = (float) $s->weight_kg * (1 + min(12, (int) $s->reps) / 30);
            $best[$exId] = max($best[$exId] ?? 0, $e1rm);
        }

        return $best;
    }

    /** @return array{0:string,1:string} [state, detail] */
    private static function adherence(Profile $profile, $program): array
    {
        if (! $program) {
            return ['unknown', 'no active program'];
        }
        $planned = 0;
        foreach (($program->currentWeek()['days'] ?? []) as $d) {
            foreach ($d['exercises'] as $e) {
                $planned += (int) $e['sets'];
            }
        }
        if ($planned === 0) {
            return ['unknown', 'no plan this week'];
        }
        $logged = class_exists(\App\Models\WorkoutSet::class)
            ? \App\Models\WorkoutSet::whereIn('workout_exercise_id',
                \App\Models\WorkoutExercise::whereIn('workout_id',
                    $profile->workouts()->where('performed_at', '>=', Carbon::now()->subDays(7))->pluck('id'))->pluck('id'))->count()
            : 0;

        $ratio = $logged / $planned;
        $state = $ratio < 0.5 ? 'behind' : ($ratio <= 1.25 ? 'on' : 'ahead');

        return [$state, "{$logged} / {$planned} sets this week"];
    }
}
