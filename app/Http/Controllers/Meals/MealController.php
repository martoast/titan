<?php

namespace App\Http\Controllers\Meals;

use App\Exceptions\AiException;
use App\Http\Controllers\Controller;
use App\Models\Meal;
use App\Models\MealItem;
use App\Models\Profile;
use App\Services\Ai\AiService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Meals vertical — AI photo-based meal & macro logging.
 *
 * Flows:
 *  - index()    daily meals list + totals vs targets + 7-day calorie trend
 *  - analyze()  snap-a-meal: upload photo -> vision -> draft items -> editable confirm
 *  - parseText()free-text ("2 eggs and oatmeal") -> json -> draft items -> confirm
 *  - confirm()  render the editable confirmation screen (also used for manual add)
 *  - store()    persist the (possibly user-corrected) meal + items
 *  - destroy()  delete a meal
 *  - targets()  update the profile's macro targets
 *
 * AI is best-effort: any AiException degrades to a manual confirmation screen so the
 * user is never blocked — they can always type the numbers in.
 */
class MealController extends Controller
{
    /** Placeholder macro targets if the profile hasn't set its own. */
    private const DEFAULT_TARGETS = [
        'calories' => 2800, 'protein_g' => 200, 'carbs_g' => 280, 'fat_g' => 80,
    ];

    public function __construct(private AiService $ai) {}

