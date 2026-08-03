<?php

namespace App\Console\Commands;

use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\Exercise;
use App\Models\Profile;
use Database\Seeders\BrainSeeder;
use Database\Seeders\ExerciseLibrarySeeder;
use Illuminate\Console\Command;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Fill ONE profile with a lived-in history for a demo recording.
 *
 * ⚠ THIS DELETES THE PROFILE'S REAL DATA. Do not call it directly — go through
 * `~/deploy/titan-demo-mode.sh on`, which snapshots the real data first and gives you
 * `off` to put it back. The guard below refuses to run without that snapshot.
 *
 * Unlike {@see SeedDemo} (which provisions a fresh App-Review *account*), this seeds an
 * EXISTING account in place and never touches its credentials or display name — you stay
 * logged in as yourself, with months of history behind you.
 *
 * Everything is generated from a fixed RNG seed, so a re-run produces byte-identical data.
 */
class DemoSeed extends Command
{
    protected $signature = 'titan:demo-seed
        {--profile=1 : Profile to fill}
        {--days=90 : How many days of history to generate}
        {--snapshot= : Name of the host snapshot taken first (supplied by titan-demo-mode.sh)}
        {--allow-unsnapshotted : Bypass the snapshot guard (you are on your own)}';

    protected $description = 'Fill a profile with demo history (destructive — use titan-demo-mode.sh)';

    /** Relations cleared before seeding so a re-run is deterministic. */
    private const WIPE = [
        'sleepLogs', 'recoveryLogs', 'meals', 'workouts', 'activitySessions', 'dailyActivity',
        'conversations', 'biomarkerReadings', 'bodyMetrics', 'hydrationLogs', 'coachMemories',
        'glucoseReadings', 'fasts', 'stackItems', 'goals', 'mealSuggestions', 'physiqueGoals',
        'livingGoalRenders', 'weeklySnapshots', 'behaviorLogs', 'longevitySnapshots', 'knowledgePages',
    ];

    private Profile $profile;

    private Carbon $today;

    private int $days;

    public function handle(): int
    {
        $this->profile = Profile::find((int) $this->option('profile')) ?? throw new \RuntimeException('profile not found');
        $this->days = max(14, (int) $this->option('days'));
        $this->today = Carbon::today(Conversation::tz());

        $snapshot = trim((string) $this->option('snapshot'));
        if ($snapshot === '' && ! $this->option('allow-unsnapshotted')) {
            $this->error('Refusing to run: no --snapshot was named.');
            $this->line('This command DELETES the profile\'s real data. Use: ~/deploy/titan-demo-mode.sh on');

            return self::FAILURE;
        }
        if ($snapshot !== '') {
            $this->line("Real data is snapshotted as <info>{$snapshot}</info>");
        }

        // Deterministic: same seed → same demo, every time.
        mt_srand(20260802 + $this->profile->id);

        $this->line("Filling profile {$this->profile->id} ({$this->profile->display_name}) with {$this->days} days of history");

        foreach (self::WIPE as $rel) {
            if (method_exists($this->profile, $rel)) {
                $this->profile->{$rel}()->delete();
            }
        }

        $this->components->task('Profile & targets', fn () => $this->seedProfile());
        $this->components->task('Exercise library', fn () => $this->runSeeder(ExerciseLibrarySeeder::class));
        $this->components->task('Sleep & recovery', fn () => $this->seedSleepRecovery());
        $this->components->task('Daily activity', fn () => $this->seedActivity());
        $this->components->task('Meals & hydration', fn () => $this->seedMeals());
        $this->components->task('Training', fn () => $this->seedTraining());
        $this->components->task('Body & biomarkers', fn () => $this->seedBodyAndLabs());
        $this->components->task('Glucose (CGM)', fn () => $this->seedGlucose());
        $this->components->task('Supplements, goals & fasts', fn () => $this->seedStackGoalsFasts());
        $this->components->task('Brain (wiki)', fn () => $this->runSeeder(BrainSeeder::class));
        $this->components->task('Coach day chats', fn () => $this->seedDayChats());
        $this->components->task('Streaks', fn () => $this->seedStreaks());

        $this->newLine();
        $this->info('✓ Demo history seeded. Restore with: ~/deploy/titan-demo-mode.sh off');

        return self::SUCCESS;
    }

