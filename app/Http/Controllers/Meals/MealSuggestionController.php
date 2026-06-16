<?php

namespace App\Http\Controllers\Meals;

use App\Exceptions\AiException;
use App\Http\Controllers\Controller;
use App\Models\MealSuggestion;
use App\Services\Meals\MealSuggestionService;
use App\Support\Pantry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * AI meal ideas: "what should I eat?" → suggestions sized to your next meal, each with a generated
 * photo and a tap-through recipe, and one-tap logging. Degrades gracefully when AI is unavailable.
 */
class MealSuggestionController extends Controller
{
    public function __construct(private readonly MealSuggestionService $suggestions) {}

    /** Generate a fresh batch for the next meal, then return to /meals to show them. */
    public function suggest(Request $request)
    {
        $profile = $request->user()->ensureProfile();
        try {
            $made = $this->suggestions->suggest($profile, 3);
        } catch (AiException $e) {
            return back()->withErrors(['suggest' => 'Meal ideas are unavailable right now ('.$e->getMessage().'). Try again shortly.']);
        } catch (\Throwable $e) {
            Log::warning('[meals] suggestion failed', ['error' => $e->getMessage()]);

            return back()->withErrors(['suggest' => 'Could not generate ideas just now. Try again shortly.']);
        }

        return redirect()->route('meals.index')->with('status', $made->count().' meal ideas ready — tap one for the recipe.');
    }

    /** Update the kitchen: add what you bought, remove an item, or clear it. */
    public function pantry(Request $request)
    {
        $profile = $request->user()->ensureProfile();
        $data = $request->validate([
            'items' => ['nullable', 'string', 'max:2000'],
            'remove' => ['nullable', 'string', 'max:120'],
        ]);

        if ($request->boolean('clear')) {
            Pantry::set($profile, []);
        } elseif (! empty($data['remove'])) {
            Pantry::remove($profile, $data['remove']);
        } elseif (! empty($data['items'])) {
            Pantry::add($profile, $data['items']);
        }

        return back()->with('status', 'Kitchen updated.');
    }

    /** The recipe for one suggestion. */
    public function recipe(Request $request, MealSuggestion $suggestion)
    {
        abort_unless($suggestion->profile_id === $request->user()->ensureProfile()->id, 403);

        return view('meals.recipe', ['s' => $suggestion]);
    }

    /** Log a suggestion as an eaten meal (its macros become a meal entry). */
    public function log(Request $request, MealSuggestion $suggestion)
    {
        $profile = $request->user()->ensureProfile();
        abort_unless($suggestion->profile_id === $profile->id, 403);

        $profile->meals()->create([
            'name' => $suggestion->name,
            'eaten_at' => now(),
            'calories' => $suggestion->calories ?? 0,
            'protein_g' => $suggestion->protein_g ?? 0,
            'carbs_g' => $suggestion->carbs_g ?? 0,
            'fat_g' => $suggestion->fat_g ?? 0,
            'photo_path' => $suggestion->image_path,
            'source' => 'suggestion',
        ]);

        return redirect()->route('meals.index')->with('status', $suggestion->name.' logged. Nice fuel. 💪');
    }
}