    /** Daily view: today's meals, totals vs targets, 7-day calorie trend. */
    public function index(Request $request)
    {
        $profile = $request->user()->ensureProfile();
        $day = $this->resolveDay($request->query('day'));

        $meals = $profile->meals()
            ->with('items')
            ->whereBetween('eaten_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->orderBy('eaten_at')
            ->get();

        $totals = [
            'calories' => (int) $meals->sum('calories'),
            'protein_g' => round((float) $meals->sum('protein_g'), 1),
            'carbs_g' => round((float) $meals->sum('carbs_g'), 1),
            'fat_g' => round((float) $meals->sum('fat_g'), 1),
        ];

        return view('meals.index', [
            'day' => $day,
            'meals' => $meals,
            'totals' => $totals,
            'targets' => $this->targetsFor($profile),
            'trend' => $this->calorieTrend($profile, $day),
            // Meal-timing coach + recent AI suggestions (what + when to eat).
            'mealCoach' => \App\Support\MealCoach::assess($profile),
            'suggestions' => $profile->mealSuggestions()->latest()->take(6)->get(),
        ]);
    }

    /** Snap a meal: upload a photo, run vision, show an editable draft. */
    public function analyze(Request $request)
    {
        $request->validate([
            'photo' => ['required', 'image', 'max:12288'], // 12 MB
            'eaten_at' => ['nullable', 'date'],
        ]);

        $profile = $request->user()->ensureProfile();

        // Persist the photo to the public disk first — we keep it regardless of AI outcome.
        $path = $request->file('photo')->store('meals', 'public');

        $draft = $this->blankDraft();
        $draft['photo_path'] = $path;
        $draft['source'] = 'photo';
        $draft['eaten_at'] = $this->normalizeEatenAt($request->input('eaten_at'));

        $error = null;
        try {
            $imageUrl = $this->imageDataUrl($path);
            $raw = $this->ai->vision($this->visionPrompt(), [$imageUrl], [
                'max_tokens' => 1200,
                'temperature' => 0.2,
            ]);
            $parsed = $this->parseMealJson($raw);
            $draft = array_merge($draft, $parsed);
        } catch (AiException $e) {
            $error = 'AI vision is unavailable right now — enter the items manually below.';
        } catch (\Throwable $e) {
            $error = "Couldn't read that photo automatically — enter the items manually below.";
        }

        return view('meals.confirm', [
            'draft' => $draft,
            'aiError' => $error,
            'heading' => 'Confirm your meal',
        ]);
    }

    /** Natural-language entry: "2 eggs and oatmeal" -> json -> editable draft. */
    public function parseText(Request $request)
    {
        $request->validate([
            'text' => ['required', 'string', 'max:500'],
            'eaten_at' => ['nullable', 'date'],
        ]);

        $draft = $this->blankDraft();
        $draft['source'] = 'text';
        $draft['eaten_at'] = $this->normalizeEatenAt($request->input('eaten_at'));
        $draft['notes'] = trim($request->input('text'));

        $error = null;
        try {
            $parsed = $this->ai->json([
                ['role' => 'system', 'content' => $this->textSystemPrompt()],
                ['role' => 'user', 'content' => 'Meal description: '.trim($request->input('text'))],
            ], ['temperature' => 0.2, 'max_tokens' => 1000]);
            $draft = array_merge($draft, $this->normalizeParsed($parsed));
        } catch (AiException $e) {
            $error = 'AI is unavailable right now — enter the items manually below.';
        } catch (\Throwable $e) {
            $error = "Couldn't parse that — enter the items manually below.";
        }

        return view('meals.confirm', [
            'draft' => $draft,
            'aiError' => $error,
            'heading' => 'Confirm your meal',
        ]);
    }

    /** Empty editable confirmation screen for a fully manual add. */
    public function create(Request $request)
    {
        $draft = $this->blankDraft();
        $draft['source'] = 'manual';
        $draft['eaten_at'] = now()->format('Y-m-d\TH:i');
        $draft['items'][] = ['name' => '', 'quantity' => '', 'calories' => 0, 'protein_g' => 0, 'carbs_g' => 0, 'fat_g' => 0];

        return view('meals.confirm', [
            'draft' => $draft,
            'aiError' => null,
            'heading' => 'Add a meal',
        ]);
    }

    /** Persist the confirmed (and possibly user-corrected) meal + its items. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:160'],
            'eaten_at' => ['required', 'date'],
            'photo_path' => ['nullable', 'string', 'max:255'],
            'source' => ['required', 'in:photo,manual,text'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['array'],
            'items.*.name' => ['nullable', 'string', 'max:160'],
            'items.*.quantity' => ['nullable', 'string', 'max:80'],
            'items.*.calories' => ['nullable', 'numeric', 'min:0'],
            'items.*.protein_g' => ['nullable', 'numeric', 'min:0'],
            'items.*.carbs_g' => ['nullable', 'numeric', 'min:0'],
            'items.*.fat_g' => ['nullable', 'numeric', 'min:0'],
        ]);

        $profile = $request->user()->ensureProfile();

        // Only keep items that actually have a name — drop blank rows.
        $items = collect($data['items'] ?? [])
            ->filter(fn ($i) => trim((string) ($i['name'] ?? '')) !== '')
            ->values();

        $meal = $profile->meals()->create([
            'eaten_at' => Carbon::parse($data['eaten_at']),
            'name' => $data['name'] ?? null,
            'photo_path' => $data['photo_path'] ?? null,
            'source' => $data['source'],
            'notes' => $data['notes'] ?? null,
            'calories' => 0,
            'protein_g' => 0,
            'carbs_g' => 0,
            'fat_g' => 0,
        ]);

        foreach ($items as $i) {
            $meal->items()->create([
                'name' => trim($i['name']),
                'quantity' => $i['quantity'] ?? null,
                'calories' => (int) round((float) ($i['calories'] ?? 0)),
                'protein_g' => round((float) ($i['protein_g'] ?? 0), 1),
                'carbs_g' => round((float) ($i['carbs_g'] ?? 0), 1),
                'fat_g' => round((float) ($i['fat_g'] ?? 0), 1),
            ]);
        }

        $meal->recalcFromItems();

        return redirect('/meals?day='.$meal->eaten_at->format('Y-m-d'))
            ->with('status', 'Meal logged — '.$meal->calories.' kcal.');
    }

    public function destroy(Request $request, Meal $meal)
    {
        $profile = $request->user()->ensureProfile();
        abort_unless($meal->profile_id === $profile->id, 403);

        $day = $meal->eaten_at->format('Y-m-d');
        if ($meal->photo_path) {
            Storage::disk('public')->delete($meal->photo_path);
        }
        $meal->delete();

        return redirect('/meals?day='.$day)->with('status', 'Meal deleted.');
    }

    /** Update the profile's macro targets (stored in profile settings json). */
    public function targets(Request $request)
    {
        $data = $request->validate([
            'calories' => ['required', 'numeric', 'min:0', 'max:20000'],
            'protein_g' => ['required', 'numeric', 'min:0', 'max:1000'],
            'carbs_g' => ['required', 'numeric', 'min:0', 'max:2000'],
            'fat_g' => ['required', 'numeric', 'min:0', 'max:1000'],
        ]);

        $profile = $request->user()->ensureProfile();
        $settings = $profile->settings ?? [];
        $settings['macro_targets'] = [
            'calories' => (int) round($data['calories']),
            'protein_g' => (int) round($data['protein_g']),
            'carbs_g' => (int) round($data['carbs_g']),
            'fat_g' => (int) round($data['fat_g']),
        ];
        $profile->settings = $settings;
        $profile->save();

        return redirect('/meals')->with('status', 'Macro targets updated.');
    }

    // --- helpers -----------------------------------------------------------

    /** Macro targets for a profile — overrides in settings['macro_targets'] win. */
    private function targetsFor(Profile $profile): array
    {
        $set = $profile->settings['macro_targets'] ?? [];

        return [
            'calories' => (int) ($set['calories'] ?? self::DEFAULT_TARGETS['calories']),
            'protein_g' => (int) ($set['protein_g'] ?? self::DEFAULT_TARGETS['protein_g']),
            'carbs_g' => (int) ($set['carbs_g'] ?? self::DEFAULT_TARGETS['carbs_g']),
            'fat_g' => (int) ($set['fat_g'] ?? self::DEFAULT_TARGETS['fat_g']),
        ];
    }

    /** Last 7 days of total calories (ending on $day), for the trend chart. */
    private function calorieTrend(Profile $profile, Carbon $day): array
    {
        $start = $day->copy()->subDays(6)->startOfDay();
        $rows = $profile->meals()
            ->whereBetween('eaten_at', [$start, $day->copy()->endOfDay()])
            ->get(['eaten_at', 'calories']);

        $out = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = $day->copy()->subDays($i);
            $key = $d->format('Y-m-d');
            $cals = $rows->filter(fn ($m) => $m->eaten_at->format('Y-m-d') === $key)->sum('calories');
            $out[] = ['label' => $d->format('D'), 'date' => $key, 'calories' => (int) $cals];
        }

        return $out;
    }

