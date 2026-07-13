<?php

namespace App\Services\Assistant;

use App\Models\ActivitySession;
use App\Models\BiomarkerReading;
use App\Models\BodyMetric;
use App\Models\Exercise;
use App\Models\Meal;
use App\Models\Profile;
use App\Models\RecoveryLog;
use App\Models\SleepLog;
use App\Models\User;
use App\Models\WearableConnection;
use App\Models\Workout;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSet;
use App\Services\Coach\CoachTools;
use App\Support\BiologicalAge;
use App\Support\CircadianRhythm;
use App\Support\MetabolicHealth;
use App\Support\MovementBreaks;
use App\Support\Readiness;
use App\Support\SleepRegularity;
use App\Support\StepGoal;
use App\Support\TrainingLoad;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The Titan assistant/MCP operation layer: a profile-scoped, HTTP-free surface an external agent
 * (Claude via MCP) uses to run a user's whole Titan account -- read every metric, log sleep /
 * recovery / steps / weight / workouts, manage goals, and even pair the wearable. Every operation
 * is scoped to ONE user's profile (the token owner); nothing is cross-tenant.
 *
 * Mirrors the dispatcher shape of {@see CoachTools} (schemas() + dispatch()), and delegates the
 * overlapping read tools to a CoachTools instance so logic isn't duplicated.
 */
class AssistantTools
{
    protected Profile $profile;

    protected CoachTools $coach;

    public function __construct(protected User $user)
    {
        $this->profile = $user->ensureProfile();
        $this->coach = new CoachTools($this->profile);
    }

