<?php

namespace App\Services\Ai;

use App\Exceptions\AiException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Image generation with OpenAI's gpt-image-1 — the model behind ChatGPT's image edits.
 *
 * This is the dream-physique renderer. Unlike Gemini (which refuses to make a real, identifiable
 * person more muscular on TOS grounds), gpt-image-1 handles a user transforming THEIR OWN photo,
 * and `input_fidelity: high` keeps their face/identity intact — exactly the "same me, fitter" result.
 *
 * Drop-in for NanoBananaClient via the shared ImageGenerator contract. When given input images it
 * uses the /images/edits endpoint (multipart); with none it uses /images/generations.
 */
class OpenAiImageClient implements ImageGenerator
{
    /** Portrait by default — physique shots are full-body verticals. */
    private const SIZE = '1024x1536';

    public function configured(): bool
    {
        return (bool) config('services.openai.key');
    }

    /**
     * @param  array<int,array{bytes:string,mime:string}>  $inputImages
     * @return array{path:string,url:string,mime:string}
     */
    public function generateToDisk(string $prompt, string $dir = 'physique', array $inputImages = []): array
    {
        $image = $this->generate($prompt, $inputImages);
        $ext = str_contains($image['mime'], 'jpeg') ? 'jpg' : (str_contains($image['mime'], 'webp') ? 'webp' : 'png');
        $path = trim($dir, '/').'/'.Str::uuid()->toString().'.'.$ext;

        Storage::disk('public')->put($path, $image['bytes']);

        return ['path' => $path, 'url' => Storage::disk('public')->url($path), 'mime' => $image['mime']];
    }

    /**
     * @param  array<int,array{bytes:string,mime:string}>  $inputImages
     * @return array{bytes:string,mime:string}
     */
    public function generate(string $prompt, array $inputImages = []): array
    {
        if (! $this->configured()) {
            throw new AiException('Image generation is not configured (missing OPENAI_API_KEY).');
        }

        $model = (string) config('services.openai.image_model', 'gpt-image-1');
        $timeout = (int) config('services.openai.image_timeout', 180);
        $base = rtrim((string) config('services.openai.base_url'), '/');

        $request = Http::withToken((string) config('services.openai.key'))->timeout($timeout);

        // With source images → edit endpoint (preserves the person). Without → plain generation.
        if ($inputImages !== []) {
            foreach (array_values($inputImages) as $i => $img) {
                if (empty($img['bytes'])) {
                    continue;
                }
                $ext = str_contains($img['mime'] ?? '', 'png') ? 'png' : (str_contains($img['mime'] ?? '', 'webp') ? 'webp' : 'jpg');
                $request = $request->attach("image[]", $img['bytes'], "source{$i}.{$ext}");
            }
            $payload = [
                'model' => $model,
                'prompt' => $prompt,
                'size' => self::SIZE,
                'quality' => 'high',
                'moderation' => 'low',        // fitness self-transformations trip the default sexual filter
                'n' => 1,
            ];
            // input_fidelity (keep the person's face) is a gpt-image-1 param; gpt-image-2 rejects it.
            if (str_starts_with($model, 'gpt-image-1')) {
                $payload['input_fidelity'] = 'high';
            }
            $url = $base.'/images/edits';
        } else {
            $request = $request->asJson();
            $payload = [
                'model' => $model,
                'prompt' => $prompt,
                'size' => self::SIZE,
                'quality' => 'high',
                'moderation' => 'low',
                'n' => 1,
            ];
            $url = $base.'/images/generations';
        }

        try {
            $response = $request->post($url, $payload);
        } catch (\Throwable $e) {
            throw new AiException('Could not reach OpenAI image API: '.$e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            $detail = $response->json('error.message') ?? $response->body();
            Log::warning('[OpenAiImage] generation failed', ['status' => $response->status(), 'detail' => $detail]);
            throw new AiException('Image generation failed: '.$detail);
        }

        $b64 = $response->json('data.0.b64_json');
        if (! $b64) {
            throw new AiException('OpenAI did not return an image. Try rephrasing the prompt.');
        }

        return ['bytes' => base64_decode($b64), 'mime' => 'image/png'];
    }

    /**
     * Read a stored file (public disk) into the {bytes,mime} shape generate() wants.
     *
     * @return array{bytes:string,mime:string}
     */
    public function imageFromDisk(string $path, string $disk = 'public'): array
    {
        $bytes = Storage::disk($disk)->get($path);
        $mime = Storage::disk($disk)->mimeType($path) ?: 'image/jpeg';

        return ['bytes' => (string) $bytes, 'mime' => $mime];
    }
}
