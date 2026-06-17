<?php

namespace App\Support;

/**
 * Builds a real, followable hypertrophy mesocycle from the principles in TrainingPlaybook:
 *  - volume ramps MEV → MRV across the accumulation weeks, then a deload;
 *  - proximity to failure tightens (RIR 3 → 1), compounds kept a touch more conservative than isolations;
 *  - FOCUS (weak-point) muscles get more volume, are trained FIRST (fresh) and more frequently, and get a
 *    lengthened-position / FST-7 emphasis — that's how you bring a lagging muscle up;
 *  - split chosen by training days, frequency ~2×/muscle/week where it fits.
 *
 * Natural, evidence-based dosing — no pharmacology assumed.
 */
class MesocycleGenerator
{
    /** muscle => [ [exercise, kind(compound|isolation), reps], … ] (compound-first) */
    private const LIBRARY = [
        'chest' => [['Barbell Bench Press', 'compound', '6-8'], ['Incline Dumbbell Press', 'compound', '8-10'], ['Cable Fly', 'isolation', '12-15'], ['Machine Chest Press', 'compound', '10-12']],
        'back' => [['Weighted Pull-up / Lat Pulldown', 'compound', '8-10'], ['Barbell Row', 'compound', '8-10'], ['Seated Cable Row', 'compound', '10-12'], ['Single-arm DB Row', 'isolation', '10-12']],
        'quads' => [['Back Squat', 'compound', '6-8'], ['Hack Squat / Leg Press', 'compound', '8-12'], ['Leg Extension', 'isolation', '12-15']],
        'hamstrings' => [['Romanian Deadlift', 'compound', '8-10'], ['Seated Leg Curl', 'isolation', '10-12'], ['Lying Leg Curl', 'isolation', '12-15']],
        // Glutes ordered for the heavy → stretch → pump spectrum so day-rotation spreads it across the week.
        // Trains the maximus (thrust/hinge/squat/lunge) AND the medius "shelf" (abduction). (Contreras.)
        'glutes' => [['Barbell Hip Thrust (pause at top)', 'compound', '6-8'], ['Sumo / Deep Squat', 'compound', '8-10'], ['Bulgarian Split Squat (slow eccentric)', 'compound', '8-12'], ['45° Hip Extension (glute-biased)', 'isolation', '12-15'], ['Cable Pull-through', 'isolation', '12-15'], ['Deficit Reverse Lunge', 'compound', '10-12'], ['Machine Hip Abduction', 'isolation', '15-20'], ['Cable Glute Kickback', 'isolation', '12-20'], ['Single-leg Glute Bridge', 'isolation', '15-20']],
        'shoulders' => [['Overhead Press', 'compound', '6-10'], ['Dumbbell Lateral Raise', 'isolation', '12-20'], ['Cable Lateral Raise', 'isolation', '12-20'], ['Rear-Delt Fly', 'isolation', '15-20']],
        'biceps' => [['Barbell Curl', 'isolation', '8-12'], ['Incline DB Curl', 'isolation', '10-15'], ['Cable Curl', 'isolation', '12-15']],
        'triceps' => [['Close-Grip Bench Press', 'compound', '8-10'], ['Triceps Pushdown', 'isolation', '10-15'], ['Overhead Cable Extension', 'isolation', '12-15']],
        'calves' => [['Standing Calf Raise', 'isolation', '10-15'], ['Seated Calf Raise', 'isolation', '12-20']],
        // Waist-friendly core: control/anti-rotation + TVA, NOT heavy loaded obliques (which can widen the waist).
        'abs' => [['Pallof Press (anti-rotation)', 'isolation', '10-12/side'], ['Plank', 'isolation', '20-40s'], ['Dead Bug', 'isolation', '8-10/side'], ['Stomach Vacuum (TVA)', 'isolation', '10-20s holds'], ['Hanging Leg Raise', 'isolation', '10-15'], ['Cable Crunch', 'isolation', '12-15']],
    ];