    private function resolveDay(?string $day): Carbon
    {
        try {
            return $day ? Carbon::parse($day)->startOfDay() : now()->startOfDay();
        } catch (\Throwable) {
            return now()->startOfDay();
        }
    }

    /** Normalize the eaten_at form value to a datetime-local string, default now. */
    private function normalizeEatenAt(?string $value): string
    {
        try {
            return $value ? Carbon::parse($value)->format('Y-m-d\TH:i') : now()->format('Y-m-d\TH:i');
        } catch (\Throwable) {
            return now()->format('Y-m-d\TH:i');
        }
    }

    private function blankDraft(): array
    {
        return [
            'name' => '',
            'photo_path' => null,
            'source' => 'manual',
            'eaten_at' => now()->format('Y-m-d\TH:i'),
            'notes' => '',
            'assumptions' => '',
            'items' => [],
        ];
    }

    /** Build a base64 data URL for an image on the public disk (for the vision call). */
    private function imageDataUrl(string $path): string
    {
        $disk = Storage::disk('public');
        $bytes = $disk->get($path);
        $mime = $disk->mimeType($path) ?: 'image/jpeg';

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }

    private function visionPrompt(): string
    {
        return <<<'PROMPT'
You are a nutrition vision assistant. Look at this photo of a meal and estimate its
ingredients and macros. Return STRICT JSON ONLY (no prose, no markdown fences) of the form:
{
  "name": "short meal name",
  "items": [
    {"name":"food", "quantity":"portion e.g. 1 cup / 150 g", "calories":0, "protein_g":0, "carbs_g":0, "fat_g":0}
  ],
  "total": {"calories":0, "protein_g":0, "carbs_g":0, "fat_g":0},
  "assumptions": "one sentence on portion-size assumptions and uncertainty"
}
Estimate sensible portion sizes. Use grams for protein/carbs/fat as numbers (no units in
the numbers). Calories are whole numbers. Be realistic — meal estimates are inherently
~10-25% off, so state your main assumptions. If the photo is not food, return an empty items array.
PROMPT;
    }

    private function textSystemPrompt(): string
    {
        return <<<'PROMPT'
You are a nutrition assistant. The user describes a meal in natural language. Break it into
ingredient items with estimated macros. Return a JSON object of the form:
{
  "name": "short meal name",
  "items": [
    {"name":"food", "quantity":"portion", "calories":0, "protein_g":0, "carbs_g":0, "fat_g":0}
  ],
  "total": {"calories":0, "protein_g":0, "carbs_g":0, "fat_g":0},
  "assumptions": "one sentence on assumptions"
}
Protein/carbs/fat are grams as plain numbers; calories are whole numbers. Assume standard
portions when unspecified and note that in assumptions.
PROMPT;
    }

    /** Strip ```json fences if present and decode the vision JSON string. */
    private function parseMealJson(string $raw): array
    {
        $clean = trim($raw);
        // Strip a leading/trailing markdown code fence (```json ... ```).
        if (str_starts_with($clean, '```')) {
            $clean = preg_replace('/^```[a-zA-Z]*\s*/', '', $clean);
            $clean = preg_replace('/\s*```$/', '', $clean);
        }
        // Fall back to the first {...} block if the model added stray text.
        if (! str_starts_with(trim($clean), '{')) {
            if (preg_match('/\{.*\}/s', $clean, $m)) {
                $clean = $m[0];
            }
        }

        $decoded = json_decode(trim($clean), true);
        if (! is_array($decoded)) {
            throw new AiException('Vision returned unparseable JSON.');
        }

        return $this->normalizeParsed($decoded);
    }

    /** Coerce a decoded AI payload into our draft shape (name + items[]). */
    private function normalizeParsed(array $decoded): array
    {
        $items = [];
        foreach (($decoded['items'] ?? []) as $i) {
            if (! is_array($i)) {
                continue;
            }
            $items[] = [
                'name' => trim((string) ($i['name'] ?? '')),
                'quantity' => trim((string) ($i['quantity'] ?? '')),
                'calories' => (int) round((float) ($i['calories'] ?? 0)),
                'protein_g' => round((float) ($i['protein_g'] ?? 0), 1),
                'carbs_g' => round((float) ($i['carbs_g'] ?? 0), 1),
                'fat_g' => round((float) ($i['fat_g'] ?? 0), 1),
            ];
        }

        if ($items === []) {
            // Always give the user at least one editable row.
            $items[] = ['name' => '', 'quantity' => '', 'calories' => 0, 'protein_g' => 0, 'carbs_g' => 0, 'fat_g' => 0];
        }

        return [
            'name' => trim((string) ($decoded['name'] ?? '')),
            'assumptions' => trim((string) ($decoded['assumptions'] ?? '')),
            'items' => $items,
        ];
    }
}
