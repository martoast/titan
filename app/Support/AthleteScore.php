<?php

namespace App\Support;

use App\Models\Profile;
use Illuminate\Support\Carbon;

/**
 * The Athlete Score -- one 0-100 read on overall fitness, composed from the pillars Titan can
 * measure: cardio (VO₂max vs age/sex norms), autonomic recovery (resting HR + HRV), strength
 * (relative strength from logged lifts, else training consistency), and activity (daily steps).
 *
 * Honest by construction: pillars with no data are dropped and the weights renormalised, so the
 * score reflects what we actually know -- and confidence drops when VO₂max (the strongest signal)
 * is missing. It's a motivating composite, not a lab VO₂max test.
 */
class AthleteScore
{
    private const VO2_PEAK_M = 50.0;     // ~age-25 reference peak (mirrors BiologicalAge)
    private const VO2_PEAK_F = 42.0;
    private const VO2_DECLINE = 0.45;    // ml/kg/min lost per year after 25

    /** @return array{score:int,grade:string,label:string,pillars:array<int,array<string,mixed>>,vo2max:?float,fitness_age:?float,chrono_age:?float,confidence:string}|null */
    public static function assess(Profile $profile): ?array
    {
        $age = self::age($profile);
        $female = in_array(strtolower((string) ($profile->sex ?? '')), ['f', 'female', 'woman', 'w'], true);
        $pillars = [];

        // --- Cardio: VO₂max vs age/sex expectation (the headline) ---
        $vo2 = class_exists(\App\Models\ActivitySession::class)
            ? $profile->activitySessions()->whereNotNull('vo2max')->orderByDesc('started_at')->value('vo2max')
            : null;
        $vo2 = $vo2 !== null ? (float) $vo2 : null;
        $fitnessAge = null;
        if ($vo2 !== null) {
            $peak = $female ? self::VO2_PEAK_F : self::VO2_PEAK_M;
            $expected = $peak - max(0.0, ($age ?? 30) - 25) * self::VO2_DECLINE;
            // expected−12 → 0, expected → 50, expected+12 → 100
            $cardio = self::clamp((($vo2 - ($expected - 12)) / 24) * 100);
            $fitnessAge = round(max(18.0, 25.0 + ($peak - $vo2) / self::VO2_DECLINE), 1);
            $pillars[] = ['key' => 'cardio', 'label' => 'Cardio', 'score' => (int) round($cardio), 'weight' => 0.40,
                'detail' => 'VO₂max '.round($vo2, 1)];
        }

        // --- Recovery: resting HR + HRV ---
        $rec = class_exists(\App\Models\RecoveryLog::class)
            ? $profile->recoveryLogs()->orderByDesc('logged_at')->orderByDesc('id')->first()
            : null;
        if ($rec) {
            $parts = [];
            if ($rec->resting_hr) {
                $parts[] = self::clamp((80 - $rec->resting_hr) / 40 * 100);   // 40bpm→100, 80→0
            }
            if ($rec->hrv_ms) {
                $parts[] = self::clamp(($rec->hrv_ms - 20) / 80 * 100);       // 20ms→0, 100→100
            }
            if ($parts !== []) {
                $pillars[] = ['key' => 'recovery', 'label' => 'Recovery', 'score' => (int) round(array_sum($parts) / count($parts)), 'weight' => 0.25,
                    'detail' => trim(($rec->resting_hr ? $rec->resting_hr.' bpm' : '').($rec->hrv_ms ? ' · '.$rec->hrv_ms.' ms' : ''), ' ·')];
            }
        }

        // --- Strength: relative strength from logged lifts, else training consistency ---
        $strength = self::strengthPillar($profile);
        if ($strength) {
            $pillars[] = $strength;
        }

        // --- Activity: average daily steps over the last 14 days ---
        if (class_exists(\App\Models\DailyActivity::class)) {
            $days = $profile->dailyActivity()->where('date', '>=', Carbon::today()->subDays(13))->get();
            if ($days->count() >= 3) {
                $avg = (float) $days->avg('steps');
                $pillars[] = ['key' => 'activity', 'label' => 'Activity', 'score' => (int) round(self::clamp(($avg - 3000) / 7000 * 100)), 'weight' => 0.15,
                    'detail' => number_format(round($avg)).' steps/day'];
            }
        }

        // Need cardio, or at least two pillars, to say anything meaningful.
        if ($vo2 === null && count($pillars) < 2) {
            return null;
        }

        $totalW = array_sum(array_column($pillars, 'weight'));
        $score = (int) round(array_sum(array_map(fn ($p) => $p['score'] * $p['weight'], $pillars)) / max(1e-6, $totalW));
        [$grade, $label] = self::grade($score);

        $confidence = match (true) {
            $vo2 !== null && count($pillars) >= 3 => 'high',
            $vo2 !== null || count($pillars) >= 3 => 'medium',
            default => 'low',
        };

        return [
            'score' => $score,
            'grade' => $grade,
            'label' => $label,
            'pillars' => array_map(fn ($p) => ['key' => $p['key'], 'label' => $p['label'], 'score' => $p['score'], 'detail' => $p['detail']], $pillars),
            'vo2max' => $vo2 !== null ? round($vo2, 1) : null,
            'fitness_age' => $fitnessAge,
            'chrono_age' => $age !== null ? round($age, 1) : null,
            'confidence' => $confidence,
        ];
    }

