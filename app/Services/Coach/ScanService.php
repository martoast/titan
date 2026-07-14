<?php

namespace App\Services\Coach;

use App\Models\Profile;
use App\Services\Ai\AiService;
use App\Services\Web\WebSearch;
use App\Support\MealMemory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Snap-to-log: turn a photo into logged data. The user points their camera at a plate
 * of food or a bloodwork printout; vision identifies it, extracts the numbers, and we
 * log them -- a meal with macros, or biomarker readings. Returns a markdown reply the
 * coach shows in the chat, plus the stored image URL.
 *
 * Wellness-only by design: we read what's printed on a lab sheet, we never diagnose.
 */
class ScanService
{
    public function __construct(protected AiService $ai, protected WebSearch $web, protected MealMemory $memory) {}

    /**
     * @return array{kind:string,logged:bool,image_url:string,reply:string,data:array<string,mixed>}
     */
    public function scan(Profile $profile, UploadedFile $file, ?string $caption = null): array
    {
        [$data, $path, $imageUrl] = $this->classify($file, $caption);

        return $this->dispatchScan($profile, $data, $path, $imageUrl, $caption);
    }

    /**
     * Same as scan(), but from a photo ALREADY stored on the `public` disk (the async coach turn: the
     * controller banks the upload, then a queue job runs the vision + logging off the request path so
     * it survives the phone suspending).
     *
     * @return array{kind:string,logged:bool,image_url:string,reply:string,data:array<string,mixed>}
     */
    public function scanStored(Profile $profile, string $path, ?string $caption = null): array
    {
        [$data, $imageUrl] = $this->classifyStored($path, $caption);

        return $this->dispatchScan($profile, $data, $path, $imageUrl, $caption);
    }

    /**
     * Route classified vision data to the right handler (meal / bloodwork / physique / other). A FOOD
     * photo splits on intent: "log" records eating it now (adds to today); "save" just remembers it as
     * a reference in your foods — the difference between "here's my lunch" and "add this to the kinds
     * of pasta I make". Intent comes from vision + a caption keyword override (below).
     *
     * @param  array<string,mixed>  $data
     * @return array{kind:string,logged:bool,image_url:string,reply:string,data:array<string,mixed>}
     */
    private function dispatchScan(Profile $profile, array $data, string $path, string $imageUrl, ?string $caption = null): array
    {
        $kind = $data['kind'] ?? 'other';

        if ($kind === 'meal' && ! empty($data['meal']) && is_array($data['meal'])) {
            $meal = $this->resolveMeal($profile, $data['meal']);
            // Save-as-reference vs log-as-eaten. Vision decides from the caption; a clear caption phrase
            // overrides it (models occasionally default to "log" on a bare-looking label photo).
            return $this->mealIntent($caption, $data['intent'] ?? null) === 'save'
                ? $this->saveMeal($profile, $meal, $path, $imageUrl)
                : $this->logMeal($profile, $meal, $path, $imageUrl);
        }
        if ($kind === 'bloodwork' && ! empty($data['bloodwork']) && is_array($data['bloodwork'])) {
            return $this->logBloodwork($profile, $data['bloodwork'], $imageUrl);
        }
        if ($kind === 'physique') {
            return $this->logPhysique($profile, $path, $imageUrl);
        }

        return $this->otherResult($data, $imageUrl);
    }

    /**
     * The native "Fuel" draft flow: analyze a meal photo the SAME way (your usuals → branded label →
     * web grounding) but return a DRAFT the user confirms before it's logged — so they can fix the
     * portion/serving and we nail the macros. Non-meal photos (bloodwork/physique) are handled as in
     * {@see scan()} (logged immediately — nothing to confirm).
     *
     * @return array{kind:string,logged:bool,image_url:string,...}
     */
    public function analyzeMeal(Profile $profile, UploadedFile $file, ?string $caption = null): array
    {
        [$data, $path, $imageUrl] = $this->classify($file, $caption);
        $kind = $data['kind'] ?? 'other';

        if ($kind === 'meal' && ! empty($data['meal']) && is_array($data['meal'])) {
            return $this->draftMeal($this->resolveMeal($profile, $data['meal']), $path, $imageUrl);
        }
        if ($kind === 'bloodwork' && ! empty($data['bloodwork']) && is_array($data['bloodwork'])) {
            return $this->logBloodwork($profile, $data['bloodwork'], $imageUrl);
        }
        if ($kind === 'physique') {
            return $this->logPhysique($profile, $path, $imageUrl);
        }

        return $this->otherResult($data, $imageUrl);
    }

