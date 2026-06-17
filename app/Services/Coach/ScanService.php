<?php

namespace App\Services\Coach;

use App\Models\Profile;
use App\Services\Ai\AiService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Snap-to-log: turn a photo into logged data. The user points their camera at a plate
 * of food or a bloodwork printout; vision identifies it, extracts the numbers, and we
 * log them — a meal with macros, or biomarker readings. Returns a markdown reply the
 * coach shows in the chat, plus the stored image URL.
 *
 * Wellness-only by design: we read what's printed on a lab sheet, we never diagnose.
 */
class ScanService
{
    public function __construct(protected AiService $ai) {}

    /**
     * @return array{kind:string,logged:bool,image_url:string,reply:string,data:array<string,mixed>}
     */
    public function scan(Profile $profile, UploadedFile $file, ?string $caption = null): array
    {
        // Keep the original for the record (meal photo / audit trail).
        $path = $file->store('coach/scans', 'public');
        $mime = $file->getClientMimeType() ?: 'image/jpeg';
        $dataUrl = 'data:'.$mime.';base64,'.base64_encode((string) Storage::disk('public')->get($path));
        $imageUrl = Storage::disk('public')->url($path);

        $prompt = <<<'TXT'
        A user sent this photo to their health app. Identify what it is and extract structured data.
        Return ONLY a JSON object of this exact shape:
        {
          "kind": "meal" | "bloodwork" | "physique" | "other",
          "meal": { "name": string, "items": [string], "calories": int, "protein_g": number, "carbs_g": number, "fat_g": number, "confidence": "low"|"medium"|"high" },
          "bloodwork": [ { "marker": string, "value": number, "unit": string } ],
          "note": string
        }
        Rules:
        - If it's food/a meal/a drink: fill "meal" with your best estimate of the macros for the WHOLE portion shown, plus a short name and the visible items. Leave "bloodwork" as [].
        - If it's a lab/bloodwork report, printout, or screenshot of results: fill "bloodwork" with EVERY marker you can read. Use canonical snake_case marker keys (e.g. ldl, hdl, total_cholesterol, triglycerides, glucose, hba1c, vitamin_d, crp, alt, ast, tsh, ferritin, creatinine). Keep the printed unit. Leave "meal" empty.
        - If it's a photo of a PERSON'S BODY/PHYSIQUE — a progress photo, gym selfie, or a full or upper-body shot of themselves — set kind "physique". (Leave "meal" and "bloodwork" empty.)
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
        $data = is_array($data) ? $data : [];
        $kind = $data['kind'] ?? 'other';

        if ($kind === 'meal' && ! empty($data['meal']) && is_array($data['meal'])) {
            // Ground the macros in REAL web nutrition data instead of trusting the vision guess.
            return $this->logMeal($profile, $this->groundMeal($data['meal']), $path, $imageUrl);
        }
        if ($kind === 'bloodwork' && ! empty($data['bloodwork']) && is_array($data['bloodwork'])) {
            return $this->logBloodwork($profile, $data['bloodwork'], $imageUrl);
        }
        if ($kind === 'physique') {
            return $this->logPhysique($profile, $path, $imageUrl);
        }

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
     * Replace the vision model's macro GUESS with real web nutrition data where we can find it — so a
     * snapped meal logs true calories/macros, not invented ones. Best-effort: keeps the vision estimate
     * if the web has nothing useful.
     */
    private function groundMeal(array $m): array
    {
        if (! class_exists(\App\Services\Web\WebSearch::class)) {
            return $m;
        }
        $web = app(\App\Services\Web\WebSearch::class);
        $name = trim((string) ($m['name'] ?? ($m['items'][0] ?? '')));
        if (! $web->configured() || $name === '') {
            return $m;
        }
        $facts = $web->facts("calories protein carbs fat in {$name}");
        if ($facts === '') {
            return $m;
        }

        try {
            $g = $this->ai->json([
                ['role' => 'system', 'content' => 'You finalise a logged meal\'s macros using REAL web nutrition data. Given the meal (name, items, the vision model\'s estimate) and web nutrition facts, return JSON {"calories":int,"protein_g":number,"carbs_g":number,"fat_g":number,"grounded":bool} for the WHOLE portion shown. Prefer the web data — scale per-100g/per-serving figures up to the portion. Only fall back to the vision estimate if the web data is irrelevant. Set grounded=true when you used the web data.'],
                ['role' => 'user', 'content' => "Meal: {$name}\nItems: ".implode(', ', (array) ($m['items'] ?? []))
                    ."\nVision estimate: ".json_encode(\Illuminate\Support\Arr::only($m, ['calories', 'protein_g', 'carbs_g', 'fat_g']))
                    ."\nWeb nutrition facts:\n{$facts}"],
            ], ['temperature' => 0.2, 'max_tokens' => 300]);

            foreach (['calories', 'protein_g', 'carbs_g', 'fat_g'] as $k) {
                if (isset($g[$k]) && is_numeric($g[$k])) {
                    $m[$k] = $g[$k];
                }
            }
            if (! empty($g['grounded'])) {
                $m['grounded'] = true;
            }
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
                'low' => ' These are rough estimates from the photo — tweak them if you know better.',
                'medium' => ' Macros are estimated from the photo.',
                default => '',
            };

        // Lead with the updated macros card, then a short confirmation line.
        $reply = \App\Support\Macros::fenced($profile)
            ."\n\nLogged **{$meal->name}** — {$meal->calories} kcal · {$meal->protein_g}g protein.{$hedge}";

        return ['kind' => 'meal', 'logged' => true, 'image_url' => $imageUrl, 'reply' => $reply, 'data' => $m];
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
                ." outside the typical range. I'm a coach, not a doctor — if anything here concerns you, take it to your physician.";
        } else {
            $reply .= "\nEverything reads within typical ranges. Ask me about any marker and I'll explain what it means for you.";
        }

        return ['kind' => 'bloodwork', 'logged' => true, 'image_url' => $imageUrl, 'reply' => $reply, 'data' => ['count' => $count]];
    }
}