    private function runSeeder(string $class): void
    {
        /** @var Seeder $seeder */
        $seeder = $this->laravel->make($class);
        $seeder->setContainer($this->laravel)->setCommand($this);
        if (property_exists($seeder, 'seedProfile')) {
            $seeder->seedProfile = $this->profile;
        }
        $seeder->run();
    }

    /** A random int in [$a,$b] from the seeded stream. */
    private function r(int $a, int $b): int
    {
        return $a + (mt_rand() % max(1, $b - $a + 1));
    }

    private function chance(int $pct): bool
    {
        return $this->r(1, 100) <= $pct;
    }

    // ── profile ───────────────────────────────────────────────────────────────────

    private function seedProfile(): void
    {
        // Credentials, name, sex and birthdate are deliberately left alone — this is still
        // the real person's account, just with a fuller history behind it.
        $this->profile->forceFill([
            'primary_goal' => $this->profile->primary_goal ?: 'Recomp — lose fat, hold muscle, strong biomarkers',
            'coach_tone' => $this->profile->coach_tone ?: 'balanced',
            'height_cm' => $this->profile->height_cm ?: 178,
            'onboarded_at' => $this->profile->onboarded_at ?? now(),
            'settings' => array_merge($this->profile->settings ?? [], [
                'macro_targets' => ['calories' => 2500, 'protein_g' => 180, 'carbs_g' => 250, 'fat_g' => 75],
                'sleep_target_h' => 8,
            ]),
        ])->save();
    }

    // ── sleep + recovery ──────────────────────────────────────────────────────────

    /**
     * Correlated nights: duration drives quality, quality drives next-morning HRV/RHR, and a
     * handful of deliberately rough nights keep the trend charts honest rather than flat.
     */
    private function seedSleepRecovery(): void
    {
        $sleep = [];
        $recovery = [];

        for ($i = $this->days; $i >= 0; $i--) {
            $date = $this->today->copy()->subDays($i);
            $rough = $this->chance(14);
            $weekend = $date->isSaturday() || $date->isSunday();

            $duration = $rough ? $this->r(280, 360) : $this->r(410, 505) + ($weekend ? 20 : 0);
            $awake = $rough ? $this->r(35, 60) : $this->r(12, 30);
            $deep = (int) round($duration * ($rough ? 0.10 : 0.16) + $this->r(-8, 8));
            $rem = (int) round($duration * ($rough ? 0.15 : 0.22) + $this->r(-10, 10));
            $light = max(30, $duration - $deep - $rem - $awake);
            $quality = max(38, min(97, (int) round($duration / 5.6) + ($rough ? -14 : 4) + $this->r(-4, 4)));

            $bed = $date->copy()->subDay()->setTime(22, 0)->addMinutes($this->r(0, 130));
            $wake = $bed->copy()->addMinutes($duration + $awake);

            $sleep[] = [
                'profile_id' => $this->profile->id,
                'slept_at' => $date->toDateString(),
                'is_nap' => 0,
                'session_start' => $bed,
                'bedtime' => $bed,
                'wake_time' => $wake,
                'duration_min' => $duration,
                'quality' => $quality,
                'stage_status' => 'final',
                'coverage' => round($this->r(78, 97) / 100, 2),
                'low_confidence' => 0,
                'finalized_at' => $wake,
                'deep_min' => $deep,
                'rem_min' => $rem,
                'light_min' => $light,
                'awake_min' => $awake,
                'updated_via' => 'band',
                'created_at' => $wake,
                'updated_at' => $wake,
            ];

            // Morning recovery reads off the night just had.
            $hrv = (int) round(62 + ($quality - 70) * 0.55 + $this->r(-6, 6));
            $rhr = (int) round(54 - ($quality - 70) * 0.09 + $this->r(-2, 2));
            $recovery[] = [
                'profile_id' => $this->profile->id,
                'logged_at' => $date->copy()->setTime(7, $this->r(0, 45)),
                'hrv_ms' => max(28, $hrv),
                'resting_hr' => max(44, $rhr),
                'resp_rate' => round($this->r(130, 158) / 10, 1),
                'stress' => $rough ? $this->r(45, 70) : $this->r(15, 40),
                'soreness' => $this->r(1, 4),
                'mood' => $rough ? $this->r(2, 3) : $this->r(3, 5),
                'energy' => $rough ? $this->r(2, 3) : $this->r(3, 5),
                // JSON, matching what the band's own seal writes — the UI reads `valid` and the
                // window counts to decide how confidently to state the number.
                'quality' => json_encode([
                    'beats' => $this->r(900, 2400),
                    'valid' => true,
                    'windows_used' => $this->r(60, 120),
                    'windows_dropped' => $this->r(10, 70),
                ]),
                'updated_via' => 'band',
                'created_at' => $date->copy()->setTime(7, 30),
                'updated_at' => $date->copy()->setTime(7, 30),
            ];
        }

        foreach (array_chunk($sleep, 200) as $c) {
            DB::table('sleep_logs')->insert($c);
        }
        foreach (array_chunk($recovery, 200) as $c) {
            DB::table('recovery_logs')->insert($c);
        }
    }

