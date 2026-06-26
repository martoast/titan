<?php

namespace App\Services\Stack;

use App\Models\Profile;
use App\Services\Ai\AiService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Snap-to-add for "What you take": point the camera at one bottle, or lay the whole shelf /
 * pill organizer out and shoot it once. Vision reads the labels and returns structured
 * CANDIDATES (name, brand, dose, form, kind) — it does NOT auto-create items; the user (or the
 * coach, conversationally) confirms first, then we create the stack items. Mirrors ScanService.
 */
class StackScanService
{
    public function __construct(protected AiService $ai) {}

    /**
     * @param  'single'|'shelf'  $mode
     * @return array{image_url:string,photo_path:string,candidates:array<int,array<string,mixed>>,note:?string}
     */
    public function scan(Profile $profile, UploadedFile $file, string $mode = 'single'): array
    {
        $path = $file->store('coach/scans', 'public');
        $mime = $file->getClientMimeType() ?: 'image/jpeg';
        $dataUrl = 'data:'.$mime.';base64,'.base64_encode((string) Storage::disk('public')->get($path));
        $imageUrl = Storage::disk('public')->url($path);

        $many = $mode === 'shelf';
        $scope = $many
            ? 'This photo shows SEVERAL supplement/medication bottles or a pill organizer. Identify EVERY product you can read.'
            : 'This photo shows ONE supplement or medication bottle/label. Identify it.';

        $prompt = <<<TXT
        A user is adding what they take to their health app. {$scope}
        Return ONLY a JSON object of this exact shape:
        {
          "candidates": [
            { "name": string, "brand": string|null, "dose_amount": number|null, "dose_unit": string|null, "form": string|null, "kind": "supplement"|"medication", "confidence": "low"|"medium"|"high" }
          ],
          "note": string
        }
        Rules:
        - "name" is the ingredient/product (e.g. "Vitamin D3", "Magnesium Glycinate", "Lisinopril"). Keep it short.
        - "dose_unit" is the printed unit: IU, mg, mcg, g, ml, etc. "form": capsule, tablet, softgel, powder, gummy, liquid.
        - "kind": "medication" for prescription/OTC drugs, otherwise "supplement".
        - Only include products you can actually read. If a label is unreadable, leave it out and mention it in "note".
        - "note" is one friendly sentence (e.g. what you saw, or which bottle to re-shoot). Never diagnose or give medical advice.
        TXT;

        try {
            $raw = $this->ai->vision($prompt, [$dataUrl], ['json' => true, 'max_tokens' => 1200, 'temperature' => 0.2]);
            $data = json_decode($raw, true);
        } catch (\Throwable) {
            $data = null;
        }
        $data = is_array($data) ? $data : [];

        $candidates = [];
        foreach (($data['candidates'] ?? []) as $c) {
            if (! is_array($c) || empty($c['name'])) {
                continue;
            }
            $candidates[] = [
                'name' => Str::limit(trim((string) $c['name']), 80, ''),
                'brand' => isset($c['brand']) ? (string) $c['brand'] : null,
                'dose_amount' => isset($c['dose_amount']) && is_numeric($c['dose_amount']) ? (float) $c['dose_amount'] : null,
                'dose_unit' => isset($c['dose_unit']) ? (string) $c['dose_unit'] : null,
                'form' => isset($c['form']) ? (string) $c['form'] : null,
                'kind' => ($c['kind'] ?? 'supplement') === 'medication' ? 'medication' : 'supplement',
                'confidence' => strtolower((string) ($c['confidence'] ?? 'medium')),
                'source' => 'photo',
            ];
            if (! $many) {
                break;
            }
        }

        return [
            'image_url' => $imageUrl,
            'photo_path' => $path,
            'candidates' => $candidates,
            'note' => $data['note'] ?? null,
        ];
    }
}
