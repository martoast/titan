<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BrandedFood;
use App\Models\FoodFact;
use App\Models\Meal;
use App\Models\MealTemplate;
use App\Models\Profile;
use App\Services\Coach\ScanService;
use App\Services\Nutrition\OpenFoodFacts;
use App\Support\Macros;
use App\Support\MealMemory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Native-app nutrition surface (the "Fuel" tab). Same engine the coach/web use — a meal photo runs
 * through ScanService (OpenAI vision → macros, grounded in real food data → logged) — but returns
 * STRUCTURED JSON (macro rings + meal rows) instead of a markdown chat card. The coach stays in the
 * loop: every logged Meal is exactly what its macros_today / recent_meals tools already read.
 */
class MobileNutritionController extends Controller
{
    public function __construct(protected ScanService $scan, protected MealMemory $memory, protected OpenFoodFacts $off) {}

    /**
     * A day's fuel: the macro-ring card + that day's meals. `?date=yyyy-MM-dd` (profile-local) scopes to a
     * past day for the Fuel history pager; omitted → today (MEAL_LOGGING_REVISION 2.1).
     */
    public function index(Request $request): JsonResponse
    {
        $profile = $this->profile($request);
        $date = $this->validDate($request->query('date'), $profile);
        $meals = $this->mealsForDay($profile, $date);
        // Pre-fetch the day's glucose once (null when no CGM), so each meal's response is O(1), not a query.
        $glucose = $this->dayGlucoseReadings($profile, $meals);

        return response()->json([
            'date' => $date,   // the resolved local day the client is viewing (null → today)
            'macros' => Macros::today($profile, $date),
            'meals' => $meals->map(fn (Meal $m) => $this->mealJson($m) + array_filter([
                'glucose' => $glucose ? \App\Support\GlucoseResponse::forMeal($profile, $m, $glucose) : null,
            ], fn ($v) => $v !== null))->values(),
        ]);
    }

    /** The profile's glucose readings spanning the day's meals (±window), fetched once. Null when no CGM. */
    private function dayGlucoseReadings(Profile $profile, $meals)
    {
        if ($meals->isEmpty() || ! class_exists(\App\Models\GlucoseReading::class)) {
            return null;
        }
        // Query in APP-TZ (the frame glucose taken_at is stored in, same as meals) — NOT UTC, or the bounds
        // miss every reading and every meal gets glucose:null (review 5876613; mirror GlucoseDay).
        $first = $meals->min(fn (Meal $m) => $m->eaten_at)->copy()->subMinutes(20);
        $last = $meals->max(fn (Meal $m) => $m->eaten_at)->copy()->addHours(3);
        $rows = $profile->glucoseReadings()->whereBetween('taken_at', [$first, $last])->orderBy('taken_at')->get(['taken_at', 'mg_dl']);

        return $rows->isEmpty() ? null : $rows;
    }

    /** A valid past-or-today yyyy-MM-dd in the profile tz, or null (today). Guards against junk + future dates. */
    private function validDate(?string $raw, Profile $profile): ?string
    {
        if (! $raw) {
            return null;
        }
        $tz = $profile->settings['timezone'] ?? config('app.timezone', 'UTC');
        $day = rescue(fn () => Carbon::parse($raw, $tz)->startOfDay(), null, false);
        if (! $day || $day->greaterThan(Carbon::now($tz)->startOfDay())) {
            return null;   // unparseable or in the future → treat as today
        }

        return $day->toDateString();
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
     * Barcode scan: a GTIN/EAN/UPC → the exact product's macros → a draft to confirm the amount. Cached
     * by barcode (BrandedFood) so the second scan is instant + free, and seeded into the coach's food
     * knowledge (FoodFact) so later "I made a shake with that whey" needs no lookup — the coach already
     * knows it. Falls back to a not-found result the client can offer to log manually / by photo.
     */
    public function barcode(Request $request): JsonResponse
    {
        $profile = $this->profile($request);
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^\d{8,14}$/'],
        ]);
        $code = $data['code'];