    /**
     * Store the photo and run the vision classifier over it.
     *
     * @return array{0:array<string,mixed>,1:string,2:string}  [$data, $path, $imageUrl]
     */
    private function classify(UploadedFile $file, ?string $caption): array
    {
        // Keep the original for the record (meal photo / audit trail).
        $path = $file->store('coach/scans', 'public');
        [$data, $imageUrl] = $this->classifyStored($path, $caption);

        return [$data, $path, $imageUrl];
    }

    /**
     * The vision-classify half, from a photo already on the `public` disk. Returns [$data, $imageUrl].
     *
     * @return array{0:array<string,mixed>,1:string}
     */
    private function classifyStored(string $path, ?string $caption): array
    {
        $mime = Storage::disk('public')->mimeType($path) ?: 'image/jpeg';
        $dataUrl = 'data:'.$mime.';base64,'.base64_encode((string) Storage::disk('public')->get($path));
        $imageUrl = Storage::disk('public')->url($path);

        $prompt = <<<'TXT'
        A user sent this photo to their health app. Identify what it is and extract structured data.
        Return ONLY a JSON object of this exact shape:
        {
          "kind": "meal" | "bloodwork" | "physique" | "other",
          "intent": "log" | "save",
          "meal": { "name": string, "items": [string], "calories": int, "protein_g": number, "carbs_g": number, "fat_g": number, "fiber_g": number, "confidence": "low"|"medium"|"high", "packaged": boolean, "is_label": boolean, "brand": string, "product": string, "serving_hint": string },
          "bloodwork": [ { "marker": string, "value": number, "unit": string } ],
          "note": string
        }
        Rules:
        - "intent" (food photos only): decide what the user wants DONE with this food, from their words.
          - "save" = REMEMBER this food/ingredient/product as a reference for later, do NOT record eating it now. Signals: "save this", "add this to my foods/pantry/usuals", "remember this", "one of the pastas/meals/things I make", "the kind of X I use", "for later/next time", "for reference". A photo of a NUTRITION-FACTS panel or a packaged product with this kind of phrasing is almost always "save" — they're cataloguing the product, not eating it.
          - "log" = they are eating it now / want it recorded to today. Signals: "I ate", "just had", "log this", "for lunch", "this is my breakfast" — OR no instruction at all (a bare food photo defaults to "log").
          Default to "log" when genuinely unclear. Never "save" for bloodwork/physique — use "log".
        - If it's food/a meal/a drink: fill "meal" with your best estimate of the macros for the WHOLE portion shown, plus a short name and the visible items. Leave "bloodwork" as []. Include "fiber_g" (dietary fibre, grams) when the food plausibly has it (vegetables, fruit, legumes, whole grains, nuts) — omit it or use 0 only when there's genuinely none; on a nutrition-facts panel, transcribe the printed fibre figure.
        - If the image IS a NUTRITION FACTS panel (the printed nutrition table on packaging, or a screenshot of one): set "is_label": true AND read the macros DIRECTLY off it — copy calories/protein_g/carbs_g/fat_g PER SERVING exactly as printed (do not estimate). Put the printed serving size in "serving_hint" (e.g. "1 cup (240 ml)"). Set brand/product too if the label shows them. These printed numbers are ground truth — transcribe, don't guess.
        - If it's a PACKAGED / branded product (a wrapper, bottle, box, protein tub, bar, ready meal — a brand is visible but the full facts panel is NOT clearly readable): set "packaged": true and read the "brand" and exact "product" name off the label as precisely as you can (e.g. brand "Chobani", product "Non-Fat Greek Yogurt, Vanilla"). Put the serving/size you can see in "serving_hint". We will look up the OFFICIAL label macros for this exact product, so getting the brand + product right matters more than guessing the numbers.
        - For a non-packaged home/restaurant plate, set "packaged": false and leave brand/product empty; put your read of the portion in "serving_hint" (e.g. "~1.5 cups rice, palm-size chicken").
        - If it's a lab/bloodwork report, printout, or screenshot of results: fill "bloodwork" with EVERY marker you can read. Use canonical snake_case marker keys (e.g. ldl, hdl, total_cholesterol, triglycerides, glucose, hba1c, vitamin_d, crp, alt, ast, tsh, ferritin, creatinine). Keep the printed unit. Leave "meal" empty.
        - If it's a photo of a PERSON'S BODY/PHYSIQUE -- a progress photo, gym selfie, or a full or upper-body shot of themselves -- set kind "physique". (Leave "meal" and "bloodwork" empty.)
        - Otherwise set kind "other" and explain in "note".
        - "note" is one friendly sentence summarising what you saw. Never diagnose or give medical advice.
        TXT;

        // The user's own words ("this is what I ate", "a 12oz steak") sharpen the read.
        if ($caption !== null && trim($caption) !== '') {
            $prompt .= "\n\nThe user also said: \"".trim($caption)."\". Use it to identify the food and estimate the portion.";
        }

        try {
            $raw = $this->ai->vision($prompt, [$dataUrl], ['json' => true, 'max_tokens' => 1400, 'temperature' => 0.2]);
            $data = json_decode($raw, true);
        } catch (\Throwable) {
            $data = null;
        }

        return [is_array($data) ? $data : [], $imageUrl];
    }