    // ── steps ─────────────────────────────────────────────────────────────────────

    private function seedActivity(): void
    {
        $rows = [];
        for ($i = $this->days; $i >= 0; $i--) {
            $date = $this->today->copy()->subDays($i);
            $steps = $date->isSunday() ? $this->r(3200, 6500) : $this->r(6800, 15200);
            $rows[] = [
                'profile_id' => $this->profile->id,
                'date' => $date->toDateString(),
                'steps' => $steps,
                'mvpa_min' => (int) round($steps / 260) + $this->r(0, 12),
                'active_kcal' => (int) round($steps * 0.041) + $this->r(-30, 60),
                'floors' => $this->r(2, 18),
                'distance_km' => round($steps * 0.00076, 2),
                'source' => 'band',
                'updated_via' => 'band',
                'created_at' => $date->copy()->setTime(23, 30),
                'updated_at' => $date->copy()->setTime(23, 30),
            ];
        }
        foreach (array_chunk($rows, 200) as $c) {
            DB::table('daily_activity')->insert($c);
        }
    }

    // ── meals + hydration ─────────────────────────────────────────────────────────

    private function seedMeals(): void
    {
        $catalog = [
            'breakfast' => [
                ['Greek yogurt, berries & granola', 430, 32, 48, 11, 6],
                ['3-egg omelette, spinach & feta', 390, 28, 6, 28, 2],
                ['Protein oats with banana', 470, 34, 62, 10, 8],
                ['Smoked salmon on rye', 410, 27, 34, 18, 5],
            ],
            'lunch' => [
                ['Chicken burrito bowl', 680, 48, 72, 20, 12],
                ['Poke bowl — salmon & edamame', 640, 42, 66, 21, 9],
                ['Steak salad with avocado', 590, 45, 18, 36, 8],
                ['Turkey & hummus wrap', 560, 38, 54, 21, 9],
            ],
            'dinner' => [
                ['Grilled salmon, rice & broccoli', 720, 46, 68, 28, 9],
                ['Chicken stir-fry with noodles', 690, 44, 78, 20, 7],
                ['Carne asada tacos', 780, 41, 66, 36, 8],
                ['Beef chili with beans', 650, 43, 52, 26, 14],
            ],
            'snack' => [
                ['Whey shake', 180, 30, 6, 3, 1],
                ['Apple & peanut butter', 260, 8, 28, 14, 6],
                ['Cottage cheese & pineapple', 220, 24, 20, 4, 1],
                ['Handful of almonds', 210, 8, 7, 18, 4],
            ],
        ];

        $meals = [];
        $items = [];
        $hydration = [];

        for ($i = $this->days; $i >= 0; $i--) {
            $date = $this->today->copy()->subDays($i);
            $slots = [['breakfast', 8], ['lunch', 13], ['dinner', 19]];
            if ($this->chance(70)) {
                $slots[] = ['snack', 16];
            }

            foreach ($slots as [$type, $hour]) {
                $pick = $catalog[$type][$this->r(0, count($catalog[$type]) - 1)];
                [$name, $kcal, $p, $c, $f, $fib] = $pick;
                $jitter = $this->r(92, 108) / 100;

                $eatenAt = $date->copy()->setTime($hour, $this->r(0, 55));
                $meals[] = [
                    'profile_id' => $this->profile->id,
                    'eaten_at' => $eatenAt,
                    'name' => $name,
                    'calories' => (int) round($kcal * $jitter),
                    'protein_g' => (int) round($p * $jitter),
                    'carbs_g' => (int) round($c * $jitter),
                    'fat_g' => (int) round($f * $jitter),
                    'fiber_g' => (int) round($fib * $jitter),
                    'macros_estimated' => null,
                    'source' => $this->chance(55) ? 'photo' : 'coach',
                    'meal_type' => $type,
                    'created_at' => $eatenAt,
                    'updated_at' => $eatenAt,
                ];
            }

            $hydration[] = [
                'profile_id' => $this->profile->id,
                'logged_on' => $date->toDateString(),
                'amount_ml' => $this->r(1800, 3400),
                'source' => 'manual',
                'created_at' => $date->copy()->setTime(20, 0),
                'updated_at' => $date->copy()->setTime(20, 0),
            ];
        }

        foreach (array_chunk($meals, 200) as $c) {
            DB::table('meals')->insert($c);
        }
        foreach (array_chunk($hydration, 200) as $c) {
            DB::table('hydration_logs')->insert($c);
        }

        // Item breakdowns for the most recent fortnight — enough to show the feature without
        // bloating the older history.
        $recent = $this->profile->meals()->where('eaten_at', '>=', $this->today->copy()->subDays(14))->get();
        foreach ($recent as $meal) {
            $parts = max(2, min(4, (int) round($meal->calories / 260)));
            $left = ['kcal' => $meal->calories, 'p' => $meal->protein_g, 'c' => $meal->carbs_g, 'f' => $meal->fat_g];
            foreach (range(1, $parts) as $n) {
                $last = $n === $parts;
                $share = $last ? 1.0 : $this->r(25, 45) / 100;
                $items[] = [
                    'meal_id' => $meal->id,
                    'name' => explode(',', $meal->name)[min($n - 1, count(explode(',', $meal->name)) - 1)] ?: 'Component '.$n,
                    'quantity' => '1 serving',
                    'calories' => (int) round($left['kcal'] * $share),
                    'protein_g' => (int) round($left['p'] * $share),
                    'carbs_g' => (int) round($left['c'] * $share),
                    'fat_g' => (int) round($left['f'] * $share),
                    'fiber_g' => 1,
                    'created_at' => $meal->eaten_at,
                    'updated_at' => $meal->eaten_at,
                ];
                foreach ($left as $k => $v) {
                    $left[$k] = (int) round($v * (1 - $share));
                }
            }
        }
        foreach (array_chunk($items, 200) as $c) {
            DB::table('meal_items')->insert($c);
        }
    }