    /** Movement regions — so a focus muscle is added to anatomically-sensible days. */
    private const REGIONS = [
        'lower' => ['quads', 'hamstrings', 'glutes', 'calves'],
        'push' => ['chest', 'shoulders', 'triceps'],
        'pull' => ['back', 'biceps'],
        'core' => ['abs'],
    ];

    private const LABELS = [
        'chest' => 'Chest', 'back' => 'Back', 'quads' => 'Quads', 'hamstrings' => 'Hamstrings',
        'glutes' => 'Glutes', 'shoulders' => 'Shoulders', 'biceps' => 'Biceps', 'triceps' => 'Triceps',
        'calves' => 'Calves', 'abs' => 'Abs',
    ];

    private const ALIASES = [
        'delts' => 'shoulders', 'delt' => 'shoulders', 'shoulder' => 'shoulders', 'side delts' => 'shoulders', 'rear delts' => 'shoulders',
        'lats' => 'back', 'upper back' => 'back', 'hams' => 'hamstrings', 'hamstring' => 'hamstrings',
        'glute' => 'glutes', 'quad' => 'quads', 'tri' => 'triceps', 'tris' => 'triceps', 'bi' => 'biceps', 'bis' => 'biceps',
        'pecs' => 'chest', 'chest ' => 'chest', 'core' => 'abs', 'ab' => 'abs', 'calf' => 'calves',
    ];

    /** Split templates by training days: [dayName => [muscles in training order]] */
    private const SPLITS = [
        2 => [['Full A', ['chest', 'back', 'quads', 'shoulders', 'biceps']], ['Full B', ['back', 'chest', 'hamstrings', 'glutes', 'triceps', 'calves']]],
        3 => [['Full A', ['chest', 'back', 'shoulders', 'triceps', 'biceps']], ['Lower', ['quads', 'hamstrings', 'glutes', 'calves', 'abs']], ['Full B', ['back', 'chest', 'shoulders', 'quads', 'hamstrings', 'biceps', 'triceps']]],
        4 => [['Upper A', ['chest', 'back', 'shoulders', 'triceps', 'biceps']], ['Lower A', ['quads', 'hamstrings', 'glutes', 'calves', 'abs']], ['Upper B', ['back', 'chest', 'shoulders', 'biceps', 'triceps']], ['Lower B', ['hamstrings', 'quads', 'glutes', 'calves', 'abs']]],
        5 => [['Upper', ['chest', 'back', 'shoulders', 'biceps', 'triceps']], ['Lower', ['quads', 'hamstrings', 'glutes', 'calves']], ['Push', ['chest', 'shoulders', 'triceps']], ['Pull', ['back', 'biceps', 'abs']], ['Legs', ['quads', 'hamstrings', 'glutes', 'calves']]],
        6 => [['Push A', ['chest', 'shoulders', 'triceps']], ['Pull A', ['back', 'biceps', 'abs']], ['Legs A', ['quads', 'hamstrings', 'glutes', 'calves']], ['Push B', ['shoulders', 'chest', 'triceps']], ['Pull B', ['back', 'biceps']], ['Legs B', ['hamstrings', 'quads', 'glutes', 'calves']]],
    ];

    private const SPLIT_NAMES = [2 => 'Full body', 3 => 'Full body', 4 => 'Upper / Lower', 5 => 'Upper / Lower + Push·Pull·Legs', 6 => 'Push / Pull / Legs'];