    /**
     * Nail a meal's macros with the best source available, in priority order:
     *   1. YOUR USUALS — if you've logged this exact dish before, reuse your own (corrected) macros.
     *   2. BRANDED LABEL — a packaged product → look up the OFFICIAL per-serving label on the web.
     *   3. WEB GROUNDING — a generic dish → the cached FoodLibrary per-100g scaled to the portion.
     * Stamps `_source` + `_confidence` so the UI can show where the numbers came from and whether to ask
     * the user to confirm. Falls back to the vision estimate throughout.
     *
     * @param  array<string,mixed>  $m
     * @return array<string,mixed>
     */
    private function resolveMeal(Profile $profile, array $m): array
    {
        // 0. A NUTRITION-FACTS photo IS the data — vision transcribed the printed panel, so trust it
        //    outright (no web, no scaling: the fastest + most accurate path). Cache it as a branded label
        //    so a later non-label scan of the same product is instant too.
        if (! empty($m['is_label']) && (float) ($m['calories'] ?? 0) > 0) {
            \App\Models\BrandedFood::remember($m['brand'] ?? null, $m['product'] ?? ($m['name'] ?? null), [
                'calories' => (int) $m['calories'], 'protein_g' => (float) ($m['protein_g'] ?? 0),
                'carbs_g' => (float) ($m['carbs_g'] ?? 0), 'fat_g' => (float) ($m['fat_g'] ?? 0),
                'serving' => (string) ($m['serving_hint'] ?? ''),
            ], 'label');
            $m['_source'] = 'label';
            $m['_confidence'] = 'high';

            return $m;
        }

        // 1. Your usuals — your own history is the most accurate source there is.
        $name = trim((string) ($m['name'] ?? ($m['product'] ?? '')));
        if ($tpl = $this->matchLibrary($profile, $name)) {
            $m['name'] = $name ?: $tpl->name;
            $m['calories'] = (int) $tpl->calories;
            $m['protein_g'] = (float) $tpl->protein_g;
            $m['carbs_g'] = (float) $tpl->carbs_g;
            $m['fat_g'] = (float) $tpl->fat_g;
            $m['_source'] = 'your_meals';
            $m['_confidence'] = 'high';

            return $m;
        }

        // 2. Branded / packaged → the exact official label (cache-first: researched on the web once,
        //    then reused free forever).
        if (! empty($m['packaged']) && $branded = $this->brandedMacros($m)) {
            foreach (['calories', 'protein_g', 'carbs_g', 'fat_g'] as $k) {
                $m[$k] = $branded[$k];
            }
            $m['_source'] = 'brand';
            $m['_confidence'] = 'high';
            $m['serving_hint'] = ($branded['serving'] ?? '') ?: ($m['serving_hint'] ?? null);

            return $m;
        }

        // 3. Generic web grounding (FoodLibrary cache) — else keep the vision estimate.
        $m = $this->groundMeal($m, $profile);
        $m['_source'] = ! empty($m['grounded']) ? 'web' : 'photo';
        $m['_confidence'] = ! empty($m['grounded']) ? 'medium' : strtolower((string) ($m['confidence'] ?? 'low'));

        return $m;
    }