    // ── training ──────────────────────────────────────────────────────────────────

    /**
     * A push/pull/legs split plus a couple of runs a week. Each lift writes an
     * ActivitySession (what the band sealed) AND a Workout with real sets, so the strain ring
     * and the logged-sets view agree.
     */
    private function seedTraining(): void
    {
        $split = [
            'Push day' => ['Bench Press', 'Overhead Press', 'Incline Dumbbell Press', 'Cable Fly', 'Triceps Pushdown'],
            'Pull day' => ['Deadlift', 'Barbell Row', 'Lat Pulldown', 'Face Pull', 'Barbell Curl'],
            'Leg day' => ['Back Squat', 'Romanian Deadlift', 'Leg Press', 'Walking Lunge', 'Calf Raise'],
        ];
        $names = array_keys($split);

        for ($i = $this->days; $i >= 0; $i--) {
            $date = $this->today->copy()->subDays($i);
            $dow = (int) $date->dayOfWeek;

            // Lift Mon/Wed/Fri, run Tue/Sat.
            if (in_array($dow, [1, 3, 5], true)) {
                $name = $names[($i / 2) % 3];
                $start = $date->copy()->setTime(18, $this->r(0, 40));
                $dur = $this->r(48, 72);
                $session = $this->profile->activitySessions()->create([
                    'source' => 'band',
                    'started_at' => $start,
                    'ended_at' => $start->copy()->addMinutes($dur),
                    'duration_min' => $dur,
                    'activity_type' => 'lift',
                    'activity_confidence' => 0.93,
                    'is_training' => true,
                    'avg_hr' => $this->r(112, 132),
                    'max_hr' => $this->r(150, 176),
                    'hr_source' => 'band',
                    'hr_quality' => round($this->r(880, 985) / 1000, 3),
                    'trimp' => $this->r(58, 96),
                    'calories_kcal' => $this->r(320, 520),
                    'relative_effort' => $this->r(30, 62),
                    'updated_via' => 'band',
                ]);

                $workout = $this->profile->workouts()->create([
                    'activity_session_id' => $session->id,
                    'performed_at' => $start,
                    'name' => $name,
                    'duration_min' => $dur,
                    'updated_via' => 'coach',
                ]);

                // Progressive overload: weights creep up across the window.
                $progress = 1 + (($this->days - $i) / max(1, $this->days)) * 0.14;
                foreach ($split[$name] as $idx => $exName) {
                    $ex = Exercise::firstOrCreate(
                        ['slug' => \Illuminate\Support\Str::slug($exName)],
                        ['name' => $exName, 'muscle_group' => 'full_body', 'category' => 'strength', 'equipment' => 'barbell']
                    );
                    $we = $workout->exercises()->create(['exercise_id' => $ex->id, 'order' => $idx + 1]);
                    $base = match (true) {
                        str_contains($exName, 'Deadlift') => 120,
                        str_contains($exName, 'Squat') => 100,
                        str_contains($exName, 'Bench') => 80,
                        str_contains($exName, 'Press') => 55,
                        default => 35,
                    };
                    foreach (range(1, $this->r(3, 4)) as $sn) {
                        DB::table('workout_sets')->insert([
                            'workout_exercise_id' => $we->id,
                            'set_number' => $sn,
                            'reps' => $this->r(6, 12),
                            'weight_kg' => round($base * $progress + $this->r(-4, 4), 1),
                            'rpe' => $this->r(7, 9),
                            'is_warmup' => 0,
                            'created_at' => $start,
                            'updated_at' => $start,
                        ]);
                    }
                }
            } elseif (in_array($dow, [2, 6], true)) {
                $start = $date->copy()->setTime($dow === 6 ? 8 : 19, $this->r(0, 40));
                $dur = $this->r(26, 52);
                $km = round($dur / $this->r(52, 62) * 10, 2);
                $this->profile->activitySessions()->create([
                    'source' => 'band',
                    'started_at' => $start,
                    'ended_at' => $start->copy()->addMinutes($dur),
                    'duration_min' => $dur,
                    'activity_type' => 'run',
                    'activity_confidence' => 0.96,
                    'is_training' => true,
                    'distance_km' => $km,
                    'distance_source' => 'gps',
                    'avg_hr' => $this->r(142, 162),
                    'max_hr' => $this->r(168, 184),
                    'hr_source' => 'band',
                    'hr_quality' => round($this->r(850, 970) / 1000, 3),
                    'trimp' => $this->r(70, 120),
                    'calories_kcal' => (int) round($km * 68),
                    'vo2max' => round($this->r(470, 512) / 10, 1),
                    'moving_time_s' => $dur * 60,
                    'avg_pace_s_per_km' => (int) round($dur * 60 / max(0.1, $km)),
                    'elevation_gain_m' => $this->r(10, 120),
                    'relative_effort' => $this->r(40, 88),
                    'updated_via' => 'band',
                ]);
            }
        }
    }

