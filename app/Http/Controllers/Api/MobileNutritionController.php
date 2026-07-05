<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Meal;
use App\Models\MealTemplate;
use App\Models\Profile;
use App\Services\Coach\ScanService;
use App\Support\Macros;
use App\Support\MealMemory;
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
    public function __construct(protected ScanService $scan, protected MealMemory $memory) {}

    /** Today's fuel: the macro-ring card + the meals logged today. */
    public function index(Request $request): JsonResponse
    {
        $profile = $this->profile($request);

        return response()->json([
            'macros' => Macros::today($profile),
            'meals' => $this->todaysMeals($profile)->map(fn (Meal $m) => $this->mealJson($m))->values(),
        ]);
    }

    /**
     * Snap a meal: photo → AI identifies it → macros nailed via your usuals / the official branded label /
     * web grounding → returned as a DRAFT for the user to confirm the amount before it's logged (so we get
     * the macros right). Bloodwork/physique are still filed immediately (nothing to confirm).
     */
    public function scan(Request $request): JsonResponse
    {
        $request->validate([
            'photo' => ['required', 'image', 'max:12288'],
            'caption' => ['nullable', 'string', 'max:280'],
        ]);
        $profile = $this->profile($request);

        $res = $this->scan->analyzeMeal($profile, $request->file('photo'), $request->input('caption'));

        return response()->json([
            'kind' => $res['kind'] ?? 'other',
            // A meal comes back as a draft (not yet logged) — the client confirms the amount, then POSTs
            // /meals/confirm. Non-meals were handled server-side already.
            'draft' => ($res['kind'] ?? null) === 'meal' ? Arr::except($res, ['kind', 'logged']) : null,
            'progress_photo_id' => $res['progress_photo_id'] ?? null,
            'image_url' => $res['image_url'] ?? null,
            'message' => ($res['kind'] ?? null) === 'physique'
                ? 'That looks like a body photo — I saved it to your Progress.'
                : (($res['kind'] ?? null) === 'meal' ? null : ($res['reply'] ?? null)),
            'macros' => Macros::today($profile),
        ]);
    }

    /**
     * Confirm a scanned draft → log it. The client sends the FINAL macros (after the user set the
     * servings/amount) + the draft's stored photo. We re-attach that photo (validated to the scans
     * folder — no arbitrary paths) so the logged meal keeps its picture, and it folds into meal memory.
     */
    public function confirm(Request $request): JsonResponse
    {
        $profile = $this->profile($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'calories' => ['required', 'integer', 'min:0', 'max:20000'],
            'protein_g' => ['required', 'numeric', 'min:0', 'max:2000'],
            'carbs_g' => ['required', 'numeric', 'min:0', 'max:2000'],
            'fat_g' => ['required', 'numeric', 'min:0', 'max:2000'],
            'photo_path' => ['nullable', 'string', 'max:255'],
        ]);

        // Only ever re-attach a photo the scanner itself just stored — never an arbitrary path.
        $photo = null;
        if (! empty($data['photo_path']) && str_starts_with($data['photo_path'], 'coach/scans/')
            && \Illuminate\Support\Facades\Storage::disk('public')->exists($data['photo_path'])) {
            $photo = $data['photo_path'];
        }

        $meal = $profile->meals()->create([
            'name' => $data['name'],
            'eaten_at' => now(),
            'calories' => $data['calories'],
            'protein_g' => $data['protein_g'],
            'carbs_g' => $data['carbs_g'],
            'fat_g' => $data['fat_g'],
            'photo_path' => $photo,
            'source' => 'photo',
        ]);

        return response()->json(['meal' => $this->mealJson($meal), 'macros' => Macros::today($profile)]);
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

    /** "Your meals" — the profile's memory of dishes it eats, ranked (favorites + recency-weighted). */
    public function library(Request $request): JsonResponse
    {
        $profile = $this->profile($request);

        return response()->json([
            'meals' => $this->memory->library($profile)->map(fn (MealTemplate $t) => $this->templateJson($t))->values(),
        ]);
    }

    /**
     * One-tap re-log: log a NEW meal today straight from a remembered one — its saved macros + photo,
     * no camera and no AI. An optional `portion` multiplier scales it (1.5× a bigger bowl). The new
     * Meal folds back into the memory (bumps frequency + recency) via Meal::created.
     */
    public function relog(Request $request): JsonResponse
    {
        $profile = $this->profile($request);
        $data = $request->validate([
            'template_id' => ['required', 'integer'],
            'portion' => ['sometimes', 'numeric', 'min:0.1', 'max:10'],
        ]);
        $tpl = $profile->mealTemplates()->findOrFail($data['template_id']);
        $p = (float) ($data['portion'] ?? 1.0);

        $meal = $profile->meals()->create([
            'name' => $tpl->name,
            'eaten_at' => now(),
            'calories' => (int) round((float) $tpl->calories * $p),
            'protein_g' => round((float) $tpl->protein_g * $p, 1),
            'carbs_g' => round((float) $tpl->carbs_g * $p, 1),
            'fat_g' => round((float) $tpl->fat_g * $p, 1),
            'photo_path' => $tpl->photo_path,   // reuse the remembered photo (same public file)
            'source' => 'memory',
        ]);

        return response()->json(['meal' => $this->mealJson($meal), 'macros' => Macros::today($profile)]);
    }

    /** Toggle a remembered meal as a favorite (pins it to the top of "your meals"). */
    public function favoriteTemplate(Request $request, int $template): JsonResponse
    {
        $profile = $this->profile($request);
        $tpl = $profile->mealTemplates()->findOrFail($template);
        $tpl->update(['favorite' => (bool) $request->boolean('favorite', ! $tpl->favorite)]);

        return response()->json(['template' => $this->templateJson($tpl->fresh())]);
    }

    /** Forget a remembered meal (removes it from "your meals"; logged meals stay). */
    public function forgetTemplate(Request $request, int $template): JsonResponse
    {
        $profile = $this->profile($request);
        $profile->mealTemplates()->findOrFail($template)->delete();

        return response()->json(['ok' => true]);
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

    /** @return array<string,mixed> */
    private function templateJson(MealTemplate $t): array
    {
        return [
            'id' => $t->id,
            'name' => $t->name,
            'calories' => (int) $t->calories,
            'protein_g' => (float) $t->protein_g,
            'carbs_g' => (float) $t->carbs_g,
            'fat_g' => (float) $t->fat_g,
            'photo_url' => $t->photoUrl(),
            'times_logged' => (int) $t->times_logged,
            'last_eaten_at' => $t->last_eaten_at?->toIso8601String(),
            'favorite' => (bool) $t->favorite,
        ];
    }
}