    /** Tool catalog: name → {description, args, write?}. The MCP turns these into MCP tools. */
    public function schemas(): array
    {
        return [
            // --- the one-call entry point ---
            ['name' => 'get_overview', 'description' => "START HERE. A complete snapshot of the user right now -- readiness, last night's sleep + regularity, recovery (HRV/resting HR), steps vs goal + movement, VO2max, metabolic health, today's nutrition, latest weight, flagged biomarkers and their primary goal. Call this first to understand someone before answering or acting.", 'args' => []],

            // --- reads ---
            ['name' => 'get_today', 'description' => "Today's snapshot: readiness, last sleep, resting HR, steps vs goal, metabolic health.", 'args' => []],
            ['name' => 'get_longevity', 'description' => 'The longevity panel: Sleep Regularity Index, circadian rest-activity rhythm, metabolic-health forecast, VO2max, resting HR & HRV -- each with the score and what it means. The "how well am I aging" view.', 'args' => []],
            ['name' => 'get_trends', 'description' => 'Time-series over recent days so you can spot patterns: HRV, resting HR, sleep hours, weight, steps. Great for "how has my X changed".', 'args' => ['days' => 'int -- default 30']],
            ['name' => 'get_nutrition', 'description' => "Today's calories & macros (and recent days) vs targets.", 'args' => ['days' => 'int -- default 7']],
            ['name' => 'get_physique', 'description' => 'Physique status: latest body composition, the dream-physique goal and progress.', 'args' => []],
            ['name' => 'search_knowledge', 'description' => "Search the user's personal knowledge base / notes (their 'brain') in natural language.", 'args' => ['query' => 'string']],
            ['name' => 'get_recovery', 'description' => 'Latest HRV (RMSSD), resting HR, readiness, baselines and the metabolic-health forecast.', 'args' => []],
            ['name' => 'get_sleep', 'description' => 'Recent nights, the 7-day average, the Sleep Regularity Index and the circadian rest-activity rhythm.', 'args' => ['days' => 'int -- nights back (default 14)']],
            ['name' => 'get_fitness', 'description' => 'VO2max estimate + trend, heart-rate recovery, and recent cardio sessions.', 'args' => []],
            ['name' => 'get_activity', 'description' => "Today's steps vs the personalized goal, movement breaks, and the 7-day step trend.", 'args' => []],
            ['name' => 'get_workouts', 'description' => 'Recent strength workouts with exercises, sets, reps and volume.', 'args' => ['days' => 'int -- default 14']],
            ['name' => 'get_biomarkers', 'description' => 'Most recent bloodwork / biomarker results with flags.', 'args' => []],
            ['name' => 'assess_chair_stand', 'description' => 'Score a guided 30-second chair-stand test (lower-body function / frailty screen) against the user\'s age/sex norms. Guide them: arms crossed, stand fully and sit as many times as they can in 30s, then pass the count.', 'args' => ['reps' => 'int -- full stands in 30 seconds']],
            ['name' => 'get_meals', 'description' => 'Recent nutrition: per-day calories/macros and recent meals.', 'args' => ['days' => 'int -- default 7']],
            ['name' => 'get_pantry', 'description' => "The food the user currently has on hand (their kitchen). Read this before suggesting meals so you only suggest things they can actually make.", 'args' => []],
            ['name' => 'get_profile', 'description' => 'Profile basics: name, age, sex, height, latest weight, primary goal.', 'args' => []],
            ['name' => 'get_devices', 'description' => 'Paired wearables and their last sync time.', 'args' => []],
            ['name' => 'get_cycle', 'description' => "Menstrual-cycle status: cycle day, phase (menstrual/follicular/fertile/ovulation/luteal), predicted next period + ovulation, fertile window, conception likelihood, regularity, and how the cycle phase relates to resting-HR/HRV recovery. Awareness only -- never contraception or diagnosis.", 'args' => []],
            // --- writes ---
            ['name' => 'log_sleep', 'description' => 'Log a night of sleep.', 'args' => ['date' => 'YYYY-MM-DD', 'hours' => 'number', 'bedtime' => 'HH:MM (optional)', 'wake_time' => 'HH:MM (optional)', 'quality' => '1-100 (optional)'], 'write' => true],
            ['name' => 'log_steps', 'description' => "Set a day's step count.", 'args' => ['steps' => 'int', 'date' => 'YYYY-MM-DD (optional, default today)'], 'write' => true],
            ['name' => 'log_recovery', 'description' => 'Log resting HR / HRV / subjective recovery for a day.', 'args' => ['date' => 'YYYY-MM-DD (optional)', 'resting_hr' => 'int (optional)', 'hrv_ms' => 'int (optional)', 'stress' => '1-10 (optional)', 'mood' => '1-10 (optional)', 'energy' => '1-10 (optional)', 'soreness' => '1-10 (optional)'], 'write' => true],
            ['name' => 'log_weight', 'description' => 'Log a body-weight (kg) measurement.', 'args' => ['weight_kg' => 'number', 'date' => 'YYYY-MM-DD (optional)', 'body_fat_pct' => 'number (optional)'], 'write' => true],
            ['name' => 'log_workout', 'description' => 'Log a strength workout with its exercises and sets.', 'args' => ['name' => 'string (optional)', 'performed_at' => 'ISO datetime (optional)', 'exercises' => '[{name, sets:[{reps, weight_kg, rpe?}]}]'], 'write' => true],
            ['name' => 'set_goal', 'description' => "Set the profile's primary goal.", 'args' => ['goal' => 'string'], 'write' => true],
            ['name' => 'log_meal', 'description' => 'Log a meal with its macros.', 'args' => ['name' => 'string', 'calories' => 'int', 'protein_g' => 'number (optional)', 'carbs_g' => 'number (optional)', 'fat_g' => 'number (optional)', 'eaten_at' => 'ISO datetime (optional, default now)'], 'write' => true],
            ['name' => 'update_pantry', 'description' => "Update the user's kitchen inventory when they say what they have or bought (e.g. \"I bought ground beef, eggs, tuna\"). mode=add appends, replace overwrites, remove deletes. Then suggest meals from what they have.", 'args' => ['items' => 'string (comma-separated) or array', 'mode' => 'add|replace|remove -- default add'], 'write' => true],
            ['name' => 'log_cardio', 'description' => 'Log a cardio session (run/walk/ride/etc).', 'args' => ['type' => 'run|walk|cycle|other', 'duration_min' => 'int', 'distance_km' => 'number (optional)', 'avg_hr' => 'int (optional)', 'calories_kcal' => 'int (optional)', 'started_at' => 'ISO datetime (optional)'], 'write' => true],
            ['name' => 'log_biomarker', 'description' => 'Log a bloodwork / biomarker result (the abnormal-range flag is computed automatically).', 'args' => ['marker' => 'string e.g. ldl, hba1c, vitamin_d', 'value' => 'number', 'unit' => 'string (optional)', 'taken_at' => 'YYYY-MM-DD (optional)'], 'write' => true],
            ['name' => 'log_period', 'description' => "Log a menstrual period event. event='start' = day 1 of a new period (anchors all cycle math); event='end' = last day of bleeding.", 'args' => ['event' => 'start|end', 'date' => "YYYY-MM-DD / today / yesterday (optional)"], 'write' => true],
            ['name' => 'log_cycle', 'description' => 'Log a cycle day: flow, symptoms, mood/energy, basal body temperature.', 'args' => ['flow' => 'none|spotting|light|medium|heavy (optional)', 'symptoms' => '[cramps, headache, bloating, fatigue, mood_swings, tender_breasts, cravings, …] (optional)', 'mood' => '1-5 (optional)', 'energy' => '1-5 (optional)', 'bbt_c' => 'number °C (optional)', 'date' => 'YYYY-MM-DD (optional)', 'notes' => 'string (optional)'], 'write' => true],
            ['name' => 'update_profile', 'description' => 'Update profile basics.', 'args' => ['birthdate' => 'YYYY-MM-DD (optional)', 'sex' => 'M|F (optional)', 'height_cm' => 'number (optional)', 'primary_goal' => 'string (optional)'], 'write' => true],
            ['name' => 'save_knowledge', 'description' => "Save a note to the user's brain. Pin to inject it into every future coach conversation (use sparingly).", 'args' => ['title' => 'string', 'content' => 'markdown', 'pinned' => 'bool (optional)'], 'write' => true],
            ['name' => 'pair_device', 'description' => 'Pair a Titan wearable and return its device id + one-time secret (show the secret to the user once).', 'args' => ['name' => 'string (optional)'], 'write' => true],
            ['name' => 'unpair_device', 'description' => 'Revoke a paired wearable so it can no longer sync.', 'args' => ['device_id' => 'string'], 'write' => true],
        ];
    }

