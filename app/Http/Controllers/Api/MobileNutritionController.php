<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Meal;
use App\Models\Profile;
use App\Services\Coach\ScanService;
use App\Support\Macros;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Native-app nutrition surface (the "Fuel" tab). Same engine the coach/web use — a meal photo runs
 * through ScanService (OpenAI vision → macros, grounded in real food data → logged) — but returns
 * STRUCTURED JSON (macro rings + meal rows) instead of a markdown chat card. The coach stays in the
 * loop: every logged Meal is exactly what its macros_today / recent_meals tools already read.
 */
class MobileNutritionController extends Controller
{
    public function __construct(protected ScanService $scan) {}

    /** Today's fuel: the macro-ring card + the meals logged today. */
    public function index(Request $request): JsonResponse
    {
        $profile = $this->profile($request);

        return response()->json([
            'macros' => Macros::today($profile),
            'meals' => $this->todaysMeals($profile)->map(fn (Meal $m) => $this->mealJson($m))->values(),
        ]);
    }

    /** Snap a meal: photo → AI macros (grounded) → logged. Returns the meal + the updated card. */
    public function scan(Request $request): JsonResponse
    {
        $request->validate([
            'photo' => ['required', 'image', 'max:12288'],
            'caption' => ['nullable', 'string', 'max:280'],
        ]);
        $profile = $this->profile($request);

        $res = $this->scan->scan($profile, $request->file('photo'), $request->input('caption'));

        $meal = isset($res['meal_id']) ? $profile->meals()->find($res['meal_id']) : null;

        return response()->json([
            'kind' => $res['kind'] ?? 'other',
            'meal' => $meal ? $this->mealJson($meal) : null,
            'progress_photo_id' => $res['progress_photo_id'] ?? null,
            'image_url' => $res['image_url'] ?? null,
            // For a body shot ScanService files it under Progress — tell the user plainly.
            'message' => ($res['kind'] ?? null) === 'physique'
                ? 'That looks like a body photo — I saved it to your Progress.'
                : (($res['kind'] ?? null) === 'meal' ? null : ($res['reply'] ?? null)),
            'macros' => Macros::today($profile),
        ]);
    }

    /** Manual entry (or "add what the camera missed"). */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validateMeal($request, required: true);
        $profile = $this->profile($request);

        $meal = $profile->meals()->create($data + [
            'eaten_at' => $data['eaten_at'] ?? now(),
            'source' => 'manual',
        ]);

        return response()->json(['meal' => $this->mealJson($meal), 'macros' => Macros::today($profile)]);
    }

    /** Correct an AI estimate (or any meal). */
    public function update(Request $request, int $meal): JsonResponse
    {
        $profile = $this->profile($request);
        $row = $profile->meals()->findOrFail($meal);
        $row->update($this->validateMeal($request, required: false));

        return response()->json(['meal' => $this->mealJson($row->fresh()), 'macros' => Macros::today($profile)]);
    }

    public function destroy(Request $request, int $meal): JsonResponse
    {
        $profile = $this->profile($request);
        $profile->meals()->findOrFail($meal)->delete();

        return response()->json(['ok' => true, 'macros' => Macros::today($profile)]);
    }

    // ----- helpers ----------------------------------------------------------

    private function profile(Request $request): Profile
    {
        return $request->user()->profile ?? $request->user()->ensureProfile();
    }

    /** @return \Illuminate\Support\Collection<int,Meal> */
    private function todaysMeals(Profile $profile)
    {
        $appTz = config('app.timezone', 'UTC');
        $tz = $profile->settings['timezone'] ?? $appTz;
        $start = Carbon::now($tz)->startOfDay()->setTimezone($appTz);

        return $profile->meals()
            ->where('eaten_at', '>=', $start)
            ->where('eaten_at', '<', $start->copy()->addDay())
            ->orderByDesc('eaten_at')->orderByDesc('id')
            ->get();
    }

    /** @return array<string,mixed> */
    private function validateMeal(Request $request, bool $required): array
    {
        $req = $required ? 'required' : 'sometimes';

        return $request->validate([
            'name' => [$req, 'string', 'max:80'],
            'calories' => [$req, 'integer', 'min:0', 'max:20000'],
            'protein_g' => [$req, 'numeric', 'min:0', 'max:2000'],
            'carbs_g' => [$req, 'numeric', 'min:0', 'max:2000'],
            'fat_g' => [$req, 'numeric', 'min:0', 'max:2000'],
            'eaten_at' => ['sometimes', 'date'],
        ]);
    }

    /** @return array<string,mixed> */
    private function mealJson(Meal $m): array
    {
        return [
            'id' => $m->id,
            'name' => $m->name,
            'eaten_at' => $m->eaten_at?->toIso8601String(),
            'calories' => (int) $m->calories,
            'protein_g' => (float) $m->protein_g,
            'carbs_g' => (float) $m->carbs_g,
            'fat_g' => (float) $m->fat_g,
            'photo_url' => $m->photoUrl(),
            'source' => $m->source,
        ];
    }
}