    /** A dish you've logged before → its remembered {@see \App\Models\MealTemplate}, matched on name. */
    private function matchLibrary(Profile $profile, string $name): ?\App\Models\MealTemplate
    {
        $key = MealMemory::normalize($name);

        return $key === '' ? null : $profile->mealTemplates()->where('key', $key)->first();
    }

    /**
     * A packaged product's per-serving macros, CACHE-FIRST: reuse the shared {@see \App\Models\BrandedFood}
     * label if we've seen this product before (instant + free); otherwise research it on the web once and
     * cache the result so the next scan is instant.
     *
     * @param  array<string,mixed>  $m
     * @return array{calories:int,protein_g:float,carbs_g:float,fat_g:float,serving:string}|null
     */
    private function brandedMacros(array $m): ?array
    {
        $brand = trim((string) ($m['brand'] ?? ''));
        $product = trim((string) ($m['product'] ?? ($m['name'] ?? '')));

        if ($cached = \App\Models\BrandedFood::lookup($brand, $product)) {
            return [
                'calories' => (int) $cached->calories, 'protein_g' => (float) $cached->protein_g,
                'carbs_g' => (float) $cached->carbs_g, 'fat_g' => (float) $cached->fat_g,
                'serving' => (string) ($cached->serving ?? ''),
            ];
        }

        $res = $this->researchBranded($m);
        if ($res !== null) {
            \App\Models\BrandedFood::remember($brand, $product, $res, 'web');
        }

        return $res;
    }

    /**
     * Look up a packaged product's OFFICIAL per-serving macros on the web (brand + product read off the
     * label by vision), so a snapped wrapper logs the label's exact numbers, not a guess. Best-effort:
     * returns null when the web isn't configured or the snippets don't clearly match the product.
     *
     * @param  array<string,mixed>  $m
     * @return array{calories:int,protein_g:float,carbs_g:float,fat_g:float,serving:string}|null
     */
    private function researchBranded(array $m): ?array
    {
        if (! $this->web->configured()) {
            return null;
        }
        $brand = trim((string) ($m['brand'] ?? ''));
        $product = trim((string) ($m['product'] ?? ($m['name'] ?? '')));
        if ($product === '') {
            return null;
        }
        $facts = $this->web->facts(trim("{$brand} {$product} nutrition facts label calories protein carbs fat per serving"), 5);
        if (trim($facts) === '') {
            return null;
        }

        try {
            $g = $this->ai->json([
                ['role' => 'system', 'content' => 'From web snippets of a PACKAGED product\'s official nutrition label, return its macros PER SERVING as JSON {"serving":string,"calories":int,"protein_g":number,"carbs_g":number,"fat_g":number}. Use the official label figures; ignore snippets for a different product/brand. "serving" = the label serving (e.g. "1 bottle (500 ml)", "2 scoops (60 g)"). If nothing clearly matches this product, return {"calories":0}.'],
                ['role' => 'user', 'content' => "Product: {$brand} {$product}\nServing seen in photo: ".(string) ($m['serving_hint'] ?? '')."\nWeb snippets:\n{$facts}"],
            ], ['temperature' => 0.1, 'max_tokens' => 300]);
        } catch (\Throwable) {
            return null;
        }

        if (empty($g['calories']) || ! is_numeric($g['calories'])) {
            return null;   // no confident label match
        }

        return [
            'calories' => (int) round((float) $g['calories']),
            'protein_g' => round((float) ($g['protein_g'] ?? 0), 1),
            'carbs_g' => round((float) ($g['carbs_g'] ?? 0), 1),
            'fat_g' => round((float) ($g['fat_g'] ?? 0), 1),
            'serving' => (string) ($g['serving'] ?? ''),
        ];
    }

