<?php

namespace App\Services\Ai;

/**
 * A provider-agnostic image generator (image+text → image). Both NanoBananaClient (Gemini) and
 * OpenAiImageClient (gpt-image-1) implement this, so the dream-physique / living-goal code can be
 * pointed at either via config (services.image.provider) without changing call sites.
 */
interface ImageGenerator
{
    /** Whether this provider is configured (has an API key). */
    public function configured(): bool;

    /**
     * Generate an image from a prompt + zero or more input images.
     *
     * @param  array<int,array{bytes:string,mime:string}>  $inputImages
     * @return array{bytes:string,mime:string}
     */
    public function generate(string $prompt, array $inputImages = []): array;

    /**
     * Generate an image and store it on the public disk.
     *
     * @param  array<int,array{bytes:string,mime:string}>  $inputImages
     * @return array{path:string,url:string,mime:string}
     */
    public function generateToDisk(string $prompt, string $dir = 'physique', array $inputImages = []): array;

    /**
     * Read a stored file into the {bytes,mime} shape generate() wants.
     *
     * @return array{bytes:string,mime:string}
     */
    public function imageFromDisk(string $path, string $disk = 'public'): array;
}