        // 1. Cache hit → instant, free.
        if ($cached = BrandedFood::lookupBarcode($code)) {
            return response()->json($this->barcodeFound([
                'name' => $cached->product, 'brand' => $cached->brand, 'image_url' => null,
                'serving' => $cached->serving,
                'per_serving' => ['calories' => (int) $cached->calories, 'protein_g' => (float) $cached->protein_g, 'carbs_g' => (float) $cached->carbs_g, 'fat_g' => (float) $cached->fat_g],
                'per_100g' => ['calories' => (int) $cached->calories, 'protein_g' => (float) $cached->protein_g, 'carbs_g' => (float) $cached->carbs_g, 'fat_g' => (float) $cached->fat_g],
            ], $profile));
        }

        // 2. Miss → Open Food Facts, then cache + seed the coach's food knowledge.
        $prod = $this->off->lookup($code);
        if ($prod === null) {
            return response()->json([
                'kind' => 'other', 'draft' => null, 'image_url' => null,
                'message' => "I couldn't find that barcode in the food database. Snap the nutrition label or the plate instead, or add it manually — and I'll remember it.",
                'macros' => Macros::today($profile),
            ]);
        }

        $serv = $prod['per_serving'] ?? $prod['per_100g'];   // prefer the label serving; else per-100g
        BrandedFood::rememberBarcode($code, $prod['brand'], $prod['name'], $serv + ['serving' => $prod['serving']], 'openfoodfacts');
        $this->seedFoodKnowledge($prod);   // so the coach knows this product without a lookup