    /**
     * Package a resolved meal as a DRAFT for the native confirm sheet — NOT logged yet. Carries the
     * source + confidence so the UI can say "official label" / "your usual" / "estimated", and asks the
     * user to confirm unless we're highly confident.
     *
     * @param  array<string,mixed>  $m
     */
    private function draftMeal(array $m, string $path, string $imageUrl): array
    {
        $confidence = (string) ($m['_confidence'] ?? 'low');

        return [
            'kind' => 'meal',
            'logged' => false,
            'draft' => true,
            'photo_path' => $path,
            'image_url' => $imageUrl,
            'name' => Str::limit(trim((string) ($m['name'] ?? 'Meal')) ?: 'Meal', 80, ''),
            'brand' => trim((string) ($m['brand'] ?? '')) ?: null,
            'items' => array_values(array_filter((array) ($m['items'] ?? []), 'is_string')),
            'calories' => (int) round((float) ($m['calories'] ?? 0)),
            'protein_g' => round((float) ($m['protein_g'] ?? 0), 1),
            'carbs_g' => round((float) ($m['carbs_g'] ?? 0), 1),
            'fat_g' => round((float) ($m['fat_g'] ?? 0), 1),
            // Secondary stat: null (unknown) unless the estimate actually carried a fibre figure.
            'fiber_g' => isset($m['fiber_g']) && is_numeric($m['fiber_g']) ? round((float) $m['fiber_g'], 1) : null,
            'confidence' => $confidence,
            'source' => (string) ($m['_source'] ?? 'photo'),
            'serving_hint' => trim((string) ($m['serving_hint'] ?? '')) ?: null,
            // High-confidence (your usual / exact label) can log with one tap; otherwise confirm the amount.
            'needs_confirmation' => $confidence !== 'high',
        ];
    }

    private function otherResult(array $data, string $imageUrl): array
    {
        return [
            'kind' => 'other',
            'logged' => false,
            'image_url' => $imageUrl,
            'reply' => $data['note']
                ?? "I couldn't tell what this is. For meals, snap the plate from above; for bloodwork, capture the results page so the marker names and numbers are legible.",
            'data' => $data,
        ];
    }

    /** A body/progress photo → save it, and tee up the dream-physique render. */
    private function logPhysique(Profile $profile, string $path, string $imageUrl): array
    {
        $photo = $profile->progressPhotos()->create([
            'photo_path' => $path,
            'taken_at' => now()->toDateString(),
        ]);

        return [
            'kind' => 'physique',
            'logged' => true,
            'image_url' => $imageUrl,
            'progress_photo_id' => $photo->id,
            'reply' => "Saved as a progress photo. Want me to render your **dream physique** from this? Tell me the goal (e.g. \"+10 lb lean muscle\") or just say go, and I'll show you your future self.",
            'data' => ['progress_photo_id' => $photo->id],
            '_followup' => 'physique_render_offer',
        ];
    }

    /** @param  array<string,mixed>  $m */
    /**
     * Replace the vision model's macro GUESS with real web nutrition data where we can find it -- so a
     * snapped meal logs true calories/macros, not invented ones. Best-effort: keeps the vision estimate
     * if the web has nothing useful.
     */
    private function groundMeal(array $m, Profile $profile): array
    {
        if (! class_exists(\App\Support\FoodLibrary::class)) {
            return $m;
        }
        $name = trim((string) ($m['name'] ?? ($m['items'][0] ?? '')));
        if ($name === '') {
            return $m;
        }

        // Cache-first base macros (per 100g) -- only researches the web the first time this food is seen.
        $lib = app(\App\Support\FoodLibrary::class)->lookup($name, $profile);
        if (! ($lib['ok'] ?? false)) {
            return $m;
        }

        try {
            // Scale the real per-basis macros to the portion the photo actually shows.
            $g = $this->ai->json([
                ['role' => 'system', 'content' => 'You finalise a photographed meal\'s macros. Given a food\'s REAL nutrition per a basis, the vision model\'s whole-portion estimate, and the items, return JSON {"calories":int,"protein_g":number,"carbs_g":number,"fat_g":number,"grounded":true} for the WHOLE portion shown. Scale the real per-basis figures to the estimated portion size; sanity-check against the vision estimate. Prefer the real data.'],
                ['role' => 'user', 'content' => "Meal: {$name}\nItems: ".implode(', ', (array) ($m['items'] ?? []))
                    ."\nReal nutrition per {$lib['basis']}: {$lib['calories']} kcal, {$lib['protein_g']}g protein, {$lib['carbs_g']}g carbs, {$lib['fat_g']}g fat"
                    ."\nVision whole-portion estimate: ".json_encode(\Illuminate\Support\Arr::only($m, ['calories', 'protein_g', 'carbs_g', 'fat_g']))],
            ], ['temperature' => 0.2, 'max_tokens' => 250]);

            foreach (['calories', 'protein_g', 'carbs_g', 'fat_g'] as $k) {
                if (isset($g[$k]) && is_numeric($g[$k])) {
                    $m[$k] = $g[$k];
                }
            }
            $m['grounded'] = true;
        } catch (\Throwable) {
            // keep the vision estimate
        }

        return $m;
    }

