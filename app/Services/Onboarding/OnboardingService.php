<?php

namespace App\Services\Onboarding;

use App\Models\KnowledgePage;
use App\Models\Profile;
use App\Models\User;
use App\Support\Cycle;
use App\Support\MacroTargets;
use Illuminate\Support\Carbon;

/**
 * The onboarding brain — shared by the web wizard (OnboardingController) and the native app
 * (MobileOnboardingController). Turns the validated intake into a real Titan profile: personalized
 * macro targets, meal plan, cycle config, a starting weight, and the coach's pinned core memory.
 */
class OnboardingService
{
    public const GOALS = [
        'build_muscle' => 'Build muscle',
        'lose_fat' => 'Lose fat / get lean',
        'recomp' => 'Recomposition (lean + strong)',
        'longevity' => 'Longevity & healthspan',
        'performance' => 'Athletic performance',
        'general' => 'General health & energy',
    ];

    /** @return array<string,mixed> validation rules (shared by web + mobile). */
    public static function rules(): array
    {
        return [
            'display_name' => ['required', 'string', 'max:60'],
            'birthdate' => ['required', 'date', 'before:today', 'after:1900-01-01'],
            'sex' => ['required', 'in:F,M,other'],
            'units' => ['required', 'in:metric,imperial'],
            'height' => ['required', 'numeric', 'min:1', 'max:300'],
            'weight' => ['required', 'numeric', 'min:1', 'max:600'],
            'activity_level' => ['required', 'in:sedentary,light,moderate,active'],
            'primary_goal' => ['required', 'in:'.implode(',', array_keys(self::GOALS))],
            'coach_tone' => ['required', 'in:tough_love,balanced,gentle'],
            'coaching_intensity' => ['nullable', 'in:minimal,balanced,intense'],
            'meals_per_day' => ['required', 'integer', 'min:2', 'max:6'],
            'eat_start' => ['nullable', 'date_format:H:i'],
            'eat_end' => ['nullable', 'date_format:H:i'],
            'timezone' => ['nullable', 'timezone'],
            'cycle_enabled' => ['nullable', 'boolean'],
            'last_period' => ['nullable', 'date', 'before_or_equal:today'],
            'cycle_length' => ['nullable', 'integer', 'min:21', 'max:45'],
            'birth_control' => ['nullable', 'in:none,pill,patch,ring,hormonal_iud,copper_iud,implant,injection,other'],
            'cycle_intent' => ['nullable', 'in:tracking,conceiving,avoiding'],
            'injuries' => ['nullable', 'string', 'max:400'],
            'health_notes' => ['nullable', 'string', 'max:400'],
            'experience' => ['nullable', 'in:beginner,intermediate,advanced'],
            'train_at' => ['nullable', 'in:full_gym,home_weights,bodyweight,mix'],
            'train_days' => ['nullable', 'integer', 'min:0', 'max:7'],
            'diet' => ['nullable', 'in:omnivore,vegetarian,vegan,pescatarian,keto,halal'],
            'allergies' => ['nullable', 'string', 'max:200'],
            'avoid_foods' => ['nullable', 'string', 'max:200'],
            'motivation' => ['nullable', 'string', 'max:400'],
            'event_date' => ['nullable', 'date', 'after_or_equal:today'],
            'focus_areas' => ['nullable', 'string', 'max:400'],
            'has_wearable' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Apply validated onboarding data to a profile.
     *
     * @param  array<string,mixed>  $data
     * @return array{route:string,name:string}  where to send them next: 'pairing' | 'coach'
     */
    public function apply(User $user, Profile $profile, array $data): array
    {
        $injuries = array_values(array_filter(array_map('trim', explode('|', $data['injuries'] ?? ''))));
        $focusAreas = array_values(array_filter(array_map('trim', explode('|', $data['focus_areas'] ?? ''))));

        $imperial = $data['units'] === 'imperial';
        $heightCm = $imperial ? round($data['height'] * 2.54, 1) : (float) $data['height'];
        $weightKg = $imperial ? round($data['weight'] * 0.45359237, 2) : (float) $data['weight'];
        $female = $data['sex'] === 'F';
        $cycleEnabled = $female && filter_var($data['cycle_enabled'] ?? false, FILTER_VALIDATE_BOOL);
        $hasWearable = filter_var($data['has_wearable'] ?? false, FILTER_VALIDATE_BOOL);

        $settings = $profile->settings ?? [];
        $settings['units'] = $data['units'];
        $settings['timezone'] = $data['timezone'] ?? ($settings['timezone'] ?? config('app.timezone', 'UTC'));
        $settings['activity_level'] = $data['activity_level'];
        $settings['coaching_intensity'] = $data['coaching_intensity'] ?? 'balanced';
        $settings['meal_plan'] = [
            'meals' => (int) $data['meals_per_day'],
            'start' => ($data['eat_start'] ?? null) ?: '08:00',
            'end' => ($data['eat_end'] ?? null) ?: '21:00',
        ];
        $settings['macro_targets'] = $this->macros($weightKg, $heightCm, Carbon::parse($data['birthdate'])->age, $female, $data['activity_level'], $data['primary_goal']);

        $settings['intake'] = [
            'experience' => $data['experience'] ?? null,
            'train_at' => $data['train_at'] ?? null,
            'train_days' => (int) ($data['train_days'] ?? 0),
            'diet' => $data['diet'] ?? null,
            'allergies' => trim((string) ($data['allergies'] ?? '')) ?: null,
            'avoid_foods' => trim((string) ($data['avoid_foods'] ?? '')) ?: null,
            'injuries' => $injuries,
            'health_notes' => trim((string) ($data['health_notes'] ?? '')) ?: null,
            'focus_areas' => $focusAreas,
            'motivation' => trim((string) ($data['motivation'] ?? '')) ?: null,
            'event_date' => $data['event_date'] ?? null,
        ];

        if ($cycleEnabled) {
            $settings['cycle'] = array_merge($settings['cycle'] ?? [], [
                'enabled' => true,
                'avg_length' => $data['cycle_length'] ?? Cycle::DEFAULT_LENGTH,
                'avg_period' => $settings['cycle']['avg_period'] ?? Cycle::DEFAULT_PERIOD,
                'luteal_length' => $settings['cycle']['luteal_length'] ?? Cycle::DEFAULT_LUTEAL,
                'birth_control' => $data['birth_control'] ?? 'none',
                'intent' => $data['cycle_intent'] ?? 'tracking',
            ]);
        }

        $profile->update([
            'display_name' => $data['display_name'],
            'birthdate' => $data['birthdate'],
            'sex' => $data['sex'],
            'height_cm' => $heightCm,
            'primary_goal' => self::GOALS[$data['primary_goal']],
            'coach_tone' => $data['coach_tone'],
            'settings' => $settings,
            'onboarded_at' => now(),
        ]);

        if (method_exists($profile, 'bodyMetrics')) {
            $profile->bodyMetrics()->create(['taken_at' => Carbon::today(), 'weight_kg' => $weightKg]);
        }

        $this->seedCoreMemory($user, $profile, [
            'name' => $data['display_name'],
            'goal' => self::GOALS[$data['primary_goal']],
            'focus_areas' => $focusAreas,
            'injuries' => $injuries,
            'data' => $data,
        ]);

        if ($cycleEnabled && ! empty($data['last_period'])) {
            Cycle::startPeriod($profile, Carbon::parse($data['last_period']));
        }

        return ['route' => $hasWearable ? 'pairing' : 'coach', 'name' => $data['display_name']];
    }

    /** Partial-edit rules (every field optional) — for changing profile info after onboarding. */
    public static function partialRules(): array
    {
        $rules = [];
        foreach (self::rules() as $field => $constraints) {
            $rules[$field] = array_values(array_map(fn ($c) => $c === 'required' ? 'sometimes' : $c, $constraints));
            if (! in_array('sometimes', $rules[$field], true) && ! in_array('nullable', $rules[$field], true)) {
                array_unshift($rules[$field], 'sometimes');
            }
        }

        return $rules;
    }

    /** The user's editable profile as a flat payload (for pre-filling the edit form). */
    public static function snapshot(Profile $profile): array
    {
        $s = $profile->settings ?? [];
        $intake = $s['intake'] ?? [];
        $cycle = $s['cycle'] ?? [];
        $units = $s['units'] ?? 'metric';
        $imperial = $units === 'imperial';
        $heightCm = $profile->height_cm ? (float) $profile->height_cm : null;

        return [
            'display_name' => $profile->display_name,
            'birthdate' => optional($profile->birthdate)->toDateString(),
            'sex' => $profile->sex,
            'units' => $units,
            'height' => $heightCm !== null ? ($imperial ? round($heightCm / 2.54, 1) : $heightCm) : null,
            'activity_level' => $s['activity_level'] ?? null,
            'primary_goal' => array_search($profile->primary_goal, self::GOALS, true) ?: null,
            'coach_tone' => $profile->coach_tone,
            'coaching_intensity' => $s['coaching_intensity'] ?? 'balanced',
            'meals_per_day' => $s['meal_plan']['meals'] ?? 4,
            'eat_start' => $s['meal_plan']['start'] ?? '08:00',
            'eat_end' => $s['meal_plan']['end'] ?? '21:00',
            'timezone' => $s['timezone'] ?? null,
            'experience' => $intake['experience'] ?? null,
            'train_at' => $intake['train_at'] ?? null,
            'train_days' => $intake['train_days'] ?? 0,
            'diet' => $intake['diet'] ?? null,
            'allergies' => $intake['allergies'] ?? null,
            'avoid_foods' => $intake['avoid_foods'] ?? null,
            'injuries' => implode('|', $intake['injuries'] ?? []),
            'health_notes' => $intake['health_notes'] ?? null,
            'focus_areas' => implode('|', $intake['focus_areas'] ?? []),
            'motivation' => $intake['motivation'] ?? null,
            'event_date' => $intake['event_date'] ?? null,
            'cycle_enabled' => (bool) ($cycle['enabled'] ?? false),
            'cycle_length' => $cycle['avg_length'] ?? null,
            'birth_control' => $cycle['birth_control'] ?? 'none',
            'cycle_intent' => $cycle['intent'] ?? 'tracking',
        ];
    }

    /**
     * Apply a partial edit to an already-onboarded profile (change anything collected in onboarding,
     * without re-seeding a starting weight or re-anchoring the cycle). Re-seeds coach memory.
     *
     * @param  array<string,mixed>  $data
     */
    public function updateProfile(User $user, Profile $profile, array $data): void
    {
        $settings = $profile->settings ?? [];
        $units = $data['units'] ?? ($settings['units'] ?? 'metric');
        $imperial = $units === 'imperial';

        $cols = [];
        if (isset($data['display_name'])) {
            $cols['display_name'] = $data['display_name'];
        }
        if (isset($data['birthdate'])) {
            $cols['birthdate'] = $data['birthdate'];
        }
        if (isset($data['sex'])) {
            $cols['sex'] = $data['sex'];
        }
        if (isset($data['height'])) {
            $cols['height_cm'] = $imperial ? round($data['height'] * 2.54, 1) : (float) $data['height'];
        }
        if (isset($data['primary_goal'])) {
            $cols['primary_goal'] = self::GOALS[$data['primary_goal']];
        }
        if (isset($data['coach_tone'])) {
            $cols['coach_tone'] = $data['coach_tone'];
        }

        foreach (['units', 'timezone', 'activity_level', 'coaching_intensity'] as $k) {
            if (isset($data[$k])) {
                $settings[$k] = $data[$k];
            }
        }
        if (isset($data['meals_per_day']) || isset($data['eat_start']) || isset($data['eat_end'])) {
            $mp = $settings['meal_plan'] ?? ['meals' => 4, 'start' => '08:00', 'end' => '21:00'];
            if (isset($data['meals_per_day'])) {
                $mp['meals'] = (int) $data['meals_per_day'];
            }
            if (isset($data['eat_start'])) {
                $mp['start'] = $data['eat_start'];
            }
            if (isset($data['eat_end'])) {
                $mp['end'] = $data['eat_end'];
            }
            $settings['meal_plan'] = $mp;
        }

        $intake = $settings['intake'] ?? [];
        foreach (['experience', 'train_at', 'diet'] as $k) {
            if (array_key_exists($k, $data)) {
                $intake[$k] = $data[$k] ?: null;
            }
        }
        if (array_key_exists('train_days', $data)) {
            $intake['train_days'] = (int) $data['train_days'];
        }
        foreach (['allergies', 'avoid_foods', 'health_notes', 'motivation', 'event_date'] as $k) {
            if (array_key_exists($k, $data)) {
                $intake[$k] = trim((string) $data[$k]) ?: null;
            }
        }
        if (array_key_exists('injuries', $data)) {
            $intake['injuries'] = array_values(array_filter(array_map('trim', explode('|', $data['injuries'] ?? ''))));
        }
        if (array_key_exists('focus_areas', $data)) {
            $intake['focus_areas'] = array_values(array_filter(array_map('trim', explode('|', $data['focus_areas'] ?? ''))));
        }
        $settings['intake'] = $intake;

        if (array_key_exists('cycle_enabled', $data)) {
            $female = ($cols['sex'] ?? $profile->sex) === 'F';
            if ($female && filter_var($data['cycle_enabled'], FILTER_VALIDATE_BOOL)) {
                $settings['cycle'] = array_merge($settings['cycle'] ?? ['avg_length' => Cycle::DEFAULT_LENGTH], array_filter([
                    'enabled' => true,
                    'avg_length' => $data['cycle_length'] ?? null,
                    'birth_control' => $data['birth_control'] ?? null,
                    'intent' => $data['cycle_intent'] ?? null,
                ], fn ($v) => $v !== null));
            } else {
                $settings['cycle']['enabled'] = false;
            }
        }

        // Recompute the macro seed from current stats — unless the user has set custom targets.
        if (($settings['macro_targets']['source'] ?? null) !== 'custom') {
            $weightKg = (float) ($profile->bodyMetrics()->whereNotNull('weight_kg')->latest('taken_at')->value('weight_kg') ?? 0);
            $heightCm = $cols['height_cm'] ?? $profile->height_cm;
            $birthdate = $cols['birthdate'] ?? optional($profile->birthdate)->toDateString();
            $sex = $cols['sex'] ?? $profile->sex;
            $goalKey = $data['primary_goal'] ?? (array_search($profile->primary_goal, self::GOALS, true) ?: 'general');
            if ($weightKg > 0 && $heightCm && $birthdate) {
                $m = $this->macros($weightKg, (float) $heightCm, Carbon::parse($birthdate)->age, $sex === 'F', $settings['activity_level'] ?? 'light', $goalKey);
                $settings['macro_targets'] = array_merge($settings['macro_targets'] ?? [], $m);
            }
        }

        $profile->update($cols + ['settings' => $settings]);

        // On edit, a provided last-period date anchors (or re-anchors) the cycle — needed to compute
        // phase + the pregnancy chance. Idempotent on the date (Cycle::startPeriod updateOrCreates).
        if (! empty($data['last_period']) && $profile->sex === 'F') {
            Cycle::startPeriod($profile, Carbon::parse($data['last_period']));
        }

        $this->seedCoreMemory($user, $profile->fresh(), $this->memoryCtx($profile->fresh()));
    }

    /** Build the coach-memory context from a profile's stored settings (so it's always complete). */
    private function memoryCtx(Profile $p): array
    {
        $s = $p->settings ?? [];
        $intake = $s['intake'] ?? [];

        return [
            'name' => $p->display_name ?: 'You',
            'goal' => $p->primary_goal ?: '',
            'focus_areas' => $intake['focus_areas'] ?? [],
            'injuries' => $intake['injuries'] ?? [],
            'data' => [
                'coach_tone' => $p->coach_tone ?: 'balanced',
                'coaching_intensity' => $s['coaching_intensity'] ?? 'balanced',
                'experience' => $intake['experience'] ?? null,
                'train_at' => $intake['train_at'] ?? null,
                'train_days' => $intake['train_days'] ?? 0,
                'diet' => $intake['diet'] ?? null,
                'allergies' => $intake['allergies'] ?? null,
                'avoid_foods' => $intake['avoid_foods'] ?? null,
                'health_notes' => $intake['health_notes'] ?? null,
                'motivation' => $intake['motivation'] ?? null,
                'event_date' => $intake['event_date'] ?? null,
            ],
        ];
    }

    /**
     * @param  array{name:string,goal:string,focus_areas:array<int,string>,injuries:array<int,string>,data:array<string,mixed>}  $ctx
     */
    private function seedCoreMemory(User $user, Profile $profile, array $ctx): void
    {
        $d = $ctx['data'];
        $name = $ctx['name'];
        $expLabels = ['beginner' => 'New / returning', 'intermediate' => 'Intermediate', 'advanced' => 'Advanced'];
        $gymLabels = ['full_gym' => 'Full gym', 'home_weights' => 'Home with weights', 'bodyweight' => 'Bodyweight only', 'mix' => 'A mix'];

        $pages = [];

        $overview = "- **Goal:** {$ctx['goal']}\n";
        if (! empty($ctx['focus_areas'])) {
            $overview .= '- **Focus areas:** '.implode(', ', $ctx['focus_areas'])."\n";
        }
        if (! empty($d['motivation'])) {
            $overview .= '- **Why / motivation:** '.trim($d['motivation'])."\n";
        }
        if (! empty($d['event_date'])) {
            $overview .= '- **Target date:** '.$d['event_date']."\n";
        }
        $overview .= '- **Coaching:** '.str_replace('_', ' ', $d['coach_tone']).' tone, '.($d['coaching_intensity'] ?? 'balanced')." intensity\n";
        $pages[] = ['title' => "{$name} -- goals & focus", 'type' => 'overview', 'content' => trim($overview)];

        if (! empty($d['experience']) || ! empty($d['train_at']) || (int) ($d['train_days'] ?? 0) > 0) {
            $training = '';
            if (! empty($d['experience'])) {
                $training .= '- **Experience:** '.($expLabels[$d['experience']] ?? $d['experience'])."\n";
            }
            if (! empty($d['train_at'])) {
                $training .= '- **Trains at:** '.($gymLabels[$d['train_at']] ?? $d['train_at'])."\n";
            }
            if ((int) ($d['train_days'] ?? 0) > 0) {
                $training .= '- **Days per week:** '.(int) $d['train_days']."\n";
            }
            $pages[] = ['title' => "{$name} -- training profile", 'type' => 'note', 'content' => trim($training)];
        }

        if (! empty($d['diet']) || ! empty($d['allergies']) || ! empty($d['avoid_foods'])) {
            $nutrition = '';
            if (! empty($d['diet'])) {
                $nutrition .= '- **Diet:** '.ucfirst($d['diet'])."\n";
            }
            if (! empty($d['allergies'])) {
                $nutrition .= '- **Allergies / intolerances:** '.trim($d['allergies'])."\n";
            }
            if (! empty($d['avoid_foods'])) {
                $nutrition .= '- **Won\'t eat:** '.trim($d['avoid_foods'])."\n";
            }
            $pages[] = ['title' => "{$name} -- nutrition profile", 'type' => 'note', 'content' => trim($nutrition)];
        }

        if (! empty($ctx['injuries']) || ! empty($d['health_notes'])) {
            $health = '';
            if (! empty($ctx['injuries'])) {
                $health .= '- **Injuries / areas to respect:** '.implode(', ', $ctx['injuries'])."\n";
            }
            if (! empty($d['health_notes'])) {
                $health .= '- **Notes:** '.trim($d['health_notes'])."\n";
            }
            $pages[] = ['title' => "{$name} -- health & limitations", 'type' => 'note', 'content' => trim($health)];
        }

        foreach ($pages as $p) {
            $profile->knowledgePages()->updateOrCreate(
                ['slug' => KnowledgePage::slugFor($p['title'])],
                [
                    'title' => $p['title'],
                    'type' => $p['type'],
                    'content' => $p['content'],
                    'is_pinned' => true,
                    'updated_by_user_id' => $user->id,
                    'embed_hash' => null,
                ],
            );
        }
    }

    /** @return array{calories:int,protein_g:int} */
    private function macros(float $kg, float $cm, int $age, bool $female, string $activity, string $goal): array
    {
        $bmr = 10 * $kg + 6.25 * $cm - 5 * $age + ($female ? -161 : 5);
        $af = ['sedentary' => 1.2, 'light' => 1.375, 'moderate' => 1.55, 'active' => 1.725][$activity] ?? 1.375;
        $tdee = $bmr * $af;

        $goalMult = match ($goal) {
            'lose_fat' => 0.80,
            'build_muscle' => 1.10,
            'performance' => 1.05,
            default => 1.0,
        };
        $calories = (int) (round($tdee * $goalMult / 10) * 10);
        $protein = (int) round($kg * MacroTargets::proteinPerKg($goal));

        return ['calories' => max(1200, $calories), 'protein_g' => $protein];
    }
}