    private static function strengthPillar(Profile $profile): ?array
    {
        if (! class_exists(\App\Models\Workout::class)) {
            return null;
        }
        // Relative strength: best estimated 1RM (Epley) across logged sets, vs bodyweight.
        $bw = class_exists(\App\Models\BodyMetric::class)
            ? $profile->bodyMetrics()->whereNotNull('weight_kg')->orderByDesc('taken_at')->value('weight_kg')
            : null;

        $best = null;
        if (class_exists(\App\Models\WorkoutSet::class) && class_exists(\App\Models\WorkoutExercise::class)) {
            $weIds = \App\Models\WorkoutExercise::whereIn('workout_id', $profile->workouts()->pluck('id'))->pluck('id');
            if ($weIds->isNotEmpty()) {
                foreach (\App\Models\WorkoutSet::whereIn('workout_exercise_id', $weIds)->where('weight_kg', '>', 0)->get(['weight_kg', 'reps']) as $s) {
                    $e1rm = (float) $s->weight_kg * (1 + min(12, (int) $s->reps) / 30);   // cap reps for a sane estimate
                    $best = $best === null ? $e1rm : max($best, $e1rm);
                }
            }
        }

        if ($best !== null && $bw) {
            $ratio = $best / (float) $bw;                              // best lift / bodyweight
            $score = self::clamp($ratio / 2.5 * 100);                  // ~2.5× bw → elite
            return ['key' => 'strength', 'label' => 'Strength', 'score' => (int) round($score), 'weight' => 0.20,
                'detail' => round($ratio, 2).'× bodyweight'];
        }

        // Fallback: training consistency over the last 28 days (frequency, capped -- not true strength).
        $sessions = $profile->workouts()->where('performed_at', '>=', Carbon::now()->subDays(28))->count();
        if ($sessions >= 1) {
            $perWeek = $sessions / 4;
            return ['key' => 'strength', 'label' => 'Strength', 'score' => (int) round(self::clamp($perWeek / 4 * 100, 0, 80)), 'weight' => 0.20,
                'detail' => round($perWeek, 1).' sessions/wk'];
        }

        return null;
    }

    private static function age(Profile $profile): ?float
    {
        if (! $profile->birthdate) {
            return null;
        }

        return rescue(fn () => Carbon::parse($profile->birthdate)->diffInDays(Carbon::now()) / 365.25, null, false);
    }

    /** @return array{0:string,1:string} [grade, one-line label] */
    private static function grade(int $s): array
    {
        return match (true) {
            $s >= 85 => ['Elite', 'Top-tier athletic fitness.'],
            $s >= 70 => ['Excellent', 'Well above average -- strong all round.'],
            $s >= 55 => ['Strong', 'Solidly fit with clear strengths.'],
            $s >= 40 => ['Building', 'A real base to build on.'],
            $s >= 25 => ['Developing', 'Early days -- momentum is everything.'],
            default => ['Starting out', 'The best time to begin was yesterday.'],
        };
    }

    private static function clamp(float $v, float $min = 0.0, float $max = 100.0): float
    {
        return max($min, min($max, $v));
    }
}