    private function logMeal(Profile $profile, array $m, string $path, string $imageUrl): array
    {
        $meal = $profile->meals()->create([
            'name' => Str::limit(trim((string) ($m['name'] ?? 'Meal')) ?: 'Meal', 80, ''),
            'eaten_at' => now(),
            'calories' => (int) round((float) ($m['calories'] ?? 0)),
            'protein_g' => round((float) ($m['protein_g'] ?? 0), 1),
            'carbs_g' => round((float) ($m['carbs_g'] ?? 0), 1),
            'fat_g' => round((float) ($m['fat_g'] ?? 0), 1),
            'photo_path' => $path,
            'source' => 'photo',
        ]);

        $conf = strtolower((string) ($m['confidence'] ?? ''));
        $hedge = ! empty($m['grounded'])
            ? ' Macros grounded in real nutrition data for this dish.'
            : match ($conf) {
                'low' => ' These are rough estimates from the photo -- tweak them if you know better.',
                'medium' => ' Macros are estimated from the photo.',
                default => '',
            };

        // Lead with the updated macros card, then a short confirmation line.
        $reply = \App\Support\Macros::fenced($profile)
            ."\n\nLogged **{$meal->name}** -- {$meal->calories} kcal · {$meal->protein_g}g protein.{$hedge}";

        return ['kind' => 'meal', 'logged' => true, 'meal_id' => $meal->id, 'image_url' => $imageUrl, 'reply' => $reply, 'data' => $m];
    }

    /**
     * Save a food as a REFERENCE — a {@see \App\Models\MealTemplate} in "your foods" — WITHOUT logging
     * it to today. This is what "add this to the kinds of pasta I make" wants: next time they say "I
     * made pasta", {@see matchLibrary()} resolves to these exact macros. No Meal row, no change to
     * today's totals. (The branded label was already cached to {@see \App\Models\BrandedFood} in
     * resolveMeal, so a future scan of the same product is instant too.)
     *
     * @param  array<string,mixed>  $m
     */
    private function saveMeal(Profile $profile, array $m, string $path, string $imageUrl): array
    {
        $name = trim((string) ($m['name'] ?? 'Food')) ?: 'Food';
        $key = MealMemory::normalize($name) ?: MealMemory::normalize('food');

        $tpl = \App\Models\MealTemplate::firstOrNew(['profile_id' => $profile->id, 'key' => $key]);
        $tpl->name = $name;
        $tpl->calories = (int) round((float) ($m['calories'] ?? 0));
        $tpl->protein_g = round((float) ($m['protein_g'] ?? 0), 1);
        $tpl->carbs_g = round((float) ($m['carbs_g'] ?? 0), 1);
        $tpl->fat_g = round((float) ($m['fat_g'] ?? 0), 1);
        if ($path) {
            $tpl->photo_path = $path;
        }
        $tpl->source = $tpl->source ?: 'photo';
        // Deliberately DON'T touch times_logged / last_eaten_at — it was saved, not eaten.
        $tpl->save();

        $serving = trim((string) ($m['serving'] ?? $m['serving_hint'] ?? ''));
        $per = $serving !== '' ? " (per {$serving})" : '';
        $reply = "Saved **{$tpl->name}** to your foods{$per} — {$tpl->calories} kcal · {$tpl->protein_g}g protein · "
            ."{$tpl->carbs_g}g carbs · {$tpl->fat_g}g fat. I'll use these when you tell me you had it. "
            .'Nothing was logged to today — just tap it in your usuals, or say the word, when you actually eat it.';

        return ['kind' => 'meal_saved', 'logged' => false, 'template_id' => $tpl->id, 'image_url' => $imageUrl, 'reply' => $reply, 'data' => $m];
    }