    /** Run one tool. Returns a JSON-serialisable value (array/string) or an ['error' => …]. */
    public function dispatch(string $name, array $args): mixed
    {
        return match ($name) {
            'get_overview' => $this->getOverview(),
            'get_today' => $this->getToday(),
            'get_longevity' => $this->getLongevity(),
            'get_trends' => $this->getTrends((int) ($args['days'] ?? 30)),
            'get_nutrition' => $this->getNutrition((int) ($args['days'] ?? 7)),
            'get_physique' => $this->coach->dispatch('physique_status', []),
            'get_cycle' => $this->coach->dispatch('cycle_status', []),
            'log_period' => $this->coach->dispatch('log_period', $args),
            'log_cycle' => $this->coach->dispatch('log_cycle', $args),
            'search_knowledge' => $this->coach->dispatch('search_knowledge', $args),
            'get_recovery' => $this->getRecovery(),
            'get_sleep' => $this->getSleep((int) ($args['days'] ?? 14)),
            'get_fitness' => $this->getFitness(),
            'assess_chair_stand' => $this->assessChairStand($args),
            'get_activity' => $this->getActivity(),
            'get_workouts' => $this->coach->dispatch('recent_workouts', $args),
            'get_biomarkers' => $this->coach->dispatch('recent_biomarkers', $args),
            'get_meals' => $this->coach->dispatch('recent_meals', $args),
            'get_pantry' => $this->getPantry(),
            'get_profile' => $this->getProfile(),
            'get_devices' => $this->getDevices(),
            'log_sleep' => $this->logSleep($args),
            'log_steps' => $this->logSteps($args),
            'log_recovery' => $this->logRecovery($args),
            'log_weight' => $this->logWeight($args),
            'log_workout' => $this->logWorkout($args),
            'log_meal' => $this->logMeal($args),
            'update_pantry' => $this->updatePantry($args),
            'log_cardio' => $this->logCardio($args),
            'log_biomarker' => $this->logBiomarker($args),
            'update_profile' => $this->updateProfile($args),
            'save_knowledge' => $this->coach->dispatch('save_knowledge', $args),
            'set_goal' => $this->setGoal($args),
            'pair_device' => $this->pairDevice($args),
            'unpair_device' => $this->unpairDevice($args),
            default => ['error' => "Unknown tool: {$name}"],
        };
    }

    // ---------- reads ----------

    private function getToday(): array
    {
        $rec = $this->profile->recoveryLogs()->orderByDesc('logged_at')->orderByDesc('id')->first();
        $sleep = $this->profile->sleepLogs()->nights()->final()->orderByDesc('slept_at')->orderByDesc('id')->first();
        $steps = (int) ($this->profile->dailyActivity()->whereDate('date', Carbon::today())->value('steps') ?? 0);
        $goal = StepGoal::assess($steps, StepGoal::targetFor($this->profile));

        return [
            'readiness' => Readiness::compute($this->profile, $rec?->logged_at)['score'] ?? null,
            'resting_hr' => $rec?->resting_hr,
            'hrv_ms' => $rec?->hrv_ms,
            'resp_rate' => $rec?->resp_rate,
            'last_sleep_hours' => $sleep ? round($sleep->duration_min / 60, 1) : null,
            'steps' => $steps,
            'step_goal' => $goal['target'],
            'step_progress_pct' => $goal['pct'],
            'metabolic_health' => MetabolicHealth::assess($this->profile)['score'] ?? null,
            // The daily loop: today's focus + Strain (vs recovery-driven target) + Sleep coaching.
            'focus' => \App\Support\DailyFocus::compute($this->profile),
            'strain' => \App\Support\Strain::assess($this->profile),
            'sleep_coach' => \App\Support\SleepCoach::assess($this->profile),
            'next_meal' => \App\Support\MealCoach::assess($this->profile),
        ];
    }

    private function getRecovery(): array
    {
        $rec = $this->profile->recoveryLogs()->orderByDesc('logged_at')->orderByDesc('id')->first();

        return [
            'latest' => $rec ? ['date' => optional($rec->logged_at)->toDateString(), 'hrv_ms' => $rec->hrv_ms, 'resting_hr' => $rec->resting_hr, 'resp_rate' => $rec->resp_rate, 'stress' => $rec->stress, 'mood' => $rec->mood, 'energy' => $rec->energy] : null,
            'readiness' => Readiness::compute($this->profile, $rec?->logged_at)['score'] ?? null,
            'metabolic_health' => MetabolicHealth::assess($this->profile),
        ];
    }