    // ── body + labs ───────────────────────────────────────────────────────────────

    private function seedBodyAndLabs(): void
    {
        // Weekly weigh-ins on a slow recomp: down ~4 kg with body fat falling faster than weight.
        $startKg = 86.5;
        for ($i = $this->days; $i >= 0; $i -= 7) {
            $date = $this->today->copy()->subDays($i);
            $t = ($this->days - $i) / max(1, $this->days);
            DB::table('body_metrics')->insert([
                'profile_id' => $this->profile->id,
                'weight_kg' => round($startKg - 4.2 * $t + $this->r(-4, 4) / 10, 1),
                'body_fat_pct' => round(21.5 - 4.5 * $t + $this->r(-3, 3) / 10, 1),
                'waist_cm' => round(88 - 6 * $t + $this->r(-3, 3) / 10, 1),
                'chest_cm' => round(103 + 1.5 * $t, 1),
                'arm_cm' => round(35.5 + 1.2 * $t, 1),
                'taken_at' => $date->copy()->setTime(7, 15),
                'created_at' => $date->copy()->setTime(7, 15),
                'updated_at' => $date->copy()->setTime(7, 15),
            ]);
        }

        // Two blood panels so every marker has a trend + an improvement story.
        $panels = [
            ['days' => $this->days, 'shift' => 0.0],
            ['days' => 9, 'shift' => 1.0],
        ];
        $markers = [
            // marker, unit, before, after, flag_before, flag_after
            ['Total Testosterone', 'ng/dL', 512, 648, 'normal', 'normal'],
            ['Free Testosterone', 'pg/mL', 9.8, 13.4, 'low', 'normal'],
            ['ApoB', 'mg/dL', 104, 82, 'high', 'normal'],
            ['LDL', 'mg/dL', 138, 108, 'high', 'normal'],
            ['HDL', 'mg/dL', 48, 57, 'normal', 'normal'],
            ['Triglycerides', 'mg/dL', 142, 96, 'high', 'normal'],
            ['HbA1c', '%', 5.6, 5.2, 'normal', 'normal'],
            ['Fasting Glucose', 'mg/dL', 97, 88, 'normal', 'normal'],
            ['Insulin', 'uIU/mL', 11.2, 6.8, 'high', 'normal'],
            ['Vitamin D', 'ng/mL', 24, 46, 'low', 'normal'],
            ['Ferritin', 'ng/mL', 118, 132, 'normal', 'normal'],
            ['hs-CRP', 'mg/L', 2.4, 0.8, 'high', 'normal'],
            ['ALT', 'U/L', 34, 24, 'normal', 'normal'],
            ['AST', 'U/L', 29, 22, 'normal', 'normal'],
            ['Creatinine', 'mg/dL', 1.02, 0.98, 'normal', 'normal'],
            ['eGFR', 'mL/min', 94, 98, 'normal', 'normal'],
            ['Albumin', 'g/dL', 4.4, 4.6, 'normal', 'normal'],
            ['White Blood Cell Count', 'K/uL', 6.2, 5.8, 'normal', 'normal'],
            ['Lymphocyte %', '%', 31, 34, 'normal', 'normal'],
            ['Mean Cell Volume', 'fL', 89, 90, 'normal', 'normal'],
            ['Red Cell Distribution Width', '%', 12.9, 12.4, 'normal', 'normal'],
            ['Alkaline Phosphatase', 'U/L', 68, 64, 'normal', 'normal'],
        ];

        foreach ($panels as $panel) {
            $when = $this->today->copy()->subDays($panel['days'])->setTime(9, 0);
            foreach ($markers as [$name, $unit, $before, $after, $fb, $fa]) {
                DB::table('biomarker_readings')->insert([
                    'profile_id' => $this->profile->id,
                    'marker' => $name,
                    'value' => $panel['shift'] > 0 ? $after : $before,
                    'unit' => $unit,
                    'taken_at' => $when,
                    'source' => 'lab',
                    'flag' => $panel['shift'] > 0 ? $fa : $fb,
                    'created_at' => $when,
                    'updated_at' => $when,
                ]);
            }
        }
    }

