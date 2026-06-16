<?php

namespace App\Services\Coach;

use App\Models\Profile;
use Illuminate\Support\Carbon;

/**
 * The coach's READ-only toolbox over a single profile's data, plus knowledge save.
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