    private function getSleep(int $days): array
    {
        $days = max(1, min(60, $days));
        $logs = $this->profile->sleepLogs()->nights()->final()->where('slept_at', '>=', Carbon::today()->subDays($days - 1))->orderByDesc('slept_at')->get();
        $month = $this->profile->sleepLogs()->nights()->final()->where('slept_at', '>=', Carbon::today()->subDays(27))->get();
        $activity = $this->profile->dailyActivity()->where('date', '>=', Carbon::today()->subDays(13))->get();

        return [
            'nights' => $logs->map(fn ($s) => ['date' => $s->slept_at->toDateString(), 'hours' => round($s->duration_min / 60, 1), 'quality' => $s->quality, 'bedtime' => $s->bedtime, 'wake_time' => $s->wake_time])->values(),
            'sleep_regularity' => SleepRegularity::compute($month),
            'circadian_rhythm' => CircadianRhythm::compute($activity),
        ];
    }

    private function assessChairStand(array $a): array
    {
        $reps = isset($a['reps']) ? (int) $a['reps'] : null;
        if ($reps === null || $reps < 0 || $reps > 60) {
            return ['error' => 'provide reps = full stands completed in 30 seconds (0-60)'];
        }
        $score = \App\Support\ChairStand::scoreFor($this->profile, $reps);
        if ($score === null) {
            return ['error' => 'set the profile birthdate first so the result can be scored against age norms'];
        }

        return $score + ['protocol' => '30-second chair-stand: arms crossed, stand fully and sit, max reps in 30s. Lower-body function / frailty screen -- a wellness estimate, not a diagnosis.'];
    }

    private function getFitness(): array
    {
        $sessions = $this->profile->activitySessions()->orderByDesc('started_at')->limit(20)->get();
        $latestVo2 = $sessions->firstWhere(fn ($s) => $s->vo2max !== null);

        // ACWR needs ~6 weeks of history → its own query (not capped at the 20 most recent).
        $loadSessions = $this->profile->activitySessions()
            ->where('started_at', '>=', Carbon::now()->subDays(42))->get();

        return [
            'vo2max' => $latestVo2?->vo2max,
            'fitness_level' => $latestVo2?->fitness_level,
            'latest_hrr_bpm' => $sessions->firstWhere(fn ($s) => $s->hrr_bpm !== null)?->hrr_bpm,
            'training_load' => TrainingLoad::assess($loadSessions),
            'recent_sessions' => $sessions->take(8)->map(fn ($s) => [
                'date' => optional($s->started_at)->toDateString(), 'type' => $s->activity_type,
                'duration_min' => $s->duration_min, 'distance_km' => $s->distance_km, 'avg_hr' => $s->avg_hr,
                'trimp' => $s->trimp, 'vo2max' => $s->vo2max,
            ])->values(),
        ];
    }

    private function getActivity(): array
    {
        $today = $this->profile->dailyActivity()->whereDate('date', Carbon::today())->first();
        $steps = (int) ($today->steps ?? 0);
        $week = $this->profile->dailyActivity()->where('date', '>=', Carbon::today()->subDays(6))->get();

        return [
            'steps_today' => $steps,
            'floors_today' => $today?->floors,
            'goal' => StepGoal::assess($steps, StepGoal::targetFor($this->profile)),
            'movement_breaks' => MovementBreaks::assess($today?->hourly),
            'week_avg_steps' => $week->count() ? (int) round($week->avg('steps')) : null,
        ];
    }

    private function getProfile(): array
    {
        $p = $this->profile;
        $weight = $p->bodyMetrics()->latest('taken_at')->value('weight_kg');

        return [
            'name' => $p->display_name ?? $this->user->name,
            'age' => $p->birthdate ? Carbon::parse($p->birthdate)->age : null,
            'sex' => $p->sex,
            'height_cm' => $p->height_cm,
            'weight_kg' => $weight ? (float) $weight : null,
            'primary_goal' => $p->primary_goal,
        ];
    }

    private function getDevices(): array
    {
        return $this->profile->wearableConnections()->get()->map(fn (WearableConnection $c) => [
            'device_id' => $c->device_id, 'source' => $c->source, 'status' => $c->status,
            'last_sync_at' => optional($c->last_sync_at)->toDateTimeString(),
        ])->values()->all();
    }

    // ---------- writes ----------

    private function logSleep(array $a): array
    {
        $hours = (float) ($a['hours'] ?? 0);
        if ($hours <= 0) {
            return ['error' => 'hours is required and must be > 0'];
        }
        $log = $this->profile->sleepLogs()->updateOrCreate(
            ['slept_at' => $this->date($a['date'] ?? null)],
            array_filter([
                'duration_min' => (int) round($hours * 60),
                'bedtime' => $a['bedtime'] ?? null,
                'wake_time' => $a['wake_time'] ?? null,
                'quality' => isset($a['quality']) ? (int) $a['quality'] : null,
                'updated_via' => 'assistant',
            ], fn ($v) => $v !== null),
        );

        return ['ok' => true, 'slept_at' => $log->slept_at->toDateString(), 'hours' => round($log->duration_min / 60, 1)];
    }