    // ── CGM ───────────────────────────────────────────────────────────────────────

    /** Two weeks of 15-minute CGM values with believable post-meal excursions. */
    private function seedGlucose(): void
    {
        $rows = [];
        for ($i = 13; $i >= 0; $i--) {
            $date = $this->today->copy()->subDays($i);
            for ($m = 0; $m < 24 * 60; $m += 15) {
                $at = $date->copy()->addMinutes($m);
                $hour = $at->hour + $at->minute / 60;

                $base = 88 + $this->r(-4, 4);
                // Bumps after the three meal times, decaying over ~2h.
                foreach ([[8.2, 42], [13.3, 55], [19.3, 48]] as [$peak, $amp]) {
                    $d = $hour - $peak;
                    if ($d >= 0 && $d < 2.5) {
                        $base += (int) round($amp * exp(-pow(($d - 0.55) / 0.62, 2)));
                    }
                }
                if ($hour >= 1 && $hour <= 5) {
                    $base -= 6;   // overnight dip
                }

                $rows[] = [
                    'profile_id' => $this->profile->id,
                    'taken_at' => $at,
                    'mg_dl' => max(62, min(185, $base)),
                    'trend' => 'flat',
                    'source' => 'nightscout',
                    'device' => 'Dexcom G7',
                    'created_at' => $at,
                    'updated_at' => $at,
                ];
            }
        }
        foreach (array_chunk($rows, 500) as $c) {
            DB::table('glucose_readings')->insert($c);
        }
    }

    // ── stack / goals / fasts ─────────────────────────────────────────────────────

