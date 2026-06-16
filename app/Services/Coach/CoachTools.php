<?php

namespace App\Services\Coach;

use App\Models\Profile;
use Illuminate\Support\Carbon;

/**
 * The coach's toolbox over a single profile's data: read tools for every vertical,
 * knowledge save, and live workout logging (start_workout / log_set / finish_workout).
 *
 * Every cross-domain model is guarded with class_exists so the coach keeps working
 * before those verticals are integrated — a missing domain returns a friendly
 * "no data yet" note instead of fatally erroring the tool loop. Each tool returns a
 * compact array/string suitable for feeding straight back into the model.
 */
class CoachTools
{
    public function __construct(protected Profile $profile) {}

    /**
     * OpenAI tool schemas advertised to the model. Knowledge tools are only offered
     * when the Brain vertical exists, so the coach never promises a capability it
     * cannot fulfil.
     *
     * @return array<int,array<string,mixed>>
     */
    public function schemas(): array
    {
        $tools = [];

        // The "how was my day" tool — one call pulls everything for a single day: wearable vitals
        // (HRV, resting HR, respiratory rate), readiness, last night's sleep, today's strain,
        // activity (steps/floors/movement), nutrition and any workouts, plus the day's focus.
        $tools[] = $this->fn('daily_summary', "Pull a full snapshot of one day from the wearable and every other source — vitals (HRV, resting HR, respiratory rate, stress, energy), readiness score, last night's sleep, strain, steps/activity, nutrition and workouts, plus the single focus for the day. Use this whenever the person asks how their day/vitals/recovery/sleep were, or for a daily check-in.", [
            'date' => ['type' => 'string', 'description' => "Which day: 'today' (default), 'yesterday', or an ISO date like 2026-06-16."],
        ], []);

        if (class_exists(\App\Models\KnowledgePage::class)) {
            $tools[] = $this->fn('search_knowledge', "Search this person's long-term-memory health wiki (the brain) for relevant notes, history, preferences, goals, doctor's notes, etc.", [
                'query' => ['type' => 'string', 'description' => 'What to look for, in natural language.'],
            ], ['query']);

            $tools[] = $this->fn('save_knowledge', 'Save or update a durable fact about this person in the brain so it is remembered in future conversations. Use for stable facts (preferences, history, goals), not transient chatter.', [
                'title' => ['type' => 'string', 'description' => 'Short page title.'],
                'content' => ['type' => 'string', 'description' => 'Markdown content of the note.'],
                'pinned' => ['type' => 'boolean', 'description' => 'Pin as core memory (injected into every future conversation). Use sparingly.'],
            ], ['title', 'content']);
        }

        if (class_exists(\App\Models\BiomarkerReading::class)) {
            $tools[] = $this->fn('recent_biomarkers', "Get the latest bloodwork value for each tracked marker, with its out-of-range flag.", [], []);
        }

        if (class_exists(\App\Models\Meal::class)) {
            $tools[] = $this->fn('recent_meals', 'Get recently logged meals and per-day macro totals (calories, protein, carbs, fat).', [
                'days' => ['type' => 'integer', 'description' => 'How many days back to include (default 7).'],
            ], []);
        }

        if (class_exists(\App\Models\Workout::class)) {
            $tools[] = $this->fn('recent_workouts', 'Get a summary of recently logged training sessions (volume, top sets).', [
                'days' => ['type' => 'integer', 'description' => 'How many days back to include (default 14).'],
            ], []);
        }

        if (class_exists(\App\Models\SleepLog::class) || class_exists(\App\Models\RecoveryLog::class)) {
            $tools[] = $this->fn('sleep_recovery_summary', 'Get recent sleep duration/quality and recovery markers (HRV, resting HR, stress, soreness) with averages.', [], []);
        }

        if (class_exists(\App\Models\PhysiqueGoal::class) || class_exists(\App\Models\PhysiqueAnalysis::class)) {
            $tools[] = $this->fn('physique_status', "Get the active physique goal and the latest physique analysis (body-fat range, % of the way to the goal image).", [], []);
        }

        // --- Live workout logging (write) — log sets as the user calls them out during a session ---
        if (class_exists(\App\Models\Workout::class)) {
            $tools[] = $this->fn('start_workout', 'Begin a new workout session when the user says they are starting/about to train. Optional — log_set will start one automatically if none is open. Returns the session id.', [
                'name' => ['type' => 'string', 'description' => 'Optional session name, e.g. "Push day", "Legs".'],
            ], []);

            $tools[] = $this->fn('log_set', "Log ONE set the user just did, into the open workout session (auto-started if none). Use this whenever they call out a set, e.g. \"bench, 8 reps at 135\". IMPORTANT: 'weight' is the TOTAL load lifted INCLUDING the bar. A standard barbell is 45 lb (20 kg). If they describe plates per side, total = bar + 2 × (weight per side) — e.g. one 45 lb plate each side on a barbell = 45 + 90 = 135 lb. Dumbbell/machine weight is taken as given. Pass the unit the user spoke in.", [
                'exercise' => ['type' => 'string', 'description' => 'Exercise name, e.g. "bench press", "back squat".'],
                'reps' => ['type' => 'integer', 'description' => 'Reps completed in this set.'],
                'weight' => ['type' => 'number', 'description' => 'TOTAL weight lifted including the bar, in the given unit. Omit/0 for bodyweight.'],
                'unit' => ['type' => 'string', 'enum' => ['lb', 'kg'], 'description' => 'Unit of weight (default lb).'],
                'rpe' => ['type' => 'number', 'description' => 'Optional rate of perceived exertion, 1–10.'],
                'is_warmup' => ['type' => 'boolean', 'description' => 'True if this was a warm-up set.'],
            ], ['exercise', 'reps']);

            $tools[] = $this->fn('finish_workout', 'Close out the open workout session when the user says they are done. Optionally attach a note.', [
                'notes' => ['type' => 'string', 'description' => 'Optional summary note for the session.'],
            ], []);
        }

        // --- Cardio / activity sessions (write) — start an activity and PRIME the wearable for it ---
        if (class_exists(\App\Models\ActivitySession::class)) {
            $tools[] = $this->fn('start_activity', "Start a cardio/endurance activity when the user says they're beginning one (run, walk, hike, bike ride, swim, row, HIIT, etc.). This opens a session AND primes the Titan wearable to sense for that activity — the band reads the activity on its next connection and switches to the right sampling (e.g. GPS + faster HR for a run). Use this for cardio; use start_workout/log_set for weight training.", [
                'type' => ['type' => 'string', 'description' => 'Activity, e.g. run, walk, hike, cycle, swim, row, hiit. Free text is fine — it gets normalized.'],
                'note' => ['type' => 'string', 'description' => 'Optional note, e.g. "easy zone 2", "tempo".'],
            ], ['type']);

            $tools[] = $this->fn('finish_activity', 'Close the open cardio activity when the user is done, and stand the wearable down from activity mode. Optionally attach distance/heart-rate/calories if the user reports them (the band fills these in on sync otherwise).', [
                'distance_km' => ['type' => 'number', 'description' => 'Optional distance in km.'],
                'avg_hr' => ['type' => 'integer', 'description' => 'Optional average heart rate (bpm).'],
                'calories_kcal' => ['type' => 'integer', 'description' => 'Optional calories burned.'],
                'note' => ['type' => 'string', 'description' => 'Optional summary note.'],
            ], []);
        }

        // --- Menstrual cycle (read + write) — only offered when she tracks it ---
        if (\App\Support\Cycle::available($this->profile)) {
            $tools[] = $this->fn('cycle_status', "Get where she is in her menstrual cycle right now — cycle day, phase (menstrual/follicular/fertile/ovulation/luteal), predicted next period and ovulation, the estimated fertile window and conception likelihood, regularity, today's logged symptoms, and how her cycle phase relates to her recovery (resting-HR/HRV). Use this for ANY cycle, period, fertility, PMS, or 'how will my cycle affect X' question. Awareness only — never present fertility info as contraception or a diagnosis.", [], []);

            $tools[] = $this->fn('log_period', "Log a period event. event='start' records day 1 of a new period (the anchor for all cycle math); event='end' marks the last day of bleeding. Use when she says her period started/ended.", [
                'event' => ['type' => 'string', 'enum' => ['start', 'end'], 'description' => 'start = first day of bleeding; end = last day.'],
                'date' => ['type' => 'string', 'description' => "Date (YYYY-MM-DD, 'today', 'yesterday'); default today."],
            ], ['event']);

            $tools[] = $this->fn('log_cycle', 'Log how she feels today within her cycle — flow, symptoms, mood/energy, basal body temperature. Use when she mentions cramps, PMS, flow, etc.', [
                'flow' => ['type' => 'string', 'enum' => ['none', 'spotting', 'light', 'medium', 'heavy'], 'description' => 'Menstrual flow level.'],
                'symptoms' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'e.g. cramps, headache, bloating, fatigue, mood_swings, tender_breasts, cravings, acne, nausea, insomnia.'],
                'mood' => ['type' => 'integer', 'description' => 'Mood 1 (low) to 5 (great).'],
                'energy' => ['type' => 'integer', 'description' => 'Energy 1 (low) to 5 (high).'],
                'bbt_c' => ['type' => 'number', 'description' => 'Basal body temperature in °C, if measured.'],
                'date' => ['type' => 'string', 'description' => "Date; default today."],
                'notes' => ['type' => 'string', 'description' => 'Free-text notes.'],
            ], []);
        }