    private function logSteps(array $a): array
    {
        $steps = (int) ($a['steps'] ?? -1);
        if ($steps < 0) {
            return ['error' => 'steps is required'];
        }
        $this->profile->dailyActivity()->updateOrCreate(
            ['date' => $this->date($a['date'] ?? null)],
            ['steps' => $steps, 'source' => 'assistant', 'updated_via' => 'assistant'],
        );

        return ['ok' => true, 'steps' => $steps];
    }

    private function logRecovery(array $a): array
    {
        $fields = array_filter([
            'resting_hr' => isset($a['resting_hr']) ? (int) $a['resting_hr'] : null,
            'hrv_ms' => isset($a['hrv_ms']) ? (int) $a['hrv_ms'] : null,
            'stress' => isset($a['stress']) ? (int) $a['stress'] : null,
            'mood' => isset($a['mood']) ? (int) $a['mood'] : null,
            'energy' => isset($a['energy']) ? (int) $a['energy'] : null,
            'soreness' => isset($a['soreness']) ? (int) $a['soreness'] : null,
        ], fn ($v) => $v !== null);
        if ($fields === []) {
            return ['error' => 'provide at least one of resting_hr, hrv_ms, stress, mood, energy, soreness'];
        }
        $log = $this->profile->recoveryLogs()->updateOrCreate(
            ['logged_at' => $this->date($a['date'] ?? null)],
            $fields + ['updated_via' => 'assistant'],
        );

        return ['ok' => true, 'logged_at' => $log->logged_at->toDateString()];
    }

    private function logWeight(array $a): array
    {
        $w = (float) ($a['weight_kg'] ?? 0);
        if ($w <= 0) {
            return ['error' => 'weight_kg is required'];
        }
        BodyMetric::create(array_filter([
            'profile_id' => $this->profile->id,
            'taken_at' => $this->date($a['date'] ?? null),
            'weight_kg' => $w,
            'body_fat_pct' => $a['body_fat_pct'] ?? null,
        ], fn ($v) => $v !== null));

        return ['ok' => true, 'weight_kg' => $w];
    }

