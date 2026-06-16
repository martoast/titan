<?php

namespace App\Services\Assistant;

use App\Models\BodyMetric;
use App\Models\Exercise;
use App\Models\Profile;
use App\Models\User;
use App\Models\WearableConnection;
use App\Models\Workout;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSet;
use App\Services\Coach\CoachTools;
use App\Support\CircadianRhythm;
use App\Support\MetabolicHealth;
use App\Support\MovementBreaks;
use App\Support\Readiness;
use App\Support\SleepRegularity;
use App\Support\StepGoal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The Titan assistant/MCP operation layer: a profile-scoped, HTTP-free surface an external agent
 * (Claude via MCP) uses to run a user's whole Titan account — read every metric, log sleep /
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
            // --- reads ---
            ['name' => 'get_today', 'description' => "Today's snapshot: readiness, last sleep, resting HR, steps vs goal, metabolic health.", 'args' => []],
            ['name' => 'get_recovery', 'description' => 'Latest HRV (RMSSD), resting HR, readiness, baselines and the metabolic-health forecast.', 'args' => []],
            ['name' => 'get_sleep', 'description' => 'Recent nights, the 7-day average, the Sleep Regularity Index and the circadian rest-activity rhythm.', 'args' => ['days' => 'int — nights back (default 14)']],
            ['name' => 'get_fitness', 'description' => 'VO2max estimate + trend, heart-rate recovery, and recent cardio sessions.', 'args' => []],
            ['name' => 'get_activity', 'description' => "Today's steps vs the personalized goal, movement breaks, and the 7-day step trend.", 'args' => []],
            ['name' => 'get_workouts', 'description' => 'Recent strength workouts with exercises, sets, reps and volume.', 'args' => ['days' => 'int — default 14']],
            ['name' => 'get_biomarkers', 'description' => 'Most recent bloodwork / biomarker results with flags.', 'args' => []],
            ['name' => 'get_meals', 'description' => 'Recent nutrition: per-day calories/macros and recent meals.', 'args' => ['days' => 'int — default 7']],
            ['name' => 'get_profile', 'description' => 'Profile basics: name, age, sex, height, latest weight, primary goal.', 'args' => []],
            ['name' => 'get_devices', 'description' => 'Paired wearables and their last sync time.', 'args' => []],
            // --- writes ---
            ['name' => 'log_sleep', 'description' => 'Log a night of sleep.', 'args' => ['date' => 'YYYY-MM-DD', 'hours' => 'number', 'bedtime' => 'HH:MM (optional)', 'wake_time' => 'HH:MM (optional)', 'quality' => '1-100 (optional)'], 'write' => true],
            ['name' => 'log_steps', 'description' => "Set a day's step count.", 'args' => ['steps' => 'int', 'date' => 'YYYY-MM-DD (optional, default today)'], 'write' => true],
            ['name' => 'log_recovery', 'description' => 'Log resting HR / HRV / subjective recovery for a day.', 'args' => ['date' => 'YYYY-MM-DD (optional)', 'resting_hr' => 'int (optional)', 'hrv_ms' => 'int (optional)', 'stress' => '1-10 (optional)', 'mood' => '1-10 (optional)', 'energy' => '1-10 (optional)', 'soreness' => '1-10 (optional)'], 'write' => true],
            ['name' => 'log_weight', 'description' => 'Log a body-weight (kg) measurement.', 'args' => ['weight_kg' => 'number', 'date' => 'YYYY-MM-DD (optional)', 'body_fat_pct' => 'number (optional)'], 'write' => true],
            ['name' => 'log_workout', 'description' => 'Log a strength workout with its exercises and sets.', 'args' => ['name' => 'string (optional)', 'performed_at' => 'ISO datetime (optional)', 'exercises' => '[{name, sets:[{reps, weight_kg, rpe?}]}]'], 'write' => true],
            ['name' => 'set_goal', 'description' => "Set the profile's primary goal.", 'args' => ['goal' => 'string'], 'write' => true],
            ['name' => 'pair_device', 'description' => 'Pair a Titan wearable and return its device id + one-time secret (show the secret to the user once).', 'args' => ['name' => 'string (optional)'], 'write' => true],
        ];
    }

    /** Run one tool. Returns a JSON-serialisable value (array/string) or an ['error' => …]. */
    public function dispatch(string $name, array $args): mixed
    {
        return match ($name) {
            'get_today' => $this->getToday(),
            'get_recovery' => $this->getRecovery(),
            'get_sleep' => $this->getSleep((int) ($args['days'] ?? 14)),
            'get_fitness' => $this->getFitness(),
            'get_activity' => $this->getActivity(),
            'get_workouts' => $this->coach->dispatch('recent_workouts', $args),
            'get_biomarkers' => $this->coach->dispatch('recent_biomarkers', $args),
            'get_meals' => $this->coach->dispatch('recent_meals', $args),
            'get_profile' => $this->getProfile(),
            'get_devices' => $this->getDevices(),
            'log_sleep' => $this->logSleep($args),
            'log_steps' => $this->logSteps($args),
            'log_recovery' => $this->logRecovery($args),
            'log_weight' => $this->logWeight($args),
            'log_workout' => $this->logWorkout($args),
            'set_goal' => $this->setGoal($args),
            'pair_device' => $this->pairDevice($args),
            default => ['error' => "Unknown tool: {$name}"],
        };
    }

    // ---------- reads ----------

    private function getToday(): array
    {
        $rec = $this->profile->recoveryLogs()->orderByDesc('logged_at')->orderByDesc('id')->first();
        $sleep = $this->profile->sleepLogs()->orderByDesc('slept_at')->orderByDesc('id')->first();
        $steps = (int) ($this->profile->dailyActivity()->whereDate('date', Carbon::today())->value('steps') ?? 0);
        $goal = StepGoal::assess($steps, StepGoal::targetFor($this->profile));

        return [
            'readiness' => Readiness::compute($this->profile, $rec?->logged_at)['score'] ?? null,
            'resting_hr' => $rec?->resting_hr,
            'hrv_ms' => $rec?->hrv_ms,
            'last_sleep_hours' => $sleep ? round($sleep->duration_min / 60, 1) : null,
            'steps' => $steps,
            'step_goal' => $goal['target'],
            'step_progress_pct' => $goal['pct'],
            'metabolic_health' => MetabolicHealth::assess($this->profile)['score'] ?? null,
        ];
    }

    private function getRecovery(): array
    {
        $rec = $this->profile->recoveryLogs()->orderByDesc('logged_at')->orderByDesc('id')->first();

        return [
            'latest' => $rec ? ['date' => optional($rec->logged_at)->toDateString(), 'hrv_ms' => $rec->hrv_ms, 'resting_hr' => $rec->resting_hr, 'stress' => $rec->stress, 'mood' => $rec->mood, 'energy' => $rec->energy] : null,
            'readiness' => Readiness::compute($this->profile, $rec?->logged_at)['score'] ?? null,
            'metabolic_health' => MetabolicHealth::assess($this->profile),
        ];
    }

    private function getSleep(int $days): array
    {
        $days = max(1, min(60, $days));
        $logs = $this->profile->sleepLogs()->where('slept_at', '>=', Carbon::today()->subDays($days - 1))->orderByDesc('slept_at')->get();
        $month = $this->profile->sleepLogs()->where('slept_at', '>=', Carbon::today()->subDays(27))->get();
        $activity = $this->profile->dailyActivity()->where('date', '>=', Carbon::today()->subDays(13))->get();

        return [
            'nights' => $logs->map(fn ($s) => ['date' => $s->slept_at->toDateString(), 'hours' => round($s->duration_min / 60, 1), 'quality' => $s->quality, 'bedtime' => $s->bedtime, 'wake_time' => $s->wake_time])->values(),
            'sleep_regularity' => SleepRegularity::compute($month),
            'circadian_rhythm' => CircadianRhythm::compute($activity),
        ];
    }

    private function getFitness(): array
    {
        $sessions = $this->profile->activitySessions()->orderByDesc('started_at')->limit(20)->get();
        $latestVo2 = $sessions->firstWhere(fn ($s) => $s->vo2max !== null);

        return [
            'vo2max' => $latestVo2?->vo2max,
            'fitness_level' => $latestVo2?->fitness_level,
            'latest_hrr_bpm' => $sessions->firstWhere(fn ($s) => $s->hrr_bpm !== null)?->hrr_bpm,
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
        $workout = $this->profile->workouts()->create([
            'name' => $a['name'] ?? 'Workout',
            'performed_at' => isset($a['performed_at']) ? Carbon::parse($a['performed_at']) : now(),
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
            'secret' => $secret,   // shown ONCE — the user enters this on the watch / bridge
            'note' => 'Give the device_id + secret to the watch bridge to start syncing. The secret is shown only once.',
        ];
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
