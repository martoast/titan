<?php

namespace App\Services\Ai;

use App\Exceptions\AiException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Generates images with Google's "Nano Banana 2" (Gemini Flash Image) via the
 * Gemini generateContent endpoint, which returns the image as base64 inline data.
 * Ported from fullstack-suite and extended to accept INPUT images so we can do
 * image+text → image -- the core of Titan's visual features:
 *   - dream-physique: current photo + "add 10 lbs lean muscle"
 *   - living goal image: current progress photo morphed a step toward the goal
 *
 * Dependency-free (plain Http), like AiService.
 */
class NanoBananaClient
{
    public function configured(): bool
    {
        return (bool) config('services.gemini.key');
    }

    /**
     * Generate an image and store it on the public disk.
     *
     * @param  array<int,array{bytes:string,mime:string}>  $inputImages  optional source images
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
     * Generate an image from a text prompt and zero or more input images.
     *
     * @param  array<int,array{bytes:string,mime:string}>  $inputImages
     * @return array{bytes:string,mime:string}
     */
    public function generate(string $prompt, array $inputImages = []): array
    {
        if (! $this->configured()) {
            throw new AiException('Image generation is not configured (missing GEMINI_API_KEY).');
        }

        $model = (string) config('services.gemini.image_model');
        $url = rtrim((string) config('services.gemini.base_url'), '/')."/models/{$model}:generateContent";

        // Build the multimodal parts: input images first, then the instruction.
        $parts = [];
        foreach ($inputImages as $img) {
            if (! empty($img['bytes'])) {
                $parts[] = ['inlineData' => [
                    'mimeType' => $img['mime'] ?? 'image/jpeg',
                    'data' => base64_encode($img['bytes']),
                ]];
            }
        }
        $parts[] = ['text' => $prompt];

        try {
            $response = Http::timeout((int) config('services.gemini.timeout', 120))
                ->acceptJson()
                ->withQueryParameters(['key' => config('services.gemini.key')])
                ->post($url, [
                    'contents' => [['parts' => $parts]],
                    'generationConfig' => ['responseModalities' => ['IMAGE', 'TEXT']],
                ]);
        } catch (\Throwable $e) {
            throw new AiException('Could not reach Gemini: '.$e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            $detail = $response->json('error.message') ?? $response->body();
            Log::warning('[NanoBanana] image generation failed', ['status' => $response->status(), 'detail' => $detail]);
            throw new AiException('Image generation failed: '.$detail);
        }

        foreach ($response->json('candidates.0.content.parts', []) as $part) {
            $data = $part['inlineData']['data'] ?? $part['inline_data']['data'] ?? null;
            if ($data) {
                return [
                    'bytes' => base64_decode($data),
                    'mime' => $part['inlineData']['mimeType'] ?? $part['inline_data']['mime_type'] ?? 'image/png',
                ];
            }
        }

        // Log the full response structure (minus raw image bytes) so we can diagnose
        // safety blocks or unexpected response shapes.
        $finish = $response->json('candidates.0.finishReason');
        $blockReason = $response->json('promptFeedback.blockReason');
        $textParts = collect($response->json('candidates.0.content.parts', []))
            ->filter(fn ($p) => isset($p['text']))
            ->pluck('text')
            ->implode(' | ');
        Log::warning('[NanoBanana] no image in response', [
            'finishReason' => $finish,
            'blockReason' => $blockReason,
            'textParts' => mb_substr($textParts, 0, 500),
            'partCount' => count($response->json('candidates.0.content.parts', [])),
            'candidateCount' => count($response->json('candidates', [])),
        ]);

        throw new AiException('Gemini did not return an image. Try rephrasing the prompt.');
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