    /**
     * Resolve save-vs-log intent for a food photo. Vision's read is the base; an unambiguous caption
     * phrase overrides it either way (a bare label photo sometimes defaults to "log" wrongly, and an
     * explicit "I'm eating this" should always log). Defaults to "log".
     */
    private function mealIntent(?string $caption, ?string $visionIntent): string
    {
        $intent = strtolower(trim((string) $visionIntent)) === 'save' ? 'save' : 'log';
        $c = strtolower(trim((string) $caption));
        if ($c === '') {
            return $intent;
        }

        // "save this", "add … to my foods", "one of the pastas I make", "the … I use", "for later/reference"…
        $saveSignals = '/\b(sav(e|ing)|remember|for later|for next time|for reference|to my (foods|meals|pantry|usuals|library|list)|add (this|it|to)|one of (the|my)|kinds? of|the .* i (make|cook|use|buy|eat))\b/';
        // …but an explicit "I ate / just had / log this / for lunch" always wins back to logging.
        $logSignals = '/\b(ate|eaten|eating|just (had|ate)|had (this|it|for)|log (this|it)|for (breakfast|lunch|dinner|a snack)|my (breakfast|lunch|dinner))\b/';

        if (preg_match($saveSignals, $c)) {
            $intent = 'save';
        }
        if (preg_match($logSignals, $c)) {
            $intent = 'log';
        }

        return $intent;
    }

    /** @param  array<int,array<string,mixed>>  $markers */
    private function logBloodwork(Profile $profile, array $markers, string $imageUrl): array
    {
        $rows = [];
        foreach ($markers as $b) {
            if (! is_array($b) || empty($b['marker']) || ! isset($b['value']) || ! is_numeric($b['value'])) {
                continue;
            }
            $r = $profile->biomarkerReadings()->create([
                'marker' => Str::of((string) $b['marker'])->lower()->trim()->replace(' ', '_')->value(),
                'value' => (float) $b['value'],
                'unit' => isset($b['unit']) ? (string) $b['unit'] : null,
                'taken_at' => now()->toDateString(),
                'source' => 'photo',
            ]);
            $rows[] = $r;
        }

        if ($rows === []) {
            return [
                'kind' => 'other', 'logged' => false, 'image_url' => $imageUrl,
                'reply' => "I could see this looks like bloodwork, but couldn't read the values cleanly. Try a sharper, straight-on photo of the results.",
                'data' => [],
            ];
        }

        $flagged = collect($rows)->filter(fn ($r) => ! empty($r->flag) && $r->flag !== 'normal');
        $table = "| Marker | Value | |\n|---|---|---|\n";
        foreach ($rows as $r) {
            $mark = strtoupper(str_replace('_', ' ', $r->marker));
            $val = rtrim(rtrim(number_format((float) $r->value, 2), '0'), '.').($r->unit ? ' '.$r->unit : '');
            $flagBadge = (! empty($r->flag) && $r->flag !== 'normal') ? '⚠️ '.$r->flag : '✓';
            $table .= "| {$mark} | {$val} | {$flagBadge} |\n";
        }

        $count = count($rows);
        $reply = "**Logged {$count} marker".($count === 1 ? '' : 's')." from your bloodwork**\n\n".$table;
        if ($flagged->isNotEmpty()) {
            $reply .= "\n".$flagged->count()." marker".($flagged->count() === 1 ? ' is' : 's are')
                ." outside the typical range. I'm a coach, not a doctor -- if anything here concerns you, take it to your physician.";
        } else {
            $reply .= "\nEverything reads within typical ranges. Ask me about any marker and I'll explain what it means for you.";
        }

        return ['kind' => 'bloodwork', 'logged' => true, 'image_url' => $imageUrl, 'reply' => $reply, 'data' => ['count' => $count]];
    }
}
