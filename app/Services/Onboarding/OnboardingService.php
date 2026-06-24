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
