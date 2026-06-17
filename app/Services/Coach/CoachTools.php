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

        // --- Logging & data entry (write) — so the chat can run the whole platform ---
        if (class_exists(\App\Models\Meal::class)) {
            $tools[] = $this->fn('log_meal', 'Log a meal with its macros when the user tells you what they ate. (For a photo of food they use the camera button.)', [
                'name' => ['type' => 'string', 'description' => 'Short meal name.'],
                'calories' => ['type' => 'integer', 'description' => 'Calories (kcal).'],
                'protein_g' => ['type' => 'number', 'description' => 'Protein grams.'],
                'carbs_g' => ['type' => 'number', 'description' => 'Carb grams.'],
                'fat_g' => ['type' => 'number', 'description' => 'Fat grams.'],
                'eaten_at' => ['type' => 'string', 'description' => 'ISO datetime; default now.'],
            ], ['name']);
        }
        if (class_exists(\App\Models\BodyMetric::class)) {
            $tools[] = $this->fn('log_weight', 'Log a body-weight measurement (kg), optionally body-fat %.', [
                'weight_kg' => ['type' => 'number', 'description' => 'Body weight in kg.'],
                'body_fat_pct' => ['type' => 'number', 'description' => 'Optional body-fat %.'],
                'date' => ['type' => 'string', 'description' => 'Date; default today.'],
            ], ['weight_kg']);
        }
        if (class_exists(\App\Models\RecoveryLog::class)) {
            $tools[] = $this->fn('log_recovery', 'Log resting HR / HRV / subjective recovery (stress, mood, energy, soreness) for a day. Use when the wearable is not connected and the user reports these.', [
                'resting_hr' => ['type' => 'integer', 'description' => 'Resting heart rate (bpm).'],
                'hrv_ms' => ['type' => 'integer', 'description' => 'HRV / RMSSD (ms).'],
                'stress' => ['type' => 'integer', 'description' => 'Stress 1–10.'],
                'mood' => ['type' => 'integer', 'description' => 'Mood 1–10.'],
                'energy' => ['type' => 'integer', 'description' => 'Energy 1–10.'],
                'soreness' => ['type' => 'integer', 'description' => 'Soreness 1–10.'],
                'date' => ['type' => 'string', 'description' => 'Date; default today.'],
            ], []);
        }
        if (class_exists(\App\Models\SleepLog::class)) {
            $tools[] = $this->fn('log_sleep', 'Log a night of sleep.', [
                'hours' => ['type' => 'number', 'description' => 'Hours slept.'],
                'bedtime' => ['type' => 'string', 'description' => 'HH:MM (optional).'],
                'wake_time' => ['type' => 'string', 'description' => 'HH:MM (optional).'],
                'quality' => ['type' => 'integer', 'description' => 'Quality 1–100 (optional).'],
                'date' => ['type' => 'string', 'description' => 'The morning date; default today.'],
            ], ['hours']);
        }
        if (class_exists(\App\Models\BiomarkerReading::class)) {
            $tools[] = $this->fn('log_biomarker', 'Log a bloodwork / biomarker result the user tells you (the abnormal-range flag is computed automatically). For a lab photo they use the camera button.', [
                'marker' => ['type' => 'string', 'description' => 'e.g. ldl, hba1c, vitamin_d, ferritin.'],
                'value' => ['type' => 'number', 'description' => 'The measured value.'],
                'unit' => ['type' => 'string', 'description' => 'Unit (optional).'],
                'taken_at' => ['type' => 'string', 'description' => 'YYYY-MM-DD (optional).'],
            ], ['marker', 'value']);
        }
        if (class_exists(\App\Models\ActivitySession::class)) {
            $tools[] = $this->fn('log_cardio', 'Log a COMPLETED cardio session after the fact (run/walk/ride/swim/row). For a live session use start_activity/finish_activity.', [
                'type' => ['type' => 'string', 'description' => 'run | walk | cycle | swim | row | other.'],
                'duration_min' => ['type' => 'integer', 'description' => 'Duration in minutes.'],
                'distance_km' => ['type' => 'number', 'description' => 'Distance in km (optional).'],
                'avg_hr' => ['type' => 'integer', 'description' => 'Average HR (optional).'],
                'calories_kcal' => ['type' => 'integer', 'description' => 'Calories (optional).'],
                'started_at' => ['type' => 'string', 'description' => 'ISO datetime (optional).'],
            ], ['duration_min']);
        }

        $tools[] = $this->fn('set_goal', "Set the user's primary goal (e.g. \"build muscle\", \"get lean for summer\", \"longevity\").", [
            'goal' => ['type' => 'string', 'description' => 'The primary goal, in their words.'],
        ], ['goal']);

        $tools[] = $this->fn('set_reminders', "Adjust how proactive/present the coach is. Set overall intensity (minimal = just a morning briefing · balanced = briefing + meals + sleep + cycle · intense = all-day eat/train/move/stretch/sleep), and/or toggle one reminder type on/off. Use when the user wants more or less nudging, or to turn a specific reminder on/off ('stop reminding me to eat', 'remind me to stretch', 'be more on me').", [
            'intensity' => ['type' => 'string', 'enum' => ['minimal', 'balanced', 'intense'], 'description' => 'Overall coaching presence.'],
            'type' => ['type' => 'string', 'enum' => ['briefing', 'meals', 'sleep', 'cycle', 'move', 'training'], 'description' => 'A specific reminder to toggle.'],
            'on' => ['type' => 'boolean', 'description' => 'Turn the specified type on (true) or off (false).'],
        ], []);

        $tools[] = $this->fn('coaching_playbook', "Titan's deep advanced-physique knowledge — the published methods of the greats (Arnold, Mentzer, Yates, Cutler/FST-7, O'Hearn, Coleman) distilled with modern hypertrophy science. Call this whenever the user wants to PUSH hard or go advanced: programming/periodization, intensity techniques, hypertrophy volume/failure, lean-gaining or contest-lean nutrition, peak week, recovery, mindset. Pass their intent as the topic and apply the principles in your answer. Natural, evidence-based only.", [
            'topic' => ['type' => 'string', 'description' => 'What they want to go deep on, in natural language (e.g. "break a chest plateau", "program a hypertrophy block", "cut to single-digit body fat", "intensity techniques").'],
        ], ['topic']);

        if (class_exists(\App\Models\TrainingProgram::class)) {
            $tools[] = $this->fn('generate_mesocycle', "Build and SAVE a real, periodized hypertrophy mesocycle (a multi-week training program): sessions with prescribed exercises, sets, reps and RIR; volume ramps week to week then deloads; and the user's FOCUS muscles get priority — more volume, trained first/fresh, more frequency, and a lengthened-position emphasis — to bring up lagging parts. Use whenever they want a program, a plan, or to grow specific muscles.", [
                'focus' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Muscles to prioritise / bring up, e.g. ["chest","side delts","arms"]. Optional but the whole point of specialization.'],
                'days_per_week' => ['type' => 'integer', 'description' => 'Training days per week, 2–6 (default 4).'],
                'weeks' => ['type' => 'integer', 'description' => 'Mesocycle length 4–8 weeks incl. a deload (default 5).'],
                'experience' => ['type' => 'string', 'enum' => ['beginner', 'intermediate', 'advanced'], 'description' => 'Training experience (sets the volume).'],
            ], []);
            $tools[] = $this->fn('current_program', "The user's active training program + the current week's sessions, as a `program` card. Use for 'what's my program / what's my workout today / which week am I on'. Read a specific day's exercises from week_detail.", [], []);
            $tools[] = $this->fn('advance_program', 'Move the active program to the next week (call when they finish a week). Returns the new week as a `program` card.', [], []);
        }

        $tools[] = $this->fn('autoregulate', "Progress-aware autoregulation: reads the user's LOGGED training (are the lifts going up?) vs their plan and their RECOVERY, and returns a push/hold/back-off/deload nudge as an `autoreg` card. Use when they ask 'should I push or back off / how's my training going / am I recovered to train hard', before prescribing today's session, or proactively when their data shifts.", [], []);

        if (class_exists(\App\Support\Pantry::class)) {
            $tools[] = $this->fn('get_pantry', 'See the food the user currently has on hand. Read this before suggesting meals so you only suggest things they can make.', [], []);
            $tools[] = $this->fn('update_pantry', "Update the kitchen inventory when the user says what they have or bought. mode add appends, replace overwrites, remove deletes.", [
                'items' => ['type' => 'string', 'description' => 'Comma-separated items, e.g. "ground beef, eggs, tuna".'],
                'mode' => ['type' => 'string', 'enum' => ['add', 'replace', 'remove'], 'description' => 'Default add.'],
            ], ['items']);
        }

        $tools[] = $this->fn('show_trend', "Get a metric's time-series to render as a sparkline card in the chat. Use when the user asks how something has changed over time. Then emit a ```titan-card {\"type\":\"sparkline\",…}``` with the points.", [
            'metric' => ['type' => 'string', 'enum' => ['weight', 'hrv', 'resting_hr', 'sleep', 'steps', 'vo2max'], 'description' => 'Which metric to chart.'],
            'days' => ['type' => 'integer', 'description' => 'Days back (default 30).'],
        ], ['metric']);

        if (class_exists(\App\Support\BiologicalAge::class)) {
            $tools[] = $this->fn('biological_age', "The Titan-age reveal: the user's biological age vs their actual age, from bloodwork (PhenoAge), VO₂max fitness age and wearable levers. Returns a ready-made `bioage` card. Use for any 'how old is my body / biological age / Titan age / am I aging well' question.", [], []);
        }
        if (class_exists(\App\Support\AthleteScore::class)) {
            $tools[] = $this->fn('fitness_score', "The overall Athlete Score (0–100) with VO₂max as the headline, composed from cardio, recovery, strength and activity. Returns a ready-made `fitness` card. Use for any 'how fit am I / my fitness / VO₂max / rate me as an athlete' question.", [], []);
        }

        // --- Skill cards: ready-made designed components for the common questions ---
        if (class_exists(\App\Support\DailyFocus::class)) {
            $tools[] = $this->fn('daily_checkin', "The 'how am I today' card: Recovery · Strain · Sleep plus the one thing to focus on. Returns a ready-made `checkin` card — lead any daily check-in / 'how am I doing today' answer with it.", [], []);
        }
        if (class_exists(\App\Support\SleepCoach::class)) {
            $tools[] = $this->fn('sleep_detail', "Last night's sleep as a ready-made `sleep` card: hours, performance, stage breakdown, debt. Lead any 'how did I sleep' answer with it.", [], []);
        }
        if (class_exists(\App\Support\Strain::class)) {
            $tools[] = $this->fn('strain_status', "Today's cardiovascular strain as a ready-made `strain` gauge card (0–21 with the recovery-aware target zone). Lead any strain question with it.", [], []);
        }
        if (class_exists(\App\Models\BiomarkerReading::class)) {
            $tools[] = $this->fn('bloodwork_panel', "The user's latest bloodwork as a ready-made `markers` card (each marker with an in-range / flagged dot). Lead any 'show my bloodwork / labs' answer with it.", [], []);
        }
        if (class_exists(\App\Models\Meal::class)) {
            $tools[] = $this->fn('macros_today', "Today's macros — calories + protein / carbs / fat vs targets — as a ready-made `macros` card. Use whenever the user asks about their macros / calories / what's left to eat. (log_meal already shows this after logging.)", [], []);
        }

        if (class_exists(\App\Models\PhysiqueGoal::class) && class_exists(\App\Models\ProgressPhoto::class)) {
            $tools[] = $this->fn('render_dream_physique', "THE marquee feature: render the user's realistic future self from their most recent uploaded body photo (they upload via the camera button). Pass an optional description of the goal (e.g. \"+10 lb lean muscle\", \"lean and shredded\"). Returns an image URL — embed it inline as markdown so they SEE their future self. Use whenever they ask to see, create, or update their dream physique.", [
                'description' => ['type' => 'string', 'description' => 'Optional goal description for the render.'],
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
            'log_meal' => 'Logging your meal',
            'log_weight' => 'Logging your weight',
            'log_recovery' => 'Logging your recovery',
            'log_sleep' => 'Logging your sleep',
            'log_biomarker' => 'Logging your bloodwork',
            'log_cardio' => 'Logging your cardio',
            'set_goal' => 'Updating your goal',
            'set_reminders' => 'Updating your reminders',
            'coaching_playbook' => 'Consulting the playbook',
            'generate_mesocycle' => 'Building your program',
            'current_program' => 'Pulling your program',
            'advance_program' => 'Advancing your program',
            'autoregulate' => 'Reading your progress',
            'get_pantry' => 'Checking your pantry',
            'update_pantry' => 'Updating your pantry',
            'show_trend' => 'Charting your trend',
            'biological_age' => 'Calculating your biological age',
            'fitness_score' => 'Scoring your fitness',
            'daily_checkin' => 'Pulling your check-in',
            'sleep_detail' => 'Reading last night',
            'strain_status' => 'Checking your strain',
            'bloodwork_panel' => 'Pulling your bloodwork',
            'macros_today' => 'Tallying your macros',
            'render_dream_physique' => 'Rendering your future self',
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
            'log_meal' => $this->logMeal($args),
            'log_weight' => $this->logWeight($args),
            'log_recovery' => $this->logRecovery($args),
            'log_sleep' => $this->logSleep($args),
            'log_biomarker' => $this->logBiomarker($args),
            'log_cardio' => $this->logCardio($args),
            'set_goal' => $this->setGoal($args),
            'set_reminders' => $this->setReminders($args),
            'coaching_playbook' =>\App\Support\TrainingPlaybook::lookup((string) ($args['topic'] ?? '')) + ['_show' => 'Apply these principles in YOUR voice, tailored to this user\'s data, goal and level — don\'t just paste them. Be specific and prescriptive (sets, reps, RIR, calories, weeks). Honour the natural-only rail: never prescribe or advise PEDs/SARMs/diuretics/insulin.'],
            'generate_mesocycle' => $this->generateMesocycle($args),
            'current_program' => $this->currentProgram(),
            'advance_program' => $this->advanceProgram(),
            'autoregulate' => $this->autoregulate(),
            'get_pantry' => $this->getPantry(),
            'update_pantry' => $this->updatePantry($args),
            'show_trend' => $this->showTrend($args),
            'biological_age' => $this->biologicalAge(),
            'fitness_score' => $this->fitnessScore(),
            'daily_checkin' => $this->dailyCheckin(),
            'sleep_detail' => $this->sleepDetail(),
            'strain_status' => $this->strainStatus(),
            'bloodwork_panel' => $this->bloodworkPanel(),
            'macros_today' => ['card' => $this->macrosCard(), '_show' => 'Emit this `macros` card inside a ```titan-card fence, then a one-line read of where they are vs targets.'],
            'render_dream_physique' => $this->renderDreamPhysique($args),
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

        // A live-session card with today's recovery-aware strain target.
        $card = ['type' => 'workout', 'name' => $workout->name];
        if (class_exists(\App\Support\Strain::class)) {
            $readiness = rescue(fn () => \App\Support\Readiness::compute($this->profile)['score'] ?? null, null, false);
            $target = \App\Support\Strain::targetFor($readiness !== null ? (float) $readiness : null);
            $card += ['mode' => $target['label'] ?? null, 'target_low' => $target['low'] ?? null, 'target_high' => $target['high'] ?? null];
        }

        return [
            'ok' => true,
            'workout_id' => $workout->id,
            'name' => $workout->name,
            'card' => $card,
            '_show' => 'Show this `workout` card (inside a ```titan-card fence) to confirm the session is live, then invite them to call out their sets.',
            'message' => "Started \"{$workout->name}\" — call out your sets and I'll log them.",
        ];
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

    // ---- Logging & data entry (the chat as the control surface) ---------------

    private function logMeal(array $a): mixed
    {
        $name = trim((string) ($a['name'] ?? ''));
        if ($name === '') {
            return ['error' => 'A meal name is required.'];
        }
        $meal = $this->profile->meals()->create([
            'name' => \Illuminate\Support\Str::limit($name, 80, ''),
            'eaten_at' => isset($a['eaten_at']) ? rescue(fn () => Carbon::parse($a['eaten_at']), Carbon::now(), false) : Carbon::now(),
            'calories' => (int) round((float) ($a['calories'] ?? 0)),
            'protein_g' => round((float) ($a['protein_g'] ?? 0), 1),
            'carbs_g' => round((float) ($a['carbs_g'] ?? 0), 1),
            'fat_g' => round((float) ($a['fat_g'] ?? 0), 1),
            'source' => 'coach',
        ]);

        return [
            'ok' => true, 'name' => $meal->name, 'calories' => $meal->calories, 'protein_g' => $meal->protein_g,
            'card' => $this->macrosCard(),
            '_show' => "Logged it — show the updated `macros` card (inside a ```titan-card fence), then one short line on what's left to hit their targets.",
            'message' => "Logged {$meal->name} — {$meal->calories} kcal, {$meal->protein_g}g protein.",
        ];
    }

    /** Today's macros card (calories + protein/carbs/fat vs targets) — shared with the meal scan. */
    private function macrosCard(): array
    {
        return \App\Support\Macros::today($this->profile);
    }

    private function logWeight(array $a): mixed
    {
        $w = (float) ($a['weight_kg'] ?? 0);
        if ($w <= 0) {
            return ['error' => 'weight_kg is required.'];
        }
        $this->profile->bodyMetrics()->create(array_filter([
            'taken_at' => $this->cycleDate($a['date'] ?? null)->toDateString(),
            'weight_kg' => round($w, 2),
            'body_fat_pct' => isset($a['body_fat_pct']) ? round((float) $a['body_fat_pct'], 1) : null,
        ], fn ($v) => $v !== null));

        return ['ok' => true, 'weight_kg' => round($w, 2), 'message' => "Logged your weight: {$w} kg."];
    }

    private function logRecovery(array $a): mixed
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
            return ['error' => 'Provide at least one of resting_hr, hrv_ms, stress, mood, energy, soreness.'];
        }
        $log = $this->profile->recoveryLogs()->updateOrCreate(
            ['logged_at' => $this->cycleDate($a['date'] ?? null)->toDateString()],
            $fields + ['updated_via' => 'coach'],
        );

        return ['ok' => true, 'logged_at' => $log->logged_at->toDateString(), 'message' => 'Recovery logged.'];
    }

    private function logSleep(array $a): mixed
    {
        $hours = (float) ($a['hours'] ?? 0);
        if ($hours <= 0) {
            return ['error' => 'hours is required and must be > 0.'];
        }
        $log = $this->profile->sleepLogs()->updateOrCreate(
            ['slept_at' => $this->cycleDate($a['date'] ?? null)->toDateString()],
            array_filter([
                'duration_min' => (int) round($hours * 60),
                'bedtime' => $a['bedtime'] ?? null,
                'wake_time' => $a['wake_time'] ?? null,
                'quality' => isset($a['quality']) ? (int) $a['quality'] : null,
                'updated_via' => 'coach',
            ], fn ($v) => $v !== null),
        );

        return ['ok' => true, 'hours' => round($log->duration_min / 60, 1), 'message' => 'Sleep logged: '.round($hours, 1).' h.'];
    }

    private function logBiomarker(array $a): mixed
    {
        $marker = trim((string) ($a['marker'] ?? ''));
        if ($marker === '' || ! isset($a['value'])) {
            return ['error' => 'marker and value are required.'];
        }
        $r = $this->profile->biomarkerReadings()->create([
            'marker' => \Illuminate\Support\Str::of($marker)->lower()->trim()->replace(' ', '_')->value(),
            'value' => (float) $a['value'],
            'unit' => $a['unit'] ?? null,
            'taken_at' => $this->cycleDate($a['taken_at'] ?? null)->toDateString(),
            'source' => 'coach',
        ]);

        return ['ok' => true, 'marker' => $r->marker, 'value' => (float) $r->value, 'flag' => $r->flag,
            'message' => "Logged {$r->marker} = {$r->value}".($r->flag && $r->flag !== 'normal' ? " ({$r->flag})" : '')];
    }

    private function logCardio(array $a): mixed
    {
        $dur = (int) ($a['duration_min'] ?? 0);
        if ($dur <= 0) {
            return ['error' => 'duration_min is required.'];
        }
        $start = isset($a['started_at']) ? rescue(fn () => Carbon::parse($a['started_at']), Carbon::now()->subMinutes($dur), false) : Carbon::now()->subMinutes($dur);
        $session = $this->profile->activitySessions()->updateOrCreate(
            ['started_at' => $start],
            array_filter([
                'source' => 'coach',
                'ended_at' => $start->copy()->addMinutes($dur),
                'duration_min' => $dur,
                'activity_type' => $a['type'] ?? 'other',
                'distance_km' => isset($a['distance_km']) ? (float) $a['distance_km'] : null,
                'avg_hr' => isset($a['avg_hr']) ? (int) $a['avg_hr'] : null,
                'calories_kcal' => isset($a['calories_kcal']) ? (int) $a['calories_kcal'] : null,
                'updated_via' => 'coach',
            ], fn ($v) => $v !== null),
        );

        return ['ok' => true, 'type' => $session->activity_type, 'duration_min' => $dur, 'message' => "Logged a {$dur}-min {$session->activity_type}."];
    }

    private function setReminders(array $a): mixed
    {
        if (! empty($a['intensity'])) {
            \App\Support\Reminders::setIntensity($this->profile, (string) $a['intensity']);
        }
        if (! empty($a['type']) && array_key_exists('on', $a)) {
            \App\Support\Reminders::setType($this->profile, (string) $a['type'], (bool) $a['on']);
        }
        $this->profile->refresh();
        $s = \App\Support\Reminders::summary($this->profile);

        return [
            'ok' => true,
            'intensity' => $s['intensity'],
            'reminders' => $s['types'],
            'message' => 'Updated how I check in with you.',
        ];
    }

    private function setGoal(array $a): mixed
    {
        $goal = trim((string) ($a['goal'] ?? ''));
        if ($goal === '') {
            return ['error' => 'goal is required.'];
        }
        $this->profile->forceFill(['primary_goal' => \Illuminate\Support\Str::limit($goal, 120, '')])->save();

        return ['ok' => true, 'primary_goal' => $this->profile->primary_goal, 'message' => "Your goal is now: {$this->profile->primary_goal}."];
    }

    private function getPantry(): mixed
    {
        $items = \App\Support\Pantry::get($this->profile);

        return [
            'items' => $items,
            'count' => count($items),
            'note' => $items === [] ? 'Empty — ask what they have, then update_pantry.' : 'Only suggest meals they can make from these.',
        ];
    }

    private function updatePantry(array $a): mixed
    {
        $items = $a['items'] ?? null;
        if (! is_array($items) && ! is_string($items)) {
            return ['error' => 'Provide items as a comma-separated string or an array.'];
        }
        $mode = strtolower((string) ($a['mode'] ?? 'add'));
        $result = match ($mode) {
            'replace' => \App\Support\Pantry::set($this->profile, \App\Support\Pantry::parse($items)),
            'remove' => \App\Support\Pantry::remove($this->profile, $items),
            default => \App\Support\Pantry::add($this->profile, $items),
        };

        return ['ok' => true, 'mode' => $mode, 'items' => $result, 'count' => count($result), 'message' => 'Pantry updated.'];
    }

    private function showTrend(array $a): mixed
    {
        $metric = strtolower(trim((string) ($a['metric'] ?? 'weight')));
        $days = max(7, min(180, (int) ($a['days'] ?? 30)));
        $since = Carbon::today()->subDays($days)->toDateString();

        [$points, $unit, $label] = match ($metric) {
            'weight' => [$this->trendSeries($this->profile->bodyMetrics(), 'taken_at', 'weight_kg', $since), 'kg', 'Weight'],
            'hrv' => [$this->trendSeries($this->profile->recoveryLogs(), 'logged_at', 'hrv_ms', $since), 'ms', 'HRV'],
            'resting_hr', 'rhr' => [$this->trendSeries($this->profile->recoveryLogs(), 'logged_at', 'resting_hr', $since), 'bpm', 'Resting HR'],
            'steps' => [$this->trendSeries($this->profile->dailyActivity(), 'date', 'steps', $since), '', 'Steps'],
            'vo2max' => [$this->trendSeries($this->profile->activitySessions(), 'started_at', 'vo2max', $since), '', 'VO₂max'],
            'sleep' => [$this->profile->sleepLogs()->whereDate('slept_at', '>=', $since)->whereNotNull('duration_min')->orderBy('slept_at')->pluck('duration_min')->map(fn ($m) => round($m / 60, 1))->filter(fn ($v) => $v > 0)->values()->all(), 'h', 'Sleep'],
            default => [[], '', ucfirst($metric)],
        };

        if (count($points) < 2) {
            return ['note' => "Not enough {$label} data yet to chart — needs at least 2 points. Keep logging and it'll fill in."];
        }

        return [
            'metric' => $metric, 'label' => $label, 'unit' => $unit, 'points' => $points,
            'first' => $points[0], 'last' => $points[count($points) - 1], 'n' => count($points),
            '_show' => 'Render this inline as a sparkline card: ```titan-card {"type":"sparkline","label":"'.$label.($unit ? ' ('.$unit.')' : '').'","unit":"'.$unit.'","points":['.implode(',', $points).']}``` then add one short sentence reading the trend.',
        ];
    }

    /** @param  \Illuminate\Database\Eloquent\Relations\HasMany  $rel */
    private function trendSeries($rel, string $dateCol, string $valCol, string $since): array
    {
        return $rel->whereDate($dateCol, '>=', $since)->whereNotNull($valCol)
            ->orderBy($dateCol)->pluck($valCol)
            ->map(fn ($v) => (float) $v)->filter(fn ($v) => $v > 0)->values()->all();
    }

    // ---- Skill cards: ready-made designed components --------------------------

    private function dailyCheckin(): mixed
    {
        $r = rescue(fn () => \App\Support\Readiness::compute($this->profile), [], false);
        $strain = rescue(fn () => \App\Support\Strain::assess($this->profile), [], false);
        $sleep = rescue(fn () => \App\Support\SleepCoach::assess($this->profile), null, false);
        $focus = rescue(fn () => \App\Support\DailyFocus::compute($this->profile), [], false);
        $lastSleep = $this->profile->sleepLogs()->orderByDesc('slept_at')->orderByDesc('id')->first();

        $card = [
            'type' => 'checkin',
            'date' => 'Today',
            'recovery' => ['value' => $r['score'] ?? null, 'label' => $r['label'] ?? ''],
            'strain' => [
                'value' => isset($strain['strain']) ? round($strain['strain'], 1) : null,
                'target' => $strain['target']['high'] ?? null,
                'label' => $strain['target']['label'] ?? '',
            ],
            'sleep' => [
                'pct' => $sleep['performance_pct'] ?? null,
                'hours' => $lastSleep?->duration_min ? round($lastSleep->duration_min / 60, 1) : null,
            ],
            'focus' => ['headline' => $focus['headline'] ?? null, 'detail' => $focus['detail'] ?? null],
        ];

        return ['card' => $card, '_show' => 'Open your reply with this `checkin` card (emit it inside a ```titan-card fence), then one short line on the single thing to do today.'];
    }

    private function sleepDetail(): mixed
    {
        if (! class_exists(\App\Models\SleepLog::class)) {
            return ['error' => 'Sleep is not available.'];
        }
        $last = $this->profile->sleepLogs()->orderByDesc('slept_at')->orderByDesc('id')->first();
        if (! $last) {
            return ['note' => 'No nights logged yet. Connect the band or tell me how you slept and I\'ll start tracking it.'];
        }
        $coach = rescue(fn () => \App\Support\SleepCoach::assess($this->profile), null, false);

        $card = [
            'type' => 'sleep',
            'hours' => round($last->duration_min / 60, 1),
            'performance' => $coach['performance_pct'] ?? null,
            'debt' => isset($coach['debt_h']) ? round($coach['debt_h'], 1) : null,
            'status' => $coach['label'] ?? null,
            'stages' => array_filter([
                'deep' => $last->deep_min, 'rem' => $last->rem_min,
                'light' => $last->light_min, 'awake' => $last->awake_min,
            ], fn ($v) => $v !== null),
        ];

        return ['card' => $card, '_show' => 'Open with this `sleep` card inside a ```titan-card fence, then one short read of the night.'];
    }

    private function strainStatus(): mixed
    {
        if (! class_exists(\App\Support\Strain::class)) {
            return ['error' => 'Strain is not available.'];
        }
        $s = \App\Support\Strain::assess($this->profile);
        $card = [
            'type' => 'strain',
            'value' => round($s['strain'] ?? 0, 1),
            'band' => $s['label'] ?? '',
            'target_low' => $s['target']['low'] ?? null,
            'target_high' => $s['target']['high'] ?? null,
            'advice' => $s['advice'] ?? null,
        ];

        return ['card' => $card, '_show' => 'Open with this `strain` gauge card inside a ```titan-card fence, then a one-line read vs the target.'];
    }

    private function bloodworkPanel(): mixed
    {
        if (! class_exists(\App\Models\BiomarkerReading::class)) {
            return ['error' => 'Bloodwork is not available.'];
        }
        $readings = $this->profile->biomarkerReadings()->orderByDesc('taken_at')->orderByDesc('id')->get();
        if ($readings->isEmpty()) {
            return ['note' => 'No bloodwork logged yet. Snap a photo of a labs report with the camera button and I\'ll read it in.'];
        }
        $seen = [];
        $items = [];
        $flagged = 0;
        foreach ($readings as $r) {
            if (isset($seen[$r->marker])) {
                continue;
            }
            $seen[$r->marker] = true;
            $label = class_exists(\App\Support\Biomarkers::class)
                ? \App\Support\Biomarkers::label($r->marker)
                : strtoupper(str_replace('_', ' ', $r->marker));
            $val = rtrim(rtrim(number_format((float) $r->value, 2), '0'), '.').($r->unit ? ' '.$r->unit : '');
            $flag = $r->flag ?: 'normal';
            if (! in_array($flag, ['normal', 'optimal'], true)) {
                $flagged++;
            }
            $items[] = ['label' => $label, 'value' => $val, 'flag' => $flag];
            if (count($items) >= 12) {
                break;
            }
        }
        $card = [
            'type' => 'markers',
            'title' => 'Latest bloodwork',
            'items' => $items,
            'caption' => $flagged === 0 ? 'All in range.' : $flagged.' marker'.($flagged === 1 ? '' : 's').' outside range — not a diagnosis; flag with your doctor.',
        ];

        return ['card' => $card, '_show' => 'Open with this `markers` card inside a ```titan-card fence, then briefly explain any flagged marker. Never diagnose; suggest a doctor for anything concerning.'];
    }

    // ---- Mesocycle generator — a real, followable program --------------------

    private function generateMesocycle(array $args): mixed
    {
        if (! class_exists(\App\Models\TrainingProgram::class)) {
            return ['error' => 'The program builder is not available.'];
        }
        $exp = $args['experience'] ?? (($this->profile->settings['activity_level'] ?? null) === 'active' ? 'advanced' : 'intermediate');
        $build = \App\Support\MesocycleGenerator::build([
            'focus' => $args['focus'] ?? [],
            'days_per_week' => (int) ($args['days_per_week'] ?? 4),
            'weeks' => (int) ($args['weeks'] ?? 5),
            'experience' => $exp,
        ]);

        $this->profile->trainingPrograms()->where('is_active', true)->update(['is_active' => false]);
        $program = $this->profile->trainingPrograms()->create([
            'name' => $build['name'],
            'focus' => $build['focus'],
            'days_per_week' => $build['days_per_week'],
            'weeks' => $build['weeks'],
            'split' => $build['split'],
            'experience' => $build['experience'],
            'plan' => $build['plan'],
            'volume' => $build['volume'],
            'started_on' => Carbon::now()->toDateString(),
            'current_week' => 1,
            'is_active' => true,
        ]);

        return [
            'ok' => true,
            'program_id' => $program->id,
            'name' => $program->name,
            'card' => $this->programCard($program),
            'week1_detail' => $this->weekDetail($program->currentWeek()),
            '_show' => 'Lead with the `program` card. Then explain in your voice: which muscles you prioritised and WHY (more volume, trained first/fresh, more frequency, stretch emphasis — that\'s how a lagging muscle catches up), how the volume ramps to the peak week then deloads, and the RIR targets. Offer to walk them through Day 1 right now. When they train, log sets with log_set so we track progress against the plan.',
        ];
    }

    private function currentProgram(): mixed
    {
        $program = class_exists(\App\Models\TrainingProgram::class)
            ? $this->profile->trainingPrograms()->where('is_active', true)->latest('id')->first()
            : null;
        if (! $program) {
            return ['note' => "No active program yet. Want me to build you a mesocycle? Tell me which muscles to bring up and how many days a week you can train, and I'll generate a real periodized plan."];
        }

        return [
            'ok' => true,
            'name' => $program->name,
            'week' => $program->current_week,
            'of' => $program->weeks,
            'phase' => $program->currentWeek()['phase'] ?? '',
            'card' => $this->programCard($program),
            'week_detail' => $this->weekDetail($program->currentWeek()),
            '_show' => 'Lead with the `program` card. If they ask for a specific day ("what\'s my workout today / chest day"), read that day\'s exercises from week_detail as a clean list — exercise · sets×reps @RIR — and tell them to call out sets so you log them.',
        ];
    }

    private function advanceProgram(): mixed
    {
        $program = class_exists(\App\Models\TrainingProgram::class)
            ? $this->profile->trainingPrograms()->where('is_active', true)->latest('id')->first()
            : null;
        if (! $program) {
            return ['error' => 'No active program to advance.'];
        }
        if ($program->current_week >= $program->weeks) {
            return ['ok' => true, 'done' => true, 'message' => "That was the final (deload) week — the block is complete. Want me to build the next mesocycle? We can push the focus muscles further or rotate the emphasis."];
        }
        $program->update(['current_week' => $program->current_week + 1]);
        $week = $program->currentWeek();

        return [
            'ok' => true,
            'week' => $program->current_week,
            'phase' => $week['phase'] ?? '',
            'card' => $this->programCard($program),
            'week_detail' => $this->weekDetail($week),
            '_show' => 'Lead with the `program` card for the new week, then say what changed (more volume / tighter RIR, or — if deload — back off and recover). Read out the first session if they want it.',
        ];
    }

    private function autoregulate(): mixed
    {
        $a = \App\Support\Autoregulator::assess($this->profile);

        if ($a['verdict'] === 'insufficient') {
            return ['note' => $a['adjustment']['note'] ?? "Not enough training/recovery data to autoregulate yet."];
        }

        $card = [
            'type' => 'autoreg',
            'verdict' => $a['verdict'],
            'headline' => $a['headline'],
            'signals' => $a['signals'],
            'adjustment' => $a['adjustment'],
        ];

        return [
            'verdict' => $a['verdict'],
            'program' => $a['program'],
            'card' => $card,
            '_show' => 'Lead with the `autoreg` card, then coach TODAY\'s session to match the verdict: progress → add the set and push; hold → beat the logbook; back_off → hold volume, add a rep of RIR, drop intensity techniques; deload → cut volume hard and (if on a program) offer advance_program into the deload. Never push a poorly-recovered athlete to failure. Be specific to their focus muscles and lifts.',
        ];
    }

    /** Build the `program` skill-card payload for a program's current week. */
    private function programCard(\App\Models\TrainingProgram $program): array
    {
        $labels = \App\Support\MesocycleGenerator::muscleLabels();
        $week = $program->currentWeek() ?? [];

        $ramp = [];
        foreach (($program->focus ?? []) as $m) {
            if (! empty($program->volume[$m])) {
                $ramp[] = ['muscle' => $labels[$m] ?? ucfirst($m), 'sets' => array_map('intval', $program->volume[$m])];
            }
        }

        $days = [];
        foreach (($week['days'] ?? []) as $d) {
            $muscles = [];
            $sets = 0;
            foreach ($d['exercises'] as $e) {
                $muscles[$e['muscle_label']] = true;
                $sets += (int) $e['sets'];
            }
            $days[] = ['name' => $d['name'], 'summary' => implode(' · ', array_keys($muscles)), 'sets' => $sets];
        }

        return [
            'type' => 'program',
            'name' => $program->name,
            'focus' => array_map(fn ($m) => $labels[$m] ?? ucfirst($m), $program->focus ?? []),
            'days_per_week' => $program->days_per_week,
            'weeks' => $program->weeks,
            'week' => $program->current_week,
            'phase' => $week['phase'] ?? '',
            'ramp' => $ramp,
            'days' => $days,
        ];
    }

    /** A readable per-day exercise list for the current week (so the coach can read out any session). */
    private function weekDetail(?array $week): array
    {
        $out = [];
        foreach (($week['days'] ?? []) as $d) {
            $lines = [];
            foreach ($d['exercises'] as $e) {
                $lines[] = "{$e['name']} — {$e['sets']}×{$e['reps']} @{$e['rir']}RIR".(isset($e['note']) ? " ({$e['note']})" : '');
            }
            $out[$d['name']] = $lines;
        }

        return $out;
    }

    private function fitnessScore(): mixed
    {
        if (! class_exists(\App\Support\AthleteScore::class)) {
            return ['error' => 'Fitness scoring is not available.'];
        }
        $a = \App\Support\AthleteScore::assess($this->profile);
        if (! $a) {
            return ['note' => "I can't score your fitness yet — it needs at least a VO₂max (from a wearable cardio session) or a couple of fitness signals (recovery, training, steps). Connect the band or log a run/lift and I'll have it."];
        }

        $card = [
            'type' => 'fitness',
            'score' => $a['score'],
            'grade' => $a['grade'],
            'vo2max' => $a['vo2max'],
            'fitness_age' => $a['fitness_age'],
            'chrono_age' => $a['chrono_age'] !== null ? (int) round($a['chrono_age']) : null,
            'pillars' => array_map(fn ($p) => ['label' => $p['label'], 'score' => $p['score'], 'detail' => $p['detail']], $a['pillars']),
            'caption' => $a['label'].($a['confidence'] !== 'high' ? ' · '.$a['confidence'].' confidence' : ''),
        ];

        return [
            'score' => $a['score'],
            'grade' => $a['grade'],
            'vo2max' => $a['vo2max'],
            'confidence' => $a['confidence'],
            'card' => $card,
            '_show' => 'Open your reply with this `fitness` card (emit the `card` object inside a ```titan-card fence), then a short read: name their strongest pillar and the one to work on. If confidence is not "high", note that a VO₂max session (or more logged data) would sharpen it.',
        ];
    }

    // ---- Biological age — the Whoop-style "skill" card ------------------------

    private function biologicalAge(): mixed
    {
        if (! class_exists(\App\Support\BiologicalAge::class)) {
            return ['error' => 'Biological age is not available.'];
        }
        $b = \App\Support\BiologicalAge::assess($this->profile);
        if (! $b) {
            return ['note' => "I can't compute your biological age yet — it needs an anchor: either bloodwork (the PhenoAge clock — snap a labs photo), a VO₂max estimate (from a wearable cardio session), or a couple of weeks of wearable data. Add one of those and I'll have it."];
        }

        $chrono = $b['chronological_age'];
        $anchors = [];
        $levers = [];
        foreach ($b['components'] as $c) {
            if (($c['kind'] ?? null) === 'anchor') {
                $anchors[] = [
                    'label' => $c['label'],
                    'value' => round($c['value']).' yrs',
                    'good' => $c['value'] < $chrono,
                ];
            } else {
                $yr = (float) ($c['years'] ?? 0);
                $levers[] = [
                    'label' => $c['label'],
                    'years' => $yr,
                    'value' => ($yr <= 0 ? '−' : '+').number_format(abs($yr), 1).' yr',
                    'good' => $yr <= 0,
                ];
            }
        }
        usort($levers, fn ($x, $y) => abs($y['years']) <=> abs($x['years']));
        $drivers = array_merge(
            $anchors,
            array_map(fn ($l) => ['label' => $l['label'], 'value' => $l['value'], 'good' => $l['good']], array_slice($levers, 0, 3)),
        );

        $card = [
            'type' => 'bioage',
            'bio_age' => $b['biological_age'],
            'chrono_age' => $chrono,
            'drivers' => $drivers,
            'caption' => $b['label'].($b['confidence'] !== 'high' ? ' · '.$b['confidence'].' confidence' : ''),
        ];

        return [
            'biological_age' => $b['biological_age'],
            'chronological_age' => $chrono,
            'delta' => $b['delta'],
            'confidence' => $b['confidence'],
            'missing_for_bloodwork' => $b['missing_for_bloodwork'] ?? [],
            'card' => $card,
            '_show' => 'OPEN your reply with this card, emitting the `card` object as minified JSON inside a ```titan-card fence. Then one short, motivating sentence on what it means and the single biggest lever to improve it. If confidence is not "high", briefly note what would sharpen it (e.g. uploading the missing bloodwork, or a VO₂max session).',
        ];
    }

    // ---- Dream physique — the marquee, rendered right in the chat --------------

    private function renderDreamPhysique(array $a): mixed
    {
        if (! class_exists(\App\Models\PhysiqueGoal::class) || ! class_exists(\App\Models\ProgressPhoto::class)) {
            return ['error' => 'The physique module is not available.'];
        }
        $nano = app(\App\Services\Ai\NanoBananaClient::class);
        if (! $nano->configured()) {
            return ['error' => 'Image generation is not configured yet (no image API key).'];
        }
        $photo = $this->profile->progressPhotos()->latest('taken_at')->latest('id')->first();
        if (! $photo || ! $photo->photo_path) {
            return ['error' => 'No photo of you yet. Tap the camera button and upload a photo of yourself, then ask me to render your dream physique.'];
        }

        $description = trim((string) ($a['description'] ?? '')) ?: '+10 lb of lean muscle, leaner and more athletic';
        $prompt = <<<PROMPT
        Take this person's photo and render them as their realistic future self after a dedicated period of
        training and nutrition: {$description}. Keep their exact face, identity, skin tone, hair, body
        proportions, pose, lighting and background unchanged — this must look unmistakably like the SAME
        person, just fitter. Photorealistic, natural and believable — not an exaggerated bodybuilder, not a
        fantasy filter.
        PROMPT;

        try {
            $input = $nano->imageFromDisk($photo->photo_path);
            $generated = $nano->generateToDisk($prompt, 'physique/goal', [$input]);
        } catch (\Throwable $e) {
            return ['error' => 'Could not render the image right now: '.$e->getMessage()];
        }

        $this->profile->physiqueGoals()->update(['is_active' => false]);
        $goal = $this->profile->physiqueGoals()->create([
            'source_photo_path' => $photo->photo_path,
            'goal_image_path' => $generated['path'],
            'prompt' => $prompt,
            'description' => $description,
            'is_active' => true,
        ]);

        return [
            'ok' => true,
            'description' => $description,
            'future_self_image_url' => $goal->goalUrl(),
            'now_image_url' => $photo->photoUrl(),
            '_show' => 'Embed future_self_image_url inline as markdown ![your future self]('.($goal->goalUrl() ?? 'url').') so they SEE it. Celebrate it warmly and tell them this is where consistency takes them — it advances toward this as they stay on track.',
            'message' => 'Rendered your dream physique.',
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
