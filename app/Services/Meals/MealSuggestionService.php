<?php

namespace App\Services\Meals;

use App\Exceptions\AiException;
use App\Models\MealSuggestion;
use App\Models\Profile;
use App\Services\Ai\AiService;
use App\Services\Ai\NanoBananaClient;
use App\Support\MealCoach;
use App\Support\Pantry;
use Illuminate\Support\Facades\Log;

/**
 * Suggests what to eat next — meals tailored to the user's next-meal macro target — and generates a
 * photo of each so the dashboard's "you need ~50g protein now" becomes "here's a teriyaki salmon
 * bowl, tap for the recipe." LLM writes the meals (fast, one call); the image model paints each
 * (best-effort, never blocks the suggestion). Degrades to a clear message when AI isn't configured.
 */
class MealSuggestionService
{
    public function __construct(
        private readonly AiService $ai,
        private readonly NanoBananaClient $nano,
    ) {}

    /**
     * Generate + persist `count` meal suggestions for the profile's next meal.
     *
     * @return \Illuminate\Support\Collection<int,MealSuggestion>
     *
     * @throws AiException when the language model isn't available
     */
    public function suggest(Profile $profile, int $count = 3): \Illuminate\Support\Collection
    {
        $count = max(1, min(5, $count));
        $meal = MealCoach::assess($profile);
        $protein = $meal['this_meal']['protein_g'] ?: 40;
        $calories = $meal['this_meal']['calories'] ?: 600;
        $goal = $profile->primary_goal ?: 'build a lean, strong physique';
        $prefs = trim((string) ($profile->settings['food_prefs'] ?? ''));
        $pantry = Pantry::get($profile);

        $rows = $this->writeMeals($count, $protein, $calories, $goal, $prefs, $pantry);
        $ctx = $pantry === [] ? sprintf('next meal · ~%dg protein', $protein)
            : sprintf('next meal · ~%dg protein · from your kitchen', $protein);

        $saved = collect();
        foreach ($rows as $r) {
            $s = $profile->mealSuggestions()->create([
                'name' => $r['name'],
                'description' => $r['description'] ?? null,
                'calories' => isset($r['calories']) ? (int) round((float) $r['calories']) : null,
                'protein_g' => isset($r['protein_g']) ? round((float) $r['protein_g'], 1) : null,
                'carbs_g' => isset($r['carbs_g']) ? round((float) $r['carbs_g'], 1) : null,
                'fat_g' => isset($r['fat_g']) ? round((float) $r['fat_g'], 1) : null,
                'ingredients' => array_values(array_filter((array) ($r['ingredients'] ?? []), 'is_string')),
                'extras' => array_values(array_filter((array) ($r['extras'] ?? []), 'is_string')),
                'steps' => array_values(array_filter((array) ($r['steps'] ?? []), 'is_string')),
                'context' => $ctx,
            ]);
            $this->paint($s);   // best-effort image — failure leaves a clean text card
            $saved->push($s);
        }

        return $saved;
    }

    /**
     * Ask the LLM for the meals. When the pantry is known, cook from it.
     *
     * @param  array<int,string>  $pantry
     * @return array<int,array<string,mixed>>
     */
    private function writeMeals(int $count, int $protein, int $calories, string $goal, string $prefs, array $pantry = []): array
    {
        $pantryRule = $pantry === []
            ? "The user hasn't listed their kitchen, so suggest common, accessible meals."
            : 'The user has THESE foods on hand: '.implode(', ', $pantry).". Suggest meals they can make MOSTLY "
                ."from these (assume basic staples: salt, pepper, oil, common spices, water). It's fine to need "
                .'1-2 cheap extras — if so, list them in an "extras" array. Do NOT invent ingredients they likely '
                ."don't have. Prioritise using their highest-protein items.";

        $system = <<<SYS
        You are Titan's nutrition coach. Suggest {$count} realistic, quick-to-make meal ideas that hit
        roughly {$protein} g protein and {$calories} kcal EACH. They should suit someone whose goal is:
        {$goal}. Protein-forward, whole-food-leaning, genuinely appetising — not bland "diet food".

        {$pantryRule}

        Return STRICT JSON only:
        {"meals":[{"name":"...","description":"one appetising sentence","calories":<int>,"protein_g":<int>,
          "carbs_g":<int>,"fat_g":<int>,"ingredients":["qty + item", ...],"extras":["item to buy", ...],
          "steps":["short step", ...]}]}

        Rules: 4-9 ingredients with rough quantities; 3-6 short imperative steps; macros should land near
        the target; vary the {$count} ideas (different proteins/cuisines). "extras" is only items NOT on hand
        (empty array if none). No markdown, no prose outside JSON.
        SYS;

        $user = 'Suggest the meals.'.($prefs !== '' ? " Preferences / constraints: {$prefs}." : '');
        $res = $this->ai->json([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ], ['temperature' => 0.8, 'max_tokens' => 1600]);

        $meals = array_filter((array) ($res['meals'] ?? []), fn ($m) => is_array($m) && ! empty($m['name']));
        if ($meals === []) {
            throw new AiException('No meal ideas came back. Try again in a moment.');
        }

        return array_slice(array_values($meals), 0, $count);
    }

    /** Generate + attach a food photo for one suggestion. Best-effort; never throws. */
    private function paint(MealSuggestion $s): void
    {
        if (! $this->nano->configured()) {
            return;
        }
        try {
            $prompt = sprintf(
                'A mouth-watering, professional overhead food photograph of "%s": %s. Plated on a clean '
                .'modern plate, soft natural light, shallow depth of field, vibrant and realistic, high '
                .'detail, no text, no hands, no utensils in frame.',
                $s->name, $s->description ?: 'a healthy high-protein meal'
            );
            $img = $this->nano->generateToDisk($prompt, 'meals');
            $s->update(['image_path' => $img['path']]);
        } catch (\Throwable $e) {
            Log::info('[meals] suggestion image skipped', ['id' => $s->id, 'error' => $e->getMessage()]);
        }
    }
}