    /** @param  array{focus?:array|string,days_per_week?:int,weeks?:int,experience?:string}  $opts */
    public static function build(array $opts): array
    {
        $days = max(2, min(6, (int) ($opts['days_per_week'] ?? 4)));
        $weeks = max(4, min(8, (int) ($opts['weeks'] ?? 5)));
        $exp = in_array($opts['experience'] ?? '', ['beginner', 'intermediate', 'advanced'], true) ? $opts['experience'] : 'intermediate';
        $focus = self::normalizeFocus($opts['focus'] ?? []);

        $split = array_map(fn ($d) => [$d[0], $d[1]], self::SPLITS[$days]);   // [name, muscles] copies
        self::applyFocus($split, $focus, $days);                              // priority freq + front placement

        $base = ['beginner' => 4, 'intermediate' => 5, 'advanced' => 6][$exp];
        $accum = $weeks - 1;                                                  // last week is the deload

        $plan = [];
        $volume = [];   // muscle => [wk1 weekly sets, …]
        for ($w = 1; $w <= $weeks; $w++) {
            $deload = $w === $weeks;
            $rir = $deload ? 4 : max(1, 3 - (int) floor(($w - 1) / max(1, $accum - 1) * 2));

            $days_out = [];
            $rotation = [];   // per-muscle: how many days it has appeared this week → rotates its exercises
            foreach ($split as [$dayName, $muscles]) {
                $exercises = [];
                foreach ($muscles as $m) {
                    $priority = in_array($m, $focus, true);
                    $perSession = self::setsPerSession($base, $priority, $w, $deload);
                    $rot = $rotation[$m] ?? 0;
                    $exercises = array_merge($exercises, self::exercisesFor($m, $perSession, $rir, $priority, $w === $accum, $rot));
                    $rotation[$m] = $rot + 1;
                    $volume[$m][$w - 1] = ($volume[$m][$w - 1] ?? 0) + $perSession;
                }
                $days_out[] = ['name' => $dayName, 'exercises' => $exercises];
            }

            $plan[] = [
                'week' => $w,
                'phase' => $deload ? 'Deload' : ($w === $accum ? 'Peak accumulation' : 'Accumulation'),
                'deload' => $deload,
                'rir' => $rir,
                'days' => $days_out,
            ];
        }

        $focusLabels = array_map(fn ($m) => self::LABELS[$m], $focus);
        $name = $focus !== []
            ? "{$weeks}-Week ".implode(' & ', $focusLabels).' Specialization'
            : "{$weeks}-Week Hypertrophy Mesocycle";

        return [
            'name' => $name,
            'focus' => $focus,
            'focus_labels' => $focusLabels,
            'days_per_week' => $days,
            'weeks' => $weeks,
            'split' => self::SPLIT_NAMES[$days],
            'experience' => $exp,
            'plan' => $plan,
            'volume' => $volume,        // weekly sets per muscle across the block
        ];
    }

    /** Per-session set count for a muscle on a given week. */
    private static function setsPerSession(int $base, bool $priority, int $w, bool $deload): int
    {
        $start = $base + ($priority ? 2 : 0);
        if ($deload) {
            return max(2, (int) round($start * 0.5));
        }
        $ramp = (int) floor(($w - 1) * ($priority ? 1.0 : 0.6));   // priority climbs faster toward MRV
        $cap = $priority ? 9 : 7;

        return min($cap, $start + $ramp);
    }

    /** @return array<int,array<string,mixed>> exercises covering $perSession sets for a muscle */
    private static function exercisesFor(string $muscle, int $perSession, int $rir, bool $priority, bool $peakWeek, int $rotation = 0): array
    {
        $lib = self::LIBRARY[$muscle];
        $count = count($lib);
        $nEx = $perSession <= 4 ? 1 : ($perSession <= 7 ? 2 : 3);
        if ($priority) {
            $nEx = max(2, $nEx);
        }
        $nEx = min($nEx, $count);

        // Rotate which exercises this muscle uses by day, so across the week a focus muscle cycles its whole
        // library (heavy thrust → stretch lunge → pump for glutes). Consistent week to week for overload.
        $start = ($rotation * $nEx) % $count;
        $picks = [];
        for ($i = 0; $i < $nEx; $i++) {
            $picks[] = ($start + $i) % $count;
        }
        // Glute focus ALWAYS finishes with hip abduction — that's the gluteus-medius "shelf" work that builds
        // the round/3D look, and it's the one thing day-rotation can miss at low frequency. (Contreras.)
        if ($muscle === 'glutes' && $priority && ! in_array(6, $picks, true)) {
            $picks[$nEx - 1] = 6;
        }

        $perEx = intdiv($perSession, $nEx);
        $extra = $perSession - $perEx * $nEx;

        $out = [];
        for ($i = 0; $i < $nEx; $i++) {
            [$exName, $kind, $reps] = $lib[$picks[$i]];
            $sets = $perEx + ($i < $extra ? 1 : 0);
            if ($sets < 1) {
                continue;
            }
            // Compounds run a touch shy of failure to protect joints/systemic fatigue; isolations closer.
            $exRir = $kind === 'compound' ? min(3, $rir + 1) : $rir;
            $note = null;
            if ($priority && $kind === 'isolation') {
                $note = $peakWeek ? 'lengthened partials / FST-7 finish' : 'emphasise the stretch';
            }
            $out[] = array_filter([
                'name' => $exName,
                'muscle' => $muscle,
                'muscle_label' => self::LABELS[$muscle],
                'sets' => $sets,
                'reps' => $reps,
                'rir' => $exRir,
                'priority' => $priority ?: null,
                'note' => $note,
            ], fn ($v) => $v !== null);
        }

        return $out;
    }