    private function seedStackGoalsFasts(): void
    {
        foreach ([
            ['Creatine Monohydrate', 'supplement', 5, 'g', 'powder', ['morning']],
            ['Vitamin D3 + K2', 'supplement', 5000, 'IU', 'capsule', ['morning']],
            ['Omega-3 (EPA/DHA)', 'supplement', 2, 'g', 'softgel', ['morning']],
            ['Magnesium Glycinate', 'supplement', 400, 'mg', 'capsule', ['evening']],
            ['Whey Isolate', 'supplement', 30, 'g', 'powder', ['afternoon']],
        ] as [$name, $kind, $amt, $unit, $form, $times]) {
            $this->profile->stackItems()->create([
                'name' => $name, 'kind' => $kind, 'dose_amount' => $amt, 'dose_unit' => $unit,
                'form' => $form,
                'schedule' => ['frequency' => 'daily', 'times' => $times, 'with_food' => true],
                'active' => true,
                'started_on' => $this->today->copy()->subDays($this->days)->toDateString(),
            ]);
        }

        $this->profile->goals()->create([
            'metric' => 'body_fat_pct', 'direction' => 'down', 'start_value' => 21.5,
            'target_value' => 14.0, 'unit' => '%', 'status' => 'active',
            'target_date' => $this->today->copy()->addDays(75)->toDateString(),
        ]);
        $this->profile->goals()->create([
            'metric' => 'weight_kg', 'direction' => 'down', 'start_value' => 86.5,
            'target_value' => 80.0, 'unit' => 'kg', 'status' => 'active',
            'target_date' => $this->today->copy()->addDays(75)->toDateString(),
        ]);

        // A 16:8 pattern over the last fortnight.
        for ($i = 13; $i >= 1; $i--) {
            $d = $this->today->copy()->subDays($i);
            $this->profile->fasts()->create([
                'started_at' => $d->copy()->subDay()->setTime(20, 0),
                'ended_at' => $d->copy()->setTime(12, 0),
                'goal_hours' => 16,
            ]);
        }
    }

    // ── streaks ───────────────────────────────────────────────────────────────────

    private function seedStreaks(): void
    {
        foreach ([['logging', 34, 41], ['training', 12, 19], ['sleep', 9, 22]] as [$kind, $cur, $best]) {
            DB::table('streaks')->updateOrInsert(
                ['profile_id' => $this->profile->id, 'kind' => $kind],
                [
                    'current_count' => $cur, 'longest_count' => $best,
                    'last_active_on' => $this->today->toDateString(), 'freezes_available' => 2,
                    'created_at' => now(), 'updated_at' => now(),
                ]
            );
        }
    }

    // ── coach day chats ───────────────────────────────────────────────────────────

