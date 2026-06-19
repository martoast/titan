<?php

namespace App\Http\Controllers;

use App\Support\Cycle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * First-run onboarding -- the wizard that turns a fresh account into a real Titan profile.
 * Collects the vitals (who they are, their goal, coaching style, nutrition, and -- for women --
 * their cycle), seeds personalized macro targets, logs a starting weight, and marks the
 * profile onboarded so the `onboarded` gate lets them into the rest of the app.
 */
class OnboardingController extends Controller
{
    private const GOALS = [
        'build_muscle' => 'Build muscle',
        'lose_fat' => 'Lose fat / get lean',
        'recomp' => 'Recomposition (lean + strong)',
        'longevity' => 'Longevity & healthspan',
        'performance' => 'Athletic performance',
        'general' => 'General health & energy',
    ];

    public function show(Request $request): View|RedirectResponse
    {
        $profile = $request->user()->ensureProfile();
        if ($profile->isOnboarded()) {
            return redirect()->route('coach.index');
        }

        return view('onboarding.index', [
            'profile' => $profile,
            'name' => $profile->display_name ?: $request->user()->name,
            'goals' => self::GOALS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $profile = $request->user()->ensureProfile();

        $data = $request->validate([
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
            // Cycle (women, optional)
            'cycle_enabled' => ['nullable', 'boolean'],
            'last_period' => ['nullable', 'date', 'before_or_equal:today'],
            'cycle_length' => ['nullable', 'integer', 'min:21', 'max:45'],
            'birth_control' => ['nullable', 'in:none,pill,patch,ring,hormonal_iud,copper_iud,implant,injection,other'],
            'cycle_intent' => ['nullable', 'in:tracking,conceiving,avoiding'],
            // Deep intake -- so the coach truly knows the user from message one. Arrays arrive '|'-joined.
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
            // Wearable: did they say their band is already in hand? (routes them to pairing after setup)
            'has_wearable' => ['nullable', 'boolean'],
        ]);

        // Parse the '|'-joined chip arrays into clean lists.
        $injuries = array_values(array_filter(array_map('trim', explode('|', $data['injuries'] ?? ''))));
        $focusAreas = array_values(array_filter(array_map('trim', explode('|', $data['focus_areas'] ?? ''))));

        $imperial = $data['units'] === 'imperial';
        $heightCm = $imperial ? round($data['height'] * 2.54, 1) : (float) $data['height'];
        $weightKg = $imperial ? round($data['weight'] * 0.45359237, 2) : (float) $data['weight'];
        $female = $data['sex'] === 'F';

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

        // Deep intake -- feeds the mesocycle generator (experience/days), meal logic (diet/allergies),
        // and the coach's first-message context. Empty fields are simply omitted later.
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

        // Cycle config for women who opted in.
        if ($female && $request->boolean('cycle_enabled')) {
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

        // Seed a starting weight so trajectories + biological age have an anchor.
        if (method_exists($profile, 'bodyMetrics')) {
            $profile->bodyMetrics()->create(['taken_at' => Carbon::today(), 'weight_kg' => $weightKg]);
        }

        // Seed the coach's core memory -- pinned wiki pages it sees from message one.
        $this->seedCoreMemory($request->user(), $profile, [
            'name' => $data['display_name'],
            'goal' => self::GOALS[$data['primary_goal']],
            'focus_areas' => $focusAreas,
            'injuries' => $injuries,
            'data' => $data,
        ]);

        // First period → anchors the cycle engine immediately.
        if ($female && $request->boolean('cycle_enabled') && ! empty($data['last_period'])) {
            Cycle::startPeriod($profile, Carbon::parse($data['last_period']));
        }

        // If their band is already in hand, the perfect moment to connect it is right now --
        // drop them on the devices page (pair + live bridge) instead of the coach.
        if ($request->boolean('has_wearable')) {
            return redirect()->route('devices.index')->with('status', "Welcome to Titan, {$data['display_name']} -- your profile's ready. Let's connect your band so your coach reads recovery from night one.");
        }

        return redirect()->route('coach.index')->with('status', "Welcome to Titan, {$data['display_name']} -- your profile is ready. Ask me anything.");
    }

    /**
     * Store one progress photo during onboarding (AJAX). Called once per angle from the
     * "Your starting point" wizard step. Photos become ProgressPhoto records so the coach
     * and physique analysis have a before baseline from day one.
     */
    public function storeProgressPhoto(Request $request): \Illuminate\Http\JsonResponse
    {
        $profile = $request->user()->ensureProfile();

        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'photo' => ['required', 'image', 'max:12288'],
            'angle' => ['nullable', 'in:front,back,side'],
        ]);
        if ($validator->fails()) {
            return response()->json(['ok' => false, 'error' => 'Upload a clear photo to continue.'], 422);
        }

        $angle = $request->input('angle') ?: 'front';
        $path = $request->file('photo')->store('physique/progress', 'public');

        $photo = $profile->progressPhotos()->create([
            'photo_path' => $path,
            'taken_at' => now()->toDateString(),
            'pose' => $angle,
        ]);

        return response()->json([
            'ok' => true,
            'photo_id' => $photo->id,
            'photo_url' => \Illuminate\Support\Facades\Storage::disk('public')->url($path),
        ]);
    }


    /**
     * Seed the coach's "core memory" from the onboarding intake -- a handful of pinned
     * KnowledgePages. Pinned pages have their TITLES injected into every coach turn (the index);
     * the coach pulls a body on demand via search_knowledge. So we keep one page per theme with a
     * descriptive title. Idempotent: re-onboarding updates the same pages (keyed by slug).
     *
     * @param  array{name:string,goal:string,focus_areas:array<int,string>,injuries:array<int,string>,data:array<string,mixed>}  $ctx
     */
    private function seedCoreMemory(\App\Models\User $user, \App\Models\Profile $profile, array $ctx): void
    {
        $d = $ctx['data'];
        $name = $ctx['name'];
        $expLabels = ['beginner' => 'New / returning', 'intermediate' => 'Intermediate', 'advanced' => 'Advanced'];
        $gymLabels = ['full_gym' => 'Full gym', 'home_weights' => 'Home with weights', 'bodyweight' => 'Bodyweight only', 'mix' => 'A mix'];

        $pages = [];

        // 1 · Who they are + the headline goal.
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

        // 2 · Training profile → mesocycle generator reads experience/days/equipment.
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

        // 3 · Nutrition profile → meal suggestions must fit this.
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

        // 4 · Health & limitations → never program around a painful joint.
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
                ['slug' => \App\Models\KnowledgePage::slugFor($p['title'])],
                [
                    'title' => $p['title'],
                    'type' => $p['type'],
                    'content' => $p['content'],
                    'is_pinned' => true,
                    'updated_by_user_id' => $user->id,
                    // Force a re-embed on next pass -- content changed.
                    'embed_hash' => null,
                ],
            );
        }
    }

    /**
     * Personalized daily macro targets -- Mifflin-St Jeor BMR × activity × goal, protein from
     * bodyweight. A sensible starting point the coach can refine later, not a prescription.
     *
     * @return array{calories:int,protein_g:int}
     */
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

        // Protein from bodyweight, evidence-based (~1 g/lb for muscle building). Single source of
        // truth so the seed matches what MealCoach recomputes later. @see App\Support\MacroTargets
        $protein = (int) round($kg * \App\Support\MacroTargets::proteinPerKg($goal));

        return ['calories' => max(1200, $calories), 'protein_g' => $protein];
    }
}