    private function logWorkout(array $a): array
    {
        $exercises = $a['exercises'] ?? [];
        if (! is_array($exercises) || $exercises === []) {
            return ['error' => 'exercises is required: [{name, sets:[{reps, weight_kg, rpe?}]}]'];
        }
        $performedAt = isset($a['performed_at']) ? Carbon::parse($a['performed_at']) : now();
        $workout = $this->profile->workouts()->create([
            'name' => $a['name'] ?? 'Workout',
            'performed_at' => $performedAt,
            // Sets-belong: link to the ActivitySession this was performed in at LOG time (covers logging a
            // set into an already-sealed/open session); the seal also backfills this by containment. Match a
            // session whose span contains performed_at, most recent first. Null when no session is open yet —
            // the seal will attach it later.
            'activity_session_id' => $this->sessionIdFor($performedAt),
            'updated_via' => 'assistant',
        ]);
        $order = 0;
        $sets = 0;
        foreach ($exercises as $ex) {
            $name = trim((string) ($ex['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $exercise = Exercise::firstOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => Str::title($name), 'muscle_group' => 'full body', 'category' => 'compound'],
            );
            $we = WorkoutExercise::create(['workout_id' => $workout->id, 'exercise_id' => $exercise->id, 'order' => $order++]);
            foreach (($ex['sets'] ?? []) as $i => $set) {
                WorkoutSet::create([
                    'workout_exercise_id' => $we->id,
                    'set_number' => $i + 1,
                    'reps' => (int) ($set['reps'] ?? 0),
                    'weight_kg' => (float) ($set['weight_kg'] ?? 0),
                    'rpe' => isset($set['rpe']) ? (float) $set['rpe'] : null,
                ]);
                $sets++;
            }
        }

        return ['ok' => true, 'workout_id' => $workout->id, 'exercises' => $workout->exercises()->count(), 'sets' => $sets];
    }

    /** The id of the profile's ActivitySession whose span contains $when (± a 5-min clock-skew margin), most
     *  recent first — where a logged workout belongs. Null when none is open/nearby (the seal links later).
     *  An OPEN (never-ended) session only reaches 4h past its start — mirrors the seal's linkWorkoutsToSession
     *  and strengthDetail windows — so an abandoned "start a run" placeholder can't sticky-claim a later lift. */
    private function sessionIdFor(Carbon $when): ?int
    {
        $margin = 300;
        $id = $this->profile->activitySessions()
            ->where('started_at', '<=', $when->copy()->addSeconds($margin))
            ->where(function ($q) use ($when, $margin) {
                $q->where(fn ($q2) => $q2->whereNull('ended_at')
                    ->where('started_at', '>=', $when->copy()->subSeconds($margin)->subHours(4)))
                    ->orWhere('ended_at', '>=', $when->copy()->subSeconds($margin));
            })
            ->orderByDesc('started_at')
            ->value('id');

        return $id !== null ? (int) $id : null;
    }

    private function setGoal(array $a): array
    {
        $goal = trim((string) ($a['goal'] ?? ''));
        if ($goal === '') {
            return ['error' => 'goal is required'];
        }
        $this->profile->forceFill(['primary_goal' => $goal])->save();

        return ['ok' => true, 'primary_goal' => $goal];
    }

    private function pairDevice(array $a): array
    {
        $secret = bin2hex(random_bytes(32));
        $device = WearableConnection::create([
            'profile_id' => $this->profile->id,
            'provider' => 'titan_band',
            'source' => 'titan_band',
            'device_id' => 'tb_'.Str::lower(Str::random(12)),
            'device_token_hash' => hash('sha256', $secret),
            'timezone' => config('app.timezone', 'UTC'),
            'status' => 'pending',
            'scopes' => ['ibi', 'accel', 'workout', 'sleep', 'recovery', 'activity'],
        ]);

        return [
            'ok' => true,
            'device_id' => $device->device_id,
            'secret' => $secret,   // shown ONCE -- the user enters this on the watch / bridge
            'note' => 'Secret shown once. Walk the user through setup_steps; the device_id + secret go into the live bridge.',
            'setup_steps' => [
                '1. Install the watch app (once, from a computer): open the Espruino Web IDE (espruino.com/ide) in desktop Chrome, connect the Bangle.js 2 over Bluetooth, paste the Titan firmware (Devices → Set up → Titan firmware, or /devices/firmware), and Send to Espruino. Phone browsers cannot flash BLE devices.',
                '2. Pairing is done (this call) -- give the user the device_id + secret above.',
                '3. Open the live bridge at /devices/bridge and tap Connect. On iPhone, open that URL in the Bluefy app (free Web-Bluetooth browser) since Safari has no Bluetooth; on a computer/Android use Chrome. Paste the credentials if not auto-filled.',
                '4. Wear it overnight -- it logs to its own memory. In the morning, open the bridge and Connect; the whole night syncs in seconds → readiness, sleep, recovery.',
            ],
        ];
    }

    /** The streamlined first call -- everything an agent needs to understand the user at a glance. */
    private function getOverview(): array
    {
        $p = $this->profile;
        $rec = $p->recoveryLogs()->orderByDesc('logged_at')->orderByDesc('id')->first();
        $sleep = $p->sleepLogs()->nights()->final()->orderByDesc('slept_at')->orderByDesc('id')->first();
        $month = $p->sleepLogs()->nights()->final()->where('slept_at', '>=', Carbon::today()->subDays(27))->get();
        $steps = (int) ($p->dailyActivity()->whereDate('date', Carbon::today())->value('steps') ?? 0);
        $stepGoal = StepGoal::assess($steps, StepGoal::targetFor($p));
        $vo2 = $p->activitySessions()->whereNotNull('vo2max')->orderByDesc('started_at')->value('vo2max');
        $load = TrainingLoad::assess($p->activitySessions()->where('started_at', '>=', Carbon::now()->subDays(42))->get());
        $weight = $p->bodyMetrics()->latest('taken_at')->value('weight_kg');
        $todayMeals = $p->meals()->whereDate('eaten_at', Carbon::today())->get();
        $flagged = $p->biomarkerReadings()->whereNotNull('flag')->where('flag', '!=', 'normal')
            ->orderByDesc('taken_at')->limit(5)->get();

        $readiness = Readiness::compute($p, $rec?->logged_at);
        $sri = SleepRegularity::compute($month);
        $metabolic = MetabolicHealth::assess($p);
        $bioAge = BiologicalAge::assess($p);

        return [
            'name' => $p->display_name ?? $this->user->name,
            'primary_goal' => $p->primary_goal,
            'readiness' => ['score' => $readiness['score'] ?? null, 'label' => $readiness['label'] ?? null],
            'sleep' => $sleep ? ['hours' => round($sleep->duration_min / 60, 1), 'quality' => $sleep->quality, 'regularity_sri' => $sri['sri'] ?? null] : null,
            'recovery' => ['resting_hr' => $rec?->resting_hr, 'hrv_ms' => $rec?->hrv_ms],
            'activity' => ['steps' => $steps, 'goal' => $stepGoal['target'], 'progress_pct' => $stepGoal['pct'], 'movement' => MovementBreaks::assess($p->dailyActivity()->whereDate('date', Carbon::today())->value('hourly'))],
            'fitness' => ['vo2max' => $vo2 ? (float) $vo2 : null, 'training_load' => $load ? ['acwr' => $load['acwr'], 'band' => $load['band']] : null],
            'metabolic_health' => $metabolic['score'] ?? null,
            'biological_age' => $bioAge ? ['estimate' => $bioAge['biological_age'], 'vs_actual' => $bioAge['delta'], 'confidence' => $bioAge['confidence']] : null,
            'nutrition_today' => $todayMeals->count() ? ['calories' => (int) $todayMeals->sum('calories'), 'protein_g' => round((float) $todayMeals->sum('protein_g'), 1)] : null,
            'weight_kg' => $weight ? (float) $weight : null,
            'flagged_biomarkers' => $flagged->map(fn ($b) => ['marker' => $b->marker, 'value' => (float) $b->value, 'unit' => $b->unit, 'flag' => $b->flag])->values(),
        ];
    }

    /** The longevity panel in one call. */
    private function getLongevity(): array
    {
        $p = $this->profile;
        $rec = $p->recoveryLogs()->orderByDesc('logged_at')->orderByDesc('id')->first();

        return [
            'biological_age' => BiologicalAge::assess($p),
            'sleep_regularity' => SleepRegularity::compute($p->sleepLogs()->nights()->final()->where('slept_at', '>=', Carbon::today()->subDays(27))->get()),
            'circadian_rhythm' => CircadianRhythm::compute($p->dailyActivity()->where('date', '>=', Carbon::today()->subDays(13))->get()),
            'metabolic_health' => MetabolicHealth::assess($p),
            'vo2max' => $p->activitySessions()->whereNotNull('vo2max')->orderByDesc('started_at')->value('vo2max'),
            'resting_hr' => $rec?->resting_hr,
            'hrv_ms' => $rec?->hrv_ms,
            'note' => 'Each is independently linked to longevity in large cohorts. Track your own trend over weeks, not single readings.',
        ];
    }

    /** Daily time-series for pattern-spotting. */
    private function getTrends(int $days): array
    {
        $days = max(2, min(180, $days));
        $since = Carbon::today()->subDays($days - 1);

        $recovery = $this->profile->recoveryLogs()->where('logged_at', '>=', $since)->orderBy('logged_at')->get()
            ->map(fn (RecoveryLog $r) => ['date' => $r->logged_at->toDateString(), 'hrv_ms' => $r->hrv_ms, 'resting_hr' => $r->resting_hr])->values();
        $sleep = $this->profile->sleepLogs()->nights()->final()->where('slept_at', '>=', $since)->orderBy('slept_at')->get()
            ->map(fn (SleepLog $s) => ['date' => $s->slept_at->toDateString(), 'hours' => round($s->duration_min / 60, 1)])->values();
        $weight = $this->profile->bodyMetrics()->where('taken_at', '>=', $since)->orderBy('taken_at')->get()
            ->map(fn ($b) => ['date' => optional($b->taken_at)->toDateString(), 'weight_kg' => (float) $b->weight_kg])->values();
        $steps = $this->profile->dailyActivity()->where('date', '>=', $since)->orderBy('date')->get()
            ->map(fn ($d) => ['date' => $d->date->toDateString(), 'steps' => (int) $d->steps])->values();

        return ['days' => $days, 'recovery' => $recovery, 'sleep' => $sleep, 'weight' => $weight, 'steps' => $steps];
    }

    private function getPantry(): array
    {
        $items = \App\Support\Pantry::get($this->profile);

        return [
            'items' => $items,
            'count' => count($items),
            'updated_at' => optional(\App\Support\Pantry::updatedAt($this->profile))->toIso8601String(),
            'note' => $items === [] ? 'Empty -- ask the user what they have, then update_pantry.' : 'Suggest meals the user can make from these.',
        ];
    }

    private function updatePantry(array $a): array
    {
        $items = $a['items'] ?? null;
        if (! is_array($items) && ! is_string($items)) {
            return ['error' => 'provide items as a comma-separated string or an array'];
        }
        $mode = strtolower((string) ($a['mode'] ?? 'add'));
        $p = $this->profile;
        $result = match ($mode) {
            'replace' => \App\Support\Pantry::set($p, \App\Support\Pantry::parse($items)),
            'remove' => \App\Support\Pantry::remove($p, $items),
            default => \App\Support\Pantry::add($p, $items),
        };

        return ['ok' => true, 'mode' => $mode, 'items' => $result, 'count' => count($result)];
    }

    private function getNutrition(int $days): array
    {
        $days = max(1, min(31, $days));
        $meals = $this->profile->meals()->where('eaten_at', '>=', Carbon::today()->subDays($days - 1)->startOfDay())->get();
        $byDay = $meals->groupBy(fn (Meal $m) => optional($m->eaten_at)->toDateString())->map(fn ($g, $d) => [
            'date' => $d, 'meals' => $g->count(), 'calories' => (int) $g->sum('calories'),
            'protein_g' => round((float) $g->sum('protein_g'), 1), 'carbs_g' => round((float) $g->sum('carbs_g'), 1), 'fat_g' => round((float) $g->sum('fat_g'), 1),
        ])->values();
        $settings = $this->profile->settings ?? [];
        $targets = (array) ($settings['nutrition_targets'] ?? ['calories' => 2800, 'protein_g' => 200, 'carbs_g' => 280, 'fat_g' => 80]);

        return [
            'window_days' => $days, 'targets' => $targets, 'by_day' => $byDay,
            // The meal-timing coach: when the next meal is due + what it should carry. Use this to
            // nudge the user to eat (they overwork and forget) -- protein-forward, before hunger hits.
            'meal_timing' => \App\Support\MealCoach::assess($this->profile),
            // What's in their kitchen -- suggest meals from these, not things they'd have to buy.
            'pantry' => \App\Support\Pantry::get($this->profile),
        ];
    }

    private function logMeal(array $a): array
    {
        $name = trim((string) ($a['name'] ?? ''));
        if ($name === '') {
            return ['error' => 'name is required'];
        }
        $eatenAt = isset($a['eaten_at']) ? rescue(fn () => Carbon::parse($a['eaten_at']), now(), false) : now();
        $meal = $this->profile->meals()->create([
            'name' => $name,
            // Clamp to now — "I ate X" can never be in the future, and a future eaten_at
            // silently drops the meal out of today's macros.
            'eaten_at' => $eatenAt->isFuture() ? now() : $eatenAt,
            'calories' => (int) round((float) ($a['calories'] ?? 0)),
            'protein_g' => round((float) ($a['protein_g'] ?? 0), 1),
            'carbs_g' => round((float) ($a['carbs_g'] ?? 0), 1),
            'fat_g' => round((float) ($a['fat_g'] ?? 0), 1),
            'source' => 'assistant',
        ]);

        return ['ok' => true, 'meal_id' => $meal->id, 'name' => $meal->name, 'calories' => $meal->calories];
    }

    private function logCardio(array $a): array
    {
        $dur = (int) ($a['duration_min'] ?? 0);
        if ($dur <= 0) {
            return ['error' => 'duration_min is required'];
        }
        $start = isset($a['started_at']) ? Carbon::parse($a['started_at']) : now()->subMinutes($dur);
        $session = $this->profile->activitySessions()->updateOrCreate(
            ['started_at' => $start],
            array_filter([
                'source' => 'assistant',
                'ended_at' => $start->copy()->addMinutes($dur),
                'duration_min' => $dur,
                'activity_type' => $a['type'] ?? 'other',
                'distance_km' => $a['distance_km'] ?? null,
                'avg_hr' => isset($a['avg_hr']) ? (int) $a['avg_hr'] : null,
                'calories_kcal' => isset($a['calories_kcal']) ? (int) $a['calories_kcal'] : null,
                'updated_via' => 'assistant',
            ], fn ($v) => $v !== null),
        );

        return ['ok' => true, 'activity_session_id' => $session->id, 'type' => $session->activity_type, 'duration_min' => $dur];
    }

    private function logBiomarker(array $a): array
    {
        $marker = trim((string) ($a['marker'] ?? ''));
        if ($marker === '' || ! isset($a['value'])) {
            return ['error' => 'marker and value are required'];
        }
        $r = $this->profile->biomarkerReadings()->create([
            'marker' => $marker,
            'value' => (float) $a['value'],
            'unit' => $a['unit'] ?? null,
            'taken_at' => $this->date($a['taken_at'] ?? null),
            'source' => 'assistant',
        ]);

        return ['ok' => true, 'marker' => $r->marker, 'value' => (float) $r->value, 'flag' => $r->flag];
    }

    private function updateProfile(array $a): array
    {
        $fields = array_filter([
            'birthdate' => isset($a['birthdate']) ? $this->date($a['birthdate']) : null,
            'sex' => isset($a['sex']) ? strtoupper(substr((string) $a['sex'], 0, 1)) : null,
            'height_cm' => isset($a['height_cm']) ? (float) $a['height_cm'] : null,
            'primary_goal' => $a['primary_goal'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
        if ($fields === []) {
            return ['error' => 'provide at least one of birthdate, sex, height_cm, primary_goal'];
        }
        $this->profile->forceFill($fields)->save();

        return ['ok' => true, 'updated' => array_keys($fields)];
    }

    private function unpairDevice(array $a): array
    {
        $id = (string) ($a['device_id'] ?? '');
        $device = $this->profile->wearableConnections()->where('device_id', $id)->first();
        if (! $device) {
            return ['error' => 'device not found'];
        }
        $device->forceFill(['device_token_hash' => null, 'status' => 'revoked'])->save();

        return ['ok' => true, 'device_id' => $id, 'status' => 'revoked'];
    }

    private function date(?string $value): string
    {
        try {
            return $value ? Carbon::parse($value)->toDateString() : Carbon::today()->toDateString();
        } catch (\Throwable) {
            return Carbon::today()->toDateString();
        }
    }
}