    /**
     * A month of day-scoped chats: real back-and-forth on some days, the coach's proactive
     * briefings and reactions on all of them — so the date sidebar has depth to scroll.
     */
    private function seedDayChats(): void
    {
        $exchanges = [
            ['How did I sleep?', "Solid night — **7h 42m**, with 1h 18m of deep and 1h 41m REM. Your deep landed early, which is exactly what you want: that's when growth hormone pulses and the day's training gets consolidated.\n\nHRV came in at **71 ms**, a touch above your 30-day baseline of 66. You're recovered — this is a day to push."],
            ['What should I train today?', "Push day. Your last one was Monday and your shoulders have had 72 hours.\n\nStart with **bench 4×8 at 82.5 kg** — you hit 80 for 8 clean last week with an RPE of 8, so that's the natural step. Then overhead press, incline dumbbell, and finish with cable fly and pushdowns.\n\nOne note: your sleep was short on Tuesday, so if bar speed drops off on set 3, stop at 3 working sets rather than grinding a fourth."],
            ['I had carne asada tacos for lunch', "Logged — **780 kcal, 41 g protein, 66 g carbs, 36 g fat**.\n\nThat puts you at 1,340 kcal and 89 g protein for the day. You've got room for a solid dinner; aim for 60 g of protein there to land on your 180 g target."],
            ['Why is my HRV lower this week?', "Three things line up in your data:\n\n1. **Sleep debt** — you're 3h 20m down over the last five nights.\n2. **Training density** — you lifted Mon/Wed/Fri *and* ran Tuesday and Saturday. That's five hard days in seven.\n3. **Late meals** — your last three dinners landed after 20:30, and your deep sleep on those nights averaged 48 min versus 71 on earlier-dinner nights.\n\nHRV is a lagging signal, so this is your body reporting last week's load. Take today easy, eat by 19:30, and it should rebound within two nights."],
            ['Am I on track for my goal?', "Yes, and the trend is better than the scale suggests.\n\nWeight is down **3.1 kg** over the window, but body fat is down **3.4 percentage points** — meaning you've held (slightly gained) lean mass while cutting. That's the recomp working.\n\nYour waist is down 4.5 cm against a 1.5 cm gain at the chest. That ratio is the one I'd frame and put on the wall."],
            ['My knee felt off during squats', "Noted, and I'll carry that forward.\n\nDrop to **60% for your next leg session** and keep the depth conservative. If it's the same spot on the medial side, that's usually a tracking issue rather than the joint itself — widen your stance slightly and drive the knees out.\n\nIf it's still there in two sessions, that's a physio conversation, not a coaching one. I'll ask you about it next leg day."],
            ['What do my labs say?', "Your second panel is a genuinely strong result.\n\n**ApoB 104 → 82** and **LDL 138 → 108** — both out of the flagged range. ApoB is the number that actually tracks cardiovascular risk, so that's the meaningful one.\n\n**hs-CRP 2.4 → 0.8** — systemic inflammation way down. **Vitamin D 24 → 46**, out of deficiency. **Insulin 11.2 → 6.8** with HbA1c at 5.2 — your metabolic health markers moved together, which is what you'd expect from the fat loss plus consistent training."],
        ];

        $briefings = [
            "**Morning briefing** — readiness **82**. HRV 71 ms (baseline 66), resting HR 51. You slept 7h 42m.\n\nGreen light for a hard session today. Protein target 180 g; you averaged 168 g last week, so front-load it at breakfast.",
            "**Morning briefing** — readiness **58**. HRV dipped to 54 ms and you only got 5h 48m.\n\nToday's a deload or a walk, not a PR attempt. Get to bed by 22:30 and we'll reassess tomorrow.",
            "**Evening nudge** — you're at 118 g protein with dinner still to come. A 60 g dinner puts you on target.\n\nAlso: 6,400 steps. A 20-minute walk after dinner would both close the ring and blunt your glucose curve.",
        ];

        $reactions = [
            "🏋️ **Push day sealed** — 62 min, avg HR 121, strain 8.4.\n\nBench 4×8 at 82.5 kg is a new working best. That's +2.5 kg in three weeks with RPE holding at 8 — textbook progression.",
            "🌙 **Last night's sleep is in** — 7h 42m, 1h 18m deep, 1h 41m REM, 94% signal coverage.\n\nYour bedtime has drifted 40 minutes earlier this week and deep sleep is up 12% with it. Keep it.",
            "🏃 **Run sealed** — 6.4 km in 34:12 (5:21/km), avg HR 152, relative effort 71.\n\nThat's your fastest average pace at this heart rate in the window — your aerobic base is genuinely improving.",
            "🍽️ **Meal logged** — 780 kcal, 41 g protein. You're at 1,340 kcal for the day.",
        ];

        $days = min(60, $this->days);
        for ($i = $days; $i >= 0; $i--) {
            $date = $this->today->copy()->subDays($i);
            $convo = Conversation::forDay($this->profile, $date);
            $rows = [];

            // A morning briefing most days.
            if ($this->chance(85)) {
                $rows[] = ['assistant', ChatMessage::KIND_BRIEFING, $briefings[$i % count($briefings)], 7, $this->r(5, 40)];
            }

            // One or two real exchanges most days — enough that scrolling back through the date
            // list shows genuine conversation, not just a wall of automated pushes.
            $howMany = $this->chance(45) ? 2 : ($this->chance(80) ? 1 : 0);
            for ($n = 0; $n < $howMany; $n++) {
                [$q, $a] = $exchanges[($i + $n * 3) % count($exchanges)];
                $h = $n === 0 ? $this->r(9, 13) : $this->r(15, 21);
                $min = $this->r(0, 55);
                $rows[] = ['user', null, $q, $h, $min];
                $rows[] = ['assistant', null, $a, $h, $min + 1];
            }

            // Event reactions.
            if ($this->chance(70)) {
                $rows[] = ['assistant', ChatMessage::KIND_REACTION, $reactions[$i % count($reactions)], $this->r(13, 21), $this->r(0, 55)];
            }

            foreach ($rows as [$role, $kind, $content, $h, $m]) {
                $at = $date->copy()->setTime($h, min(59, $m));
                DB::table('chat_messages')->insert([
                    'conversation_id' => $convo->id,
                    'role' => $role,
                    'kind' => $kind,
                    'content' => $content,
                    'created_at' => $at,
                    'updated_at' => $at,
                ]);
            }
        }
    }
}