        return response()->json($this->barcodeFound($prod, $profile));
    }

    /** Build the meal-scan response (draft to confirm) from an Open-Food-Facts-shaped product. */
    private function barcodeFound(array $prod, Profile $profile): array
    {
        $m = $prod['per_serving'] ?? $prod['per_100g'];
        $servingBasis = isset($prod['per_serving']) ? ($prod['serving'] ?? 'per serving') : 'per 100 g';

        return [
            'kind' => 'meal',
            'draft' => [
                'photo_path' => null,
                'image_url' => $prod['image_url'] ?? null,
                'name' => (string) ($prod['name'] ?? 'Scanned product'),
                'brand' => $prod['brand'] ?? null,
                'items' => [],
                'calories' => (int) $m['calories'],
                'protein_g' => (float) $m['protein_g'],
                'carbs_g' => (float) $m['carbs_g'],
                'fat_g' => (float) $m['fat_g'],
                'confidence' => 'high',
                'source' => 'barcode',
                'serving_hint' => $servingBasis,
                'needs_confirmation' => false,
            ],
            'image_url' => $prod['image_url'] ?? null,
            'message' => null,
            'macros' => Macros::today($profile),
        ];
    }

    /** Seed the shared FoodFact cache (per-100g) so the coach's food lookup + meal grounding know it. */
    private function seedFoodKnowledge(array $prod): void
    {
        $name = trim((string) ($prod['name'] ?? ''));
        $per100 = $prod['per_100g'] ?? null;
        if ($name === '' || $per100 === null || empty($per100['calories'])) {
            return;
        }
        $key = trim(((string) ($prod['brand'] ?? '')).' '.$name);
        FoodFact::updateOrCreate(
            ['profile_id' => null, 'name' => Str::limit(\App\Support\FoodLibrary::normalize($key), 120, '')],
            [
                'basis' => '100g',
                'calories' => (int) $per100['calories'],
                'protein_g' => round((float) $per100['protein_g'], 1),
                'carbs_g' => round((float) $per100['carbs_g'], 1),
                'fat_g' => round((float) $per100['fat_g'], 1),
                'source' => 'openfoodfacts',
            ],
        );
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
            'fiber_g' => ['nullable', 'numeric', 'min:0', 'max:500'],   // secondary stat, not in the reconcile
            'meal_type' => ['nullable', \Illuminate\Validation\Rule::in(array_keys(\App\Support\MealType::TYPES))],
            'photo_path' => ['nullable', 'string', 'max:255'],
            // The draft's real source (a barcode scan confirms through here too) — was hardcoded 'photo',
            // which mislabelled barcode meals (MEAL_LOGGING_REVISION 1.3).
            'source' => ['nullable', 'string', \Illuminate\Validation\Rule::in(Meal::SOURCES)],
            // The edited per-item breakdown (MEAL_LOGGING_REVISION 3.2) — when present it IS the meal.
            'line_items' => ['sometimes', 'array', 'max:40'],
        ]);

        // Only ever re-attach a photo the scanner itself just stored — never an arbitrary path.
        $photo = null;
        if (! empty($data['photo_path']) && str_starts_with($data['photo_path'], 'coach/scans/')
            && \Illuminate\Support\Facades\Storage::disk('public')->exists($data['photo_path'])) {
            $photo = $data['photo_path'];
        }

        // If the user edited per-item lines, the items ARE the source of truth: create them and let
        // recalcFromItems set the totals (so what they see equals the sum). Otherwise the lumped totals
        // run the same macro↔calorie reconcile as every other creation path.
        $items = \App\Support\MealItems::clean($request->input('line_items', []));
        if ($items !== []) {
            // Insert with zero totals, attach items, then recalc — pausing the auto-remember so the memory
            // captures the FINISHED meal (totals + breakdown), not the empty intermediate.
            $meal = \App\Support\MealMemory::withoutRemembering(function () use ($profile, $data, $photo, $items) {
                $meal = $profile->meals()->create([
                    'name' => $data['name'],
                    'eaten_at' => now(),
                    'calories' => 0, 'protein_g' => 0, 'carbs_g' => 0, 'fat_g' => 0,
                    'meal_type' => $data['meal_type'] ?? null,
                    'photo_path' => $photo,
                    'source' => $data['source'] ?? 'photo',
                ]);
                $meal->items()->createMany($items);
                $meal->recalcFromItems();   // totals + fiber = the item sum; clears macros_estimated
                return $meal;
            });
            app(\App\Support\MealMemory::class)->remember($meal);
        } else {
            $m = Macros::reconcile((int) $data['calories'], (float) $data['protein_g'], (float) $data['carbs_g'], (float) $data['fat_g']);
            $meal = $profile->meals()->create([
                'name' => $data['name'],
                'eaten_at' => now(),
                'calories' => $m['calories'],
                'protein_g' => $m['protein_g'],
                'carbs_g' => $m['carbs_g'],
                'fat_g' => $m['fat_g'],
                'fiber_g' => self::normFiber($data['fiber_g'] ?? null),
                'macros_estimated' => $m['estimated'] ?? null,
                'meal_type' => $data['meal_type'] ?? null,   // null → inferred from time at read
                'photo_path' => $photo,
                'source' => $data['source'] ?? 'photo',
            ]);
        }

        return response()->json(['meal' => $this->mealJson($meal), 'macros' => Macros::today($profile)]);
    }

    /**
     * Native food search (MEAL_LOGGING_REVISION 3.1) — ranked matches from Titan's own caches (your logged
     * dishes + the nutrition library + branded products). The client shows each with its basis so it can
     * take a gram amount (per_100g) or a serving multiplier (per_serving), scale, and log via /meals.
     */
    public function search(Request $request): JsonResponse
    {
        $profile = $this->profile($request);
        $q = (string) $request->query('q', '');

        return response()->json([
            'query' => $q,
            'results' => \App\Support\FoodSearch::forProfile($profile, $q),
        ]);
    }

    /** Manual entry (or "add what the camera missed"). */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validateMeal($request, required: true);
        $profile = $this->profile($request);

        // Same reconcile guardrail as every other creation path (MEAL_LOGGING_REVISION 1.3).
        $m = Macros::reconcile((int) $data['calories'], (float) $data['protein_g'], (float) $data['carbs_g'], (float) $data['fat_g']);
        $meal = $profile->meals()->create([
            'name' => $data['name'],
            'eaten_at' => $data['eaten_at'] ?? now(),
            'calories' => $m['calories'],
            'protein_g' => $m['protein_g'],
            'carbs_g' => $m['carbs_g'],
            'fat_g' => $m['fat_g'],
            'fiber_g' => self::normFiber($data['fiber_g'] ?? null),
            'macros_estimated' => $m['estimated'] ?? null,
            'meal_type' => $data['meal_type'] ?? null,   // null → inferred from time at read
            'source' => 'manual',
        ]);

        return response()->json(['meal' => $this->mealJson($meal), 'macros' => Macros::today($profile)]);
    }

    /**
     * Copy a past day's meals to today — "log this day again", for people on repeating diets
     * (MEAL_LOGGING_REVISION 2.3). Each meal is re-created at today's date keeping its time-of-day (clamped
     * to now, never the future), reconciled + stamped source 'memory'. App-tz frame, per the meal-tz fault.
     */
    public function copyDay(Request $request): JsonResponse
    {
        $profile = $this->profile($request);
        $date = $this->validDate($request->input('date'), $profile);
        if ($date === null) {
            return response()->json(['error' => 'A valid past date is required.'], 422);
        }
        $meals = $this->mealsForDay($profile, $date);
        if ($meals->isEmpty()) {
            return response()->json(['error' => 'No meals were logged that day to copy.'], 422);
        }

        $appTz = config('app.timezone', 'UTC');
        $tz = $profile->settings['timezone'] ?? $appTz;
        $todayLocal = Carbon::now($tz)->toDateString();

        $copied = 0;
        foreach ($meals as $m) {
            // Today's date + the meal's original wall-clock time (the frame it's displayed in); clamp future.
            $eatenAt = Carbon::parse($todayLocal.' '.$m->eaten_at->format('H:i:s'), $appTz);
            if ($eatenAt->isFuture()) {
                $eatenAt = Carbon::now();
            }

            // Faithful copy: if the source meal has an item breakdown, recreate those items (so a copied
            // multi-item meal keeps its ingredients + refreshes the 3.4 template), else copy the lumped macros.
            $srcItems = \App\Support\MealItems::clean($m->items()->get()->map(fn (\App\Models\MealItem $i) => [
                'name' => $i->name, 'quantity' => $i->quantity, 'calories' => (int) $i->calories,
                'protein_g' => (float) $i->protein_g, 'carbs_g' => (float) $i->carbs_g,
                'fat_g' => (float) $i->fat_g, 'fiber_g' => $i->fiber_g,
            ])->all());

            if ($srcItems !== []) {
                $meal = \App\Support\MealMemory::withoutRemembering(function () use ($profile, $m, $eatenAt, $srcItems) {
                    $meal = $profile->meals()->create([
                        'name' => $m->name, 'eaten_at' => $eatenAt,
                        'calories' => 0, 'protein_g' => 0, 'carbs_g' => 0, 'fat_g' => 0,
                        'meal_type' => $m->meal_type, 'photo_path' => $m->photo_path, 'source' => 'memory',
                    ]);
                    $meal->items()->createMany($srcItems);
                    $meal->recalcFromItems();
                    return $meal;
                });
                app(\App\Support\MealMemory::class)->remember($meal);
            } else {
                $rc = Macros::reconcile((int) $m->calories, (float) $m->protein_g, (float) $m->carbs_g, (float) $m->fat_g);
                $profile->meals()->create([
                    'name' => $m->name,
                    'eaten_at' => $eatenAt,
                    'calories' => $rc['calories'],
                    'protein_g' => $rc['protein_g'],
                    'carbs_g' => $rc['carbs_g'],
                    'fat_g' => $rc['fat_g'],
                    'fiber_g' => $m->fiber_g,   // carry fibre onto the copy (unknown stays unknown)
                    'meal_type' => $m->meal_type,   // preserve an explicit override (time-of-day is kept, so inference matches anyway)
                    // Carry the honesty flag from the source meal (its macros are already reconciled, so a
                    // re-reconcile here invents nothing) — a copied estimate is still an estimate.
                    'macros_estimated' => $m->macros_estimated ?: null,
                    'photo_path' => $m->photo_path,
                    'source' => 'memory',
                ]);
            }
            $copied++;
        }

        return response()->json([
            'ok' => true,
            'copied' => $copied,
            'macros' => Macros::today($profile),
            'meals' => $this->mealsForDay($profile)->map(fn (Meal $m) => $this->mealJson($m))->values(),
        ]);
    }

    /** Correct an AI estimate (or any meal). */
    public function update(Request $request, int $meal): JsonResponse
    {
        $profile = $this->profile($request);
        $row = $profile->meals()->findOrFail($meal);
        $row->fill($this->validateMeal($request, required: false));
        // Only re-reconcile when a macro/calorie field was actually submitted. A metadata-only edit
        // (e.g. re-filing meal_type or setting fibre) must NOT rewrite the macros — for an item-based
        // meal that would drift the stored calories away from its item sum for no reason.
        if ($request->hasAny(['calories', 'protein_g', 'carbs_g', 'fat_g'])) {
            $m = Macros::reconcile((int) $row->calories, (float) $row->protein_g, (float) $row->carbs_g, (float) $row->fat_g);
            $row->calories = $m['calories'];
            $row->protein_g = $m['protein_g'];
            $row->carbs_g = $m['carbs_g'];
            $row->fat_g = $m['fat_g'];
            $row->macros_estimated = $m['estimated'] ?? null;   // a real correction clears the flag
        }
        $row->save();

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

        // A multi-item memory (3.4) recreates its ingredient breakdown, scaled by portion, and lets
        // recalcFromItems set the totals; a plain single-dish memory logs the lumped macros as before.
        $items = \App\Support\MealItems::clean($this->scaleItems($tpl->items, $p));
        if ($items !== []) {
            $meal = \App\Support\MealMemory::withoutRemembering(function () use ($profile, $tpl, $items) {
                $meal = $profile->meals()->create([
                    'name' => $tpl->name,
                    'eaten_at' => now(),
                    'calories' => 0, 'protein_g' => 0, 'carbs_g' => 0, 'fat_g' => 0,
                    'photo_path' => $tpl->photo_path,
                    'source' => 'memory',
                ]);
                $meal->items()->createMany($items);
                $meal->recalcFromItems();
                return $meal;
            });
            app(\App\Support\MealMemory::class)->remember($meal);
        } else {
            $meal = $profile->meals()->create([
                'name' => $tpl->name,
                'eaten_at' => now(),
                'calories' => (int) round((float) $tpl->calories * $p),
                'protein_g' => round((float) $tpl->protein_g * $p, 1),
                'carbs_g' => round((float) $tpl->carbs_g * $p, 1),
                'fat_g' => round((float) $tpl->fat_g * $p, 1),
                'fiber_g' => $tpl->fiber_g !== null ? round((float) $tpl->fiber_g * $p, 1) : null,
                'photo_path' => $tpl->photo_path,   // reuse the remembered photo (same public file)
                'source' => 'memory',
            ]);
        }

        return response()->json(['meal' => $this->mealJson($meal), 'macros' => Macros::today($profile)]);
    }

    /** Scale a stored template's item lines by a portion multiplier (null-safe). */
    private function scaleItems(mixed $items, float $portion): array
    {
        if (! is_array($items)) {
            return [];
        }
        return array_map(function ($i) use ($portion) {
            foreach (['calories', 'protein_g', 'carbs_g', 'fat_g', 'fiber_g'] as $k) {
                if (isset($i[$k]) && is_numeric($i[$k])) {
                    $i[$k] = (float) $i[$k] * $portion;
                }
            }
            return $i;
        }, $items);
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
    /** The meals for a profile-local day (today when $date is null), newest first. */
    private function mealsForDay(Profile $profile, ?string $date = null)
    {
        $appTz = config('app.timezone', 'UTC');
        $tz = $profile->settings['timezone'] ?? $appTz;
        $start = ($date ? Carbon::parse($date, $tz)->startOfDay() : Carbon::now($tz)->startOfDay())
            ->setTimezone($appTz);

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
            'fiber_g' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:500'],
            'meal_type' => ['sometimes', 'nullable', \Illuminate\Validation\Rule::in(array_keys(\App\Support\MealType::TYPES))],
            'eaten_at' => ['sometimes', 'date'],
        ]);
    }

    /** Fibre is a secondary stat: keep it null (unknown) rather than storing a misleading 0 for "not given". */
    private static function normFiber(mixed $v): ?float
    {
        return is_numeric($v) ? round((float) $v, 1) : null;
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
            'fiber_g' => $m->fiber_g !== null ? (float) $m->fiber_g : null,   // secondary stat, null when unknown
            'meal_type' => $m->mealType(),   // resolved group (stored override, else inferred from time)
            'macros_estimated' => $m->macros_estimated ?: null,   // which macros the server invented (honesty chip)
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
            'fiber_g' => $t->fiber_g !== null ? (float) $t->fiber_g : null,
            'item_count' => is_array($t->items) ? count($t->items) : 0,   // >0 → a multi-item memory (3.4)
            'photo_url' => $t->photoUrl(),
            'times_logged' => (int) $t->times_logged,
            'last_eaten_at' => $t->last_eaten_at?->toIso8601String(),
            'favorite' => (bool) $t->favorite,
        ];
    }
}