    /** Ensure focus muscles are trained often (2× / 3× when there's room) on sensible days, listed FIRST. */
    private static function applyFocus(array &$split, array $focus, int $days): void
    {
        if ($focus === []) {
            return;
        }
        $targetFreq = min($days, $days >= 5 ? 3 : 2);
        foreach ($focus as $m) {
            $region = self::regionOf($m);
            $onDays = [];
            foreach ($split as $i => [$name, $muscles]) {
                if (in_array($m, $muscles, true)) {
                    $onDays[] = $i;
                }
            }
            // Candidate days to add to, preferring same-region days (a glute lands on leg days, not arm days),
            // then the shortest. Skip days that have no same-region muscle at all when alternatives exist.
            $candidates = array_values(array_diff(array_keys($split), $onDays));
            usort($candidates, function ($a, $z) use ($split, $region) {
                $ra = self::dayHasRegion($split[$a][1], $region) ? 0 : 1;
                $rz = self::dayHasRegion($split[$z][1], $region) ? 0 : 1;

                return $ra !== $rz ? $ra <=> $rz : count($split[$a][1]) <=> count($split[$z][1]);
            });
            foreach ($candidates as $i) {
                if (count($onDays) >= $targetFreq) {
                    break;
                }
                if (! self::dayHasRegion($split[$i][1], $region)) {
                    continue;   // don't bolt a lower-body focus onto a pure upper day
                }
                $split[$i][1][] = $m;
                $onDays[] = $i;
            }
            // Move it to the front of every day it appears on (trained fresh).
            foreach ($onDays as $i) {
                $split[$i][1] = array_values(array_unique(array_merge([$m], array_diff($split[$i][1], [$m]))));
            }
        }
    }

    private static function regionOf(string $muscle): string
    {
        foreach (self::REGIONS as $region => $muscles) {
            if (in_array($muscle, $muscles, true)) {
                return $region;
            }
        }

        return 'other';
    }

    private static function dayHasRegion(array $muscles, string $region): bool
    {
        foreach ($muscles as $m) {
            if (self::regionOf($m) === $region) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int,string> canonical, de-duplicated focus muscles */
    public static function normalizeFocus(array|string $focus): array
    {
        $items = is_string($focus) ? preg_split('/[,\n;]+/', $focus) : $focus;
        $out = [];
        foreach ((array) $items as $raw) {
            $k = strtolower(trim((string) $raw));
            if ($k === '') {
                continue;
            }
            // expand "legs" / "arms" groups
            if (in_array($k, ['legs', 'leg'], true)) {
                array_push($out, 'quads', 'hamstrings', 'glutes');
                continue;
            }
            if (in_array($k, ['arms', 'arm'], true)) {
                array_push($out, 'biceps', 'triceps');
                continue;
            }
            $k = self::ALIASES[$k] ?? $k;
            if (isset(self::LIBRARY[$k])) {
                $out[] = $k;
            }
        }

        return array_values(array_unique($out));
    }

    public static function muscleLabels(): array
    {
        return self::LABELS;
    }
}