        return $tools;
    }

    /** Friendly present-tense status shown in the chat while a tool runs. */
    public static function label(string $name): string
    {
        return match ($name) {
            'daily_summary' => 'Reading your day',
            'search_knowledge' => 'Searching your brain',
            'save_knowledge' => 'Saving to your brain',
            'recent_biomarkers' => 'Checking your bloodwork',
            'recent_meals' => 'Reviewing your nutrition',
            'recent_workouts' => 'Looking at your training',
            'sleep_recovery_summary' => 'Checking sleep & recovery',
            'physique_status' => 'Checking your physique progress',
            'start_workout' => 'Starting your workout',
            'log_set' => 'Logging your set',
            'finish_workout' => 'Wrapping up your workout',
            'start_activity' => 'Priming your wearable',
            'finish_activity' => 'Closing out your activity',
            'cycle_status' => 'Checking your cycle',
            'log_period' => 'Logging your period',
            'log_cycle' => 'Logging your cycle day',
            default => 'Looking that up',
        };
    }

    /** Build one OpenAI function-tool schema. */
    private function fn(string $name, string $description, array $properties, array $required): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $name,
                'description' => $description,
                'parameters' => [
                    'type' => 'object',
                    'properties' => (object) $properties,
                    'required' => $required,
                ],
            ],
        ];
    }

    /**
     * Run a tool by name. Always returns a compact array or string; never throws
     * (the AiService loop also guards, but we degrade gracefully here too).
     */
    public function dispatch(string $name, array $args): mixed
    {
        return match ($name) {
            'daily_summary' => $this->dailySummary((string) ($args['date'] ?? 'today')),
            'search_knowledge' => $this->searchKnowledge((string) ($args['query'] ?? '')),
            'save_knowledge' => $this->saveKnowledge($args),
            'recent_biomarkers' => $this->recentBiomarkers(),
            'recent_meals' => $this->recentMeals((int) ($args['days'] ?? 7)),
            'recent_workouts' => $this->recentWorkouts((int) ($args['days'] ?? 14)),
            'sleep_recovery_summary' => $this->sleepRecoverySummary(),
            'physique_status' => $this->physiqueStatus(),
            'start_workout' => $this->startWorkout($args),
            'log_set' => $this->logSet($args),
            'finish_workout' => $this->finishWorkout($args),
            'start_activity' => $this->startActivity($args),
            'finish_activity' => $this->finishActivity($args),
            'cycle_status' => $this->cycleStatus(),
            'log_period' => $this->logPeriod($args),
            'log_cycle' => $this->logCycle($args),
            default => ['error' => "Unknown tool: {$name}"],
        };
    }

    // ---- Tool implementations -------------------------------------------------

    private function searchKnowledge(string $query): mixed
    {
        if (! class_exists(\App\Models\KnowledgePage::class) || ! class_exists(\App\Services\Brain\KnowledgeSearch::class)) {
            return 'The brain is not available yet — no notes to search.';
        }

        try {
            $search = app(\App\Services\Brain\KnowledgeSearch::class);
            $hits = $search->search($this->profile, $query, 6);
        } catch (\Throwable $e) {
            return ['error' => 'Knowledge search failed: '.$e->getMessage()];
        }

        if ($hits === []) {
            return 'No matching notes found in the brain.';
        }

        return array_map(fn ($h) => [
            'title' => $h['page']->title ?? null,
            'snippet' => $h['snippet'] ?? null,
            'score' => $h['score'] ?? null,
        ], $hits);
    }

    private function saveKnowledge(array $args): mixed
    {
        if (! class_exists(\App\Models\KnowledgePage::class)) {
            return 'The brain is not available yet — cannot save notes.';
        }

        $title = trim((string) ($args['title'] ?? ''));
        $content = trim((string) ($args['content'] ?? ''));
        if ($title === '' || $content === '') {
            return ['error' => 'Both title and content are required to save a note.'];
        }

        try {
            $model = \App\Models\KnowledgePage::class;
            $page = $model::query()
                ->where('profile_id', $this->profile->id)
                ->where('title', $title)
                ->first();

            $payload = [
                'profile_id' => $this->profile->id,
                'title' => $title,
                'content' => $content,
                'is_pinned' => (bool) ($args['pinned'] ?? false),
            ];
            // slug/type are nice-to-haves the Brain schema may require.
            if (method_exists($model, 'slugFor')) {
                $payload['slug'] = $model::slugFor($title);
            }
            $payload['type'] ??= 'note';

            if ($page) {
                $page->fill($payload)->save();
            } else {
                $page = $model::create($payload);
            }

            // Best-effort re-embed so the new note is searchable immediately.
            if (class_exists(\App\Services\Brain\KnowledgeSearch::class) && method_exists(app(\App\Services\Brain\KnowledgeSearch::class), 'embedPage')) {
                try {
                    app(\App\Services\Brain\KnowledgeSearch::class)->embedPage($page);
                } catch (\Throwable) {
                    // embedding is optional; keyword search still works.
                }
            }

            return ['saved' => true, 'title' => $title, 'pinned' => (bool) ($args['pinned'] ?? false)];
        } catch (\Throwable $e) {
            return ['error' => 'Could not save note: '.$e->getMessage()];
        }
    }

    private function recentBiomarkers(): mixed
    {
        if (! class_exists(\App\Models\BiomarkerReading::class)) {
            return 'No biomarker data yet.';
        }

        try {
            $rows = \App\Models\BiomarkerReading::query()
                ->where('profile_id', $this->profile->id)
                ->orderByDesc('taken_at')
                ->get();
        } catch (\Throwable) {
            return 'No biomarker data yet.';
        }

        if ($rows->isEmpty()) {
            return 'No biomarker readings logged yet.';
        }

        // Latest reading per marker.
        $latest = $rows->groupBy('marker')->map(fn ($g) => $g->first());

        return $latest->map(fn ($r) => [
            'marker' => $r->label ?? $r->marker,
            'value' => (float) $r->value,
            'unit' => $r->unit,
            'flag' => $r->flag,
            'taken_at' => optional($r->taken_at)->toDateString(),
        ])->values()->all();
    }

    private function recentMeals(int $days): mixed
    {
        if (! class_exists(\App\Models\Meal::class)) {
            return 'No meal data yet.';
        }
        $days = max(1, min($days, 60));

        try {
            $since = Carbon::now()->subDays($days)->startOfDay();
            $meals = \App\Models\Meal::query()
                ->where('profile_id', $this->profile->id)
                ->where('eaten_at', '>=', $since)
                ->orderByDesc('eaten_at')
                ->get();
        } catch (\Throwable) {
            return 'No meal data yet.';
        }

        if ($meals->isEmpty()) {
            return "No meals logged in the last {$days} days.";
        }

        $daily = $meals->groupBy(fn ($m) => optional($m->eaten_at)->toDateString())
            ->map(fn ($g) => [
                'meals' => $g->count(),
                'calories' => (int) $g->sum('calories'),
                'protein_g' => round((float) $g->sum('protein_g'), 1),
                'carbs_g' => round((float) $g->sum('carbs_g'), 1),
                'fat_g' => round((float) $g->sum('fat_g'), 1),
            ]);

        return [
            'window_days' => $days,
            'daily_totals' => $daily,
            'recent_meals' => $meals->take(12)->map(fn ($m) => [
                'name' => $m->name,
                'eaten_at' => optional($m->eaten_at)->toDateTimeString(),
                'calories' => (int) $m->calories,
                'protein_g' => round((float) $m->protein_g, 1),
            ])->values()->all(),
        ];
    }

    private function recentWorkouts(int $days): mixed
    {
        if (! class_exists(\App\Models\Workout::class)) {
            return 'No workout data yet.';
        }
        $days = max(1, min($days, 90));

        try {
            $since = Carbon::now()->subDays($days)->startOfDay();
            $workouts = \App\Models\Workout::query()
                ->where('profile_id', $this->profile->id)
                ->where('performed_at', '>=', $since)
                ->with('exercises.sets', 'exercises.exercise')
                ->orderByDesc('performed_at')
                ->get();
        } catch (\Throwable) {
            return 'No workout data yet.';
        }

        if ($workouts->isEmpty()) {
            return "No workouts logged in the last {$days} days.";
        }

        return [
            'window_days' => $days,
            'session_count' => $workouts->count(),
            'sessions' => $workouts->take(12)->map(function ($w) {
                $row = [
                    'name' => $w->name,
                    'performed_at' => optional($w->performed_at)->toDateTimeString(),
                    'duration_min' => $w->duration_min,
                ];
                // Volume/top sets are derived from set rows — guard in case relations are absent.
                try {
                    $row['total_volume_kg'] = round($w->totalVolume(), 1);
                    $row['working_sets'] = $w->workingSetCount();
                    $row['top_sets'] = $w->topSets()->all();
                } catch (\Throwable) {
                    // leave the basic summary
                }

                return $row;
            })->values()->all(),
        ];
    }

    // ---- Live workout logging -------------------------------------------------

    /** The session to log into: the most recent one started within the last 6h, else null. */
    private function openWorkout(): ?\App\Models\Workout
    {
        return $this->profile->workouts()
            ->where('performed_at', '>=', Carbon::now()->subHours(6))
            ->latest('performed_at')->first();
    }

    private function startWorkout(array $args): mixed
    {
        $workout = $this->profile->workouts()->create([
            'name' => trim((string) ($args['name'] ?? '')) ?: 'Workout',
            'performed_at' => Carbon::now(),
            'updated_via' => 'coach',
        ]);

        return ['ok' => true, 'workout_id' => $workout->id, 'name' => $workout->name, 'message' => "Started \"{$workout->name}\" — call out your sets and I'll log them."];
    }

    private function logSet(array $args): mixed
    {
        $name = trim((string) ($args['exercise'] ?? ''));
        if ($name === '') {
            return ['error' => 'exercise is required'];
        }
        $reps = (int) ($args['reps'] ?? 0);
        if ($reps < 1) {
            return ['error' => 'reps must be at least 1'];
        }

        $unit = strtolower((string) ($args['unit'] ?? 'lb'));
        $weight = (float) ($args['weight'] ?? 0);
        $weightKg = $unit === 'kg' ? $weight : $weight * 0.45359237;   // store canonical kg

        // Open session, or start one on the fly.
        $workout = $this->openWorkout() ?? $this->profile->workouts()->create([
            'name' => 'Workout',
            'performed_at' => Carbon::now(),
            'updated_via' => 'coach',
        ]);

        $exercise = \App\Models\Exercise::firstOrCreate(
            ['slug' => \Illuminate\Support\Str::slug($name)],
            ['name' => \Illuminate\Support\Str::title($name), 'muscle_group' => 'full body', 'category' => 'compound'],
        );

        // Reuse this exercise's slot in the session if it's already there, else append.
        $we = \App\Models\WorkoutExercise::firstOrCreate(
            ['workout_id' => $workout->id, 'exercise_id' => $exercise->id],
            ['order' => (int) \App\Models\WorkoutExercise::where('workout_id', $workout->id)->max('order') + 1],
        );

        $setNumber = (int) \App\Models\WorkoutSet::where('workout_exercise_id', $we->id)->max('set_number') + 1;

        \App\Models\WorkoutSet::create([
            'workout_exercise_id' => $we->id,
            'set_number' => $setNumber,
            'reps' => $reps,
            'weight_kg' => round($weightKg, 2),
            'rpe' => isset($args['rpe']) ? (float) $args['rpe'] : null,
            'is_warmup' => (bool) ($args['is_warmup'] ?? false),
        ]);

        // Echo back in the unit they spoke, so the confirmation reads naturally.
        $shown = $weight > 0
            ? rtrim(rtrim(number_format($unit === 'kg' ? $weightKg : $weight, 1), '0'), '.').' '.$unit
            : 'bodyweight';

        return [
            'ok' => true,
            'workout_id' => $workout->id,
            'exercise' => $exercise->name,
            'set_number' => $setNumber,
            'reps' => $reps,
            'weight' => $shown,
            'weight_kg' => round($weightKg, 2),
            'message' => "Logged {$exercise->name} — set {$setNumber}: {$reps} × {$shown}.",
        ];
    }

    private function finishWorkout(array $args): mixed
    {
        $workout = $this->openWorkout();
        if (! $workout) {
            return ['error' => 'No open workout to finish.'];
        }

        $minutes = (int) round(Carbon::now()->diffInMinutes($workout->performed_at, true));
        $workout->update([
            'duration_min' => $minutes ?: null,
            'notes' => trim((string) ($args['notes'] ?? '')) ?: $workout->notes,
        ]);

        $totalSets = \App\Models\WorkoutSet::whereIn(
            'workout_exercise_id',
            \App\Models\WorkoutExercise::where('workout_id', $workout->id)->pluck('id'),
        )->count();

        return [
            'ok' => true,
            'workout_id' => $workout->id,
            'duration_min' => $minutes,
            'exercises' => $workout->exercises()->count(),
            'sets' => $totalSets,
            'message' => "Nice work — {$totalSets} sets logged over {$minutes} min. Saved to your training log.",
        ];
    }

    // ---- Cardio activity + wearable priming -----------------------------------

    /** The open cardio session (started within 6h and not yet ended), else null. */
    private function openActivity(): ?\App\Models\ActivitySession
    {
        return $this->profile->activitySessions()
            ->whereNull('ended_at')
            ->where('started_at', '>=', Carbon::now()->subHours(6))
            ->latest('started_at')->first();
    }

    private function startActivity(array $args): mixed
    {
        $type = \App\Support\ActivityPriming::normalize((string) ($args['type'] ?? 'other'));
        $sensing = \App\Support\ActivityPriming::profile($type);
        $now = Carbon::now();

        $session = $this->profile->activitySessions()->create([
            'source' => 'coach',
            'started_at' => $now,
            'activity_type' => $type,
            'updated_via' => 'coach',
        ]);

        // Prime the wearable: stash the active activity on the profile. The band reads it on
        // its next connection (GET /api/devices/activity) and switches to this sensing profile.
        $settings = $this->profile->settings ?? [];
        $settings['active_activity'] = [
            'type' => $type,
            'label' => $sensing['label'],
            'started_at' => $now->toIso8601String(),
            'session_id' => $session->id,
            'sampling' => $sensing,
        ];
        $this->profile->update(['settings' => $settings]);

        $gps = $sensing['gps'] ? 'GPS on' : 'GPS off';

        return [
            'ok' => true,
            'session_id' => $session->id,
            'activity' => $sensing['label'],
            'wearable_primed' => true,
            'sampling' => $sensing,
            'message' => "{$sensing['label']} started — I've primed your band for it ({$gps}, HR {$sensing['hr_hz']}Hz). It'll switch modes on its next sync. Have a good one.",
        ];
    }

    private function finishActivity(array $args): mixed
    {
        $session = $this->openActivity();

        // Clear the priming regardless, so the band stands back down to its everyday mode.
        $settings = $this->profile->settings ?? [];
        $hadPrime = isset($settings['active_activity']);
        unset($settings['active_activity']);
        $this->profile->update(['settings' => $settings]);

        if (! $session) {
            return $hadPrime
                ? ['ok' => true, 'wearable_primed' => false, 'message' => 'Stood your band back down to everyday sensing.']
                : ['error' => 'No open activity to finish.'];
        }

        $now = Carbon::now();
        $minutes = (int) round($now->diffInMinutes($session->started_at, true));
        $session->update([
            'ended_at' => $now,
            'duration_min' => $minutes ?: null,
            'distance_km' => isset($args['distance_km']) ? (float) $args['distance_km'] : $session->distance_km,
            'avg_hr' => isset($args['avg_hr']) ? (int) $args['avg_hr'] : $session->avg_hr,
            'calories_kcal' => isset($args['calories_kcal']) ? (int) $args['calories_kcal'] : $session->calories_kcal,
        ]);

        $label = \App\Support\ActivityPriming::profile((string) $session->activity_type)['label'];
        $bits = [$minutes.' min'];
        if ($session->distance_km) {
            $bits[] = rtrim(rtrim(number_format((float) $session->distance_km, 2), '0'), '.').' km';
        }
        if ($session->avg_hr) {
            $bits[] = $session->avg_hr.' bpm avg';
        }

        return [
            'ok' => true,
            'session_id' => $session->id,
            'wearable_primed' => false,
            'duration_min' => $minutes,
            'message' => "{$label} done — ".implode(' · ', $bits).'. Band is back on everyday sensing; full stats land when it syncs.',
        ];
    }

    // ---- Menstrual cycle ------------------------------------------------------

    private function cycleStatus(): mixed
    {
        $s = \App\Support\Cycle::status($this->profile);
        $insight = \App\Support\Cycle::recoveryByPhase($this->profile);
        if (! empty($insight['note'])) {
            $s['recovery_insight'] = $insight['note'];
        }
        $s['_guidance'] = 'Be warm, matter-of-fact and supportive — this is normal health. Lead with the phase + day and what it means for how she likely feels, her training and her nutrition (e.g. luteal: a small readiness dip is expected; menstrual: watch iron; follicular: often peak energy). For any fertility/pregnancy question, give the estimate AND the disclaimer — never present it as contraception or a diagnosis. If she has hormone bloodwork, remember those values only make sense against the cycle day they were drawn.';

        return $s;
    }

    private function logPeriod(array $args): mixed
    {
        $date = $this->cycleDate($args['date'] ?? null);
        $event = strtolower((string) ($args['event'] ?? 'start'));

        if ($event === 'end') {
            $cycle = \App\Support\Cycle::endPeriod($this->profile, $date);

            return $cycle
                ? ['ok' => true, 'message' => 'Logged your period ending '.$date->toDateString().'.']
                : ['error' => 'No cycle to end yet — log a period start first.'];
        }

        \App\Support\Cycle::startPeriod($this->profile, $date, 'coach');
        $s = \App\Support\Cycle::status($this->profile, $date);

        return [
            'ok' => true,
            'cycle_day' => 1,
            'next_period_predicted' => $s['next_period']['date'] ?? null,
            'message' => 'Logged day 1 of your period on '.$date->toDateString().". I'll track your phases and predictions from here.",
        ];
    }

    private function logCycle(array $args): mixed
    {
        $date = $this->cycleDate($args['date'] ?? null);
        $log = \App\Support\Cycle::logDay($this->profile, $date, $args, 'coach');

        return [
            'ok' => true,
            'date' => $date->toDateString(),
            'flow' => $log->flow,
            'symptoms' => $log->symptoms,
            'message' => 'Logged your cycle notes for '.$date->toDateString().'.',
        ];
    }

    /** Resolve a tool date arg (today/yesterday/ISO) in the profile's timezone. */
    private function cycleDate($value): Carbon
    {
        $tz = $this->profile->settings['timezone'] ?? config('app.timezone', 'UTC');
        $raw = strtolower(trim((string) ($value ?? '')));

        return match ($raw) {
            '', 'today' => Carbon::now($tz)->startOfDay(),
            'yesterday' => Carbon::now($tz)->subDay()->startOfDay(),
            default => rescue(fn () => Carbon::parse($value, $tz)->startOfDay(), Carbon::now($tz)->startOfDay(), false),
        };
    }

    /**
     * Everything for one day, in one shot — the "how was my day / my vitals today" tool.
     * Pulls raw wearable vitals plus the computed pillars (readiness, sleep, strain, activity,
     * nutrition, workouts, focus). Every block is independently guarded so a missing model or a
     * blank day degrades to a note instead of failing the whole summary.
     */
    private function dailySummary(string $dateArg): mixed
    {
        $tz = $this->profile->settings['timezone'] ?? config('app.timezone', 'UTC');
        $today = \Illuminate\Support\Carbon::today($tz);
        $day = match (strtolower(trim($dateArg))) {
            '', 'today' => $today->copy(),
            'yesterday' => $today->copy()->subDay(),
            default => rescue(fn () => \Illuminate\Support\Carbon::parse($dateArg, $tz)->startOfDay(), $today->copy(), false),
        };
        $isToday = $day->isSameDay($today);

        $out = [
            'date' => $day->toDateString(),
            'is_today' => $isToday,
            'relative' => $isToday ? 'today' : ($day->isSameDay($today->copy()->subDay()) ? 'yesterday' : $day->diffForHumans($today)),
        ];

        // --- Wearable vitals (raw RecoveryLog row for the day) ---
        if (class_exists(\App\Models\RecoveryLog::class)) {
            try {
                $rec = \App\Models\RecoveryLog::query()
                    ->where('profile_id', $this->profile->id)
                    ->whereDate('logged_at', $day->toDateString())
                    ->latest('id')->first();
                if ($rec) {
                    $out['vitals'] = collect([
                        'hrv_ms' => $rec->getAttribute('hrv_ms'),
                        'resting_hr' => $rec->getAttribute('resting_hr'),
                        'resp_rate' => $rec->getAttribute('resp_rate'),
                        'stress_1_10' => $rec->getAttribute('stress'),
                        'soreness_1_10' => $rec->getAttribute('soreness'),
                        'mood_1_10' => $rec->getAttribute('mood'),
                        'energy_1_10' => $rec->getAttribute('energy'),
                    ])->filter(fn ($v) => $v !== null)->all();
                }
            } catch (\Throwable) {
                // ignore
            }
            if (! isset($out['vitals'])) {
                $out['vitals'] = $isToday
                    ? 'No wearable vitals captured yet today — sync the band or log how you feel.'
                    : "No wearable vitals recorded for {$out['date']}.";
            }
        }

        // --- Readiness / recovery score ---
        if (class_exists(\App\Support\Readiness::class)) {
            try {
                $r = \App\Support\Readiness::compute($this->profile, $day);
                if (($r['score'] ?? null) !== null) {
                    $out['readiness'] = [
                        'score' => $r['score'],
                        'label' => $r['label'] ?? null,
                        'note' => $r['note'] ?? null,
                        'drivers' => $r['components'] ?? null,
                        'provisional' => $r['provisional'] ?? null,
                    ];
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        // --- Last night's sleep ---
        if (class_exists(\App\Models\SleepLog::class)) {
            try {
                $s = \App\Models\SleepLog::query()
                    ->where('profile_id', $this->profile->id)
                    ->whereDate('slept_at', $day->toDateString())
                    ->latest('id')->first();
                $sleep = [];
                if ($s) {
                    $sleep = collect([
                        'duration_h' => $s->duration_min ? round($s->duration_min / 60, 1) : null,
                        'quality' => $s->getAttribute('quality'),
                        'deep_min' => $s->getAttribute('deep_min'),
                        'rem_min' => $s->getAttribute('rem_min'),
                        'light_min' => $s->getAttribute('light_min'),
                        'awake_min' => $s->getAttribute('awake_min'),
                        'bedtime' => $s->getAttribute('bedtime'),
                        'wake_time' => $s->getAttribute('wake_time'),
                    ])->filter(fn ($v) => $v !== null)->all();
                }
                if (class_exists(\App\Support\SleepCoach::class)) {
                    $coach = rescue(fn () => \App\Support\SleepCoach::assess($this->profile, $day), null, false);
                    if ($coach) {
                        $sleep += [
                            'need_h' => $coach['need_h'] ?? null,
                            'debt_h' => $coach['debt_h'] ?? null,
                            'performance_pct' => $coach['performance_pct'] ?? null,
                            'status' => $coach['label'] ?? null,
                            'advice' => $coach['advice'] ?? null,
                        ];
                    }
                }
                $out['sleep'] = $sleep !== [] ? array_filter($sleep, fn ($v) => $v !== null) : 'No sleep logged for that night.';
            } catch (\Throwable) {
                // ignore
            }
        }

        // --- Strain (training/cardiovascular load so far) ---
        if (class_exists(\App\Support\Strain::class)) {
            try {
                $st = \App\Support\Strain::assess($this->profile, $day);
                $out['strain'] = [
                    'strain' => $st['strain'] ?? null,
                    'band' => $st['label'] ?? ($st['band'] ?? null),
                    'target' => isset($st['target']) ? ($st['target']['label'] ?? null) : null,
                    'status' => $st['status'] ?? null,
                    'advice' => $st['advice'] ?? null,
                ];
            } catch (\Throwable) {
                // ignore
            }
        }

        // --- Activity (steps, floors, movement) ---
        if (class_exists(\App\Models\DailyActivity::class)) {
            try {
                $act = \App\Models\DailyActivity::query()
                    ->where('profile_id', $this->profile->id)
                    ->whereDate('date', $day->toDateString())
                    ->first();
                if ($act) {
                    $activity = collect([
                        'mvpa_min' => $act->getAttribute('mvpa_min'),
                        'active_kcal' => $act->getAttribute('active_kcal'),
                        'floors' => $act->getAttribute('floors'),
                        'distance_km' => $act->getAttribute('distance_km'),
                        'source' => $act->getAttribute('source'),
                    ])->filter(fn ($v) => $v !== null)->all();
                    $activity = ['steps' => (int) $act->steps] + $activity;
                    if (class_exists(\App\Support\StepGoal::class)) {
                        $target = \App\Support\StepGoal::targetFor($this->profile);
                        $activity['steps_goal'] = \App\Support\StepGoal::assess((int) $act->steps, $target);
                    }
                    if (class_exists(\App\Support\MovementBreaks::class) && $act->getAttribute('hourly')) {
                        $mb = rescue(fn () => \App\Support\MovementBreaks::assess($act->getAttribute('hourly')), null, false);
                        if ($mb) {
                            $activity['movement'] = $mb;
                        }
                    }
                    $out['activity'] = $activity;
                } else {
                    $out['activity'] = $isToday ? 'No activity synced yet today.' : "No activity recorded for {$out['date']}.";
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        // --- Nutrition + when-to-eat (only meaningful for today's running totals) ---
        if ($isToday && class_exists(\App\Support\MealCoach::class)) {
            try {
                $m = \App\Support\MealCoach::assess($this->profile);
                $out['nutrition'] = [
                    'meals_logged' => $m['meals_logged'] ?? null,
                    'meals_planned' => $m['meals_planned'] ?? null,
                    'consumed' => $m['consumed'] ?? null,
                    'target' => $m['target'] ?? null,
                    'next_meal' => [
                        'status' => $m['status'] ?? null,
                        'label' => $m['label'] ?? null,
                        'next_at' => $m['next_at'] ?? null,
                        'next_in_min' => $m['next_in_min'] ?? null,
                        'overdue_min' => $m['overdue_min'] ?? null,
                    ],
                    'advice' => $m['advice'] ?? null,
                ];
            } catch (\Throwable) {
                // ignore
            }
        } elseif (class_exists(\App\Models\Meal::class)) {
            try {
                $meals = $this->profile->meals()
                    ->whereDate('eaten_at', $day->toDateString())->get();
                if ($meals->isNotEmpty()) {
                    $out['nutrition'] = [
                        'meals_logged' => $meals->count(),
                        'consumed' => [
                            'calories' => (int) $meals->sum('calories'),
                            'protein_g' => (int) round((float) $meals->sum('protein_g')),
                        ],
                    ];
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        // --- Workouts performed that day ---
        if (class_exists(\App\Models\Workout::class)) {
            try {
                $workouts = \App\Models\Workout::query()
                    ->where('profile_id', $this->profile->id)
                    ->whereDate('performed_at', $day->toDateString())
                    ->get();
                if ($workouts->isNotEmpty()) {
                    $out['workouts'] = $workouts->map(fn ($w) => array_filter([
                        'name' => $w->name,
                        'performed_at' => optional($w->performed_at)->toIso8601String(),
                        'duration_min' => $w->getAttribute('duration_min'),
                    ], fn ($v) => $v !== null))->all();
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        // --- Menstrual cycle (context for readiness/nutrition/training) ---
        if (\App\Support\Cycle::available($this->profile)) {
            try {
                $cs = \App\Support\Cycle::status($this->profile, $day);
                if ($cs['has_data'] ?? false) {
                    $out['cycle'] = [
                        'cycle_day' => $cs['cycle_day'],
                        'phase' => $cs['phase_label'],
                        'next_period_in_days' => $cs['next_period']['in_days'] ?? null,
                        'fertile_window_active' => $cs['fertile_window']['active'] ?? null,
                        'late' => $cs['late'] ?? false,
                        'note' => $cs['note'],
                    ];
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        // --- The single focus for today ---
        if ($isToday && class_exists(\App\Support\DailyFocus::class)) {
            try {
                $f = \App\Support\DailyFocus::compute($this->profile, $day);
                $out['focus'] = [
                    'headline' => $f['headline'] ?? null,
                    'detail' => $f['detail'] ?? null,
                ];
            } catch (\Throwable) {
                // ignore
            }
        }

        $out['_guidance'] = 'Give a warm, brief daily check-in. Lead with the headline vitals and readiness, call out anything notably good or off, and end with the one thing to focus on. Use a small markdown table for the vitals when there are several. Only mention sections that have data.';

        return $out;
    }

    private function sleepRecoverySummary(): mixed
    {
        $out = [];

        if (class_exists(\App\Models\SleepLog::class)) {
            try {
                $sleep = \App\Models\SleepLog::query()
                    ->where('profile_id', $this->profile->id)
                    ->latest('id')->take(14)->get();
                if ($sleep->isNotEmpty()) {
                    $out['sleep'] = [
                        'nights' => $sleep->count(),
                        'avg_duration_h' => $this->avg($sleep, ['duration_hours', 'hours', 'duration_min']),
                        'avg_quality' => $this->avg($sleep, ['quality', 'quality_score', 'score']),
                    ];
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        if (class_exists(\App\Models\RecoveryLog::class)) {
            try {
                $rec = \App\Models\RecoveryLog::query()
                    ->where('profile_id', $this->profile->id)
                    ->latest('id')->take(14)->get();
                if ($rec->isNotEmpty()) {
                    $out['recovery'] = [
                        'entries' => $rec->count(),
                        'avg_hrv' => $this->avg($rec, ['hrv', 'hrv_ms']),
                        'avg_resting_hr' => $this->avg($rec, ['resting_hr', 'rhr', 'resting_heart_rate']),
                        'avg_stress' => $this->avg($rec, ['stress', 'stress_level']),
                        'avg_soreness' => $this->avg($rec, ['soreness', 'soreness_level']),
                    ];
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        return $out === [] ? 'No sleep or recovery data yet.' : $out;
    }

    private function physiqueStatus(): mixed
    {
        $out = [];

        if (class_exists(\App\Models\PhysiqueGoal::class)) {
            try {
                $q = \App\Models\PhysiqueGoal::query()->where('profile_id', $this->profile->id);
                // Prefer an "active" goal if the column exists; else the latest.
                $goal = (clone $q)->latest('id')->first();
                try {
                    $active = (clone $q)->where('is_active', true)->latest('id')->first();
                    $goal = $active ?: $goal;
                } catch (\Throwable) {
                    // no is_active column — keep latest
                }
                if ($goal) {
                    $out['goal'] = collect($goal->getAttributes())
                        ->only(['title', 'description', 'target', 'target_body_fat_pct', 'target_weight_kg', 'is_active'])
                        ->filter(fn ($v) => $v !== null)
                        ->all() ?: ['summary' => 'Active physique goal set.'];
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        if (class_exists(\App\Models\PhysiqueAnalysis::class)) {
            try {
                $analysis = \App\Models\PhysiqueAnalysis::query()
                    ->where('profile_id', $this->profile->id)
                    ->latest('id')->first();
                if ($analysis) {
                    $out['latest_analysis'] = collect($analysis->getAttributes())
                        ->only(['body_fat_pct', 'body_fat_low', 'body_fat_high', 'pct_to_goal', 'summary', 'notes', 'created_at'])
                        ->filter(fn ($v) => $v !== null)
                        ->all() ?: ['summary' => 'Physique analysis available.'];
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        // Image URLs the coach can embed (markdown ![](url)) to SHOW the physique, not just describe it.
        try {
            $goalImg = $this->profile->physiqueGoals()->where('is_active', true)->latest('id')->first()
                ?? $this->profile->physiqueGoals()->latest('id')->first();
            if ($goalImg && method_exists($goalImg, 'goalUrl') && $goalImg->goalUrl()) {
                $out['goal_image_url'] = $goalImg->goalUrl();
            }
            $render = $this->profile->livingGoalRenders()->latest('id')->first();
            if ($render && $render->imageUrl()) {
                $out['future_self_image_url'] = $render->imageUrl();
            }
            $photo = $this->profile->progressPhotos()->latest('taken_at')->latest('id')->first();
            if ($photo && $photo->photoUrl()) {
                $out['latest_progress_photo_url'] = $photo->photoUrl();
            }
            if (isset($out['goal_image_url']) || isset($out['future_self_image_url']) || isset($out['latest_progress_photo_url'])) {
                $out['_show'] = 'Embed these image URLs as markdown ![](url) so the user sees them inline.';
            }
        } catch (\Throwable) {
            // image URLs are a bonus — never fail the tool on them
        }

        return $out === [] ? 'No physique goal or analysis yet.' : $out;
    }

    /** Average of the first present numeric attribute among $keys, rounded; null if none. */
    private function avg(\Illuminate\Support\Collection $rows, array $keys): ?float
    {
        foreach ($keys as $key) {
            $vals = $rows->map(fn ($r) => $r->getAttribute($key))->filter(fn ($v) => is_numeric($v));
            if ($vals->isNotEmpty()) {
                return round((float) $vals->avg(), 1);
            }
        }

        return null;
    }
}
